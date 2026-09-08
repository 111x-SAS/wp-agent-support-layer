<?php
/**
 * Markdown 404 tests.
 *
 * @package WPASL
 */

use WPASL\Http;
use WPASL\Markdown\Delivery;
use WPASL\Plugin;
use WPASL\Settings;

/**
 * Covers the Markdown 404 response of WPASL\Markdown\Delivery (maybe_serve_404 and not_found_document).
 */
class Test_Markdown_404 extends WP_UnitTestCase {

	/**
	 * @var Delivery
	 */
	private $delivery;

	/**
	 * HTTP status codes announced through status_header().
	 *
	 * @var int[]
	 */
	private $codes = array();

	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->delivery = Plugin::instance()->get( 'delivery' );
		$this->codes    = array();
		Plugin::instance()->get( 'runner' )->clear();
		add_filter( 'wpasl_terminate_after_serve', '__return_false' );
		add_filter( 'status_header', array( $this, 'record_status' ), 10, 2 );
		unset( $_SERVER['HTTP_ACCEPT'] );
		Http::reset();
	}

	public function tear_down() {
		remove_filter( 'wpasl_terminate_after_serve', '__return_false' );
		remove_filter( 'status_header', array( $this, 'record_status' ), 10 );
		remove_filter( 'redirect_canonical', '__return_false' );
		remove_filter( 'wp_sitemaps_enabled', '__return_false' );
		remove_all_filters( 'wpasl_markdown_404' );
		remove_all_filters( 'wpasl_sitemap_url' );
		unset( $_SERVER['HTTP_ACCEPT'] );
		Plugin::instance()->get( 'runner' )->clear();
		delete_option( Settings::OPTION );
		Plugin::instance()->get( 'settings' )->flush_cache();
		parent::tear_down();
	}

	public function record_status( $header, $code ) {
		$this->codes[] = (int) $code;
		return $header;
	}

	private function settings( array $values ) {
		update_option( Settings::OPTION, $values );
		Plugin::instance()->get( 'settings' )->flush_cache();
	}

	/**
	 * Requests a URL and runs the 404 hook by hand (go_to() does not fire template_redirect).
	 *
	 * @param string $url URL.
	 * @return array{0:bool,1:string} Whether the document was served, and the output.
	 */
	private function request( $url ) {
		Http::reset();
		$this->codes = array();
		ob_start();
		$this->go_to( $url );
		$served = $this->delivery->maybe_serve_404();
		return array( $served, ob_get_clean() );
	}

	private function assert_markdown_404( $served, $out, $label ) {
		$this->assertTrue( $served, $label );
		$this->assertTrue( is_404(), $label );
		$this->assertContains( 404, $this->codes, $label . ': status 404 announced.' );
		$this->assertStringStartsWith( "# Not found\n\n", $out, $label );
		$this->assertStringContainsString( '](' . home_url( '/llms.txt' ) . ')', $out, $label );
		$this->assertStringContainsString( '](' . home_url( '/.well-known/api-catalog' ) . ')', $out, $label );
		$headers = Http::effective_headers();
		$this->assertSame( array( 'text/markdown; charset=utf-8' ), $headers['content-type'], $label );
		$this->assertSame( array( 'no-store' ), $headers['cache-control'], $label );
		$this->assertSame( array( 'nosniff' ), $headers['x-content-type-options'], $label );
		$this->assertContains( 'Accept', $headers['vary'], $label );
		$this->assertSame( array( (string) (int) ceil( strlen( $out ) / 4 ) ), $headers['x-markdown-tokens'], $label );
	}

	public function test_md_suffix_for_unknown_content_serves_markdown_404() {
		list( $served, $out ) = $this->request( home_url( '/no-existe.md' ) );
		$this->assert_markdown_404( $served, $out, '.md suffix' );
		$this->assertSame( 11, has_action( 'template_redirect', array( $this->delivery, 'maybe_serve_404' ) ), 'After redirect_canonical (10).' );
		$this->assertStringNotContainsString( 'canonical', wp_json_encode( Http::effective_headers() ), 'No canonical Link: there is no resource.' );
	}

	public function test_md_suffix_for_draft_serves_markdown_404() {
		self::factory()->post->create( array( 'post_name' => 'borrador', 'post_status' => 'draft', 'post_title' => 'Borrador secreto' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		list( $served, $out ) = $this->request( home_url( '/borrador.md' ) );
		$this->assert_markdown_404( $served, $out, 'draft' );
		$this->assertStringNotContainsString( 'Borrador secreto', $out );
		list( , $unknown ) = $this->request( home_url( '/no-existe.md' ) );
		$this->assertSame( $unknown, $out, 'Same body for a draft and for unknown content.' );
	}

	public function test_accept_markdown_on_unknown_url_serves_markdown_404() {
		$_SERVER['HTTP_ACCEPT'] = 'text/markdown';
		list( $served, $out )   = $this->request( home_url( '/no-existe/' ) );
		$this->assert_markdown_404( $served, $out, 'Accept: text/markdown' );
		$this->assertSame( array( 'Accept' ), Http::effective_headers()['vary'] );

		$_SERVER['HTTP_ACCEPT'] = 'text/markdown, text/html;q=0.9';
		list( $served )         = $this->request( home_url( '/tampoco/' ) );
		$this->assertTrue( $served, 'Markdown preferred over HTML.' );

		list( $served, $out ) = $this->request( home_url( '/no-existe/?wpasl=md' ) );
		$this->assert_markdown_404( $served, $out, '?wpasl=md' );
	}

	public function test_browser_accept_keeps_html_404() {
		$_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
		list( $served, $out )   = $this->request( home_url( '/no-existe/' ) );
		$this->assertFalse( $served );
		$this->assertSame( '', $out, 'The theme renders its own 404 page.' );
		$this->assertTrue( is_404() );
		$this->assertArrayNotHasKey( 'content-type', Http::effective_headers() );

		list( $served, $out ) = $this->request( home_url( '/no-existe.md' ) );
		$this->assert_markdown_404( $served, $out, 'The .md suffix wins over a browser Accept.' );

		unset( $_SERVER['HTTP_ACCEPT'] );
		list( $served, $out ) = $this->request( home_url( '/no-existe/' ) );
		$this->assertFalse( $served, 'No Accept header: HTML 404.' );
		$this->assertSame( '', $out );

		$post                   = self::factory()->post->create_and_get( array( 'post_name' => 'existe' ) );
		$_SERVER['HTTP_ACCEPT'] = 'text/markdown';
		list( $served, $out )   = $this->request( get_permalink( $post ) );
		$this->assertFalse( $served, 'Not a 404: nothing to do.' );
		$this->assertSame( '', $out );
	}

	public function test_links_follow_configuration() {
		list( , $out ) = $this->request( home_url( '/no-existe.md' ) );
		$this->assertStringContainsString( '- [Agent access documentation (auth.md)](' . home_url( '/auth.md' ) . '): ', $out );
		$this->assertStringContainsString( '- [Sitemap](' . home_url( '/wp-sitemap.xml' ) . '): ', $out, 'Core sitemaps are enabled by default.' );
		$this->assertStringContainsString( '- [API catalog](' . home_url( '/.well-known/api-catalog' ) . '): ', $out );
		$this->assertContains( '<' . home_url( '/.well-known/api-catalog' ) . '>; rel="api-catalog"', Http::effective_headers()['link'] );

		$this->settings( array( 'auth_md_enabled' => false ) );
		list( , $out ) = $this->request( home_url( '/no-existe.md' ) );
		$this->assertStringNotContainsString( '/auth.md', $out );
		$this->assertStringContainsString( '- [API catalog]', $out );

		add_filter( 'wp_sitemaps_enabled', '__return_false' );
		list( , $out ) = $this->request( home_url( '/no-existe.md' ) );
		$this->assertStringNotContainsString( '[Sitemap]', $out );
		add_filter( 'wpasl_sitemap_url', static function () { return home_url( '/sitemap_index.xml' ); } ); // phpcs:ignore
		list( , $out ) = $this->request( home_url( '/no-existe.md' ) );
		$this->assertStringContainsString( '- [Sitemap](' . home_url( '/sitemap_index.xml' ) . '): ', $out, 'An SEO plugin sitemap determined locally.' );

		$this->settings( array( 'manifest_enabled' => false ) );
		list( $served, $out ) = $this->request( home_url( '/no-existe.md' ) );
		$this->assertTrue( $served );
		$this->assertStringNotContainsString( 'api-catalog', $out );
		$this->assertStringNotContainsString( '/auth.md', $out, 'auth.md depends on the manifests.' );
		$this->assertStringContainsString( '](' . home_url( '/llms.txt' ) . ')', $out );
		$this->assertArrayNotHasKey( 'link', Http::effective_headers(), 'No api-catalog Link when the manifests are disabled.' );
	}

	public function test_body_never_reflects_the_request() {
		$payload = '<script>alert(1)</script>';
		foreach ( array( home_url( '/' . rawurlencode( $payload ) . '.md' ), home_url( '/' . $payload . '.md' ), home_url( '/x/?wpasl=md&q=' . rawurlencode( $payload ) ) ) as $url ) {
			list( $served, $out ) = $this->request( $url );
			$this->assertTrue( $served, $url );
			$this->assertStringNotContainsString( 'alert(1)', $out, $url );
			$this->assertStringNotContainsString( '<script', $out, $url );
			$this->assertStringNotContainsString( 'no-existe', $out );
		}
		$_SERVER['HTTP_ACCEPT'] = 'text/markdown, ' . $payload;
		list( , $out )          = $this->request( home_url( '/no-existe/' ) );
		$this->assertStringNotContainsString( 'alert(1)', $out );
		$this->assertSame( $this->delivery->not_found_document(), $out, 'The body only depends on the site configuration.' );
	}

	public function test_headers_and_single_api_catalog_link() {
		$this->assertSame( 'no', Plugin::instance()->get( 'settings' )->get( 'signal_ai_train' ) );
		$catalog = '<' . home_url( '/.well-known/api-catalog' ) . '>; rel="api-catalog"';

		// Accept route: send_headers already emitted the HTML set (X-Robots-Tag and the catalog Link).
		$_SERVER['HTTP_ACCEPT'] = 'text/markdown';
		Http::reset();
		$this->go_to( home_url( '/no-existe/' ) );
		$this->assertArrayHasKey( 'x-robots-tag', Http::effective_headers(), 'HTML pass first.' );
		$this->assertContains( $catalog, Http::effective_headers()['link'] );
		ob_start();
		$this->assertTrue( $this->delivery->maybe_serve_404() );
		ob_get_clean();
		$headers = Http::effective_headers();
		$this->assertSame( array( 'search=yes, ai-input=yes, ai-train=no' ), $headers['content-signal'] );
		$this->assertArrayNotHasKey( 'x-robots-tag', $headers );
		$this->assertSame( array( $catalog ), $headers['link'], 'Exactly one api-catalog Link.' );
		$this->assertSame( array( 'text/markdown; charset=utf-8' ), $headers['content-type'] );

		// .md route: no HTML pass before the 404.
		unset( $_SERVER['HTTP_ACCEPT'] );
		list( $served ) = $this->request( home_url( '/no-existe.md' ) );
		$this->assertTrue( $served );
		$headers = Http::effective_headers();
		$this->assertSame( array( $catalog ), $headers['link'] );
		$this->assertArrayHasKey( 'content-signal', $headers );
		$this->assertArrayNotHasKey( 'x-robots-tag', $headers );
		$this->assertArrayNotHasKey( 'content-usage', array_diff_key( $headers, array( 'content-usage' => 1 ) ) );
		$this->assertSame( array( 'train-ai=n, search=y' ), $headers['content-usage'] );
	}

	public function test_body_filter() {
		add_filter(
			'wpasl_markdown_404',
			static function ( $markdown ) {
				return $markdown . "- [Contact](mailto:agents@example.org): ask a human.\n";
			}
		);
		list( $served, $out ) = $this->request( home_url( '/no-existe.md' ) );
		$this->assertTrue( $served );
		$this->assertStringEndsWith( "- [Contact](mailto:agents@example.org): ask a human.\n", $out );
		$this->assertStringStartsWith( "# Not found\n", $out );
	}
}
