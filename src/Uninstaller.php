<?php
/**
 * Uninstall routine.
 *
 * @package WPASL
 */

namespace WPASL;

use WPASL\Generation\Scheduler;

/**
 * Removes every trace of the plugin without touching site content.
 */
final class Uninstaller {

	const EXCLUDE_META = '_wpasl_exclude';

	/**
	 * Options owned by the plugin.
	 *
	 * @var string[]
	 */
	const OPTIONS = array( Settings::OPTION, 'wpasl_state', Storage::TOKEN_OPTION, Plugin::VERSION_OPTION );

	/**
	 * Transients owned by the plugin.
	 *
	 * @var string[]
	 */
	const TRANSIENTS = array( 'wpasl_diagnostics_report' );

	/**
	 * Runs the uninstall for the current site, or every site in a network.
	 *
	 * @return void
	 */
	public static function run() {
		if ( ! is_multisite() ) {
			self::run_site();
			return;
		}

		Lifecycle::each_site( array( __CLASS__, 'run_site' ) );
	}

	/**
	 * Cleans the current site.
	 *
	 * @return void
	 */
	public static function run_site() {
		( new Scheduler( new Settings() ) )->unschedule();
		// Recurring, manual one-off and llms-full events.

		Storage::delete_all();

		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}

		foreach ( self::TRANSIENTS as $transient ) {
			delete_transient( $transient );
		}
		self::delete_run_transients();

		delete_metadata( 'post', 0, self::EXCLUDE_META, '', true );
	}

	/**
	 * Deletes the per-user diagnostics runs in progress (transients suffixed with the user id).
	 *
	 * @return void
	 */
	private static function delete_run_transients() {
		global $wpdb;
		$prefix = Diagnostics\DiagnosticsController::RUN_TRANSIENT;
		$names  = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uninstall cleanup by prefix.
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
			)
		);
		foreach ( (array) $names as $name ) {
			delete_option( $name );
		}
	}
}
