<?php
/**
 * Diagnostics tests.
 *
 * @package WPASL
 */

use WPASL\Admin\Tabs\DiagnosticsTab;
use WPASL\Diagnostics\CrawlerProbe;
use WPASL\Diagnostics\DiagnosticsController;
use WPASL\Diagnostics\HtaccessHeaders;
use WPASL\Diagnostics\PageCache;
use WPASL\Diagnostics\Report;
use WPASL\Http;
use WPASL\Plugin;
use WPASL\Settings;
use WPASL\Storage;

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
	 * Microseconds each fake request sleeps (simulates slow responses).
	 *
	 * @var int
	 */
	private $delay_us = 0;

	/**
	 * @var CrawlerProbe
	 */
	private $probe;

	/**
	 * @var DiagnosticsController
	 */
	private $controller;

	/**
	 * @var HtaccessHeaders
	 */
	private $htaccess;

	/**
	 * Path of the temporary .htaccess file used by the htaccess tests, substituted through wpasl_htaccess_file.
	 *
	 * @var string
	 */
	private $htaccess_file;

	/**
	 * Path used by fake_readonly_htaccess_file() in test_htaccess_availability().
	 *
	 * @var string
	 */
	private $readonly_htaccess_file = '';

	/**
	 * Response queued for the .htaccess verification request (matched by the "wpasl_verify" query arg), or
	 * null to let the regular fake_http() default response through.
	 *
	 * @var array<string, mixed>|\WP_Error|null
	 */
	private $verify_response = null;

	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->probe           = Plugin::instance()->get( 'probe' );
		$this->controller      = Plugin::instance()->get( 'diagnostics' );
		$this->htaccess        = Plugin::instance()->get( 'htaccess_headers' );
		$this->responses       = array();
		$this->requests        = array();
		$this->delay_us        = 0;
		$this->htaccess_file   = trailingslashit( get_temp_dir() ) . 'wpasl-test-htaccess-' . wp_generate_password( 8, false ) . '.htaccess';
		$this->verify_response = null;
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		add_filter( 'pre_http_request', array( $this, 'fake_htaccess_verify_response' ), 20, 3 );
		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );
		add_filter( 'wpasl_diagnostics_crawlers', array( $this, 'two_crawlers' ) );
		add_filter( 'wpasl_htaccess_file', array( $this, 'fake_htaccess_file' ) );
		delete_transient( Report::TRANSIENT );
		Plugin::instance()->get( 'runner' )->clear();
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'pre_http_request', array( $this, 'fake_htaccess_verify_response' ), 20 );
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );
		remove_filter( 'wpasl_diagnostics_crawlers', array( $this, 'two_crawlers' ) );
		remove_filter( 'wpasl_htaccess_file', array( $this, 'fake_htaccess_file' ) );
		remove_all_filters( 'wpasl_htaccess_environment' );
		unset( $_REQUEST[ DiagnosticsController::NONCE ], $_REQUEST[ HtaccessHeaders::NONCE ], $_SERVER['SERVER_SOFTWARE'], $_SERVER['LSWS_EDITION'] );
		delete_transient( Report::TRANSIENT );
		delete_transient( DiagnosticsController::run_key() );
		delete_transient( HtaccessHeaders::RESULT_TRANSIENT . get_current_user_id() );
		remove_all_filters( 'wpasl_diagnostics_time_budget' );
		remove_all_filters( 'wpasl_diagnostics_page_cache' );
		delete_option( Settings::OPTION );
		delete_option( HtaccessHeaders::BACKUP_OPTION );
		Plugin::instance()->get( 'storage' )->delete( HtaccessHeaders::BACKUP_FILE );
		Plugin::instance()->get( 'settings' )->flush_cache();
		Plugin::instance()->get( 'runner' )->clear();
		if ( file_exists( $this->htaccess_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			unlink( $this->htaccess_file );
		}
		parent::tear_down();
	}

	/**
	 * Points the .htaccess auto-apply feature at a temporary file instead of the real .htaccess.
	 */
	public function fake_htaccess_file() {
		return $this->htaccess_file;
	}

	/**
	 * Simulates a compatible Apache environment through the detection filter.
	 */
	public function fake_htaccess_environment() {
		return array(
			'compatible'  => true,
			'server'      => 'Apache 2.4.58',
			'version'     => '2.4.58',
			'mod_headers' => null,
			'reason'      => '',
		);
	}

	/**
	 * Simulates an incompatible environment through the detection filter.
	 */
	public function fake_incompatible_environment() {
		return array(
			'compatible'  => false,
			'server'      => 'nginx',
			'version'     => '',
			'mod_headers' => null,
			'reason'      => 'server not recognized as Apache or LiteSpeed Enterprise',
		);
	}

	public function two_crawlers( $crawlers ) {
		return array_intersect_key( $crawlers, array_flip( array( 'GPTBot', 'PerplexityBot' ) ) );
	}

	/**
	 * Simulates an active Cache Enabler through the detection filter (the plugin is not installed in the
	 * test environment and a constant cannot be undefined between tests).
	 */
	public function fake_cache_enabler() {
		return array(
			'id'      => 'cache-enabler',
			'name'    => 'Cache Enabler',
			'version' => '1.8.16',
		);
	}

	/**
	 * Registers responses served "from the Cache Enabler page cache": HTML without the plugin headers, also
	 * for the negotiated Markdown request, with or without the X-Cache-Handler signature.
	 */
	private function serve_cached_html( $post, $with_handler = true ) {
		$headers = array( 'content-type' => 'text/html; charset=utf-8' );
		if ( $with_handler ) {
			$headers['x-cache-handler'] = 'cache-enabler-engine';
		}
		$cached = array(
			'code'    => 200,
			'headers' => $headers,
			'body'    => '<html><head><link rel="alternate" type="text/markdown" href="muestra.md"><meta name="robots" content="noai, noimageai"></head></html>',
		);
		foreach ( array( home_url( '/' ), get_permalink( $post ) . '|html', get_permalink( $post ) . '|md' ) as $key ) {
			$this->responses[ $key ] = $cached;
		}
	}

	private function render_diagnostics_tab() {
		ob_start();
		Plugin::instance()->get( 'page' )->tabs()['diagnostics']->render();
		return ob_get_clean();
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
			'args'   => $args,
		);
		if ( $this->delay_us > 0 ) {
			usleep( $this->delay_us );
		}
		$accept = $args['headers']['Accept'];
		$key    = $url . '|' . ( 0 === strpos( $accept, 'text/markdown' ) ? 'md' : 'html' );
		$spec   = isset( $this->responses[ $key ] ) ? $this->responses[ $key ] : ( isset( $this->responses[ $url ] ) ? $this->responses[ $url ] : $this->default_response( $url, $accept ) );
		if ( is_wp_error( $spec ) ) {
			return $spec;
		}
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

	/**
	 * Overrides, at a higher priority than fake_http(), the response of the .htaccess verification request
	 * (identified by its "wpasl_verify" query arg) when a test has queued one in $this->verify_response.
	 */
	public function fake_htaccess_verify_response( $pre, $args, $url ) {
		if ( null === $this->verify_response || false === strpos( $url, 'wpasl_verify=' ) ) {
			return $pre;
		}
		$spec = $this->verify_response;
		if ( is_wp_error( $spec ) ) {
			return $spec;
		}
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
			return array(
				'code'    => 200,
				'headers' => $headers,
				'body'    => Plugin::instance()->get( 'robots' )->generated_output(),
			);
		} elseif ( '/agent-skills.json' === $path ) {
			$headers['content-type'] = 'application/ld+json; charset=utf-8';
		} elseif ( '/.well-known/api-catalog' === $path ) {
			$headers['content-type'] = 'application/linkset+json; charset=utf-8';
		} elseif ( 0 === strpos( $path, '/llms' ) || '.md' === substr( $path, -3 ) || 0 === strpos( $accept, 'text/markdown' ) ) {
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
			'body'    => '<html><head><link rel="alternate" type="text/markdown" href="x.md"></head><body><main><p>Sample content text.</p></main></body></html>',
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
		$this->assertSame( array( 'home', 'post_html', 'post_markdown', 'post_md', 'robots' ), array_keys( $raw['crawlers']['GPTBot'] ), 'The .md URL and robots.txt are probed with each crawler user-agent.' );
		$this->assertSame( array( 'robots', 'llms', 'llms-page', 'llms-post', 'auth', 'skills', 'catalog', 'markdown_url', 'render', 'storage' ), array_keys( $raw['site'] ) );

		$agents = array_unique( array_column( $this->requests, 'ua' ) );
		$this->assertCount( 3, $agents, 'Two crawlers plus the diagnostics agent.' );
		$this->assertNotEmpty( array_filter( $this->requests, static function ( $r ) { return false !== strpos( $r['ua'], 'GPTBot' ) && 0 === strpos( $r['accept'], 'text/markdown' ); } ) ); // phpcs:ignore
		foreach ( $this->requests as $request ) {
			$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( $request['url'], PHP_URL_HOST ) );
		}
		$this->assertSame( 5, CrawlerProbe::TIMEOUT );
		foreach ( $this->requests as $request ) {
			$this->assertSame( 5, $request['args']['timeout'] );
			$this->assertSame( 0, $request['args']['redirection'] );
			$this->assertSame( CrawlerProbe::MAX_RESPONSE_BYTES, $request['args']['limit_response_size'] );
		}
		$this->assertArrayHasKey( 'body', $raw['site']['robots'], 'The served robots.txt body is kept for the report.' );
		$this->assertArrayNotHasKey( 'body', $raw['crawlers']['GPTBot']['home'] );
	}

	public function test_report_contains_markdown_url_check_per_crawler() {
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$report = $this->controller->run();
		foreach ( array( 'GPTBot', 'PerplexityBot' ) as $agent ) {
			$this->assertArrayHasKey( 'markdown_url', $report['crawlers'][ $agent ]['checks'] );
			$this->assertArrayHasKey( 'robots_fetch', $report['crawlers'][ $agent ]['checks'] );
			$this->assertSame( Report::OK, $report['crawlers'][ $agent ]['checks']['markdown_url']['status'] );
			$this->assertSame( Report::OK, $report['crawlers'][ $agent ]['checks']['robots_fetch']['status'] );
		}
	}

	public function test_ua_specific_block_on_md_is_reported_only_for_that_crawler() {
		$post = self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$md   = Plugin::instance()->get( 'delivery' )->markdown_url( $post );
		$waf  = static function ( $pre, $args, $url ) use ( $md ) {
			if ( $md === $url && false !== strpos( (string) $args['user-agent'], 'PerplexityBot' ) ) {
				return array(
					'response' => array( 'code' => 403, 'message' => 'Forbidden' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					'headers'  => array( 'content-type' => 'text/html' ),
					'body'     => 'blocked',
				);
			}
			return $pre;
		};
		add_filter( 'pre_http_request', $waf, 20, 3 );
		$report = $this->controller->run();
		remove_filter( 'pre_http_request', $waf, 20 );

		$this->assertSame( Report::ERROR, $report['crawlers']['PerplexityBot']['checks']['markdown_url']['status'] );
		$this->assertStringContainsString( '403', $report['crawlers']['PerplexityBot']['checks']['markdown_url']['message'] );
		$this->assertSame( Report::OK, $report['crawlers']['GPTBot']['checks']['markdown_url']['status'] );
		$this->assertSame( Report::OK, $report['site']['markdown_url']['status'], 'The site-wide probe with the plugin user-agent is unaffected.' );
	}

	public function test_probe_does_not_follow_redirects() {
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$this->responses[ home_url( '/robots.txt' ) ] = array(
			'code'    => 302,
			'headers' => array( 'location' => 'https://www.example.org/robots.txt' ),
		);
		$raw = $this->probe->run();

		$robots_requests = array_filter( $this->requests, static function ( $r ) { return home_url( '/robots.txt' ) === $r['url']; } ); // phpcs:ignore
		$this->assertCount( 3, $robots_requests, 'Once with the diagnostics user-agent, once per crawler; never a second time to follow the redirect.' );
		foreach ( $robots_requests as $request ) {
			$this->assertSame( 0, $request['args']['redirection'] );
		}
		foreach ( $this->requests as $request ) {
			$this->assertStringNotContainsString( 'www.example.org', $request['url'] );
		}
		$this->assertSame( 302, $raw['site']['robots']['status'] );
		$this->assertSame( 'https://www.example.org/robots.txt', $raw['site']['robots']['headers']['location'] );
	}

	public function test_report_flags_redirect_as_warning() {
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$this->responses[ home_url( '/robots.txt' ) ] = array(
			'code'    => 301,
			'headers' => array( 'location' => 'https://www.example.org/robots.txt' ),
		);
		$this->responses[ home_url( '/' ) ]           = array(
			'code'    => 301,
			'headers' => array( 'location' => 'https://www.example.org/' ),
		);
		$report                                       = $this->controller->run();

		$this->assertSame( Report::WARNING, $report['site']['robots']['status'] );
		$this->assertStringContainsString( 'HTTP 301 redirect to https://www.example.org/robots.txt', $report['site']['robots']['message'] );
		$this->assertSame( Report::WARNING, $report['crawlers']['GPTBot']['checks']['home']['status'] );
		$this->assertStringContainsString( 'redirect to https://www.example.org/', $report['crawlers']['GPTBot']['checks']['home']['message'] );
	}

	/**
	 * Runs handle() and returns the redirect location it produced.
	 *
	 * @return string
	 */
	private function handle_and_capture_redirect() {
		$_REQUEST[ DiagnosticsController::NONCE ] = wp_create_nonce( DiagnosticsController::ACTION );
		try {
			$this->controller->handle();
		} catch ( WPDieException $e ) {
			throw $e;
		} catch ( Exception $e ) {
			$this->assertStringStartsWith( 'redirect:', $e->getMessage() );
			return substr( $e->getMessage(), strlen( 'redirect:' ) );
		}
		$this->fail( 'Expected a redirect.' );
	}

	public function test_diagnostics_splits_into_batches_and_resumes() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		add_filter( 'wpasl_diagnostics_time_budget', '__return_zero' );

		// First request: site targets plus exactly one crawler, then a redirect to the next batch.
		$location = $this->handle_and_capture_redirect();
		$this->assertStringContainsString( 'admin-post.php', $location );
		$this->assertStringContainsString( 'action=' . DiagnosticsController::ACTION, $location );
		$this->assertStringContainsString( DiagnosticsController::NONCE . '=', $location );
		$this->assertStringNotContainsString( 'wpasl_notice', $location );

		$run = get_transient( DiagnosticsController::run_key() );
		$this->assertIsArray( $run );
		$this->assertSame( array( 'PerplexityBot' ), $run['pending'] );
		$this->assertSame( array( 'GPTBot' ), array_keys( $run['crawlers'] ) );
		$this->assertArrayHasKey( 'robots', $run['site'] );
		$this->assertNull( Report::load(), 'No report until the last batch.' );
		$first_batch = count( $this->requests );
		$this->assertSame( 10 + 5, $first_batch, 'Ten site targets (two per-type files, the render request) and the five requests of one crawler.' );

		// Second request: resumes with the first pending crawler only and publishes the report.
		$this->requests = array();
		$location       = $this->handle_and_capture_redirect();
		$this->assertStringContainsString( 'wpasl_notice=diagnostics', $location );
		$this->assertCount( 5, $this->requests, 'Only the pending crawler was probed.' );
		foreach ( $this->requests as $request ) {
			$this->assertStringContainsString( 'PerplexityBot', $request['ua'] );
		}
		$this->assertFalse( get_transient( DiagnosticsController::run_key() ) );

		$report = Report::load();
		$this->assertNotNull( $report );
		$this->assertSame( array( 'GPTBot', 'PerplexityBot' ), array_keys( $report['crawlers'] ) );
		$this->assertSame( Report::OK, $report['site']['robots']['status'] );
	}

	public function test_diagnostics_step_requires_capability_and_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		add_filter( 'wpasl_diagnostics_time_budget', '__return_zero' );
		$this->handle_and_capture_redirect();
		$this->assertNotNull( DiagnosticsController::pending_run() );
		$this->requests = array();

		// A chained GET without a valid nonce must not continue the run.
		$_REQUEST[ DiagnosticsController::NONCE ] = 'bad';
		try {
			$this->controller->handle();
			$this->fail( 'Expected wp_die for an invalid nonce.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( array(), $this->requests );
			$this->assertNotNull( DiagnosticsController::pending_run(), 'The run in progress is kept.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_REQUEST[ DiagnosticsController::NONCE ] = wp_create_nonce( DiagnosticsController::ACTION );
		try {
			$this->controller->handle();
			$this->fail( 'Expected wp_die for missing capability.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( array(), $this->requests );
		}
	}

	public function test_diagnostics_request_duration_is_bounded() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		remove_filter( 'wpasl_diagnostics_crawlers', array( $this, 'two_crawlers' ) );
		$this->assertGreaterThanOrEqual( 20, count( $this->probe->crawlers() ) );

		// Every request "takes" 20 ms and each batch may spend 50 ms: a batch never fits more than one
		// crawler beyond the budget, whatever the catalog size.
		$this->delay_us = 20000;
		add_filter( 'wpasl_diagnostics_time_budget', static function () { return 0.05; } ); // phpcs:ignore

		$per_crawler = 3;
		$site        = count( $this->probe->site_targets() );
		$batches     = 0;
		do {
			$this->requests = array();
			$started        = microtime( true );
			$location       = $this->handle_and_capture_redirect();
			$elapsed        = microtime( true ) - $started;
			++$batches;

			$allowance = ( 0 === $batches - 1 ? $site : 0 ) + $per_crawler; // budget already spent by site targets or the first crawler.
			$this->assertLessThanOrEqual( $allowance + $per_crawler * 2, count( $this->requests ), "Batch {$batches} probed too many crawlers." );
			$this->assertLessThan( 0.05 + ( $per_crawler + $site ) * 0.02 + 0.5, $elapsed, "Batch {$batches} took {$elapsed}s." );
		} while ( false === strpos( $location, 'wpasl_notice' ) && $batches < 50 );

		$this->assertGreaterThan( 3, $batches, 'A slow site is split into several batches.' );
		$report = Report::load();
		$this->assertNotNull( $report );
		$this->assertSame( count( $this->probe->crawlers() ), count( $report['crawlers'] ), 'Every crawler ends up in the report.' );
	}

	public function test_tab_shows_in_progress_notice() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		add_filter( 'wpasl_diagnostics_time_budget', '__return_zero' );
		$this->handle_and_capture_redirect();

		$tabs = Plugin::instance()->get( 'page' )->tabs();
		ob_start();
		$tabs['diagnostics']->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'A simulation is in progress: 1 crawler(s) done, 1 pending.', $html );

		$this->handle_and_capture_redirect();
		ob_start();
		$tabs['diagnostics']->render();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'in progress', $html );
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

	public function test_robots_verdict_matches_served_body() {
		$body = "# comment\nUser-agent: *\nDisallow: /wp-admin/\n\nuser-agent: gptbot\nUSER-AGENT: CCBot\nDisallow: / # blocked\n\nUser-agent: PerplexityBot\nAllow: /\n\nUser-agent: Amazonbot\nDisallow: /private/\n\nUser-agent: Diffbot\nDisallow: /\nAllow: /\n";
		$this->assertSame( 'block', Report::robots_verdict( $body, 'GPTBot' ) );
		$this->assertSame( 'block', Report::robots_verdict( $body, 'ccbot' ) );
		$this->assertSame( 'allow', Report::robots_verdict( $body, 'PerplexityBot' ) );
		$this->assertSame( 'allow', Report::robots_verdict( $body, 'Amazonbot' ), 'A partial Disallow is not a site-wide block.' );
		$this->assertSame( 'allow', Report::robots_verdict( $body, 'Diffbot' ), 'An explicit root Allow wins.' );
		$this->assertNull( Report::robots_verdict( $body, 'ClaudeBot' ), 'The * group is not a verdict for a token.' );
		$this->assertNull( Report::robots_verdict( '', 'GPTBot' ) );

		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$report = $this->controller->run();
		$this->assertSame( Report::OK, $report['crawlers']['GPTBot']['checks']['robots']['status'] );
		$this->assertStringContainsString( 'Blocked by robots.txt', $report['crawlers']['GPTBot']['checks']['robots']['message'] );
		$this->assertSame( Report::OK, $report['crawlers']['PerplexityBot']['checks']['robots']['status'] );
		$this->assertStringContainsString( 'Allowed by robots.txt', $report['crawlers']['PerplexityBot']['checks']['robots']['message'] );
	}

	public function test_robots_verdict_warns_when_group_missing() {
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$this->responses[ home_url( '/robots.txt' ) ] = array(
			'code'    => 200,
			'headers' => array( 'content-type' => 'text/plain' ),
			'body'    => "User-agent: *\nDisallow: /wp-admin/\n",
		);
		$report                                       = $this->controller->run();
		$check                                        = $report['crawlers']['GPTBot']['checks']['robots'];
		$this->assertSame( Report::WARNING, $check['status'] );
		$this->assertStringContainsString( 'No rule for GPTBot in the served robots.txt', $check['message'] );
		$this->assertStringContainsString( '"block"', $check['message'] );
	}

	public function test_robots_verdict_warns_when_body_differs_from_policy() {
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$this->responses[ home_url( '/robots.txt' ) ] = array(
			'code'    => 200,
			'headers' => array( 'content-type' => 'text/plain' ),
			'body'    => "User-agent: GPTBot\nAllow: /\n\nUser-agent: PerplexityBot\nDisallow: /\n",
		);
		$report                                       = $this->controller->run();
		$this->assertSame( Report::WARNING, $report['crawlers']['GPTBot']['checks']['robots']['status'] );
		$this->assertStringContainsString( 'says "allow" for GPTBot but the configured policy is "block"', $report['crawlers']['GPTBot']['checks']['robots']['message'] );
		$this->assertSame( Report::WARNING, $report['crawlers']['PerplexityBot']['checks']['robots']['status'] );
		$this->assertStringContainsString( 'says "block" for PerplexityBot but the configured policy is "allow"', $report['crawlers']['PerplexityBot']['checks']['robots']['message'] );

		// Unreadable robots.txt: warning, not an error attributed to the crawler.
		$this->responses[ home_url( '/robots.txt' ) ] = array(
			'code'    => 500,
			'headers' => array(),
		);
		$report                                       = $this->controller->run();
		$this->assertSame( Report::WARNING, $report['crawlers']['GPTBot']['checks']['robots']['status'] );
		$this->assertStringContainsString( 'could not be read (HTTP 500)', $report['crawlers']['GPTBot']['checks']['robots']['message'] );
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

	public function test_storage_probe_runs_without_generated_documents() {
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$storage = Plugin::instance()->get( 'storage' );
		$this->assertSame( array(), $storage->list_files( '' ), 'No document generated yet.' );

		$direct = $this->probe->storage_direct_url();
		$this->assertNotNull( $direct );
		$this->assertStringEndsWith( '/' . WPASL\Storage::PROBE_FILE, $direct );
		$this->assertFileExists( $storage->base_dir() . '/' . WPASL\Storage::PROBE_FILE );
		$this->assertSame( array(), $storage->list_files( '' ), 'The probe file is a guard, not a document.' );

		$report = $this->controller->run();
		$this->assertArrayHasKey( 'storage', $report['site'] );
		$this->assertSame( Report::OK, $report['site']['storage']['status'] );

		Plugin::instance()->get( 'runner' )->clear();
		$this->assertFileExists( $storage->base_dir() . '/' . WPASL\Storage::PROBE_FILE, 'Clearing the storage keeps the probe.' );
	}

	public function test_tab_curl_textarea_has_no_leading_whitespace() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$tabs = Plugin::instance()->get( 'page' )->tabs();
		ob_start();
		$tabs['diagnostics']->render();
		$html = ob_get_clean();
		$this->assertMatchesRegularExpression( '/<textarea[^>]*>#/', $html, 'The textarea content starts immediately with the first comment.' );
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

	public function test_curl_commands_are_shell_safe() {
		$this->assertSame( "'plain'", DiagnosticsTab::shell_quote( 'plain' ) );
		$this->assertSame( "'it'\\''s'", DiagnosticsTab::shell_quote( "it's" ) );

		$add_bot = static function ( $crawlers ) {
			$crawlers[] = array( 'agent' => "O'Bot", 'vendor' => 'Acme', 'group' => 'agent', 'docs' => '' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			return $crawlers;
		};
		add_filter( 'wpasl_crawler_catalog', $add_bot );
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$text = Plugin::instance()->get( 'page' )->tabs()['diagnostics']->curl_commands( "https://example.org/it's here/" );
		remove_filter( 'wpasl_crawler_catalog', $add_bot );

		$this->assertStringContainsString( "curl -sI -A 'Mozilla/5.0 (compatible; O'\\''Bot/1.0)' -H 'Accept: text/html' 'https://example.org/it'\\''s here/'", $text );
		$this->assertStringContainsString( "curl -s -H 'X-WPASL-Render: 1' 'https://example.org/it'\\''s here/?wpasl_render=1'", $text );
		$this->assertStringContainsString( "curl -sI -A 'Mozilla/5.0 (compatible; GPTBot/1.0)' -H 'Accept: text/markdown' 'https://example.org/it'\\''s here/'", $text );
		foreach ( explode( "\n", $text ) as $line ) {
			if ( 0 === strpos( $line, 'curl ' ) ) {
				$this->assertSame( 0, substr_count( str_replace( "'\\''", '', $line ), "'" ) % 2, 'Balanced quotes: ' . $line );
			}
		}
	}

	public function test_tab_renders_report_from_previous_version_without_notices() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_transient(
			Report::TRANSIENT,
			array(
				'generated_at'   => time(),
				'sample_post'    => 0,
				'infrastructure' => array( 'cdn' => '' ),
				'site'           => array( 'robots' => array( 'status' => 'ok', 'message' => 'robots ok' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				'crawlers'       => array(
					'GPTBot' => array(
						'policy' => 'block',
						'checks' => array( 'home' => array( 'status' => 'ok', 'message' => 'home ok' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					),
				),
			),
			Report::TTL
		);

		$report = Report::load();
		$this->assertFalse( $report['infrastructure']['storage_exposed'] );
		$this->assertSame( '', $report['infrastructure']['page_cache'] );
		$this->assertFalse( $report['infrastructure']['page_cache_served'] );
		$this->assertSame( Report::NOT_AVAILABLE, $report['crawlers']['GPTBot']['checks']['markdown_url']['status'] );
		$this->assertSame( 'ok', $report['crawlers']['GPTBot']['checks']['home']['status'] );

		ob_start();
		Plugin::instance()->get( 'page' )->tabs()['diagnostics']->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'home ok', $html );
		$this->assertStringContainsString( 'Not available', $html );
		$this->assertStringContainsString( 'No CDN or proxy detected.', $html );
		$this->assertStringNotContainsString( 'Page cache:', $html );
	}

	public function test_page_cache_detection_is_null_here_and_replaceable_by_filter() {
		$this->assertNull( PageCache::detect(), 'Cache Enabler is not installed in the test environment.' );
		add_filter( 'wpasl_diagnostics_page_cache', array( $this, 'fake_cache_enabler' ) );
		$this->assertSame( $this->fake_cache_enabler(), PageCache::detect() );
		$this->assertTrue( PageCache::is_cache_enabler( PageCache::detect() ) );
		remove_filter( 'wpasl_diagnostics_page_cache', array( $this, 'fake_cache_enabler' ) );

		add_filter( 'wpasl_diagnostics_page_cache', '__return_null' );
		$this->assertNull( PageCache::detect() );

		$this->assertSame( 'Cache Enabler', PageCache::label( 'cache-enabler-engine' ) );
		$this->assertSame( 'Cache Enabler', PageCache::label( ' Cache-Enabler-Engine ' ) );
		$this->assertSame( 'foo-cache', PageCache::label( 'foo-cache' ) );
		$this->assertInstanceOf( PageCache::class, Plugin::instance()->get( 'page_cache' ) );
	}

	public function test_report_detects_cache_enabler_from_x_cache_handler() {
		$post = self::factory()->post->create_and_get( array( 'post_name' => 'muestra' ) );
		$this->serve_cached_html( $post );

		$raw = $this->probe->run();
		$this->assertNull( $raw['page_cache'], 'Local detection is recorded with the run.' );
		$this->assertSame( 'cache-enabler-engine', $raw['crawlers']['GPTBot']['post_html']['headers']['x-cache-handler'] );

		$report = $this->controller->run();
		$this->assertSame( 'Cache Enabler', $report['infrastructure']['page_cache'] );
		$this->assertTrue( $report['infrastructure']['page_cache_served'] );
		foreach ( array( 'GPTBot', 'PerplexityBot' ) as $agent ) {
			$checks = $report['crawlers'][ $agent ]['checks'];
			$this->assertSame( Report::WARNING, $checks['content_signal']['status'] );
			$this->assertStringContainsString( 'Cache Enabler served it from its page cache', $checks['content_signal']['message'] );
			$this->assertSame( Report::WARNING, $checks['x_robots_tag']['status'] );
			$this->assertStringContainsString( 'Cache Enabler served it from its page cache', $checks['x_robots_tag']['message'] );
			$this->assertSame( Report::ERROR, $checks['negotiation']['status'] );
			$this->assertStringContainsString( 'Cache Enabler served the cached HTML', $checks['negotiation']['message'] );
			$this->assertSame( Report::OK, $checks['alternate_link']['status'], 'The <link> in the body survives in the cached HTML.' );
			$this->assertSame( Report::OK, $checks['markdown_url']['status'] );
			$this->assertSame( Report::OK, $checks['home']['status'] );
		}
		foreach ( array( 'llms', 'robots', 'skills', 'catalog', 'markdown_url' ) as $key ) {
			$this->assertSame( Report::OK, $report['site'][ $key ]['status'], $key );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$html = $this->render_diagnostics_tab();
		$this->assertStringContainsString( 'Page cache: Cache Enabler (served at least one probed response', $html );
		$this->assertStringNotContainsString( 'mod_headers', $html, 'No local notice: the plugin is not active here.' );
	}

	public function test_report_uses_local_detection_without_x_cache_handler() {
		add_filter( 'wpasl_diagnostics_page_cache', array( $this, 'fake_cache_enabler' ) );
		$post = self::factory()->post->create_and_get( array( 'post_name' => 'muestra' ) );
		$this->serve_cached_html( $post, false );

		$raw = $this->probe->run();
		$this->assertSame( 'cache-enabler', $raw['page_cache']['id'] );
		$this->assertArrayNotHasKey( 'x-cache-handler', $raw['crawlers']['GPTBot']['post_html']['headers'] );

		$report = $this->controller->run();
		$this->assertSame( 'Cache Enabler', $report['infrastructure']['page_cache'] );
		$this->assertFalse( $report['infrastructure']['page_cache_served'] );
		$checks = $report['crawlers']['GPTBot']['checks'];
		$this->assertSame( Report::WARNING, $checks['content_signal']['status'] );
		$this->assertStringContainsString( 'Cache Enabler is active', $checks['content_signal']['message'] );
		$this->assertSame( Report::WARNING, $checks['x_robots_tag']['status'] );
		$this->assertStringContainsString( 'Cache Enabler is active', $checks['x_robots_tag']['message'] );
		$this->assertSame( Report::ERROR, $checks['negotiation']['status'] );
		$this->assertStringContainsString( 'Cache Enabler', $checks['negotiation']['message'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$html = $this->render_diagnostics_tab();
		$this->assertStringContainsString( 'Page cache: Cache Enabler (active at the time of the last run)', $html );
	}

	public function test_report_shows_other_page_cache_handler_verbatim() {
		$post = self::factory()->post->create_and_get( array( 'post_name' => 'muestra' ) );
		$this->serve_cached_html( $post );
		foreach ( array( home_url( '/' ), get_permalink( $post ) . '|html', get_permalink( $post ) . '|md' ) as $key ) {
			$this->responses[ $key ]['headers']['x-cache-handler'] = 'foo-cache';
		}

		$report = $this->controller->run();
		$this->assertSame( 'foo-cache', $report['infrastructure']['page_cache'] );
		$this->assertTrue( $report['infrastructure']['page_cache_served'] );
		$checks = $report['crawlers']['GPTBot']['checks'];
		$this->assertStringContainsString( 'a cache or proxy may strip it', $checks['content_signal']['message'] );
		$this->assertSame( 'X-Robots-Tag noai missing on the HTML response.', $checks['x_robots_tag']['message'] );
		$this->assertStringContainsString( 'A page cache or CDN that ignores "Vary: Accept"', $checks['negotiation']['message'] );
		$this->assertStringNotContainsString( 'Cache Enabler', wp_json_encode( $report ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertStringContainsString( 'Page cache: foo-cache', $this->render_diagnostics_tab() );
	}

	public function test_report_without_page_cache_keeps_generic_messages() {
		$post = self::factory()->post->create_and_get( array( 'post_name' => 'muestra' ) );
		$this->responses[ get_permalink( $post ) . '|html' ] = array(
			'code'    => 200,
			'headers' => array( 'content-type' => 'text/html' ),
			'body'    => '<html></html>',
		);
		$report = $this->controller->run();
		$this->assertSame( '', $report['infrastructure']['page_cache'] );
		$this->assertFalse( $report['infrastructure']['page_cache_served'] );
		$this->assertStringContainsString( 'a cache or proxy may strip it', $report['crawlers']['GPTBot']['checks']['content_signal']['message'] );
		$this->assertStringNotContainsString( 'Cache Enabler', wp_json_encode( $report ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertStringNotContainsString( 'Page cache:', $this->render_diagnostics_tab() );
	}

	public function test_tab_shows_cache_enabler_notice_and_snippets() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'wpasl_diagnostics_page_cache', array( $this, 'fake_cache_enabler' ) );
		$this->assertNull( Report::load(), 'No report yet.' );

		$html = $this->render_diagnostics_tab();
		$text = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );
		$this->assertStringContainsString( 'Cache Enabler 1.8.16 is active on this site', $text );
		$this->assertStringContainsString( 'notice notice-warning inline', $html );
		foreach ( array( 'Content-Signal', 'Content-Usage', 'X-Robots-Tag', 'Accept: text/markdown', '.md', 'llms.txt', 'robots.txt', 'manifests', 'web server or CDN', 'the plugin can apply the .htaccess block for you' ) as $needle ) {
			$this->assertStringContainsString( $needle, $text );
		}
		$this->assertStringNotContainsString( 'never writes .htaccess', $text );
		$this->assertLessThan( strpos( $html, 'Run crawler simulation' ), strpos( $html, 'wpasl-page-cache' ), 'The notice comes before the form.' );

		$catalog = home_url( '/.well-known/api-catalog' );
		// .htaccess block.
		$this->assertStringContainsString( '<IfModule mod_headers.c>', $text );
		$this->assertStringContainsString( 'Header onsuccess unset Content-Signal "expr=%{CONTENT_TYPE} =~ m#^text/html#"', $text );
		$this->assertStringContainsString( 'Header always set Content-Signal "search=yes, ai-input=yes, ai-train=no" "expr=%{CONTENT_TYPE} =~ m#^text/html#"', $text );
		$this->assertStringContainsString( 'Header always set Content-Usage "train-ai=n, search=y" "expr=%{CONTENT_TYPE} =~ m#^text/html#"', $text );
		$this->assertStringContainsString( 'Header always setifempty X-Robots-Tag "noai, noimageai" "expr=%{CONTENT_TYPE} =~ m#^text/html#"', $text );
		$this->assertStringContainsString( 'Header always setifempty Link "<' . $catalog . '>; rel=\"api-catalog\"" "expr=%{CONTENT_TYPE} =~ m#^text/html#"', $text );
		$this->assertStringContainsString( 'Header always set X-WPASL-Headers "htaccess" "expr=%{CONTENT_TYPE} =~ m#^text/html#"', $text );
		// nginx block.
		$this->assertStringContainsString( 'map $sent_http_content_type $wpasl_content_signal {', $text );
		$this->assertStringContainsString( '"~^text/html" "search=yes, ai-input=yes, ai-train=no";', $text );
		$this->assertStringContainsString( '"~^text/html" \'<' . $catalog . '>; rel="api-catalog"\';', $text );
		$this->assertStringContainsString( 'add_header Content-Signal $wpasl_content_signal always;', $text );
		$this->assertStringContainsString( 'add_header X-Robots-Tag $wpasl_x_robots_tag always;', $text );
		$this->assertStringContainsString( 'add_header Link $wpasl_link always;', $text );
		// OpenLiteSpeed block.
		$this->assertStringContainsString( 'END_extraHeaders', $text );
		$this->assertStringContainsString( 'X-Robots-Tag is not included', $text );
		$this->assertStringNotContainsString( 'example.com', $text );
		$this->assertStringNotContainsString( 'cognosonline', $text );
		// The nginx and OpenLiteSpeed blocks never carry the verification marker.
		$nginx_block = substr( $text, strpos( $text, 'map $sent_http_content_type' ), strpos( $text, 'context / {' ) - strpos( $text, 'map $sent_http_content_type' ) );
		$this->assertStringNotContainsString( 'X-WPASL-Headers', $nginx_block );
		$ols_block = substr( $text, strpos( $text, 'context / {' ) );
		$this->assertStringNotContainsString( 'X-WPASL-Headers', $ols_block );
		$notice = substr( $html, strpos( $html, 'wpasl-page-cache' ), strpos( $html, 'Run crawler simulation' ) - strpos( $html, 'wpasl-page-cache' ) );
		$this->assertSame( 3, preg_match_all( '/<textarea readonly class="large-text code"[^>]*>#/', $notice ), 'Three snippets, each starting with its first comment.' );
		$this->assertStringNotContainsString( 'Cloudflare', $notice, 'No reminder without a report (the static checklist mentions Cloudflare on its own).' );
		$this->assertStringNotContainsString( 'Transform Rule', $text );

		Report::save( array_merge( Report::defaults(), array( 'infrastructure' => array_merge( Report::defaults()['infrastructure'], array( 'cdn' => 'Cloudflare' ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$text = html_entity_decode( $this->render_diagnostics_tab(), ENT_QUOTES, 'UTF-8' );
		$this->assertStringContainsString( 'Transform Rule', $text );
		$this->assertStringContainsString( 'Content-Signal: search=yes, ai-input=yes, ai-train=no', $text );
		$this->assertStringContainsString( 'Link: <' . $catalog . '>; rel="api-catalog"', $text );
	}

	public function test_openlitespeed_snippet_omits_x_robots_tag() {
		$page_cache = Plugin::instance()->get( 'page_cache' );
		$catalog    = home_url( '/.well-known/api-catalog' );
		$snippet    = $page_cache->openlitespeed_snippet();

		$this->assertStringContainsString( 'context / {', $snippet );
		$this->assertStringContainsString( 'extraHeaders            <<<END_extraHeaders', $snippet );
		$this->assertStringContainsString( 'set Content-Signal "search=yes, ai-input=yes, ai-train=no"', $snippet );
		$this->assertStringContainsString( 'set Content-Usage "train-ai=n, search=y"', $snippet );
		$this->assertStringContainsString( 'merge Link "<' . $catalog . '>; rel=api-catalog"', $snippet );
		$this->assertStringContainsString( "\n  END_extraHeaders\n", $snippet );
		foreach ( array( 'vHost Conf', 'Header Operations', 'Graceful Restart', 'systemctl restart lsws', 'X-Robots-Tag is left out on purpose' ) as $needle ) {
			$this->assertStringContainsString( $needle, $snippet );
		}

		preg_match( '/<<<END_extraHeaders\n(.*?)\n\s*END_extraHeaders/s', $snippet, $m );
		$this->assertSame(
			array(
				'set Content-Signal "search=yes, ai-input=yes, ai-train=no"',
				'set Content-Usage "train-ai=n, search=y"',
				'merge Link "<' . $catalog . '>; rel=api-catalog"',
			),
			explode( "\n", $m[1] ),
			'The header lines never include X-Robots-Tag or noai.'
		);
		$this->assertSame(
			array(
				'Content-Signal' => 'search=yes, ai-input=yes, ai-train=no',
				'Content-Usage'  => 'train-ai=n, search=y',
				'Link'           => '<' . $catalog . '>; rel=api-catalog',
			),
			$page_cache->openlitespeed_headers()
		);
		$this->assertStringContainsString( 'X-Robots-Tag "noai, noimageai"', $page_cache->htaccess_snippet() );
		$this->assertStringContainsString( 'X-Robots-Tag', $page_cache->nginx_snippet() );
		$this->assertStringNotContainsString( 'X-WPASL-Headers', $snippet, 'The OpenLiteSpeed block never carries the verification marker.' );
	}

	public function test_snippets_follow_settings() {
		update_option(
			Settings::OPTION,
			array(
				'signal_ai_train'      => 'yes',
				'content_usage_header' => false,
				'manifest_enabled'     => false,
			)
		);
		Plugin::instance()->get( 'settings' )->flush_cache();
		$page_cache = Plugin::instance()->get( 'page_cache' );

		$this->assertSame( array( 'Content-Signal' => 'search=yes, ai-input=yes, ai-train=yes' ), $page_cache->headers() );
		$this->assertSame( array( 'Content-Signal' => 'search=yes, ai-input=yes, ai-train=yes' ), $page_cache->openlitespeed_headers() );
		foreach ( array( $page_cache->htaccess_snippet(), $page_cache->nginx_snippet(), $page_cache->openlitespeed_snippet(), $page_cache->cloudflare_note( 'Cloudflare' ) ) as $snippet ) {
			$this->assertStringContainsString( 'ai-train=yes', $snippet );
			$this->assertStringNotContainsString( 'Content-Usage "', $snippet );
			$this->assertStringNotContainsString( 'Content-Usage:', $snippet );
			$this->assertStringNotContainsString( 'wpasl_content_usage', $snippet );
			$this->assertStringNotContainsString( 'X-Robots-Tag "', $snippet );
			$this->assertStringNotContainsString( 'X-Robots-Tag:', $snippet );
			$this->assertStringNotContainsString( 'wpasl_x_robots_tag', $snippet );
			$this->assertStringNotContainsString( 'Link "', $snippet );
			$this->assertStringNotContainsString( 'Link:', $snippet );
			$this->assertStringNotContainsString( 'api-catalog', $snippet );
		}
		$this->assertStringContainsString( 'X-WPASL-Headers', $page_cache->htaccess_snippet(), 'The marker is written regardless of which signal headers are enabled.' );
		$this->assertStringNotContainsString( 'X-WPASL-Headers', $page_cache->nginx_snippet() );
		$this->assertStringNotContainsString( 'X-WPASL-Headers', $page_cache->openlitespeed_snippet() );
		$this->assertSame( '', $page_cache->cloudflare_note( '' ) );
		$this->assertSame( '', $page_cache->cloudflare_note( 'Fastly' ) );
	}

	public function test_marker_header_is_never_sent_by_php() {
		$post = self::factory()->post->create_and_get( array( 'post_name' => 'muestra-marcador' ) );

		add_filter( 'wpasl_terminate_after_serve', '__return_false' );

		Http::reset();
		ob_start();
		$this->go_to( get_permalink( $post ) );
		ob_get_clean();
		$this->assertArrayNotHasKey( 'x-wpasl-headers', Http::effective_headers(), 'HTML page.' );

		Http::reset();
		ob_start();
		$this->go_to( home_url( '/muestra-marcador.md' ) );
		ob_get_clean();
		$this->assertArrayNotHasKey( 'x-wpasl-headers', Http::effective_headers(), '.md URL.' );

		Http::reset();
		ob_start();
		$this->go_to( home_url( '/robots.txt' ) );
		ob_get_clean();
		$this->assertArrayNotHasKey( 'x-wpasl-headers', Http::effective_headers(), 'robots.txt.' );

		Http::reset();
		ob_start();
		$this->go_to( home_url( '/llms.txt' ) );
		ob_get_clean();
		$this->assertArrayNotHasKey( 'x-wpasl-headers', Http::effective_headers(), 'llms.txt.' );

		remove_filter( 'wpasl_terminate_after_serve', '__return_false' );
	}

	public function test_probe_fetch_keeps_the_marker_header() {
		$this->responses[ home_url( '/' ) ] = array(
			'code'    => 200,
			'headers' => array(
				'content-type'    => 'text/html; charset=utf-8',
				'x-wpasl-headers' => 'htaccess',
				'content-signal'  => 'search=yes, ai-input=yes, ai-train=no',
			),
			'body'    => '<html></html>',
		);
		$result                             = $this->probe->fetch( home_url( '/' ), 'ua', 'text/html' );
		$this->assertSame( 'htaccess', $result['headers']['x-wpasl-headers'] );
	}

	public function test_htaccess_environment_detection() {
		$cases = array(
			'Apache/2.4.58' => true,
			'Apache/2.2.34' => false,
			'Apache'        => true,
			'nginx/1.24.0'  => false,
			''              => false,
		);
		foreach ( $cases as $software => $expected_compatible ) {
			$_SERVER['SERVER_SOFTWARE'] = $software;
			$result                     = $this->htaccess->environment();
			$this->assertSame( $expected_compatible, $result['compatible'], "SERVER_SOFTWARE={$software}" );
		}

		$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
		unset( $_SERVER['LSWS_EDITION'] );
		$this->assertTrue( $this->htaccess->environment()['compatible'], 'LiteSpeed without LSWS_EDITION is assumed Enterprise.' );

		$_SERVER['LSWS_EDITION'] = 'Openlitespeed 1.7.19';
		$this->assertFalse( $this->htaccess->environment()['compatible'], 'OpenLiteSpeed is never compatible.' );

		unset( $_SERVER['SERVER_SOFTWARE'], $_SERVER['LSWS_EDITION'] );
		add_filter( 'wpasl_htaccess_environment', '__return_null' );
		$this->assertNull( $this->htaccess->environment(), 'The result can be replaced with a filter.' );
		remove_filter( 'wpasl_htaccess_environment', '__return_null' );
	}

	public function test_htaccess_availability() {
		add_filter( 'wpasl_diagnostics_page_cache', array( $this, 'fake_cache_enabler' ) );
		add_filter( 'wpasl_htaccess_environment', array( $this, 'fake_htaccess_environment' ) );
		file_put_contents( $this->htaccess_file, "# existing\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		if ( is_multisite() ) {
			$this->assertFalse( $this->htaccess->available(), 'Never available on multisite, even with Cache Enabler, a compatible environment and a writable file.' );
			return;
		}

		$this->assertTrue( $this->htaccess->available() );

		remove_filter( 'wpasl_diagnostics_page_cache', array( $this, 'fake_cache_enabler' ) );
		$this->assertFalse( $this->htaccess->available(), 'Without Cache Enabler.' );
		add_filter( 'wpasl_diagnostics_page_cache', array( $this, 'fake_cache_enabler' ) );

		remove_filter( 'wpasl_htaccess_environment', array( $this, 'fake_htaccess_environment' ) );
		add_filter( 'wpasl_htaccess_environment', array( $this, 'fake_incompatible_environment' ) );
		$this->assertFalse( $this->htaccess->available(), 'Incompatible environment.' );
		remove_filter( 'wpasl_htaccess_environment', array( $this, 'fake_incompatible_environment' ) );
		add_filter( 'wpasl_htaccess_environment', array( $this, 'fake_htaccess_environment' ) );

		$dir = trailingslashit( get_temp_dir() ) . 'wpasl-test-readonly-' . wp_generate_password( 8, false );
		wp_mkdir_p( $dir );
		chmod( $dir, 0555 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture: read-only directory.
		$readonly_file = $dir . '/.htaccess';
		add_filter( 'wpasl_htaccess_file', array( $this, 'fake_readonly_htaccess_file' ) );
		$this->readonly_htaccess_file = $readonly_file;
		$this->assertFalse( $this->htaccess->available(), 'Unwritable directory.' );
		remove_filter( 'wpasl_htaccess_file', array( $this, 'fake_readonly_htaccess_file' ) );
		chmod( $dir, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restoring so rmdir succeeds.
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.

		$this->assertTrue( $this->htaccess->available(), 'Available again once the fixtures are back to compatible.' );
	}

	/**
	 * Points wpasl_htaccess_file at the read-only fixture directory used by test_htaccess_availability().
	 */
	public function fake_readonly_htaccess_file() {
		return $this->readonly_htaccess_file;
	}

	public function test_htaccess_status() {
		add_filter( 'wpasl_htaccess_environment', array( $this, 'fake_htaccess_environment' ) );

		$this->assertSame( 'not_applied', $this->htaccess->status() );

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		insert_with_markers( $this->htaccess_file, HtaccessHeaders::MARKER, Plugin::instance()->get( 'page_cache' )->htaccess_lines() );
		$this->assertSame( 'current', $this->htaccess->status() );

		update_option( Settings::OPTION, array( 'signal_ai_train' => 'yes' ) );
		Plugin::instance()->get( 'settings' )->flush_cache();
		$this->assertSame( 'stale', $this->htaccess->status() );
	}

	public function test_htaccess_backup_and_restore() {
		$storage = Plugin::instance()->get( 'storage' );
		file_put_contents( $this->htaccess_file, "# original content\nSomeDirective 1\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$this->assertTrue( $this->htaccess->backup() );
		$this->assertSame( "# original content\nSomeDirective 1\n", $storage->read( HtaccessHeaders::BACKUP_FILE ) );
		$meta = get_option( HtaccessHeaders::BACKUP_OPTION );
		$this->assertTrue( $meta['existed'] );

		file_put_contents( $this->htaccess_file, "# changed\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->assertTrue( $this->htaccess->backup() );
		$this->assertSame( "# changed\n", $storage->read( HtaccessHeaders::BACKUP_FILE ), 'Only the most recent backup is kept: the content just before this second call.' );

		$this->assertTrue( $this->htaccess->restore() );
		$this->assertSame( "# changed\n", file_get_contents( $this->htaccess_file ), 'restore() writes back the most recent backup.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion.

		// existed => false: restore() removes the file.
		unlink( $this->htaccess_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture.
		$this->assertTrue( $this->htaccess->backup() );
		$meta = get_option( HtaccessHeaders::BACKUP_OPTION );
		$this->assertFalse( $meta['existed'] );
		file_put_contents( $this->htaccess_file, 'created by the write step' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->assertTrue( $this->htaccess->restore() );
		$this->assertFileDoesNotExist( $this->htaccess_file );
	}

	public function test_htaccess_verify_request_shape() {
		self::factory()->post->create( array( 'post_name' => 'muestra-verify' ) );
		$this->htaccess->verify();

		$this->assertCount( 1, $this->requests, 'Exactly one verification request.' );
		$request = $this->requests[0];
		$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( $request['url'], PHP_URL_HOST ) );
		$this->assertSame( 'text/html', $request['accept'] );
		$this->assertSame( 0, $request['args']['redirection'] );
		$this->assertArrayHasKey( 'wpasl_verify', wp_parse_args( wp_parse_url( $request['url'], PHP_URL_QUERY ) ) );
	}

	public function test_htaccess_verify_outcomes() {
		self::factory()->post->create_and_get( array( 'post_name' => 'muestra-verify' ) );

		$this->verify_response = array(
			'code'    => 200,
			'headers' => array(
				'content-type'    => 'text/html; charset=utf-8',
				'x-wpasl-headers' => 'htaccess',
				'content-signal'  => 'search=yes, ai-input=yes, ai-train=no',
			),
			'body'    => '<html></html>',
		);
		$result                = $this->htaccess->verify();
		$this->assertTrue( $result['ok'] );

		$this->verify_response = array(
			'code'    => 200,
			'headers' => array(
				'content-type'   => 'text/html; charset=utf-8',
				'content-signal' => 'search=yes, ai-input=yes, ai-train=no',
			),
			'body'    => '<html></html>',
		);
		$result                = $this->htaccess->verify();
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'header_missing:X-WPASL-Headers', $result['reason'] );

		$this->verify_response = array(
			'code'    => 500,
			'headers' => array( 'content-type' => 'text/html; charset=utf-8' ),
			'body'    => 'error',
		);
		$result                = $this->htaccess->verify();
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'http_500', $result['reason'] );

		$this->verify_response = new WP_Error( 'http_request_failed', 'Connection timed out' );
		$result                = $this->htaccess->verify();
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'request_error:Connection timed out', $result['reason'] );

		$this->verify_response = array(
			'code'    => 301,
			'headers' => array(
				'content-type' => 'text/html; charset=utf-8',
				'location'     => 'https://www.example.org/',
			),
			'body'    => '',
		);
		$result                = $this->htaccess->verify();
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'redirect_301', $result['reason'] );
	}

	public function test_htaccess_apply_success() {
		add_filter( 'wpasl_htaccess_environment', array( $this, 'fake_htaccess_environment' ) );
		self::factory()->post->create_and_get( array( 'post_name' => 'muestra-apply' ) );
		file_put_contents( $this->htaccess_file, "# unrelated line\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->verify_response = array(
			'code'    => 200,
			'headers' => array(
				'content-type'    => 'text/html; charset=utf-8',
				'x-wpasl-headers' => 'htaccess',
				'content-signal'  => 'search=yes, ai-input=yes, ai-train=no',
			),
			'body'    => '<html></html>',
		);

		$result = $this->htaccess->apply();

		if ( is_multisite() ) {
			$this->assertFalse( $result['ok'] );
			$this->assertSame( 'environment', $result['step'] );
			$this->assertSame( 'multisite', $result['reason'] );
			return;
		}

		$this->assertTrue( $result['ok'] );
		$storage = Plugin::instance()->get( 'storage' );
		$this->assertSame( "# unrelated line\n", $storage->read( HtaccessHeaders::BACKUP_FILE ) );
		$contents = file_get_contents( $this->htaccess_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion.
		$this->assertStringContainsString( '# unrelated line', $contents );
		$this->assertStringContainsString( 'BEGIN WP Agent Support Layer', $contents );
		$this->assertStringContainsString( 'END WP Agent Support Layer', $contents );
		foreach ( Plugin::instance()->get( 'page_cache' )->htaccess_lines() as $line ) {
			$this->assertStringContainsString( $line, $contents );
		}
		$this->assertCount( 1, $this->requests, 'Exactly one verification request.' );
		$this->assertSame( 'current', $this->htaccess->status() );
	}

	public function test_htaccess_apply_replaces_existing_block_in_place() {
		add_filter( 'wpasl_htaccess_environment', array( $this, 'fake_htaccess_environment' ) );
		self::factory()->post->create_and_get( array( 'post_name' => 'muestra-apply' ) );
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		file_put_contents( $this->htaccess_file, "# before\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		insert_with_markers( $this->htaccess_file, HtaccessHeaders::MARKER, array( 'Header set Content-Signal "old-value"' ) );
		file_put_contents( $this->htaccess_file, rtrim( file_get_contents( $this->htaccess_file ), "\n" ) . "\n# after\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.

		$this->verify_response = array(
			'code'    => 200,
			'headers' => array(
				'content-type'    => 'text/html; charset=utf-8',
				'x-wpasl-headers' => 'htaccess',
				'content-signal'  => 'search=yes, ai-input=yes, ai-train=no',
			),
			'body'    => '<html></html>',
		);

		$result = $this->htaccess->apply();

		if ( is_multisite() ) {
			$this->assertFalse( $result['ok'] );
			$this->assertSame( 'environment', $result['step'] );
			$this->assertSame( 'multisite', $result['reason'] );
			return;
		}

		$this->assertTrue( $result['ok'] );
		$contents = file_get_contents( $this->htaccess_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion.
		$this->assertStringContainsString( "# before\n", $contents );
		$this->assertStringContainsString( "# after\n", $contents );
		$this->assertStringNotContainsString( 'old-value', $contents );
		$this->assertSame( 1, substr_count( $contents, '# BEGIN WP Agent Support Layer' ), 'Only one pair of markers.' );
		$this->assertSame( 1, substr_count( $contents, '# END WP Agent Support Layer' ) );
		$this->assertSame( 'current', $this->htaccess->status() );
	}

	public function test_htaccess_apply_rolls_back_on_verification_failure() {
		add_filter( 'wpasl_htaccess_environment', array( $this, 'fake_htaccess_environment' ) );
		self::factory()->post->create_and_get( array( 'post_name' => 'muestra-apply' ) );
		$original = "# original content\n";
		file_put_contents( $this->htaccess_file, $original ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$this->verify_response = array(
			'code'    => 200,
			'headers' => array(
				'content-type'   => 'text/html; charset=utf-8',
				'content-signal' => 'search=yes, ai-input=yes, ai-train=no',
			),
			'body'    => '<html></html>',
		);

		$result = $this->htaccess->apply();

		if ( is_multisite() ) {
			$this->assertSame( 'environment', $result['step'] );
			$this->assertSame( 'multisite', $result['reason'] );
			return;
		}

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'verify', $result['step'] );
		$this->assertTrue( $result['restored'] );
		$this->assertSame( $original, file_get_contents( $this->htaccess_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion.
		$this->assertSame( 'not_applied', $this->htaccess->status() );
	}

	public function test_htaccess_apply_removes_created_file_when_it_did_not_exist() {
		add_filter( 'wpasl_htaccess_environment', array( $this, 'fake_htaccess_environment' ) );
		self::factory()->post->create_and_get( array( 'post_name' => 'muestra-apply' ) );
		$this->assertFileDoesNotExist( $this->htaccess_file );
		$this->verify_response = array(
			'code'    => 200,
			'headers' => array( 'content-type' => 'text/html; charset=utf-8' ),
			'body'    => '<html></html>',
		);

		$result = $this->htaccess->apply();

		if ( is_multisite() ) {
			$this->assertSame( 'environment', $result['step'] );
			$this->assertSame( 'multisite', $result['reason'] );
			$this->assertFileDoesNotExist( $this->htaccess_file );
			return;
		}

		$this->assertFalse( $result['ok'] );
		$this->assertTrue( $result['restored'] );
		$this->assertFileDoesNotExist( $this->htaccess_file );
	}

	public function test_htaccess_apply_aborts_when_backup_fails() {
		add_filter( 'wpasl_htaccess_environment', array( $this, 'fake_htaccess_environment' ) );
		file_put_contents( $this->htaccess_file, "# original\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$storage    = Plugin::instance()->get( 'storage' );
		$system_dir = dirname( $storage->path( HtaccessHeaders::BACKUP_FILE ) );
		wp_mkdir_p( $system_dir );
		chmod( $system_dir, 0555 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture: make the backup directory unwritable.

		$result = $this->htaccess->apply();

		chmod( $system_dir, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restore so other tests/cleanup can use the directory.

		if ( is_multisite() ) {
			$this->assertSame( 'environment', $result['step'] );
			$this->assertSame( 'multisite', $result['reason'] );
			return;
		}

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'backup', $result['step'] );
		$this->assertSame( array(), $this->requests, 'No verification request when the backup fails.' );
		$this->assertSame( "# original\n", file_get_contents( $this->htaccess_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion.
	}

	public function test_htaccess_apply_aborts_on_multisite() {
		add_filter( 'wpasl_htaccess_environment', array( $this, 'fake_htaccess_environment' ) );
		file_put_contents( $this->htaccess_file, "# original\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$result = $this->htaccess->apply();

		if ( is_multisite() ) {
			$this->assertFalse( $result['ok'] );
			$this->assertSame( 'environment', $result['step'] );
			$this->assertSame( 'multisite', $result['reason'] );
			$this->assertSame( "# original\n", file_get_contents( $this->htaccess_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion.
			$this->assertSame( array(), $this->requests, 'No verification request on multisite.' );
		} else {
			$this->assertNotSame( 'multisite', $result['reason'], 'Single site never aborts for the multisite reason.' );
		}
	}

	public function test_htaccess_handler_requires_capability_and_nonce() {
		add_filter( 'wpasl_htaccess_environment', array( $this, 'fake_htaccess_environment' ) );
		file_put_contents( $this->htaccess_file, "# original\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		try {
			$this->htaccess->handle();
			$this->fail( 'Expected a WPDieException.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( array(), $this->requests );
		}
		$this->assertSame( array(), $this->requests, 'No request without capability.' );
		$this->assertSame( "# original\n", file_get_contents( $this->htaccess_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion.

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		try {
			$this->htaccess->handle();
			$this->fail( 'Expected a WPDieException.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( array(), $this->requests );
		}
		$this->assertSame( array(), $this->requests, 'No request without a valid nonce.' );
		$this->assertSame( "# original\n", file_get_contents( $this->htaccess_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion.
	}

	public function test_htaccess_handler_applies_and_redirects() {
		add_filter( 'wpasl_htaccess_environment', array( $this, 'fake_htaccess_environment' ) );
		self::factory()->post->create_and_get( array( 'post_name' => 'muestra-handler' ) );
		file_put_contents( $this->htaccess_file, "# original\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->verify_response = array(
			'code'    => 200,
			'headers' => array(
				'content-type'    => 'text/html; charset=utf-8',
				'x-wpasl-headers' => 'htaccess',
				'content-signal'  => 'search=yes, ai-input=yes, ai-train=no',
			),
			'body'    => '<html></html>',
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST[ HtaccessHeaders::NONCE ] = wp_create_nonce( HtaccessHeaders::ACTION );

		if ( is_multisite() ) {
			// available() hides the button, but a submitted request still goes through handle() -> apply(),
			// which aborts without writing regardless of the nonce being valid.
			try {
				$this->htaccess->handle();
				$this->fail( 'Expected a redirect.' );
			} catch ( Exception $e ) {
				$this->assertStringContainsString( 'wpasl_notice=htaccess', $e->getMessage() );
			}
			$result = $this->htaccess->last_result();
			$this->assertFalse( $result['ok'] );
			$this->assertSame( 'environment', $result['step'] );
			return;
		}

		try {
			$this->htaccess->handle();
			$this->fail( 'Expected a redirect.' );
		} catch ( Exception $e ) {
			$this->assertStringContainsString( 'redirect:', $e->getMessage() );
			$this->assertStringContainsString( 'wpasl_notice=htaccess', $e->getMessage() );
		}

		$result = get_transient( HtaccessHeaders::RESULT_TRANSIENT . get_current_user_id() );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertStringContainsString( 'BEGIN WP Agent Support Layer', file_get_contents( $this->htaccess_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion.
	}

	public function test_htaccess_headers_service_is_wired() {
		$this->assertInstanceOf( HtaccessHeaders::class, Plugin::instance()->get( 'htaccess_headers' ) );
		$this->assertNotFalse( has_action( 'admin_post_' . HtaccessHeaders::ACTION ) );
	}

	public function test_tab_shows_htaccess_apply_control_when_available() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'wpasl_diagnostics_page_cache', array( $this, 'fake_cache_enabler' ) );
		add_filter( 'wpasl_htaccess_environment', array( $this, 'fake_htaccess_environment' ) );
		file_put_contents( $this->htaccess_file, "# existing\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$html = $this->render_diagnostics_tab();

		if ( is_multisite() ) {
			$this->assertStringNotContainsString( 'wpasl-htaccess-apply', $html );
			$this->assertStringNotContainsString( HtaccessHeaders::ACTION, $html );
			return;
		}

		$text = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );
		$this->assertStringContainsString( 'wpasl-htaccess-apply', $html );
		$this->assertStringContainsString( 'Apache 2.4.58', $text );
		$this->assertStringContainsString( $this->htaccess_file, $text );
		$this->assertStringContainsString( 'Not applied', $text );
		$this->assertStringContainsString( 'Apply automatically', $text );
		$this->assertStringContainsString( 'name="' . HtaccessHeaders::NONCE . '"', $html );

		// Exactly one <form within the page cache notice (the apply form); no form nested inside another.
		$this->assertSame( substr_count( $html, '<form' ), substr_count( $html, '</form>' ), 'Every opened form is closed.' );
		$open = 0;
		foreach ( preg_split( '/(<form\b|<\/form>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) as $token ) {
			if ( '<form' === substr( $token, 0, 5 ) ) {
				$this->assertSame( 0, $open, 'A <form is never opened while another is still open.' );
				++$open;
			} elseif ( '</form>' === $token ) {
				--$open;
			}
		}
	}

	public function test_tab_hides_htaccess_apply_control_when_unavailable() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'wpasl_diagnostics_page_cache', array( $this, 'fake_cache_enabler' ) );

		// No wpasl_htaccess_environment filter: the CLI test environment is never detected as compatible.
		$html   = $this->render_diagnostics_tab();
		$notice = substr( $html, strpos( $html, 'wpasl-page-cache' ), strpos( $html, 'Run crawler simulation' ) - strpos( $html, 'wpasl-page-cache' ) );
		$this->assertStringNotContainsString( 'wpasl-htaccess-apply', $html );
		$this->assertStringNotContainsString( HtaccessHeaders::ACTION, $html );
		$this->assertSame( 3, preg_match_all( '/<textarea readonly class="large-text code"[^>]*>#/', $notice ), 'The three snippets are still shown.' );

		// Incompatible environment explicitly.
		add_filter( 'wpasl_htaccess_environment', array( $this, 'fake_incompatible_environment' ) );
		$html = $this->render_diagnostics_tab();
		$this->assertStringNotContainsString( 'wpasl-htaccess-apply', $html );
		remove_filter( 'wpasl_htaccess_environment', array( $this, 'fake_incompatible_environment' ) );

		// Compatible environment but unwritable file.
		add_filter( 'wpasl_htaccess_environment', array( $this, 'fake_htaccess_environment' ) );
		$dir = trailingslashit( get_temp_dir() ) . 'wpasl-test-readonly-' . wp_generate_password( 8, false );
		wp_mkdir_p( $dir );
		chmod( $dir, 0555 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture: read-only directory.
		$this->readonly_htaccess_file = $dir . '/.htaccess';
		add_filter( 'wpasl_htaccess_file', array( $this, 'fake_readonly_htaccess_file' ) );
		$html = $this->render_diagnostics_tab();
		remove_filter( 'wpasl_htaccess_file', array( $this, 'fake_readonly_htaccess_file' ) );
		chmod( $dir, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restoring so rmdir succeeds.
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
		$this->assertStringNotContainsString( 'wpasl-htaccess-apply', $html );
	}

	public function test_tab_shows_htaccess_result_notices() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['wpasl_notice'] = 'htaccess';

		set_transient(
			HtaccessHeaders::RESULT_TRANSIENT . get_current_user_id(),
			array(
				'ok'       => true,
				'step'     => '',
				'reason'   => '',
				'restored' => null,
			),
			MINUTE_IN_SECONDS
		);
		$html = $this->render_diagnostics_tab();
		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'X-WPASL-Headers marker', $html );
		$this->assertFalse( get_transient( HtaccessHeaders::RESULT_TRANSIENT . get_current_user_id() ), 'The transient is consumed.' );

		set_transient(
			HtaccessHeaders::RESULT_TRANSIENT . get_current_user_id(),
			array(
				'ok'       => false,
				'step'     => 'verify',
				'reason'   => 'header_missing:X-WPASL-Headers',
				'restored' => true,
			),
			MINUTE_IN_SECONDS
		);
		$html = $this->render_diagnostics_tab();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'header_missing:X-WPASL-Headers', $html );
		$this->assertStringContainsString( 'restored', $html );

		set_transient(
			HtaccessHeaders::RESULT_TRANSIENT . get_current_user_id(),
			array(
				'ok'       => false,
				'step'     => 'verify',
				'reason'   => 'http_500',
				'restored' => false,
			),
			MINUTE_IN_SECONDS
		);
		$html = $this->render_diagnostics_tab();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( Plugin::instance()->get( 'storage' )->path( HtaccessHeaders::BACKUP_FILE ), $html );

		unset( $_GET['wpasl_notice'] );
		$html = $this->render_diagnostics_tab();
		$this->assertStringNotContainsString( 'wpasl-htaccess-result', $html );
	}

	public function test_htaccess_is_never_written_outside_the_action() {
		add_filter( 'wpasl_diagnostics_page_cache', array( $this, 'fake_cache_enabler' ) );
		add_filter( 'wpasl_htaccess_environment', array( $this, 'fake_htaccess_environment' ) );
		self::factory()->post->create( array( 'post_name' => 'muestra-no-write' ) );
		file_put_contents( $this->htaccess_file, "# untouched\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$contents = file_get_contents( $this->htaccess_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		$mtime    = filemtime( $this->htaccess_file );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		\WPASL\Lifecycle::activate();
		Plugin::instance()->get( 'settings' )->sanitize( array( '_tab' => 'general' ) );
		Plugin::instance()->get( 'settings' )->sanitize( array( '_tab' => 'signals' ) );
		Plugin::instance()->get( 'runner' )->run();
		$this->controller->run();
		$this->render_diagnostics_tab();

		clearstatcache( true, $this->htaccess_file );
		$this->assertSame( $contents, file_get_contents( $this->htaccess_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion.
		$this->assertSame( $mtime, filemtime( $this->htaccess_file ) );
		$this->assertFalse( Plugin::instance()->get( 'storage' )->exists( HtaccessHeaders::BACKUP_FILE ) );
		foreach ( $this->requests as $request ) {
			$this->assertStringNotContainsString( 'wpasl_verify', $request['url'] );
		}
	}

	public function test_htaccess_block_goes_stale_without_rewriting() {
		add_filter( 'wpasl_htaccess_environment', array( $this, 'fake_htaccess_environment' ) );
		self::factory()->post->create_and_get( array( 'post_name' => 'muestra-stale' ) );
		file_put_contents( $this->htaccess_file, "# original\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->verify_response = array(
			'code'    => 200,
			'headers' => array(
				'content-type'    => 'text/html; charset=utf-8',
				'x-wpasl-headers' => 'htaccess',
				'content-signal'  => 'search=yes, ai-input=yes, ai-train=no',
			),
			'body'    => '<html></html>',
		);
		$result                = $this->htaccess->apply();
		if ( is_multisite() ) {
			$this->assertSame( 'environment', $result['step'] );
			return;
		}
		$this->assertTrue( $result['ok'] );
		$contents = file_get_contents( $this->htaccess_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.

		update_option( Settings::OPTION, array( 'signal_ai_train' => 'yes' ) );
		Plugin::instance()->get( 'settings' )->flush_cache();

		$this->assertSame( $contents, file_get_contents( $this->htaccess_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion.
		$this->assertSame( 'stale', $this->htaccess->status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'wpasl_diagnostics_page_cache', array( $this, 'fake_cache_enabler' ) );
		$html = $this->render_diagnostics_tab();
		$this->assertStringContainsString( 'Applied with different values than the current settings', $html );
		$this->assertStringContainsString( 'Update the block', $html );
	}

	public function test_tab_hides_cache_notice_without_page_cache() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'wpasl_diagnostics_page_cache', '__return_null' );
		$html = $this->render_diagnostics_tab();
		$this->assertStringNotContainsString( 'Cache Enabler', $html );
		$this->assertStringNotContainsString( 'mod_headers', $html );
		$this->assertStringNotContainsString( 'wpasl-page-cache', $html );
		$this->assertStringContainsString( 'Run crawler simulation', $html );
	}

	public function test_report_checks_auth_md() {
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$url = home_url( '/auth.md' );

		$report = $this->controller->run();
		$this->assertSame( Report::OK, $report['site']['auth']['status'] );
		$this->assertStringContainsString( 'auth.md: HTTP 200, text/markdown', $report['site']['auth']['message'] );
		$this->assertSame( array( 'robots', 'llms', 'llms-page', 'llms-post', 'auth', 'skills', 'catalog', 'markdown_url', 'render', 'storage' ), array_keys( $report['site'] ) );

		$this->responses[ $url ] = array(
			'code'    => 404,
			'headers' => array( 'content-type' => 'text/html; charset=utf-8' ),
		);
		$report                  = $this->controller->run();
		$this->assertSame( Report::ERROR, $report['site']['auth']['status'] );
		$this->assertStringContainsString( 'auth.md: HTTP 404', $report['site']['auth']['message'] );

		$this->responses[ $url ] = array(
			'code'    => 200,
			'headers' => array( 'content-type' => 'text/html; charset=utf-8' ),
		);
		$report                  = $this->controller->run();
		$this->assertSame( Report::WARNING, $report['site']['auth']['status'] );
		$this->assertStringContainsString( 'unexpected Content-Type "text/html; charset=utf-8" (expected text/markdown)', $report['site']['auth']['message'] );
	}

	public function test_report_accepts_catalog_with_profile() {
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$url = home_url( '/.well-known/api-catalog' );

		$this->responses[ $url ] = array(
			'code'    => 200,
			'headers' => array( 'content-type' => \WPASL\Manifest\ManifestRouter::CATALOG_CONTENT_TYPE ),
		);
		$report                  = $this->controller->run();
		$this->assertSame( Report::OK, $report['site']['catalog']['status'] );
		$this->assertStringContainsString( 'API catalog: HTTP 200, application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"', $report['site']['catalog']['message'] );

		$this->responses[ $url ] = array(
			'code'    => 200,
			'headers' => array( 'content-type' => 'application/linkset+json' ),
		);
		$report                  = $this->controller->run();
		$this->assertSame( Report::OK, $report['site']['catalog']['status'], 'Without the profile parameter it is still accepted.' );

		$this->responses[ $url ] = array(
			'code'    => 200,
			'headers' => array( 'content-type' => 'application/json' ),
		);
		$report                  = $this->controller->run();
		$this->assertSame( Report::WARNING, $report['site']['catalog']['status'] );
	}

	public function test_probe_only_sends_get_to_known_targets() {
		$post = self::factory()->post->create_and_get( array( 'post_name' => 'muestra' ) );
		$this->probe->run();

		$allowed = array(
			home_url( '/' ),
			get_permalink( $post ),
			Plugin::instance()->get( 'delivery' )->markdown_url( $post ),
			\WPASL\Markdown\RenderedPage::url( $post ),
			home_url( '/robots.txt' ),
			home_url( '/llms.txt' ),
			home_url( '/llms-page.txt' ),
			home_url( '/llms-post.txt' ),
			home_url( '/auth.md' ),
			home_url( '/agent-skills.json' ),
			home_url( '/.well-known/api-catalog' ),
			$this->probe->storage_direct_url(),
		);
		$this->assertNotEmpty( $this->requests );
		foreach ( $this->requests as $request ) {
			$this->assertSame( 'GET', $request['args']['method'], $request['url'] );
			$this->assertContains( $request['url'], $allowed, $request['url'] );
			$this->assertArrayNotHasKey( 'body', array_filter( $request['args'] ), 'No request body.' );
			foreach ( array( 'register', 'oauth', 'token', '/agent/auth' ) as $forbidden ) {
				$this->assertStringNotContainsString( $forbidden, $request['url'] );
			}
		}
		$auth = array_filter(
			$this->requests,
			static function ( $request ) {
				return home_url( '/auth.md' ) === $request['url'];
			}
		);
		$this->assertCount( 1, $auth, 'auth.md is a site check: probed once, not per crawler.' );
		$this->assertSame( 'WP-Agent-Support-Layer-Diagnostics/' . WPASL_VERSION, reset( $auth )['ua'] );
		$this->assertStringStartsWith( 'text/markdown', reset( $auth )['accept'] );
	}

	public function test_render_target_is_probed_with_marker_and_html_accept() {
		$post = self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$url  = home_url( '/muestra/?wpasl_render=1' );
		$this->assertSame( $url, $this->probe->site_targets()['render'] );
		$this->assertSame( array( 'markdown_url', 'render', 'storage' ), array_slice( array_keys( $this->probe->site_targets() ), -3 ) );

		$raw = $this->probe->run();

		$render = array_values( array_filter( $this->requests, static function ( $r ) use ( $url ) { return $url === $r['url']; } ) ); // phpcs:ignore
		$this->assertCount( 1, $render, 'Probed once, as a site check.' );
		$this->assertSame( 'WP-Agent-Support-Layer-Diagnostics/' . WPASL_VERSION, $render[0]['ua'] );
		$this->assertSame( 'text/html', $render[0]['accept'] );
		$this->assertSame( '1', $render[0]['args']['headers']['X-WPASL-Render'] );
		$this->assertSame( 'GET', $render[0]['args']['method'] );
		$this->assertSame( 0, $render[0]['args']['redirection'] );
		$this->assertSame( 200, $raw['site']['render']['status'] );
		$this->assertSame( 'main', $raw['site']['render']['content_region'] );
		$this->assertArrayNotHasKey( 'body', $raw['site']['render'], 'The body is analysed, not kept.' );

		// The configured selector is used by the analysis.
		update_option( Settings::OPTION, array( 'content_selector' => 'div.custom' ) );
		Plugin::instance()->get( 'settings' )->flush_cache();
		$this->responses[ $url ] = array(
			'code'    => 200,
			'headers' => array( 'content-type' => 'text/html' ),
			'body'    => '<html><body><main><p>Main</p></main><div class="custom"><p>Custom</p></div></body></html>',
		);
		$this->assertSame( 'div.custom', $this->probe->probe_site()['render']['content_region'] );
		$this->assertGreaterThan( 0, $post );
	}

	public function test_report_render_check_ok_with_region() {
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$report = $this->controller->run();
		$this->assertSame( Report::OK, $report['site']['render']['status'] );
		$this->assertSame( 'Rendered page (loopback): HTTP 200, text/html; charset=utf-8, content region main.', $report['site']['render']['message'] );
		$this->assertSame( array( 'markdown_url', 'render', 'storage' ), array_slice( array_keys( $report['site'] ), -3 ) );
	}

	public function test_report_render_check_warns_without_region() {
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$url                     = home_url( '/muestra/?wpasl_render=1' );
		$this->responses[ $url ] = array(
			'code'    => 200,
			'headers' => array( 'content-type' => 'text/html; charset=utf-8' ),
			'body'    => '<html><body><div class="wrap"><p>No region here.</p></div></body></html>',
		);
		$report                  = $this->controller->run();
		$this->assertSame( Report::WARNING, $report['site']['render']['status'] );
		$this->assertStringContainsString( 'no content region found', $report['site']['render']['message'] );
		$this->assertStringContainsString( 'the whole body would be used', $report['site']['render']['message'] );
		$this->assertStringContainsString( 'Set the content selector', $report['site']['render']['message'] );

		$this->responses[ $url ] = array(
			'code'    => 301,
			'headers' => array( 'location' => 'https://www.example.org/muestra/' ),
		);
		$report                  = $this->controller->run();
		$this->assertSame( Report::WARNING, $report['site']['render']['status'] );
		$this->assertStringContainsString( 'HTTP 301 redirect to https://www.example.org/muestra/', $report['site']['render']['message'] );
	}

	public function test_report_render_check_errors_on_block_markdown_or_connection_error() {
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$url = home_url( '/muestra/?wpasl_render=1' );

		$this->responses[ $url ] = array(
			'code'    => 403,
			'headers' => array( 'content-type' => 'text/html' ),
			'body'    => 'blocked',
		);
		$report                  = $this->controller->run();
		$this->assertSame( Report::ERROR, $report['site']['render']['status'] );
		$this->assertStringContainsString( 'Rendered page (loopback): HTTP 403', $report['site']['render']['message'] );
		$this->assertStringContainsString( 'items whose content source is the rendered page are served with the editor content', $report['site']['render']['message'] );

		$this->responses[ $url ] = array(
			'code'    => 200,
			'headers' => array( 'content-type' => 'text/markdown; charset=utf-8' ),
			'body'    => "---\ntitle: x\n---\n",
		);
		$report                  = $this->controller->run();
		$this->assertSame( Report::ERROR, $report['site']['render']['status'] );
		$this->assertStringContainsString( 'the render marker was ignored', $report['site']['render']['message'] );
		$this->assertStringContainsString( 'served with the editor content', $report['site']['render']['message'] );

		$this->responses[ $url ] = new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
		$report                  = $this->controller->run();
		$this->assertSame( Report::ERROR, $report['site']['render']['status'] );
		$this->assertStringContainsString( 'HTTP 0 cURL error 7: Failed to connect; the site cannot fetch its own pages', $report['site']['render']['message'] );
		foreach ( array( 'markdown_url', 'storage' ) as $key ) {
			$this->assertSame( Report::OK, $report['site'][ $key ]['status'], 'The other site checks are unaffected.' );
		}
	}

	public function test_tab_shows_render_failures_notice_and_hides_it_without_failures() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$state                     = new WPASL\Generation\State();
		$data                      = $state->load();
		$data['render_failed']     = array(
			21 => 1,
			22 => 3,
		);
		$data['last_render_error'] = array(
			'post_id' => 22,
			'reason'  => 'redirect_external_host',
			'time'    => 1700000000,
		);
		$state->save( $data, false );

		$html = $this->render_diagnostics_tab();
		$this->assertStringContainsString( 'wpasl-render-failures', $html );
		$this->assertStringContainsString( 'The rendered page of 2 items could not be fetched from this server.', $html );
		$this->assertStringContainsString( 'Last failure: redirect_external_host (#22, ' . wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), 1700000000 ) . ').', $html );
		$this->assertStringContainsString( 'does not accept HTTP requests to itself (loopback)', $html );
		$this->assertStringContainsString( 'blocks the plugin user-agent', $html );
		$this->assertStringContainsString( 'redirects to another host or scheme', $html );
		$this->assertStringContainsString( 'longer than the request timeout', $html );
		$this->assertStringContainsString( 'served with their editor content until the loopback works', $html );
		$this->assertNull( Report::load(), 'No simulation needed.' );

		$state->reset();
		$html = $this->render_diagnostics_tab();
		$this->assertStringNotContainsString( 'wpasl-render-failures', $html );
		$this->assertStringNotContainsString( 'could not be fetched from this server', $html );
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
		$this->assertStringContainsString( "curl -s '" . home_url( '/llms.txt' ) . "'\ncurl -s '" . home_url( '/llms-page.txt' ) . "'\ncurl -s '" . home_url( '/llms-post.txt' ) . "'\ncurl -s '" . home_url( '/auth.md' ) . "'\ncurl -s '" . home_url( '/agent-skills.json' ) . "'", $html );
		$this->assertStringContainsString( 'Do not cache or transform robots.txt, llms.txt, the llms-<type>.txt files, auth.md, agent-skills.json and /.well-known/api-catalog', $html );
		$this->assertStringContainsString( 'accepts HTTP requests from the site to itself (loopback)', $html );
		$this->assertStringContainsString( "curl -s -H 'X-WPASL-Render: 1' '" . home_url( '/muestra/?wpasl_render=1' ) . "'", $html );

		update_option( Settings::OPTION, array( 'post_types' => array( 'post' ) ) );
		Plugin::instance()->get( 'settings' )->flush_cache();
		ob_start();
		$tabs['diagnostics']->render();
		$html = html_entity_decode( ob_get_clean(), ENT_QUOTES, 'UTF-8' );
		$this->assertStringContainsString( "curl -s '" . home_url( '/llms-post.txt' ) . "'", $html );
		$this->assertStringNotContainsString( "curl -s '" . home_url( '/llms-page.txt' ) . "'", $html, 'Only the enabled post types.' );
	}

	public function test_report_checks_type_files() {
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$report = $this->controller->run();
		$this->assertSame( Report::OK, $report['site']['llms-page']['status'] );
		$this->assertSame( Report::OK, $report['site']['llms-post']['status'] );
		$this->assertStringContainsString( 'llms-page.txt: HTTP 200, text/markdown', $report['site']['llms-page']['message'] );
		$keys = array_keys( $report['site'] );
		$this->assertSame( array( 'llms', 'llms-page', 'llms-post', 'auth' ), array_slice( $keys, array_search( 'llms', $keys, true ), 4 ), 'Between llms.txt and auth.md.' );

		$this->responses[ home_url( '/llms-post.txt' ) ] = array(
			'code'    => 404,
			'headers' => array( 'content-type' => 'text/html; charset=utf-8' ),
		);
		$report = $this->controller->run();
		$this->assertSame( Report::OK, $report['site']['llms-page']['status'] );
		$this->assertSame( Report::ERROR, $report['site']['llms-post']['status'] );
		$this->assertStringContainsString( 'llms-post.txt: HTTP 404', $report['site']['llms-post']['message'] );

		$this->responses[ home_url( '/llms-post.txt' ) ] = array(
			'code'    => 200,
			'headers' => array( 'content-type' => 'text/html; charset=utf-8' ),
		);
		$report = $this->controller->run();
		$this->assertSame( Report::WARNING, $report['site']['llms-post']['status'] );

		update_option( Settings::OPTION, array( 'post_types' => array( 'post' ) ) );
		Plugin::instance()->get( 'settings' )->flush_cache();
		$report = $this->controller->run();
		$this->assertArrayNotHasKey( 'llms-page', $report['site'], 'Only the enabled post types are probed.' );
		$this->assertArrayHasKey( 'llms-post', $report['site'] );
	}

	public function test_report_warns_on_large_llms_txt() {
		self::factory()->post->create( array( 'post_name' => 'muestra' ) );
		$url = home_url( '/llms.txt' );

		$this->responses[ $url ] = array(
			'code'    => 200,
			'headers' => array( 'content-type' => 'text/markdown; charset=utf-8' ),
			'body'    => str_repeat( 'a', 82000 ),
		);
		$report                  = $this->controller->run();
		$this->assertSame( Report::WARNING, $report['site']['llms']['status'] );
		$this->assertStringContainsString( 'llms.txt: HTTP 200, text/markdown; charset=utf-8, but 82,000 characters; agents expect at most 30,000.', $report['site']['llms']['message'] );
		$this->assertStringContainsString( 'Lower "Items per section in llms.txt"', $report['site']['llms']['message'] );

		$raw = $this->probe->run();
		$this->assertSame( 82000, $raw['site']['llms']['body_length'], 'Measured before the cut.' );
		$this->assertSame( CrawlerProbe::MAX_BODY_KEPT, strlen( $raw['site']['llms']['body'] ) );

		$this->responses[ $url ]['body'] = str_repeat( 'a', 20000 );
		$report                          = $this->controller->run();
		$this->assertSame( Report::OK, $report['site']['llms']['status'] );
		$this->assertStringContainsString( 'llms.txt: HTTP 200, text/markdown', $report['site']['llms']['message'] );
	}
}
