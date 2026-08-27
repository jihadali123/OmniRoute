<?php
/**
 * Capability and ownership checks.
 *
 * Centralised so the REST controllers, AJAX handlers, admin screens, the
 * Elementor widget and the download streamer all ask the same question the same
 * way.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Permissions
 */
final class VVAI_Permissions {

	/**
	 * Capability that manages the whole plugin (settings, connections, all jobs).
	 *
	 * @return string
	 */
	public static function manage_capability() {
		/**
		 * Filter the capability required to administer Viral Video AI.
		 *
		 * @param string $capability Capability name. Default `manage_options`.
		 */
		return (string) apply_filters( 'vvai_manage_capability', VVAI_MANAGE_CAP_DEFAULT );
	}

	/**
	 * Capability to run generation jobs from the frontend widget.
	 *
	 * @return string
	 */
	public static function create_capability() {
		/**
		 * Filter the capability required to create a video job.
		 *
		 * @param string $capability Capability name. Default `upload_files`.
		 */
		return (string) apply_filters( 'vvai_create_job_capability', 'upload_files' );
	}

	/**
	 * Can the current user administer the plugin?
	 *
	 * @param int|WP|null $user_id Optional user id.
	 * @return bool
	 */
	public static function can_manage( $user_id = null ) {
		return self::user_has( $user_id, self::manage_capability() );
	}

	/**
	 * Can the current user start jobs / upload videos?
	 *
	 * Frontend submissions additionally honour the `require_login` setting.
	 *
	 * @param int|null $user_id Optional user id.
	 * @return bool
	 */
	public static function can_create_job( $user_id = null ) {
		$user_id = ( null === $user_id ) ? get_current_user_id() : (int) $user_id;

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( ! self::user_has( $user_id, self::create_capability() ) ) {
			return false;
		}

		/**
		 * Filter whether a specific user may create jobs.
		 *
		 * @param bool $allowed Allowed.
		 * @param int  $user_id   User id.
		 */
		return (bool) apply_filters( 'vvai_user_can_create_job', true, $user_id );
	}

	/**
	 * May this user read this job?
	 *
	 * Owners always can; managers can read everything; guests can only read
	 * when the site deliberately allows public results.
	 *
	 * @param array<int|string>|object $job     Job row.
	 * @param int|null                   $user_id User id.
	 * @return bool
	 */
	public static function can_read_job( $job, $user_id = null ) {
		$user_id = ( null === $user_id ) ? get_current_user_id() : (int) $user_id;
		$owner   = self::owner_of( $job );

		if ( self::can_manage( $user_id ) ) {
			return true;
		}

		if ( $user_id > 0 && $user_id === $owner ) {
			return true;
		}

		$shared = is_array( $job ) ? (int) vvai_array_get( $job, 'public', 0 ) : (int) $job->public;

		if ( $shared ) {
			/**
			 * Filter whether publicly shared job results may be read.
			 *
			 * @param bool $allow  Allow.
			 * @param int  $job_id Job id.
			 */
			return (bool) apply_filters( 'vvai_allow_public_results', false, self::job_id( $job ) );
		}

		return false;
	}

	/**
	 * May this user act on this job (retry, delete, download)?
	 *
	 * @param array|object $job     Job row.
	 * @param int|null     $user_id User id.
	 * @return bool
	 */
	public static function can_modify_job( $job, $user_id = null ) {
		$user_id = ( null === $user_id ) ? get_current_user_id() : (int) $user_id;

		if ( self::can_manage( $user_id ) ) {
			return true;
		}

		return $user_id > 0 && $user_id === self::owner_of( $job );
	}

	/**
	 * Does a user (or the current user) have a capability?
	 *
	 * @param int|null $user_id     User id or null for current.
	 * @param string   $capability  Capability.
	 * @return bool
	 */
	private static function user_has( $user_id, $capability ) {
		if ( null === $user_id ) {
			return current_user_can( $capability );
		}

		$user = get_user_by( 'id', (int) $user_id );

		if ( ! $user ) {
			return false;
		}

		return $user->has_cap( $capability );
	}

	/**
	 * Read the owning user of a job row.
	 *
	 * The column is `author_id` (jobs never use post authorship), but a caller may
	 * hand us a payload that used `user_id` — both are accepted here so ownership
	 * can never silently degrade to "nobody owns this", which would lock owners out
	 * of their own clips.
	 *
	 * @param array|object $job Job row.
	 * @return int
	 */
	private static function owner_of( $job ) {
		if ( is_array( $job ) ) {
			$owner = (int) vvai_array_get( $job, 'author_id', 0 );

			return $owner > 0 ? $owner : (int) vvai_array_get( $job, 'user_id', 0 );
		}

		$owner = isset( $job->author_id ) ? (int) $job->author_id : 0;

		return $owner > 0 ? $owner : ( isset( $job->user_id ) ? (int) $job->user_id : 0 );
	}

	/**
	 * Job id from either an array or an object.
	 *
	 * @param array|object $job Job.
	 * @return int
	 */
	private static function job_id( $job ) {
		return is_array( $job ) ? (int) vvai_array_get( $job, 'id', 0 ) : (int) $job->id;
	}
}
