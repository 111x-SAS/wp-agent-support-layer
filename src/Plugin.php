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
use WPASL\Diagnostics\CrawlerProbe;
use WPASL\Diagnostics\DiagnosticsController;
use WPASL\Diagnostics\PageCache;
use WPASL\Diagnostics\Report;
use WPASL\Generation\State;
use WPASL\Markdown\ContentExtractor;
use WPASL\Markdown\ContentSource;
use WPASL\Markdown\Delivery;
use WPASL\Markdown\DocumentBuilder;
use WPASL\Markdown\RenderedPage;
use WPASL\Llms\LlmsTxtBuilder;
use WPASL\Llms\LlmsTxtRouter;
use WPASL\Manifest\AuthMdBuilder;
use WPASL\Manifest\AuthMdRouter;
use WPASL\Manifest\CapabilityRegistry;
use WPASL\Manifest\DiscoveryLinks;
use WPASL\Manifest\ManifestBuilder;
use WPASL\Manifest\ManifestRouter;
use WPASL\Markdown\LeagueConverter;
use WPASL\Robots\RobotsTxt;
use WPASL\Signals\ContentSignals;

/**
 * Wires the plugin services into WordPress.
 */
final class Plugin {

	/**
	 * Option holding the last version that ran maybe_upgrade().
	 */
	const VERSION_OPTION = 'wpasl_version';

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
			'settings'          => $settings,
			'storage'           => $storage,
			'scheduler'         => $scheduler,
			'eligibility'       => $eligibility,
			'runner'            => $runner,
			'page'              => $page,
			'exclude_meta_box'  => new ExcludeMetaBox( $settings ),
			'status'            => new GenerationStatus( $runner, $scheduler, $page ),
			'delivery'          => new Delivery( $settings, $storage, $eligibility, $runner ),
			'signals'           => new ContentSignals( $settings ),
			'content_source'    => new ContentSource( $settings ),
			'rendered_page'     => new RenderedPage(),
			'content_extractor' => new ContentExtractor( $settings ),
		);
		$this->services['robots'] = new RobotsTxt( $settings, $this->services['signals'] );

		$llms_builder                  = new LlmsTxtBuilder( $settings, $eligibility, $this->services['delivery'] );
		$this->services['llms']        = $llms_builder;
		$this->services['llms_router'] = new LlmsTxtRouter( $settings, $storage, $llms_builder, $this->services['delivery'], $scheduler );
		$runner->add_artifact_generator( $llms_builder );

		$registry                          = new CapabilityRegistry( $settings );
		$manifest_builder                  = new ManifestBuilder( $settings, $registry, $this->services['signals'] );
		$this->services['manifest']        = $manifest_builder;
		$this->services['manifest_router'] = new ManifestRouter( $settings, $storage, $manifest_builder, $this->services['delivery'], $this->services['signals'] );
		$runner->add_artifact_generator( $manifest_builder );

		$auth_md_builder                  = new AuthMdBuilder( $settings, $registry, $this->services['signals'] );
		$this->services['auth_md']        = $auth_md_builder;
		$this->services['auth_md_router'] = new AuthMdRouter( $settings, $storage, $auth_md_builder, $this->services['delivery'] );
		$runner->add_artifact_generator( $auth_md_builder );
		$this->services['discovery_links'] = new DiscoveryLinks( $settings );

		$probe                         = new CrawlerProbe( $eligibility, $this->services['delivery'], $storage, $llms_builder );
		$page_cache                    = new PageCache( $settings, $this->services['signals'] );
		$this->services['probe']       = $probe;
		$this->services['page_cache']  = $page_cache;
		$this->services['diagnostics'] = new DiagnosticsController(
			$probe,
			new Report( $this->services['robots']->policy(), $this->services['signals'] ),
			$page,
			$page_cache
		);

		if ( LeagueConverter::is_available() ) {
			$this->services['converter'] = new LeagueConverter();
			$this->services['builder']   = new DocumentBuilder(
				$this->services['converter'],
				$this->services['content_source'],
				$this->services['rendered_page'],
				$this->services['content_extractor']
			);
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
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		// After core populated the new site (priority 10).
		add_action( 'wp_initialize_site', array( $this, 'initialize_site' ), 100 );

		foreach ( $this->services as $service ) {
			if ( method_exists( $service, 'register' ) ) {
				$service->register();
			}
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::add_command( 'wpasl', new Commands( $runner, $scheduler, $settings, $this->services['content_source'] ) );
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
	 * One-off housekeeping when the plugin version changes: options read on every front-end request
	 * become autoloaded (installs created before 1.0.2 stored them without autoload).
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		// Self-repair: a site that lost its recurring event (or was never set up) gets it back on its first
		// admin request. Reading the cron option is cheap; scheduling only happens when the event is missing.
		$scheduler = $this->get( 'scheduler' );
		if ( $scheduler instanceof Scheduler && null === $scheduler->current_interval() ) {
			$scheduler->schedule();
		}

		if ( WPASL_VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}
		wp_set_option_autoload_values(
			array(
				Settings::OPTION      => true,
				Storage::TOKEN_OPTION => true,
			)
		);
		$storage = $this->get( 'storage' );
		if ( $storage instanceof Storage ) {
			$storage->ensure();
		}
		update_option( self::VERSION_OPTION, WPASL_VERSION, true );
	}

	/**
	 * Sets up a site created after a network-wide activation (activation only ran on the sites that
	 * existed at that time).
	 *
	 * @param \WP_Site $site The new site.
	 * @return void
	 */
	public function initialize_site( $site ) {
		if ( ! $site instanceof \WP_Site || ! is_multisite() || ! self::is_network_active() ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		Lifecycle::activate_site( false );
		restore_current_blog();
	}

	/**
	 * Whether the plugin is activated for the whole network.
	 *
	 * @return bool
	 */
	public static function is_network_active() {
		if ( ! is_multisite() ) {
			return false;
		}
		$plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
		return isset( $plugins[ plugin_basename( WPASL_FILE ) ] );
	}

	/**
	 * Loads translations shipped with the plugin.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		// Bundled translations in /languages for installs without a WordPress.org language pack (GitHub
		// releases, pre-approval). Language packs, when present, take precedence over the bundled files.
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
		load_plugin_textdomain( 'wp-agent-support-layer', false, dirname( plugin_basename( WPASL_FILE ) ) . '/languages' );
	}
}
