<?php
/**
 * Activation and deactivation.
 *
 * @package WPASL
 */

namespace WPASL;

use WPASL\Generation\Scheduler;

/**
 * Sets the plugin up and tears it down, per site.
 */
final class Lifecycle {

	/**
	 * Sites fetched per page when iterating a network.
	 */
	const SITES_PER_PAGE = 100;

	/**
	 * Activation routine.
	 *
	 * @param bool $network_wide Whether all sites of a network are affected.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		self::for_each_site( $network_wide, array( __CLASS__, 'activate_site' ) );
	}

	/**
	 * Deactivation routine.
	 *
	 * @param bool $network_wide Whether all sites of a network are affected.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		self::for_each_site( $network_wide, array( __CLASS__, 'deactivate_site' ) );
	}

	/**
	 * Activates the current site.
	 *
	 * @param bool $flush_rewrite_rules Whether to flush the rewrite rules (not needed for a site created
	 *                                  after activation: the plugin registers no rules).
	 * @return void
	 */
	public static function activate_site( $flush_rewrite_rules = true ) {
		$settings = new Settings();
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', true );
		}

		$storage = new Storage();
		$storage->ensure();

		$scheduler = new Scheduler( $settings );
		$scheduler->schedule();

		if ( $flush_rewrite_rules ) {
			flush_rewrite_rules();
		}
	}

	/**
	 * Deactivates the current site.
	 *
	 * @return void
	 */
	public static function deactivate_site() {
		$scheduler = new Scheduler( new Settings() );
		$scheduler->unschedule();
		// No flush_rewrite_rules(): the plugin registers no rewrite rules (all routing runs on parse_request).
	}

	/**
	 * Runs a callback on the current site or on every site of the network.
	 *
	 * @param bool     $network_wide Whether to iterate the network.
	 * @param callable $callback     Callback.
	 * @return void
	 */
	private static function for_each_site( $network_wide, $callback ) {
		if ( ! $network_wide || ! is_multisite() ) {
			call_user_func( $callback );
			return;
		}
		self::each_site( $callback );
	}

	/**
	 * Runs a callback on every site of the network, switching to each one, in pages of SITES_PER_PAGE.
	 *
	 * @param callable $callback Callback.
	 * @return void
	 */
	public static function each_site( $callback ) {
		$offset = 0;
		do {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => self::SITES_PER_PAGE,
					'offset' => $offset,
				)
			);
			$fetched  = count( $site_ids );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				call_user_func( $callback );
				restore_current_blog();
			}
			$offset += self::SITES_PER_PAGE;
		} while ( self::SITES_PER_PAGE === $fetched );
	}
}
