<?php
/**
 * Deactivation routines.
 *
 * Deliberately conservative: no files and no database rows are removed here so
 * that re-activating the plugin resumes exactly where the site left off.
 * Removal of data only ever happens in uninstall.php or by the retention
 * scheduler.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Deactivator
 */
final class VVAI_Deactivator {

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate() {
		VVAI_Job_Queue::clear_recurring();

		// Release job locks so a later activation does not wait for expiry.
		if ( class_exists( 'VVAI_Job_Manager' ) ) {
			$jobs = new VVAI_Job_Manager();
			$jobs->release_all_locks();
		}

		// Cancel pending one-off stage events.
		VVAI_Job_Queue::cancel_all_pending();
	}
}
