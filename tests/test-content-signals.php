<?php
/**
 * Content Signals tests.
 *
 * @package WPASL
 */

use WPASL\Http;
use WPASL\Plugin;
use WPASL\Settings;
use WPASL\Signals\ContentSignals;

/**
 * Covers WPASL\Signals\ContentSignals.
 */
class Test_Content_Signals extends WP_UnitTestCase {

	/**
	 * @var ContentSignals
	 */
	private $signals;

	public function set_up() {
		parent::set_up();
		$this->signals = Plugin::instance()->get( 'signals' );
	}

	public function tear_down() {
		remove_all_filters( 'wpasl_diagnostics_page_cache' );
		delete_option( Settings::OPTION );
		Plugin::instance()->get( 'settings' )->flush_cache();
		set_current_screen( 'front' );
		parent::tear_down();
	}

	private function set_signals( $search, $input, $train, $usage = true ) {
		update_option(
			Settings::OPTION,
			array(
				'signal_search'        => $search,
				'signal_ai_input'      => $input,
				'signal_ai_train'      => $train,
				'content_usage_header' => $usage,
			)
		);
		Plugin::instance()->get( 'settings' )->flush_cache();
	}

	public function test_default_values() {
		$this->assertSame(
			array(
				'search'   => 'yes',
				'ai-input' => 'yes',
				'ai-train' => 'no',
			),
			$this->signals->values()
		);
		$this->assertSame( 'search=yes, ai-input=yes, ai-train=no', $this->signals->header_value() );
		$this->assertSame( 'train-ai=n, search=y', $this->signals->content_usage_value() );
	}

	public function test_robots_txt_gets_directive_inside_the_wildcard_group() {
		$core   = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: " . home_url( '/wp-sitemap.xml' ) . "\n";
		$output = $this->signals->filter_robots_txt( $core );

		$this->assertMatchesRegularExpression( '/^User-agent: \*\n# Content Signals[^\n]*\nContent-Signal: search=yes, ai-input=yes, ai-train=no\nDisallow: \/wp-admin\//m', $output );
		$this->assertStringContainsString( 'Sitemap: ', $output );
	}

	public function test_robots_txt_via_core_filter_reflects_changes() {
		$this->set_signals( 'yes', 'yes', 'yes' );
		$output = apply_filters( 'robots_txt', "User-agent: *\nDisallow: /wp-admin/\n", true );
		$this->assertStringContainsString( 'Content-Signal: search=yes, ai-input=yes, ai-train=yes', $output );
	}

	public function test_robots_txt_without_wildcard_group_appends_one() {
		$output = $this->signals->filter_robots_txt( "User-agent: Foo\nDisallow: /\n" );
		$this->assertStringContainsString( "User-agent: *\n# Content Signals", $output );
	}

	public function test_headers_by_default() {
		$headers = $this->signals->headers();
		$this->assertSame( 'search=yes, ai-input=yes, ai-train=no', $headers['Content-Signal'] );
		$this->assertSame( 'train-ai=n, search=y', $headers['Content-Usage'] );
		$this->assertSame( 'noai, noimageai', $headers['X-Robots-Tag'] );
	}

	public function test_headers_without_experimental_header() {
		$this->set_signals( 'yes', 'yes', 'no', false );
		$headers = $this->signals->headers();
		$this->assertArrayHasKey( 'Content-Signal', $headers );
		$this->assertArrayNotHasKey( 'Content-Usage', $headers );
	}

	public function test_headers_when_training_allowed() {
		$this->set_signals( 'no', 'no', 'yes' );
		$headers = $this->signals->headers();
		$this->assertSame( 'search=no, ai-input=no, ai-train=yes', $headers['Content-Signal'] );
		$this->assertSame( 'train-ai=y, search=n', $headers['Content-Usage'] );
		$this->assertArrayNotHasKey( 'X-Robots-Tag', $headers );
	}

	public function test_markdown_responses_get_signals_but_no_robots_tag() {
		$headers = $this->signals->headers( false );
		$this->assertArrayHasKey( 'Content-Signal', $headers );
		$this->assertArrayNotHasKey( 'X-Robots-Tag', $headers );
	}

	public function test_html_and_markdown_announce_api_catalog_link() {
		$expected = '<' . home_url( '/.well-known/api-catalog' ) . '>; rel="api-catalog"';

		Http::reset();
		$this->signals->send();
		$headers = Http::effective_headers();
		$this->assertContains( $expected, $headers['link'] );
		$this->assertArrayHasKey( 'x-robots-tag', $headers );

		Http::reset();
		Http::send_header( 'Link', '<https://example.org/>; rel="canonical"' );
		$this->signals->send( 'markdown' );
		$headers = Http::effective_headers();
		$this->assertSame( array( '<https://example.org/>; rel="canonical"', $expected ), $headers['link'], 'Added, never replacing other Link headers.' );

		Http::reset();
		$this->signals->send( 'llms-txt' );
		$this->assertArrayNotHasKey( 'link', Http::effective_headers() );
	}

	public function test_api_catalog_link_matches_the_header_sent() {
		$expected = '<' . home_url( '/.well-known/api-catalog' ) . '>; rel="api-catalog"';
		$this->assertSame( $expected, $this->signals->api_catalog_link() );

		Http::reset();
		$this->signals->send();
		$this->assertSame( array( $this->signals->api_catalog_link() ), Http::effective_headers()['link'] );
	}

	public function test_signals_tab_warns_about_cache_enabler() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$tab = Plugin::instance()->get( 'page' )->tabs()['signals'];

		add_filter(
			'wpasl_diagnostics_page_cache',
			static function () {
				return array(
					'id'      => 'cache-enabler',
					'name'    => 'Cache Enabler',
					'version' => '1.8.16',
				);
			}
		);
		ob_start();
		$tab->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Cache Enabler is active.', $html );
		$this->assertStringContainsString( 'notice notice-warning inline', $html );
		$this->assertMatchesRegularExpression( '/<a href="[^"]*tools\.php\?page=wp-agent-support-layer&(amp;)?tab=diagnostics">Diagnostics tab<\/a>/', $html );
		$this->assertStringContainsString( 'wpasl_settings[signal_ai_train]', $html, 'The form fields are still rendered.' );

		remove_all_filters( 'wpasl_diagnostics_page_cache' );
		add_filter( 'wpasl_diagnostics_page_cache', '__return_null' );
		ob_start();
		$tab->render();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'Cache Enabler', $html );
		$this->assertStringNotContainsString( 'notice-warning', $html );

		ob_start();
		Plugin::instance()->get( 'page' )->tabs()['manifests']->render();
		$this->assertStringNotContainsString( 'Cache Enabler', ob_get_clean(), 'The Manifests tab does not change.' );
	}

	public function test_no_api_catalog_link_when_manifest_disabled() {
		update_option( Settings::OPTION, array( 'manifest_enabled' => false ) );
		Plugin::instance()->get( 'settings' )->flush_cache();
		Http::reset();
		$this->signals->send();
		$headers = Http::effective_headers();
		$this->assertArrayNotHasKey( 'link', $headers );
		$this->assertArrayHasKey( 'content-signal', $headers );
	}

	public function test_robots_feed_and_sitemap_have_no_noai_header() {
		// Other tests reset WP_Rewrite (set_permalink_structure), which drops the "sitemap" rewrite tag core
		// registers on init; register it again as core does on every request.
		wp_sitemaps_get_server()->register_rewrites();
		foreach ( array( '/?robots=1', '/?feed=rss2', '/?sitemap=index' ) as $path ) {
			Http::reset();
			$this->go_to( home_url( $path ) );
			$headers = Http::effective_headers();
			$this->assertArrayHasKey( 'content-signal', $headers, $path );
			$this->assertArrayNotHasKey( 'x-robots-tag', $headers, $path . ' must not carry noai.' );
		}

		Http::reset();
		$post = self::factory()->post->create();
		$this->go_to( get_permalink( $post ) );
		$this->assertSame( array( 'noai, noimageai' ), Http::effective_headers()['x-robots-tag'] );
	}

	public function test_hooks_are_wired() {
		$this->assertSame( 10, has_filter( 'robots_txt', array( $this->signals, 'filter_robots_txt' ) ) );
		$this->assertSame( 10, has_action( 'send_headers', array( $this->signals, 'send' ) ) );
		$this->assertSame( 10, has_action( 'wpasl_before_serve', array( $this->signals, 'send' ) ) );
		$this->assertSame( 10, has_filter( 'wp_robots', array( $this->signals, 'filter_wp_robots' ) ) );
	}

	public function test_meta_robots_gets_noai_and_keeps_existing_directives() {
		$robots = apply_filters( 'wp_robots', array( 'max-image-preview' => 'large' ) );
		$this->assertSame( 'large', $robots['max-image-preview'] );
		$this->assertTrue( $robots['noai'] );
		$this->assertTrue( $robots['noimageai'] );

		ob_start();
		wp_robots();
		$tag = ob_get_clean();
		$this->assertStringContainsString( 'max-image-preview:large', $tag );
		$this->assertStringContainsString( 'noai', $tag );
		$this->assertStringContainsString( 'noimageai', $tag );
	}

	public function test_meta_robots_untouched_when_training_allowed() {
		$this->set_signals( 'yes', 'yes', 'yes' );
		$robots = apply_filters( 'wp_robots', array() );
		$this->assertArrayNotHasKey( 'noai', $robots );
	}

	public function test_nothing_in_admin() {
		set_current_screen( 'dashboard' );
		$this->assertTrue( is_admin() );
		$robots = apply_filters( 'wp_robots', array() );
		$this->assertArrayNotHasKey( 'noai', $robots );
		set_current_screen( 'front' );
	}

	public function test_signals_tab_is_registered_and_renders() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$page = Plugin::instance()->get( 'page' );
		$tabs = $page->tabs();
		$this->assertArrayHasKey( 'signals', $tabs );

		ob_start();
		$tabs['signals']->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'wpasl_settings[signal_ai_train]', $html );
		$this->assertMatchesRegularExpression( '/name="wpasl_settings\[signal_ai_train\]" value="no"\s+checked/', $html );
		$this->assertStringContainsString( 'wpasl_settings[content_usage_header]', $html );
	}
}
