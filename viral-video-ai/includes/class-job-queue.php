<?php
/**
 * Background execution.
 *
 * A long video must never be processed inside the request that submitted it.
 * This queue gives the pipeline three cooperating triggers, in order of
 * preference:
 *
 *   1. Action Scheduler (when the scheduler library or a plugin ships it) —
 *      real queue, retries, locking, visibility in Tools → Scheduled Actions.
 *   2. A non-blocking loopback "spawn" request fired right after the job is
 *      created, so processing starts within milliseconds instead of waiting for
 *      the next WP-Cron tick.
 *   3. WP-Cron heartbeat every minute, which also recovers jobs whose PHP
 *      process was killed mid-stage (timeout, OOM, deploy).
 *
 * Whichever trigger fires, the actual work is one bounded stage run
 * (VVAI_Video_Processor), so a request never runs long enough to be killed.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Job_Queue
 */
class VVAI_Job_Queue {

	const ACTION         = 'vvai_process_job';
	const HEARTBEAT      = 'vvai_queue_heartbeat';
	const CLEANUP        = 'vvai_daily_cleanup';
	const SPAWN_ACTION   = 'vvai_spawn';
	const CRON_SCHEDULE  = 'vvai_every_minute';
	const TOKEN_TTL      = 120;

	/**
	 * @var VVAI_Job_Manager
	 */
	private $jobs;

	/**
	 * @var VVAI_Video_Processor
	 */
	private $processor;

	/**
	 * Constructor.
	 *
	 * @param VVAI_Job_Manager|null     $jobs      Job repository.
	 * @param VVAI_Video_Processor|null $processor Pipeline.
	 */
	public function __construct( $jobs = null, $processor = null ) {
		$this->jobs      = $jobs instanceof VVAI_Job_Manager ? $jobs : new VVAI_Job_Manager();
		$this->processor = $processor instanceof VVAI_Video_Processor ? $processor : new VVAI_Video_Processor();
	}

	/**
	 * Attach hooks.
	 */
	public function register() {
		add_action( 'cron_init', array( __CLASS__, 'register_schedule' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedule' ) );

		add_action(
			self::ACTION,
			array( $this, 'handle_job' ),
			10,
			1
		);

		add_action( self::HEARTBEAT, array( $this, 'heartbeat' ) );
		add_action( self::CLEANUP, array( $this, 'cleanup' ) );

		// The loopback spawn endpoint (front-channel trigger #2).
		add_action( 'wp_ajax_' . self::SPAWN_ACTION, array( $this, 'handle_spawn' ) );
		add_action( 'wp_ajax_nopriv_' . self::SPAWN_ACTION, array( $this, 'handle_spawn' ) );

		// Self-heal the recurring events on ordinary admin requests.
		add_action( 'admin_init', array( __CLASS__, 'ensure_recurring' ) );
	}

	/**
	 * Register the custom 1-minute cron interval.
	 *
	 * @param array<string,array<int,string>>|void $schedules Schedules.
	 * @return array<string,array<int,string>>|void
	 */
	public static function register_schedule( $schedules = array() ) {
		if ( ! is_array( $schedules ) ) {
			return $schedules;
		}

		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = array(
				'interval' => 60,
				/* translators: %s: interval in seconds. */
				'display'  => sprintf( __( 'Every minute (%s seconds)', 'viral-video-ai' ), 60 ),
			);
		}

		return $schedules;
	}

	/**
	 * Schedule the recurring events (activation).
	 */
	public static function schedule_recurring() {
		if ( ! wp_next_scheduled( self::HEARTBEAT ) ) {
			wp_schedule_event( time() + 30, self::CRON_SCHEDULE, self::HEARTBEAT );
		}

		if ( ! wp_next_scheduled( self::CLEANUP ) ) {
			wp_schedule_event( time() + 120, 'daily', self::CLEANUP );
		}
	}

	/**
	 * Clear the recurring events (deactivation).
	 */
	public static function clear_recurring() {
		wp_clear_scheduled_hook( self::HEARTBEAT );
		wp_clear_scheduled_hook( self::CLEANUP );
	}

	/**
	 * Make sure the recurring events exist.
	 */
	public static function ensure_recurring() {
		if ( ! VVAI_DB::is_installed() ) {
			return;
		}

		// A missing heartbeat means jobs would sit in the queue forever.
		if ( ! wp_next_scheduled( self::HEARTBEAT ) && ! self::has_async_scheduler() ) {
			self::schedule_recurring();
		}
	}

	/**
	 * Is the Action Scheduler available?
	 *
	 * @return bool
	 */
	public static function has_async_scheduler() {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_schedule_single_action' );
	}


	/**
	 * Queue one job run.
	 *
	 * @param int $job_id Job id.
	 * @param int $delay  Seconds from now (only used by the cron fallback).
	 * @return string Which trigger accepted the job: scheduler|cron|idle.
	 */
	public function dispatch( $job_id, $delay = 5 ) {
		$job_id = (int) $job_id;
		$job    = $this->jobs->get( $job_id );

		if ( ! $job ) {
			return 'idle';
		}

		$used = 'idle';

		if ( self::has_async_scheduler() ) {
			$hook = self::ACTION;

			/**
			 * Filter the Action Scheduler group.
			 *
			 * @param string $group Group name.
			 * @param int    $job_id  Job id.
			 */
			$group = apply_filters( 'vvai_async_group', 'viral-video-ai', $job_id );

			as_enqueue_async_action( $hook, array( $job_id ), $group );

			$used = 'scheduler';
		} elseif ( ! wp_next_scheduled( self::ACTION, array( $job_id ) ) ) {
			wp_schedule_single_event( time() + max( 1, (int) $delay ), self::ACTION, array( $job_id ) );

			$used = 'cron';
		}

		// The loopback spawn makes the first stage start immediately instead of
		// waiting for the next cron tick; it is best-effort and non-blocking.
		if ( $this->spawn( $job_id ) ) {
			$used = 'spawn';
		}

		/**
		 * Fires after a job has been queued.
		 *
		 * @param int    $job_id Job id.
		 * @param string $used     Trigger used.
		 */
		do_action( 'vvai_job_dispatched', $job_id, $used );

		return $used;
	}


	/**
	 * Fire a non-blocking self-request that runs one pipeline tick now.
	 *
	 * @param int $job_id Job id.
	 * @return bool Whether the request was sent.
	 */
	public function spawn( $job_id ) {
		$job_id = (int) $job_id;

		if ( ! apply_filters( 'vvai_allow_loopback_spawn', true, $job_id ) ) {
			return false;
		}

		// Only one spawn may be in flight per job.
		if ( get_transient( 'vvai_spawn_' . $job_id ) ) {
			return false;
		}

		set_transient( 'vvai_spawn_' . $job_id, 1, 120 );

		$url  = add_query_arg(
			array(
				'action' => self::SPAWN_ACTION,
				'job'    => $job_id,
				'token'  => $this->spawn_token( $job_id ),
			),
			admin_url( 'admin-ajax.php' )
		);
		$args = array(
			'timeout'     => 0.01,
			'redirection' => 0,
			'blocking'    => false,
			'sslverify'   => true,
			'headers'     => array(
				// Some hosts drop unauthenticated loopback POSTs without one.
				'X-VVAI-Spawn' => (string) $job_id,
			),
			// Do not forward cookies: the endpoint is token-authenticated, and
			// sending an admin cookie to a self-request is needless risk.
			'cookies'     => array(),
		);

		$response = wp_remote_post( set_url_scheme( $url, 'https' === substr( (string) home_url(), 0, 5 ) ? 'https' : 'http' ), $args );

		if ( is_wp_error( $response ) ) {
			delete_transient( 'vvai_spawn_' . $job_id );

			return false;
		}

		return true;
	}

	/**
	 * Handle a loopback spawn request.
	 */
	public function handle_spawn() {
		$job_id = isset( $_GET['job'] ) ? absint( wp_unslash( $_GET['job'] ) ) : 0;
		$token  = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if ( ! $job_id || ! $this->verify_spawn_token( $job_id, $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		delete_transient( 'vvai_spawn_' . $job_id );

		// Run exactly one bounded tick, then exit without HTML.
		$this->handle_job( $job_id );

		wp_die( '', '', array( 'response' => 204 ) );
	}

	/**
	 * One-shot token proving the request came from this server.
	 *
	 * @param int $job_id Job id.
	 * @return string
	 */
	public function spawn_token( $job_id ) {
		return hash_hmac( 'sha256', 'spawn|' . (int) $job_id, $this->token_secret() );
	}

	/**
	 * Verify (and consume) a spawn token.
	 *
	 * @param int    $job_id Job id.
	 * @param string $token   Token.
	 * @return bool
	 */
	public function verify_spawn_token( $job_id, $token ) {
		if ( '' === $token || ! hash_equals( $this->spawn_token( $job_id ), (string) $token ) ) {
			return false;
		}

		// Consume it so a captured token cannot be replayed.
		$seen = get_transient( 'vvai_spawn_used_' . $job_id );

		if ( $seen ) {
			return false;
		}

		set_transient( 'vvai_spawn_used_' . $job_id, 1, self::TOKEN_TTL );

		return true;
	}

	/**
	 * Secret material for tokens (site-scoped, rotates with the NONCE_SALT).
	 *
	 * @return string
	 */
	protected function token_secret() {
		return (string) wp_salt( 'nonce' ) . '|vvai';
	}

	/**
	 * Run one pipeline tick for a job.
	 *
	 * @param int $job_id Job id.
	 * @return array<string,mixed>
	 */
	public function handle_job( $job_id ) {
		$job_id = (int) $job_id;
		$job    = $this->jobs->get( $job_id );

		if ( ! $job ) {
			return array( 'status' => 'missing' );
		}

		if ( VVAI_Job_Status::is_terminal( (string) $job['status'] ) ) {
			return array( 'status' => (string) $job['status'] );
		}

		$result = $this->processor->process( $job_id );

		if ( ! empty( $result['waiting'] ) && ! VVAI_Job_Status::is_terminal( (string) $result['status'] ) ) {
			// Still work to do: queue the next tick.
			$this->dispatch( $job_id, 3 );
		}

		return $result;
	}

	/**
	 * Cron heartbeat: start queued jobs, recover abandoned ones.
	 */
	public function heartbeat() {
		if ( ! VVAI_DB::is_installed() ) {
			return;
		}

		$settings    = get_option( VVAI_Settings::OPTION_KEY, array() );
		$concurrency = max( 1, (int) vvai_array_get( $settings, 'max_concurrent_jobs', 1 ) );
		$busy        = 0;

		// First recover jobs whose worker died.
		foreach ( $this->jobs->abandoned_jobs() as $job ) {
			$stage  = (string) $job['stage'];
			$status = (string) $job['status'];

			if ( in_array( $status, VVAI_Job_Status::active_stages(), true ) && ! $this->job_has_pending_run( (int) $job['id'] ) ) {
				$this->dispatch( (int) $job['id'], 1 );
				$busy++;

				if ( $busy >= $concurrency ) {
					return;
				}
			}

			unset( $stage );
		}

		foreach ( $this->jobs->pending_jobs( $concurrency + 2 ) as $job ) {
			if ( $busy >= $concurrency ) {
				break;
			}

			if ( $this->job_has_pending_run( (int) $job['id'] ) ) {
				continue;
			}

			$this->dispatch( (int) $job['id'], 1 );
			$busy++;
		}
	}

	/**
	 * Is a run already scheduled for this job?
	 *
	 * @param int $job_id Job id.
	 * @return bool
	 */
	protected function job_has_pending_run( $job_id ) {
		if ( get_transient( 'vvai_spawn_' . (int) $job_id ) ) {
			return true;
		}

		if ( self::has_async_scheduler() && function_exists( 'as_has_scheduled_action' ) ) {
			return (bool) as_has_scheduled_action( self::ACTION, array( (int) $job_id ), 'viral-video-ai' );
		}

		return (bool) wp_next_scheduled( self::ACTION, array( (int) $job_id ) );
	}

	/**
	 * Daily housekeeping: temp files, expired clips, orphaned uploads, stale logs.
	 */
	public function cleanup() {
		$settings = get_option( VVAI_Settings::OPTION_KEY, array() );

		if ( ! vvai_array_get( $settings, 'auto_cleanup', true ) ) {
			return array();
		}

		$results = new VVAI_Result_Manager( $this->jobs, new VVAI_Clip_Repository(), null );
		$report  = $results->enforce_retention();

		$uploads = new VVAI_Upload_Handler();
		$report['upload_sessions'] = $uploads->prune_expired();

		$hours = (int) vvai_array_get( $settings, 'temp_retention_hours', 6 );

		if ( $hours > 0 ) {
			$report['temp'] = $results->sweep_orphan_temp( $hours * HOUR_IN_SECONDS );
		}

		$transients = $this->sweep_stage_notes();

		if ( $transients ) {
			$report['stage_notes'] = $transients;
		}

		/**
		 * Filter the cleanup report shown on the diagnostics screen.
		 *
		 * @param array $report Report.
		 */
		return (array) apply_filters( 'vvai_cleanup_report', $report );
	}

	/**
	 * Drop stage-note transients belonging to finished jobs.
	 *
	 * @return int
	 */
	protected function sweep_stage_notes() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_vvai\\_stage\\_note\\_%' LIMIT 200"
		);

		$removed = 0;

		foreach ( $rows as $option_name ) {
			$id = (int) str_replace( '_transient_vvai_stage_note_', '', (string) $option_name );

			if ( $id <= 0 ) {
				continue;
			}

			$job = $this->jobs->get( $id );

			if ( ! $job || VVAI_Job_Status::is_terminal( (string) $job['status'] ) ) {
				delete_transient( 'vvai_stage_note_' . $id );
				$removed++;
			}
		}

		return $removed;
	}

	/**
	 * Cancel pending single-run events (deactivation).
	 */
	public static function cancel_all_pending() {
		wp_clear_scheduled_hook( self::ACTION );
	}
}
