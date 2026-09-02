<?php
/**
 * Plugin bootstrap.
 *
 * @package WPASL
 */

namespace WPASL;

/**
 * Wires the plugin services into WordPress.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Returns the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers hooks. Safe to call once.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Whether the plugin has booted.
	 *
	 * @return bool
	 */
	public function is_booted() {
		return $this->booted;
	}

	/**
	 * Loads translations shipped with the plugin.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'wp-agent-support-layer', false, dirname( plugin_basename( WPASL_FILE ) ) . '/languages' );
	}
}
