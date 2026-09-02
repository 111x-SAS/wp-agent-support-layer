<?php
/**
 * Plugin bootstrap.
 *
 * @package WPASL
 */

namespace WPASL;

use WPASL\Admin\ExcludeMetaBox;
use WPASL\Admin\Page;
use WPASL\Generation\Scheduler;

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
	 * Registered services keyed by id.
	 *
	 * @var array<string, object>
	 */
	private $services = array();

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

		$settings  = new Settings();
		$storage   = new Storage();
		$scheduler = new Scheduler( $settings );

		$this->services = array(
			'settings'  => $settings,
			'storage'   => $storage,
			'scheduler' => $scheduler,
			'page'      => new Page( $settings ),
			'exclude'   => new ExcludeMetaBox( $settings ),
		);

		/**
		 * Filters the service map before the services are registered.
		 *
		 * @param array<string, object> $services Services keyed by id.
		 * @param Plugin                $plugin   Plugin instance.
		 */
		$this->services = apply_filters( 'wpasl_services', $this->services, $this );

		add_action( 'init', array( $this, 'load_textdomain' ) );

		foreach ( $this->services as $service ) {
			if ( method_exists( $service, 'register' ) ) {
				$service->register();
			}
		}
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
	 * Returns a registered service.
	 *
	 * @param string $id Service id.
	 * @return object|null
	 */
	public function get( $id ) {
		return isset( $this->services[ $id ] ) ? $this->services[ $id ] : null;
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
