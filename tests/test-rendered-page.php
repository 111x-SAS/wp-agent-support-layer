<?php
/**
 * Rendered page loopback tests.
 *
 * @package WPASL
 */

use WPASL\Markdown\RenderedPage;

/**
 * Covers WPASL\Markdown\RenderedPage with a simulated HTTP layer (pre_http_request at priority 20).
 */
class Test_Rendered_Page extends WP_UnitTestCase {

	/**
	 * @var RenderedPage
	 */
	private $page;

	/**
	 * Requests seen by the fake HTTP layer: url and args.
	 *
	 * @var array<int, array{url:string, args:array}>
	 */
	private $requests = array();

	/**
	 * Simulated responses keyed by URL; each is array( code, headers, body ) or a WP_Error.
	 *
	 * @var array<string, mixed>
	 */
	private $responses = array();

	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->page      = new RenderedPage();
		$this->requests  = array();
		$this->responses = array();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 20, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 20 );
		remove_all_filters( 'wpasl_render_request_args' );
		remove_all_filters( 'post_link' );
		remove_all_actions( 'wpasl_run_started' );
		remove_all_actions( 'wpasl_run_finished' );
		parent::tear_down();
	}

	/**
	 * Fake transport: records the request and answers from the table, defaulting to a healthy HTML page.
	 */
	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		$spec             = isset( $this->responses[ $url ] ) ? $this->responses[ $url ] : array( 200, array( 'content-type' => 'text/html; charset=utf-8' ), '<html><body><main><p>Rendered body</p></main></body></html>' );
		if ( is_wp_error( $spec ) ) {
			return $spec;
		}
		return array(
			'response' => array(
				'code'    => $spec[0],
				'message' => 'x',
			),
			'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( $spec[1] ),
			'body'     => $spec[2],
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Creates a post and returns it with its render URL.
	 *
	 * @param string $slug Slug.
	 * @return array{0:WP_Post, 1:string}
	 */
	private function post_with_url( $slug = 'muestra' ) {
		$post = self::factory()->post->create_and_get( array( 'post_name' => $slug ) );
		return array( $post, home_url( '/' . $slug . '/?wpasl_render=1' ) );
	}

	public function test_request_carries_marker_accept_timeout_and_size() {
		list( $post, $url ) = $this->post_with_url();
		$this->assertSame( $url, RenderedPage::url( $post ) );

		$result = $this->page->fetch( $post );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( '', $result['error'] );
		$this->assertStringContainsString( 'Rendered body', $result['body'] );
		$this->assertCount( 1, $this->requests, 'Exactly one request.' );
		$request = $this->requests[0];
		$this->assertSame( $url, $request['url'] );
		$this->assertSame( 'example.org', wp_parse_url( $request['url'], PHP_URL_HOST ) );
		$this->assertSame( 'GET', $request['args']['method'] );
		$this->assertSame( 'text/html', $request['args']['headers']['Accept'] );
		$this->assertSame( '1', $request['args']['headers']['X-WPASL-Render'] );
		$this->assertSame( 10, $request['args']['timeout'] );
		$this->assertSame( 0, $request['args']['redirection'] );
		$this->assertSame( 2 * MB_IN_BYTES, $request['args']['limit_response_size'] );
		$this->assertTrue( $request['args']['reject_unsafe_urls'] );
		$this->assertSame( 'WP-Agent-Support-Layer-Render/' . WPASL_VERSION, $request['args']['user-agent'] );
		$this->assertFalse( $request['args']['sslverify'] );
	}

	public function test_args_filter_changes_timeout() {
		list( $post ) = $this->post_with_url();
		$received     = null;
		add_filter(
			'wpasl_render_request_args',
			static function ( $args, $filtered ) use ( &$received ) {
				$received        = $filtered->ID;
				$args['timeout'] = 3;
				return $args;
			},
			10,
			2
		);
		$this->assertTrue( $this->page->fetch( $post )['ok'] );
		$this->assertSame( $post->ID, $received );
		$this->assertSame( 3, $this->requests[0]['args']['timeout'] );
	}

	public function test_external_host_is_never_requested() {
		list( $post ) = $this->post_with_url();
		add_filter(
			'post_link',
			static function () {
				return 'https://other.example/muestra/';
			}
		);
		$this->assertSame( 'https://other.example/muestra/?wpasl_render=1', RenderedPage::url( $post ) );
		$result = $this->page->fetch( $post );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'external_host', $result['error'] );
		$this->assertSame( array(), $this->requests );
	}

	public function test_same_host_redirect_is_followed_at_most_twice() {
		list( $post, $url )         = $this->post_with_url();
		$second                     = home_url( '/muestra-2/?wpasl_render=1' );
		$this->responses[ $url ]    = array( 301, array( 'location' => $second ), '' );
		$this->responses[ $second ] = array( 200, array( 'content-type' => 'text/html' ), '<html><body><main>Second</main></body></html>' );

		$result = $this->page->fetch( $post );
		$this->assertTrue( $result['ok'] );
		$this->assertStringContainsString( 'Second', $result['body'] );
		$this->assertSame( array( $url, $second ), array_column( $this->requests, 'url' ) );
		$this->assertSame( 0, $this->requests[1]['args']['redirection'], 'Never automatic.' );

		// A relative Location resolves against the requested URL; two hops are fine.
		$this->requests                           = array();
		$this->responses                          = array();
		$this->responses[ $url ]                  = array( 302, array( 'location' => '/hop-1/' ), '' );
		$this->responses[ home_url( '/hop-1/' ) ] = array( 307, array( 'location' => home_url( '/hop-2/' ) ), '' );
		$result                                   = $this->page->fetch( $post );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( $url, home_url( '/hop-1/' ), home_url( '/hop-2/' ) ), array_column( $this->requests, 'url' ) );

		// Three chained redirects: the loopback fails without a fourth request.
		$this->requests                           = array();
		$this->responses[ home_url( '/hop-2/' ) ] = array( 301, array( 'location' => home_url( '/hop-3/' ) ), '' );
		$result                                   = $this->page->fetch( $post );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'redirect_loop', $result['error'] );
		$this->assertCount( 3, $this->requests );
		$this->assertNotContains( home_url( '/hop-3/' ), array_column( $this->requests, 'url' ) );
	}

	public function test_external_redirect_fails_without_contacting_host() {
		list( $post, $url )      = $this->post_with_url();
		$this->responses[ $url ] = array( 302, array( 'location' => 'https://www.example.org/muestra/?wpasl_render=1' ), '' );

		$result = $this->page->fetch( $post );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'redirect_external_host', $result['error'] );
		$this->assertSame( 302, $result['status'] );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( $url, $this->requests[0]['url'] );

		// A scheme change on the same host is allowed.
		$this->requests          = array();
		$this->responses[ $url ] = array( 301, array( 'location' => 'https://example.org/muestra/?wpasl_render=1' ), '' );
		$result                  = $this->page->fetch( $post );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'https://example.org/muestra/?wpasl_render=1', $this->requests[1]['url'] );
	}

	public function test_http_errors_and_wp_error_fail_with_reason() {
		list( $post, $url ) = $this->post_with_url();
		foreach ( array( 403, 404, 500, 503 ) as $code ) {
			$this->responses[ $url ] = array( $code, array( 'content-type' => 'text/html' ), 'blocked' );
			$result                  = $this->page->fetch( $post );
			$this->assertFalse( $result['ok'], (string) $code );
			$this->assertSame( 'http_' . $code, $result['error'] );
			$this->assertSame( $code, $result['status'] );
			$this->assertSame( '', $result['body'] );
		}

		$this->responses[ $url ] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$result                  = $this->page->fetch( $post );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'request_error:http_request_failed', $result['error'] );
		$this->assertSame( 0, $result['status'] );

		$this->responses[ $url ] = array( 301, array(), '' );
		$result                  = $this->page->fetch( $post );
		$this->assertSame( 'http_301', $result['error'], 'A redirect without Location is an HTTP failure.' );

		// The bootstrap safety net (a WP_Error at priority 1) is what a site without loopback looks like.
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 20 );
		$result = $this->page->fetch( $post );
		$this->assertSame( 'request_error:wpasl_tests_http_blocked', $result['error'] );
	}

	public function test_non_html_content_type_fails() {
		list( $post, $url ) = $this->post_with_url();
		foreach ( array( 'text/markdown; charset=utf-8', 'application/json', '' ) as $type ) {
			$this->responses[ $url ] = array( 200, '' === $type ? array() : array( 'content-type' => $type ), '# Markdown' );
			$result                  = $this->page->fetch( $post );
			$this->assertFalse( $result['ok'], $type );
			$this->assertSame( 'not_html', $result['error'], $type );
			$this->assertSame( 200, $result['status'] );
		}

		$this->responses[ $url ] = array( 200, array( 'content-type' => 'text/html' ), "  \n" );
		$result                  = $this->page->fetch( $post );
		$this->assertSame( 'empty_body', $result['error'] );

		$this->responses[ $url ] = array( 200, array( 'content-type' => 'TEXT/HTML;charset=ISO-8859-1' ), '<html><body>ok</body></html>' );
		$this->assertTrue( $this->page->fetch( $post )['ok'], 'Case-insensitive.' );
	}

	public function test_deadline_caps_timeout_and_defers_below_minimum() {
		list( $post ) = $this->post_with_url();
		$this->page->register();
		$this->assertSame( 10, has_action( 'wpasl_run_started', array( $this->page, 'on_run_started' ) ) );

		do_action( 'wpasl_run_started', microtime( true ) + 5 );
		$this->assertTrue( $this->page->fetch( $post )['ok'] );
		$timeout = $this->requests[0]['args']['timeout'];
		$this->assertGreaterThan( 4.5, $timeout );
		$this->assertLessThanOrEqual( 5, $timeout, 'Capped by the remaining time of the run.' );

		// A run with time to spare keeps the full timeout.
		$this->requests = array();
		do_action( 'wpasl_run_started', microtime( true ) + 60 );
		$this->assertTrue( $this->page->fetch( $post )['ok'] );
		$this->assertSame( 10.0, (float) $this->requests[0]['args']['timeout'] );

		// Below the minimum: deferred without any request.
		$this->requests = array();
		do_action( 'wpasl_run_started', microtime( true ) + 1 );
		$result = $this->page->fetch( $post );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'deferred', $result['error'] );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( 2, RenderedPage::MIN_TIMEOUT );

		// Outside a run the configured timeout applies again.
		do_action( 'wpasl_run_finished' );
		$this->assertTrue( $this->page->fetch( $post )['ok'] );
		$this->assertSame( 10, $this->requests[0]['args']['timeout'] );
	}
}
