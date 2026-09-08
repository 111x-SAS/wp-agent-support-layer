<?php
/**
 * AI crawler rules in the virtual robots.txt.
 *
 * @package WPASL
 */

namespace WPASL\Robots;

use WPASL\Admin\Page;
use WPASL\Admin\Tabs\CrawlersTab;
use WPASL\Manifest\AuthMdBuilder;
use WPASL\Settings;
use WPASL\Signals\ContentSignals;

/**
 * Appends one group per cataloged crawler to the robots.txt WordPress serves, and detects a physical file.
 */
final class RobotsTxt {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Policy.
	 *
	 * @var Policy
	 */
	private $policy;

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Settings.
	 * @param ContentSignals $signals  Signals.
	 */
	public function __construct( Settings $settings, ContentSignals $signals ) {
		$this->settings = $settings;
		$this->policy   = new Policy( $settings, $signals );
	}

	/**
	 * Policy resolver.
	 *
	 * @return Policy
	 */
	public function policy() {
		return $this->policy;
	}

	/**
	 * Registers hooks. Runs after the Content Signals filter (priority 10).
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), 20 );
		add_action( 'wpasl_register_tabs', array( $this, 'register_tab' ) );
		add_action( 'admin_notices', array( $this, 'physical_file_notice' ) );
	}

	/**
	 * Adds the Crawlers tab.
	 *
	 * @param Page $page Settings page.
	 * @return void
	 */
	public function register_tab( Page $page ) {
		$page->add_tab( new CrawlersTab( $this->settings, $this ) );
	}

	/**
	 * The block appended to robots.txt: one group per crawler plus the llms.txt and auth.md pointers.
	 *
	 * @return string
	 */
	public function rules_block() {
		$lines = array( '# AI crawlers - managed by WP Agent Support Layer' );
		foreach ( $this->policy->all() as $agent => $policy ) {
			$lines[] = '';
			$lines[] = 'User-agent: ' . $agent;
			$lines[] = Policy::BLOCK === $policy ? 'Disallow: /' : 'Allow: /';
		}
		$lines[] = '';
		$lines[] = '# llms.txt: ' . home_url( '/llms.txt' );
		if ( AuthMdBuilder::is_published( $this->settings ) ) {
			$lines[] = '# auth.md: ' . AuthMdBuilder::url();
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Appends the rules to the virtual robots.txt without touching existing lines.
	 *
	 * @param string $output robots.txt output.
	 * @return string
	 */
	public function filter_robots_txt( $output ) {
		return rtrim( (string) $output, "\n" ) . "\n\n" . $this->rules_block();
	}

	/**
	 * Path of a physical robots.txt, filterable for tests.
	 *
	 * @return string
	 */
	public static function physical_path() {
		/**
		 * Filters the path checked for a physical robots.txt.
		 *
		 * @param string $path Absolute path.
		 */
		return (string) apply_filters( 'wpasl_physical_robots_path', ABSPATH . 'robots.txt' );
	}

	/**
	 * Whether a physical robots.txt exists (WordPress then never serves the virtual one).
	 *
	 * @return bool
	 */
	public static function physical_file_exists() {
		return is_file( self::physical_path() );
	}

	/**
	 * Full virtual robots.txt as WordPress would generate it, for the copy-paste box.
	 *
	 * @return string
	 */
	public function generated_output() {
		// Mirrors do_robots() (wp-includes/functions.php): core emits the same two rules whether or not the
		// site discourages search engines; $public only reaches the filter.
		$public = (bool) get_option( 'blog_public' );
		$core   = "User-agent: *\n";
		$core  .= 'Disallow: ' . wp_parse_url( admin_url(), PHP_URL_PATH ) . "\n";
		$core  .= 'Allow: ' . wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH ) . "\n";
		/** This filter is documented in wp-includes/functions.php */
		return (string) apply_filters( 'robots_txt', $core, $public ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
	}

	/**
	 * Warns on the plugin page when a physical robots.txt shadows the virtual one.
	 *
	 * @return void
	 */
	public function physical_file_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, Page::SLUG ) || ! self::physical_file_exists() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'A physical robots.txt file exists in the site root, so WordPress does not serve the generated one. Copy the rules from the Crawlers tab into that file.', 'wp-agent-support-layer' )
		);
	}
}
