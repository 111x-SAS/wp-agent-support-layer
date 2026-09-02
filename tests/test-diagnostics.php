<?php
/**
 * Diagnostics tests.
 *
 * @package WPASL
 */

use WPASL\Diagnostics\CrawlerProbe;
use WPASL\Diagnostics\DiagnosticsController;
use WPASL\Diagnostics\Report;
use WPASL\Plugin;
use WPASL\Settings;

/**
 * Covers WPASL\Diagnostics\*.
 */
class Test_Diagnostics extends WP_UnitTestCase {

	/**
	 * Simulated responses keyed by "URL|Accept" (Accept optional).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $responses = array();

	/**
	 * Requests seen by the fake HTTP layer.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $requests = array();

	/**
	 * @var CrawlerProbe
	 */
	private $probe;

	/**
	 * @var DiagnosticsController
	 */
	private $controller;

	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->probe      = Plugin::instance()->get( 'probe' );
		$this->controller = Plugin::instance()->get( 'diagnostics' );
		$this->responses  = array();
		$this->requests   = array();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );
		add_filter( 'wpasl_diagnostics_crawlers', array( $this, 'two_crawlers' ) );
		delete_transient( Report::TRANSIENT );
		Plugin::instance()->get( 'runner' )->clear();
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );
		remove_filter( 'wpasl_diagnostics_crawlers', array( $this, 'two_crawlers' ) );
		unset( $_REQUEST[ DiagnosticsController::NONCE ] );
		delete_transient( Report::TRANSIENT );
		delete_option( Settings::OPTION );
		Plugin::instance()->get( 'settings' )->flush_cache();
		Plugin::instance()->get( 'runner' )->clear();
		parent::tear_down();
	}

	public function two_crawlers( $crawlers ) {
		return array_intersect_key( $crawlers, array_flip( array( 'GPTBot', 'PerplexityBot' ) ) );
	}

	public function capture_redirect( $location ) {
		throw new Exception( 'redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Fake transport: returns the configured response for the URL, defaulting to a healthy HTML page.
	 */
	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'    => $url,
			'ua'     => $args['user-agent'],
			'accept' => $args['headers']['Accept'],
		);
		$accept           = $args['headers']['Accept'];
		$key              = $url . '|' . ( 0 === strpos( $accept, 'text/markdown' ) ? 'md' : 'html' );
		$spec             = isset( $this->responses[ $key ] ) ? $this->responses[ $key ] : ( isset( $this->responses[ $url ] ) ? $this->responses[ $url ] : $this->default_response( $url, $accept ) );
		return array(
			'response' => array(
				'code'    => $spec['code'],
				'message' => 'x',
			),
			'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( $spec['headers'] ),
			'body'     => isset( $spec['body'] ) ? $spec['body'] : '',
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function default_response( $url, $accept ) {
		$headers = array( 'content-signal' => 'search=yes, ai-input=yes, ai-train=no' );
		$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '/robots.txt' === $path ) {
			$headers['content-type'] = 'text/plain; charset=utf-8';
		} elseif ( '/agent-skills.json' === $path ) {
			$headers['content-type'] = 'application/ld+json; charset=utf-8';
		} elseif ( '/.well-known/api-catalog' === $path ) {
			$headers['content-type'] = 'application/linkset+json; charset=utf-8';
		} elseif ( '/llms.txt' === $path || '.md' === substr( $path, -3 ) || 0 === strpos( $accept, 'text/markdown' ) ) {
			$headers['content-type'] = 'text/markdown; charset=utf-8';
			$headers['link']         = '<' . home_url( '/' ) . '>; rel="canonical"';
		} elseif ( false !== strpos( $path, '/wp-content/uploads/' ) ) {
			return array(
				'code'    => 403,
				'headers' => array(),
			);
		} else {
			$headers['content-type'] = 'text/html; charset=utf-8';
			$headers['x-robots-tag'] = 'noai, noimageai';
		}
		return array(
			'code'    => 200,
			'headers' => $headers,
			'body'    => '<html><head><link rel="alternate" type="text/markdown" href="x.md"></head></html>',
		);
	}

	public function test_probe_only_contacts_its_own_host() {
		$result = $this->probe->fetch( 'https://evil.example/x', 'ua', '*/*' );
		$this->assertSame( 'external host refused', $result['error'] );
		$this->assertSame( array(), $this->requests );
	}

	public function test_probe_uses_crawler_user_agents_and_accept_headers() {
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$raw = $this->probe->run();

		$this->assertArrayHasKey( 'GPTBot', $raw['crawlers'] );
		$this->assertArrayHasKey( 'PerplexityBot', $raw['crawlers'] );
		$this->assertArrayHasKey( 'post_markdown', $raw['crawlers']['GPTBot'] );
		$this->assertSame( array( 'robots', 'llms', 'skills', 'catalog', 'markdown_url' ), array_keys( $raw['site'] ) );

		$agents = array_unique( array_column( $this->requests, 'ua' ) );
		$this->assertCount( 3, $agents, 'Two crawlers plus the diagnostics agent.' );
		$this->assertNotEmpty( array_filter( $this->requests, static function ( $r ) { return false !== strpos( $r['ua'], 'GPTBot' ) && 0 === strpos( $r['accept'], 'text/markdown' ); } ) ); // phpcs:ignore
		foreach ( $this->requests as $request ) {
			$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( $request['url'], PHP_URL_HOST ) );
		}
		$this->assertSame( 10, CrawlerProbe::TIMEOUT );
	}

	public function test_report_blocked_crawler_is_coherent_and_healthy_site_is_ok() {
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$report = $this->controller->run();

		$gpt = $report['crawlers']['GPTBot'];
		$this->assertSame( 'block', $gpt['policy'] );
		$this->assertSame( Report::OK, $gpt['checks']['robots']['status'] );
		$this->assertStringContainsString( 'Blocked by robots.txt', $gpt['checks']['robots']['message'] );
		$this->assertSame( Report::OK, $gpt['checks']['negotiation']['status'] );
		$this->assertSame( Report::OK, $gpt['checks']['content_signal']['status'] );
		$this->assertSame( Report::OK, $gpt['checks']['x_robots_tag']['status'] );
		$this->assertSame( Report::OK, $gpt['checks']['alternate_link']['status'] );

		$this->assertSame( 'allow', $report['crawlers']['PerplexityBot']['policy'] );
		foreach ( $report['site'] as $check ) {
			$this->assertSame( Report::OK, $check['status'], $check['message'] );
		}
		$this->assertSame( '', $report['infrastructure']['cdn'] );
		$this->assertFalse( $report['infrastructure']['storage_exposed'] );

		$this->assertSame( $report, Report::load(), 'Stored in the transient.' );
	}

	public function test_report_flags_html_returned_for_markdown_request() {
		$post = self::factory()->post->create_and_get( array( 'post_name' => 'muestra' ) );
		$this->responses[ get_permalink( $post ) . '|md' ] = array(
			'code'    => 200,
			'headers' => array( 'content-type' => 'text/html; charset=utf-8' ),
		);
		$report = $this->controller->run();
		$check  = $report['crawlers']['GPTBot']['checks']['negotiation'];
		$this->assertSame( Report::ERROR, $check['status'] );
		$this->assertStringContainsString( 'cache or CDN', $check['message'] );
	}

	public function test_report_flags_waf_block_and_missing_headers() {
		$post                               = self::factory()->post->create_and_get( array( 'post_name' => 'muestra' ) );
		$this->responses[ home_url( '/' ) ] = array(
			'code'    => 403,
			'headers' => array(),
		);
		$this->responses[ get_permalink( $post ) . '|html' ] = array(
			'code'    => 200,
			'headers' => array( 'content-type' => 'text/html' ),
			'body'    => '<html></html>',
		);
		$report = $this->controller->run();
		$checks = $report['crawlers']['GPTBot']['checks'];
		$this->assertSame( Report::ERROR, $checks['home']['status'] );
		$this->assertStringContainsString( 'WAF', $checks['home']['message'] );
		$this->assertSame( Report::WARNING, $checks['content_signal']['status'] );
		$this->assertSame( Report::WARNING, $checks['x_robots_tag']['status'] );
		$this->assertSame( Report::WARNING, $checks['alternate_link']['status'] );
	}

	public function test_report_detects_cloudflare_and_edge_markdown() {
		$post                               = self::factory()->post->create_and_get( array( 'post_name' => 'muestra' ) );
		$this->responses[ home_url( '/' ) ] = array(
			'code'    => 200,
			'headers' => array(
				'content-type' => 'text/html',
				'cf-ray'       => 'abc-MAD',
				'server'       => 'cloudflare',
			),
		);
		$this->responses[ get_permalink( $post ) . '|md' ] = array(
			'code'    => 200,
			'headers' => array(
				'content-type'      => 'text/markdown; charset=utf-8',
				'cf-ray'            => 'abc-MAD',
				'x-markdown-tokens' => '12',
			),
		);
		$report = $this->controller->run();
		$this->assertSame( 'Cloudflare', $report['infrastructure']['cdn'] );
		$this->assertSame( 'cloudflare', $report['infrastructure']['server'] );
		$this->assertTrue( $report['infrastructure']['edge_markdown'] );
		$this->assertSame( Report::WARNING, $report['crawlers']['GPTBot']['checks']['negotiation']['status'] );
		$this->assertStringContainsString( 'Markdown for Agents', $report['crawlers']['GPTBot']['checks']['negotiation']['message'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$tabs = Plugin::instance()->get( 'page' )->tabs();
		ob_start();
		$tabs['diagnostics']->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Cloudflare detected', $html );
		$this->assertStringContainsString( 'Infrastructure checklist', $html );
	}

	public function test_report_flags_exposed_storage() {
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		Plugin::instance()->get( 'runner' )->run_cycle();
		$direct = $this->probe->storage_direct_url();
		$this->assertNotNull( $direct );
		$this->assertStringContainsString( '/wp-content/uploads/wp-agent-support-layer/', $direct );

		$this->responses[ $direct ] = array(
			'code'    => 200,
			'headers' => array( 'content-type' => 'text/markdown' ),
		);
		$report                     = $this->controller->run();
		$this->assertTrue( $report['infrastructure']['storage_exposed'] );
		$this->assertSame( Report::ERROR, $report['site']['storage']['status'] );
		$this->assertStringContainsStringIgnoringCase( 'nginx', $report['site']['storage']['message'] );
	}

	public function test_handler_requires_capability_and_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		try {
			$this->controller->handle();
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( array(), $this->requests );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		try {
			$this->controller->handle();
			$this->fail( 'Expected wp_die for missing nonce.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( array(), $this->requests );
		}
	}

	public function test_handler_runs_and_redirects() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$_REQUEST[ DiagnosticsController::NONCE ] = wp_create_nonce( DiagnosticsController::ACTION );
		try {
			$this->controller->handle();
			$this->fail( 'Expected a redirect.' );
		} catch ( Exception $e ) {
			$this->assertStringContainsString( 'wpasl_notice=diagnostics', $e->getMessage() );
		}
		$this->assertNotEmpty( $this->requests );
		$this->assertNotNull( Report::load() );
	}

	public function test_tab_renders_checklist_and_curl_commands() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$tabs = Plugin::instance()->get( 'page' )->tabs();
		$this->assertArrayHasKey( 'diagnostics', $tabs );
		$this->assertFalse( $tabs['diagnostics']->has_form() );
		ob_start();
		$tabs['diagnostics']->render();
		$html = html_entity_decode( ob_get_clean(), ENT_QUOTES, 'UTF-8' );
		$this->assertStringContainsString( 'Run crawler simulation', $html );
		$this->assertStringContainsString( 'name="' . DiagnosticsController::NONCE . '"', $html );
		$this->assertStringContainsString( 'rate limiting', $html );
		$this->assertStringContainsString( "curl -sI -A 'Mozilla/5.0 (compatible; GPTBot/1.0)' -H 'Accept: text/markdown' '" . home_url( '/muestra/' ) . "'", $html );
		$this->assertStringContainsString( "curl -s '" . home_url( '/llms.txt' ) . "'", $html );
	}
}
