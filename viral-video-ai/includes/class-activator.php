<?php
/**
 * Activation routines.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Activator
 */
final class VVAI_Activator {

	/**
	 * Run on plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( version_compare( PHP_VERSION, VVAI_MIN_PHP, '<' ) ) {
			deactivate_plugins( VVAI_PLUGIN_BASENAME );

			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: PHP version. */
						__( 'Viral Video AI needs PHP %s or newer. The plugin was not activated.', 'viral-video-ai' ),
						VVAI_MIN_PHP
					)
				),
				'',
				array( 'response' => 412 )
			);
		}

		$installed = VVAI_DB::install( true );

		if ( ! $installed['ok'] ) {
			update_option( 'vvai_activation_error', $installed['errors'], false );
		} else {
			delete_option( 'vvai_activation_error' );
		}

		// First-run defaults, without overwriting an existing configuration.
		$existing = get_option( VVAI_Settings::OPTION_KEY, null );

		if ( ! is_array( $existing ) ) {
			add_option( VVAI_Settings::OPTION_KEY, VVAI_Settings::defaults(), '', 'yes' );
		}

		if ( false === get_option( 'vvai_connections', false ) ) {
			add_option( 'vvai_connections', array(), '', 'yes' );
		}

		self::create_storage();
		self::grant_roles();
		VVAI_Job_Queue::schedule_recurring();

		// Make the new REST routes reachable without a manual flush.
		flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules -- one-off on activation.

		set_transient( 'vvai_activated', 1, 45 );
	}

	/**
	 * Create the uploads/vvai tree with directory listing protection.
	 */
	private static function create_storage() {
		foreach ( array( '', 'logs', 'sources', 'jobs', 'tmp', 'tmp/uploads' ) as $sub ) {
			vvai_storage_path( $sub );
		}

		// The clip folders inside jobs/ must not be web-readable directly.
		$jobs = vvai_storage_dir( 'jobs' );

		if ( is_dir( $jobs ) ) {
			vvai_harden_directory( $jobs );
		}

		// Also guard the storage root itself.
		vvai_harden_directory( vvai_storage_dir() );
	}

	/**
	 * Give authors/editors the job capability (they can run jobs but not read
	 * settings).
	 */
	private static function grant_roles() {
		foreach ( array( 'administrator' => true, 'editor' => true, 'author' => true, 'contributor' => true ) as $role_name => $unused ) {
			$role = get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			if ( 'administrator' === $role_name && ! $role->has_cap( 'vvai_manage' ) ) {
				$role->add_cap( 'vvai_manage' );
			}

			if ( ! $role->has_cap( 'vvai_generate' ) ) {
				$role->add_cap( 'vvai_generate' );
			}
		}
	}
}
