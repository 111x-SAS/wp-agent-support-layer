<?php
/**
 * Plugin bootstrap.
 *
 * @package WPASL
 */

namespace WPASL;

use WPASL\Admin\ExcludeMetaBox;
use WPASL\Admin\GenerationStatus;
use WPASL\Admin\Page;
use WPASL\CLI\Commands;
use WPASL\Content\Eligibility;
use WPASL\Generation\Runner;
use WPASL\Generation\Scheduler;
use WPASL\Generation\State;
use WPASL\Markdown\Delivery;
use WPASL\Markdown\DocumentBuilder;
use WPASL\Llms\LlmsTxtBuilder;
use WPASL\Llms\LlmsTxtRouter;
use WPASL\Markdown\LeagueConverter;
use WPASL\Robots\RobotsTxt;
use WPASL\Signals\ContentSignals;

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

		$settings    = new Settings();
		$storage     = new Storage();
		$scheduler   = new Scheduler( $settings );
		$eligibility = new Eligibility( $settings );
		$runner      = new Runner( $settings, $storage, $eligibility, new State() );
		$page        = new Page( $settings );

		$this->services           = array(
			'settings'    => $settings,
			'storage'     => $storage,
			'scheduler'   => $scheduler,
			'eligibility' => $eligibility,
			'runner'      => $runner,
			'page'        => $page,
			'exclude'     => new ExcludeMetaBox( $settings ),
			'status'      => new GenerationStatus( $runner, $scheduler, $page ),
			'delivery'    => new Delivery( $settings, $storage, $eligibility, $runner ),
			'signals'     => new ContentSignals( $settings ),
		);
		$this->services['robots'] = new RobotsTxt( $settings, $this->services['signals'] );

		$llms_builder                  = new LlmsTxtBuilder( $settings, $eligibility, $this->services['delivery'] );
		$this->services['llms']        = $llms_builder;
		$this->services['llms_router'] = new LlmsTxtRouter( $settings, $storage, $llms_builder, $this->services['delivery'] );
		$runner->add_artifact_generator( $llms_builder );

		if ( LeagueConverter::is_available() ) {
			$this->services['converter'] = new LeagueConverter();
			$this->services['builder']   = new DocumentBuilder( $this->services['converter'] );
			$runner->set_item_generator( $this->services['builder'] );
		} else {
			add_action( 'admin_notices', array( $this, 'missing_build_notice' ) );
		}

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

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::add_command( 'wpasl', new Commands( $runner, $scheduler ) );
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
	 * Warns when the prefixed dependencies were not built (development checkout without "composer run build").
	 *
	 * @return void
	 */
	public function missing_build_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'WP Agent Support Layer: the bundled HTML-to-Markdown library is missing. Install a release build, or run "composer run build" in the plugin directory.', 'wp-agent-support-layer' )
		);
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
