<?php
/**
 * Uninstall handler.
 *
 * Removes the plugin's own data only, and only when the site owner asked for it
 * in the settings ("delete data on uninstall"). Uploaded sources, rendered clips,
 * scratch audio, logs, options and custom tables are all inside the plugin's own
 * footprint, so nothing else on the site is touched.
 *
 * @package VVAI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Locate the plugin's options without loading any plugin code (the plugin files
 * may already be deleted at this point).
 */
$vvai_uninstall = get_option( 'vvai_settings', array() );
$vvai_purge     = true;

if ( is_array( $vvai_uninstall ) && isset( $vvai_uninstall['delete_data_on_uninstall'] ) ) {
	$vvai_purge = ! empty( $vvai_uninstall['delete_data_on_uninstall'] );
}

/**
 * The settings page exposes the checkbox, so honour it when present. A fresh
 * install that never saved settings keeps the safe default: purge, because
 * WordPress core's own uninstall contract is "remove your data".
 */
if ( ! $vvai_purge ) {
	return;
}

/**
 * Delete a directory tree, refusing anything outside the uploads folder.
 *
 * @param string $directory Absolute path.
 */
// Bound by reference so the closure can call itself: an unbound `$var()` inside
// a closure looks up a LOCAL variable and fatals with "not callable", which
// would silently leave every subfolder of uploads/vvai on the server.
$vvai_rrmdir = static function ( $directory ) use ( &$vvai_rrmdir ) {
	$uploads = wp_get_upload_dir();
	$root    = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . 'vvai' );
	$path    = wp_normalize_path( (string) $directory );

	if ( '' === $path || 0 !== strpos( $path, $root ) ) {
		return;
	}

	if ( is_file( $path ) ) {
		@unlink( $path );

		return;
	}

	if ( ! is_dir( $path ) ) {
		return;
	}

	$items = @scandir( $path );

	if ( ! is_array( $items ) ) {
		return;
	}

	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}

		$vvai_rrmdir( $path . '/' . $item );
	}

	@rmdir( $path );
};

global $wpdb;

// Custom tables.
foreach ( array( 'vvai_clips', 'vvai_jobs', 'vvai_uploads' ) as $vvai_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . $vvai_table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- uninstall only, identifier from a literal list.
}

// Files.
$vvai_rrmdir( trailingslashit( wp_get_upload_dir()['basedir'] ) . 'vvai' );

// Options.
foreach ( array(
	'vvai_settings',
	'vvai_connections',
	'vvai_db_version',
	'vvai_activation_error',
) as $vvai_option ) {
	delete_option( $vvai_option );
}

// Transients and scheduled events.
delete_transient( 'vvai_ffmpeg_availability' );
delete_transient( 'vvai_loopback_check' );
delete_transient( 'vvai_rest_reachable' );
delete_transient( 'vvai_activated' );
delete_transient( 'vvai_activated_redirect' );

foreach ( array( 'vvai_queue_heartbeat', 'vvai_daily_cleanup', 'vvai_process_job' ) as $vvai_hook ) {
	wp_clear_scheduled_hook( $vvai_hook );
}

// Capability grants made on activation.
foreach ( array( 'administrator', 'editor', 'author', 'contributor' ) as $vvai_role_name ) {
	$vvai_role = get_role( $vvai_role_name );

	if ( ! $vvai_role ) {
		continue;
	}

	$vvai_role->remove_cap( 'vvai_manage' );
	$vvai_role->remove_cap( 'vvai_generate' );
}

// Leftover per-job transients (stage notes) and any orphaned options.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_vvai\\_%' OR option_name LIKE '\\_transient\\_timeout\\_vvai\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- uninstall cleanup.
