<?php
/**
 * auth.md tests.
 *
 * @package WPASL
 */

use WPASL\Http;
use WPASL\Manifest\AuthMdBuilder;
use WPASL\Manifest\AuthMdRouter;
use WPASL\Plugin;
use WPASL\Settings;
use WPASL\Storage;

/**
 * Covers WPASL\Manifest\AuthMdBuilder and AuthMdRouter and the reserved /auth.md route in Delivery.
 */
class Test_Auth_Md extends WP_UnitTestCase {

	/**
	 * @var AuthMdBuilder
	 */
	private $builder;

	/**
	 * @var AuthMdRouter
	 */
	private $router;

	/**
	 * @var Storage
	 */
	private $storage;

	/**
	 * Headers in effect at the point where production ends the request.
	 *
	 * @var array<string, string[]>|null
	 */
	private $served_headers;

	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->builder = Plugin::instance()->get( 'auth_md' );
		$this->router  = Plugin::instance()->get( 'auth_md_router' );
		$this->storage = Plugin::instance()->get( 'storage' );
		Plugin::instance()->get( 'runner' )->clear();
		add_filter( 'wpasl_terminate_after_serve', '__return_false' );
		add_filter( 'pre_http_request', array( $this, 'block_http' ), 10, 3 );
		Http::reset();
	}

	public function block_http( $pre, $args, $url ) {
		return new WP_Error( 'blocked', 'No HTTP in tests: ' . $url );
	}

	public function tear_down() {
		remove_filter( 'wpasl_terminate_after_serve', '__return_false' );
		remove_filter( 'wpasl_terminate_after_serve', array( $this, 'capture_served_headers' ), 20 );
		remove_filter( 'pre_http_request', array( $this, 'block_http' ), 10 );
		remove_filter( 'redirect_canonical', '__return_false' );
		remove_all_filters( 'wpasl_physical_auth_md_path' );
		remove_all_filters( 'wpasl_auth_md' );
		unset( $_SERVER['HTTP_ACCEPT'] );
		Plugin::instance()->get( 'runner' )->clear();
		delete_option( Settings::OPTION );
		Plugin::instance()->get( 'settings' )->flush_cache();
		parent::tear_down();
	}

	private function settings( array $values ) {
		update_option( Settings::OPTION, $values );
		Plugin::instance()->get( 'settings' )->flush_cache();
	}

	/**
	 * Requests a URL through parse_request and returns the output.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function request( $url ) {
		ob_start();
		$this->go_to( $url );
		return ob_get_clean();
	}

	/**
	 * Records the headers in effect where production calls exit; the test suite lets WordPress carry on
	 * (send_headers then adds the HTML set), so the snapshot is what a client receives.
	 *
	 * @param bool $terminate Whether to exit.
	 * @return bool
	 */
	public function capture_served_headers( $terminate ) {
		$this->served_headers = Http::effective_headers();
		return $terminate;
	}

	private function expected_h1() {
		return '# ' . get_bloginfo( 'name' ) . " auth.md\n";
	}

	public function test_route_serves_auth_md_with_lazy_generation() {
		$this->assertFalse( $this->storage->exists( AuthMdBuilder::FILE ) );
		add_filter( 'wpasl_terminate_after_serve', array( $this, 'capture_served_headers' ), 20 );

		$out = $this->request( home_url( '/auth.md' ) );

		$this->assertStringStartsWith( $this->expected_h1(), $out );
		$this->assertTrue( $this->storage->exists( AuthMdBuilder::FILE ), 'Generated on demand and stored.' );

		$headers = $this->served_headers;
		$this->assertSame( array( 'text/markdown; charset=utf-8' ), $headers['content-type'] );
		$this->assertSame( array( (string) (int) ceil( strlen( $out ) / 4 ) ), $headers['x-markdown-tokens'] );
		$this->assertSame( array( 'public, max-age=' . Plugin::instance()->get( 'delivery' )->max_age() ), $headers['cache-control'] );
		$this->assertSame( array( 'nosniff' ), $headers['x-content-type-options'] );
		$this->assertSame( array( 'search=yes, ai-input=yes, ai-train=no' ), $headers['content-signal'] );
		$this->assertArrayNotHasKey( 'x-robots-tag', $headers, 'A non-HTML response carries no noai directive.' );
		$this->assertArrayNotHasKey( 'link', $headers, 'No api-catalog Link: the document itself links the catalog.' );

		$this->storage->write( AuthMdBuilder::FILE, "# Stored auth.md\n" );
		$this->assertSame( "# Stored auth.md\n", $this->request( home_url( '/auth.md' ) ), 'The stored document is served without regenerating.' );
	}

	public function test_route_is_404_when_disabled() {
		$this->settings( array( 'auth_md_enabled' => false ) );
		$this->assertSame( '', $this->request( home_url( '/auth.md' ) ) );
		$this->assertTrue( is_404() );
		$this->assertFalse( $this->storage->exists( AuthMdBuilder::FILE ) );
	}

	public function test_route_is_404_when_manifests_disabled() {
		$this->settings(
			array(
				'manifest_enabled' => false,
				'auth_md_enabled'  => true,
			)
		);
		$this->assertFalse( $this->builder->enabled() );
		$this->assertSame( '', $this->request( home_url( '/auth.md' ) ) );
		$this->assertTrue( is_404() );
	}

	public function test_physical_file_takes_precedence() {
		$file = wp_tempnam( 'auth' );
		file_put_contents( $file, '# physical auth.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		add_filter(
			'wpasl_physical_auth_md_path',
			static function () use ( $file ) {
				return $file;
			}
		);
		$this->assertTrue( AuthMdRouter::physical_file_exists() );
		$this->assertSame( '', $this->request( home_url( '/auth.md' ) ), 'The plugin steps aside; the web server serves the physical file.' );
		$this->assertFalse( $this->storage->exists( AuthMdBuilder::FILE ) );
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	public function test_non_canonical_root_paths_are_not_served() {
		foreach ( array( '/auth.md/', '//auth.md' ) as $path ) {
			$this->assertFalse( AuthMdRouter::requested( $path ), $path );
			$this->assertSame( '', $this->request( untrailingslashit( home_url() ) . $path ), $path . ' is left to core.' ); // home_url() would collapse the double slash.
			$this->assertTrue( is_404(), $path );
		}
		$this->assertTrue( AuthMdRouter::requested( '/auth.md' ) );
		$this->assertTrue( AuthMdRouter::requested( '/auth.md?x=1' ) );
		$this->assertTrue( AuthMdRouter::requested( home_url( '/auth.md' ) ) );
		$this->assertFalse( AuthMdRouter::requested( '/auth/.md' ) );
		$this->assertFalse( AuthMdRouter::requested( '/x/auth.md' ) );
	}

	public function test_page_with_slug_auth_loses_the_suffix_but_keeps_accept_and_query_arg() {
		$page = self::factory()->post->create_and_get(
			array(
				'post_type'  => 'page',
				'post_name'  => 'auth',
				'post_title' => 'Auth page',
			)
		);
		$this->assertSame( home_url( '/auth/' ), get_permalink( $page ) );
		$delivery = Plugin::instance()->get( 'delivery' );

		$out = $this->request( home_url( '/auth.md' ) );
		$this->assertStringStartsWith( $this->expected_h1(), $out, 'The root file wins over the page.' );
		$this->assertStringNotContainsString( '# Auth page', $out );

		$this->settings( array( 'auth_md_enabled' => false ) );
		$this->assertSame( '', $this->request( home_url( '/auth.md' ) ), 'Disabled: the reserved path never resolves to the page.' );
		$this->assertTrue( is_404() );

		foreach ( array( true, false ) as $enabled ) {
			$this->settings( array( 'auth_md_enabled' => $enabled ) );
			$label = $enabled ? 'enabled' : 'disabled';

			$_SERVER['HTTP_ACCEPT'] = 'text/markdown';
			$this->go_to( home_url( '/auth/' ) );
			ob_start();
			$served = $delivery->maybe_serve();
			$out    = ob_get_clean();
			unset( $_SERVER['HTTP_ACCEPT'] );
			$this->assertTrue( $served, 'Accept negotiation, auth.md ' . $label );
			$this->assertStringContainsString( "# Auth page\n", $out );

			$this->go_to( home_url( '/auth/?wpasl=md' ) );
			ob_start();
			$served = $delivery->maybe_serve();
			$out    = ob_get_clean();
			$this->assertTrue( $served, 'Query argument, auth.md ' . $label );
			$this->assertStringContainsString( "# Auth page\n", $out );

			$out = $this->request( home_url( '/auth/.md' ) );
			$this->assertStringContainsString( "# Auth page\n", $out, '/auth/.md is not the reserved path, auth.md ' . $label );
		}

		$this->assertSame( home_url( '/auth/?wpasl=md' ), $delivery->markdown_url( $page ), 'The announced alternate URL avoids the reserved path.' );
		$this->assertStringContainsString( '<' . home_url( '/auth/?wpasl=md' ) . '>; rel="alternate"', $delivery->html_headers( $page )['Link'] );
		$this->assertStringContainsString( '](' . home_url( '/auth/?wpasl=md' ) . ')', Plugin::instance()->get( 'llms' )->build() );

		$other = self::factory()->post->create_and_get( array( 'post_type' => 'page', 'post_name' => 'author-notes', 'post_title' => 'Other' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( home_url( '/author-notes.md' ), $delivery->markdown_url( $other ), 'Only the exact slug is affected.' );
	}

	public function test_reserved_path_respects_base_path() {
		$home = static function () {
			return 'http://example.org/blog';
		};
		add_filter( 'pre_option_home', $home );
		$this->assertTrue( AuthMdRouter::requested( '/blog/auth.md' ) );
		$this->assertFalse( AuthMdRouter::requested( '/auth.md' ) );
		$this->assertFalse( AuthMdRouter::requested( '/blogx/auth.md' ) );

		$out = $this->request( 'http://example.org/blog/auth.md' );
		$this->assertStringStartsWith( $this->expected_h1(), $out );
		$this->assertStringContainsString( 'http://example.org/blog/llms.txt', $out );

		$out = $this->request( 'http://example.org/auth.md' );
		remove_filter( 'pre_option_home', $home );
		$this->assertSame( '', $out, '/auth.md outside the base path is not the document.' );
		$this->assertTrue( is_404() );
	}

	public function test_document_structure() {
		update_option( 'blogname', 'Cognos Online' );
		$doc = $this->builder->document();

		$this->assertStringStartsWith( "# Cognos Online auth.md\n\n> How automated agents may access " . home_url( '/' ) . '.', $doc );
		$sections = array(
			'## Audience',
			'## Registration and credential provisioning',
			'## Supported access methods',
			'### Public endpoints',
			'### Discovery documents',
			'## Credential use',
			'## Usage policy',
			'## Contact',
		);
		$last     = -1;
		foreach ( $sections as $heading ) {
			$position = strpos( $doc, "\n" . $heading . "\n" );
			$this->assertNotFalse( $position, $heading );
			$this->assertGreaterThan( $last, $position, $heading . ' keeps its order.' );
			$last = $position;
		}
		$this->assertStringNotContainsString( '## Notes', $doc );

		$this->assertStringContainsString( 'AI agents, LLM-based assistants and AI crawlers', $doc );
		$this->assertStringContainsString( 'This site does not offer agent registration or credential provisioning.', $doc );
		$this->assertStringContainsString( 'no authorization server', $doc );
		$this->assertStringContainsString( 'Do not attempt to register', $doc );
		$this->assertStringContainsString( 'Anonymous HTTP GET requests, without any credential.', $doc );

		$this->assertStringContainsString( '- **Search content** — GET ' . rest_url( 'wp/v2/search' ) . ': ', $doc );
		$this->assertStringContainsString( '- **List Posts** — GET ' . rest_url( 'wp/v2/posts' ) . ': ', $doc );
		$this->assertStringContainsString( '- **Read a Post** — GET ' . rest_url( 'wp/v2/posts/{id}' ) . ': ', $doc );
		$this->assertStringContainsString( '- **List Pages** — GET ' . rest_url( 'wp/v2/pages' ) . ': ', $doc );
		$this->assertStringContainsString( '- **Read a Page** — GET ' . rest_url( 'wp/v2/pages/{id}' ) . ': ', $doc );
		$this->assertStringContainsString( '- **Read content as Markdown** — GET ' . home_url( '/{+path}.md' ) . ': ', $doc );
		$this->assertStringContainsString( '- **Site index (llms.txt)** — GET ' . home_url( '/llms.txt' ) . ': ', $doc );
		$this->assertStringContainsString( '- **API description (OpenAPI 3.1)** — GET ' . rest_url( 'wpasl/v1/openapi' ) . ': ', $doc );
		$this->assertStringNotContainsString( 'GET ' . home_url( '/auth.md' ), $doc, 'The document does not list itself.' );

		$this->assertStringContainsString( '- llms.txt: ' . home_url( '/llms.txt' ) . "\n", $doc );
		$this->assertStringContainsString( '- API catalog (RFC 9727): ' . home_url( '/.well-known/api-catalog' ) . "\n", $doc );
		$this->assertStringContainsString( '- Agent skills (JSON-LD): ' . home_url( '/agent-skills.json' ) . "\n", $doc );
		$this->assertStringContainsString( '- OpenAPI 3.1: ' . rest_url( 'wpasl/v1/openapi' ) . "\n", $doc );
		$this->assertStringContainsString( 'append `.md` to its URL, or request it with `Accept: text/markdown`.', $doc );

		$this->assertStringContainsString( 'No credential is required or accepted for the resources above.', $doc );
		$this->assertStringContainsString( 'Authenticated and write operations of the WordPress REST API are not offered to agents', $doc );
		$this->assertStringContainsString( 'Content signals: search=yes, ai-input=yes, ai-train=no. See ' . home_url( '/robots.txt' ) . ".\n", $doc );
		$this->assertStringEndsWith( "## Contact\n\nTechnical contact: " . get_option( 'admin_email' ) . "\n", $doc );

		$this->settings( array( 'signal_ai_train' => 'yes' ) );
		$this->assertStringContainsString( 'search=yes, ai-input=yes, ai-train=yes.', $this->builder->document() );
	}

	public function test_contact_email_falls_back_to_admin_email() {
		$this->assertStringContainsString( 'Technical contact: ' . get_option( 'admin_email' ) . "\n", $this->builder->document() );
		$this->settings( array( 'contact_email' => 'agents@example.org' ) );
		$this->assertStringContainsString( "Technical contact: agents@example.org\n", $this->builder->document() );
		$this->assertStringNotContainsString( get_option( 'admin_email' ), $this->builder->document() );
	}

	public function test_admin_notes_section() {
		$this->settings( array( 'auth_md_notes' => "Rate limit: 60 requests per minute.\n\n- Prefer the search endpoint." ) );
		$doc = $this->builder->document();
		$this->assertStringEndsWith( "## Notes\n\nRate limit: 60 requests per minute.\n\n- Prefer the search endpoint.\n", $doc );
		$this->assertLessThan( strpos( $doc, '## Notes' ), strpos( $doc, '## Contact' ), 'Notes come last.' );

		$this->assertStringContainsString( 'Rate limit: 60 requests per minute.', $this->request( home_url( '/auth.md' ) ) );

		$this->settings( array( 'auth_md_notes' => '' ) );
		$this->assertStringNotContainsString( '## Notes', $this->builder->document() );
	}

	public function test_document_never_mentions_oauth_or_application_passwords() {
		$this->settings( array( 'auth_md_notes' => 'Be gentle.' ) );
		$doc = $this->request( home_url( '/auth.md' ) );
		foreach ( array( 'oauth', 'agent_auth', 'application password', 'application-password' ) as $forbidden ) {
			$this->assertFalse( stripos( $doc, $forbidden ), $forbidden );
		}
		$this->assertStringContainsString( 'No credential is required or accepted', $doc );
	}

	public function test_plain_permalinks_describe_query_arg_and_rest_route() {
		$this->set_permalink_structure( '' );
		$doc = $this->builder->document();
		$this->assertStringContainsString( 'add `?wpasl=md` to its URL, or request it with `Accept: text/markdown`.', $doc );
		$this->assertStringNotContainsString( 'append `.md`', $doc );
		$this->assertStringContainsString( '- **Read content as Markdown** — GET ' . home_url( '/?p={id}&wpasl=md' ) . ': ', $doc );
		$this->assertStringContainsString( '?rest_route=/wp/v2/search', $doc );
		$this->assertStringContainsString( 'GET ' . rest_url( 'wp/v2/posts' ) . ': ', $doc );
		$this->assertStringContainsString( '- OpenAPI 3.1: ' . rest_url( 'wpasl/v1/openapi' ) . "\n", $doc );
	}

	public function test_filter_changes_the_document() {
		add_filter(
			'wpasl_auth_md',
			static function ( $markdown ) {
				return $markdown . "\n## Rate limits\n\nBe gentle.\n";
			}
		);
		$out = $this->request( home_url( '/auth.md' ) );
		$this->assertStringStartsWith( $this->expected_h1(), $out );
		$this->assertStringEndsWith( "## Rate limits\n\nBe gentle.\n", $out );
	}

	public function test_settings_change_invalidates_stored_file() {
		$this->assertStringNotContainsString( '## Notes', $this->router->document() );
		$this->assertTrue( $this->storage->exists( AuthMdBuilder::FILE ) );

		$settings                  = Settings::defaults();
		$settings['auth_md_notes'] = 'Rate limit: 60 requests per minute.';
		update_option( Settings::OPTION, $settings );

		$this->assertFalse( $this->storage->exists( AuthMdBuilder::FILE ) );
		$this->assertStringContainsString( "## Notes\n\nRate limit: 60 requests per minute.\n", $this->request( home_url( '/auth.md' ) ) );
	}

	public function test_builder_is_registered_as_artifact_generator_and_respects_the_toggle() {
		$runner = Plugin::instance()->get( 'runner' );
		$this->assertSame( 'auth-md', $this->builder->id() );
		$this->assertContains( 'auth-md', $runner->status()['artifacts'] );

		$runner->run_cycle();
		$this->assertTrue( $this->storage->exists( AuthMdBuilder::FILE ) );
		$this->assertStringStartsWith( $this->expected_h1(), $this->storage->read( AuthMdBuilder::FILE ) );

		$this->settings( array( 'auth_md_enabled' => false ) );
		$this->storage->write( AuthMdBuilder::FILE, "# stale\n" );
		$runner->run_cycle();
		$this->assertFalse( $this->storage->exists( AuthMdBuilder::FILE ), 'Disabled: the scheduled regeneration removes the stored document.' );
	}
}
