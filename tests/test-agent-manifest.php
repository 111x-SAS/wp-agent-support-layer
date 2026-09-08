<?php
/**
 * Agent manifest tests.
 *
 * @package WPASL
 */

use WPASL\Manifest\CapabilityRegistry;
use WPASL\Manifest\ManifestBuilder;
use WPASL\Manifest\ManifestRouter;
use WPASL\Plugin;
use WPASL\Settings;
use WPASL\Storage;

/**
 * Covers WPASL\Manifest\*.
 */
class Test_Agent_Manifest extends WP_UnitTestCase {

	/**
	 * @var ManifestBuilder
	 */
	private $builder;

	/**
	 * @var ManifestRouter
	 */
	private $router;

	/**
	 * @var Storage
	 */
	private $storage;

	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->builder = Plugin::instance()->get( 'manifest' );
		$this->router  = Plugin::instance()->get( 'manifest_router' );
		$this->storage = Plugin::instance()->get( 'storage' );
		Plugin::instance()->get( 'runner' )->clear();
		add_filter( 'wpasl_terminate_after_serve', '__return_false' );
	}

	public function tear_down() {
		remove_filter( 'wpasl_terminate_after_serve', '__return_false' );
		remove_all_filters( 'wpasl_agent_capabilities' );
		Plugin::instance()->get( 'runner' )->clear();
		delete_option( Settings::OPTION );
		Plugin::instance()->get( 'settings' )->flush_cache();
		parent::tear_down();
	}

	private function settings( array $values ) {
		update_option( Settings::OPTION, $values );
		Plugin::instance()->get( 'settings' )->flush_cache();
	}

	private function ids( array $capabilities ) {
		return array_column( $capabilities, 'id' );
	}

	public function test_default_capabilities_cover_rest_markdown_index_and_openapi() {
		$registry = new CapabilityRegistry( Plugin::instance()->get( 'settings' ) );
		$caps     = $registry->all();
		$ids      = $this->ids( $caps );
		foreach ( array( 'search-content', 'list-post', 'read-post', 'list-page', 'read-page', 'read-markdown', 'site-index', 'auth-md', 'openapi' ) as $id ) {
			$this->assertContains( $id, $ids, $id );
		}
		foreach ( $caps as $cap ) {
			$this->assertSame( 'none', $cap['authentication'] );
			$this->assertSame( 'GET', $cap['method'] );
			$this->assertTrue( isset( $cap['url'] ) || isset( $cap['urlTemplate'] ) );
			foreach ( $cap['parameters'] as $param ) {
				$this->assertArrayHasKey( 'name', $param );
				$this->assertContains( $param['in'], array( 'query', 'path' ) );
				$this->assertIsBool( $param['required'] );
			}
		}
		$read = $caps[ array_search( 'read-post', $ids, true ) ];
		$this->assertSame( rest_url( 'wp/v2/posts/{id}' ), $read['urlTemplate'] );
		$this->assertTrue( $read['parameters'][0]['required'] );
		$markdown = $caps[ array_search( 'read-markdown', $ids, true ) ];
		$this->assertSame( home_url( '/{+path}.md' ), $markdown['urlTemplate'] );
		$this->assertSame( 'text/markdown', $markdown['responseType'] );
		$auth = $caps[ array_search( 'auth-md', $ids, true ) ];
		$this->assertSame( home_url( '/auth.md' ), $auth['url'] );
		$this->assertSame( 'text/markdown', $auth['responseType'] );
		$this->assertStringNotContainsString( 'auth.md', wp_json_encode( array_keys( $this->builder->openapi()['paths'] ) ), 'OpenAPI keeps describing REST paths only.' );
	}

	public function test_post_type_without_rest_gets_no_rest_capabilities() {
		register_post_type(
			'wpasl_book',
			array(
				'public'       => true,
				'show_in_rest' => false,
			)
		);
		$this->settings( array( 'post_types' => array( 'post', 'wpasl_book' ) ) );
		$ids = $this->ids( ( new CapabilityRegistry( Plugin::instance()->get( 'settings' ) ) )->all() );
		$this->assertContains( 'list-post', $ids );
		$this->assertNotContains( 'list-wpasl_book', $ids );
		$this->assertNotContains( 'read-wpasl_book', $ids );
		$this->assertNotContains( 'list-page', $ids, 'Disabled type is not declared.' );
		$this->assertContains( 'read-markdown', $ids );
		unregister_post_type( 'wpasl_book' );
	}

	public function test_filter_adds_a_capability_and_authenticated_ones_are_dropped() {
		add_filter(
			'wpasl_agent_capabilities',
			static function ( $caps ) {
				$caps[] = array(
					'id'          => 'book-appointment',
					'name'        => 'Book an appointment',
					'description' => 'Public booking form endpoint.',
					'method'      => 'get',
					'url'         => 'https://example.org/wp-json/booking/v1/slots',
					'parameters'  => array(
						array(
							'name'     => 'date',
							'type'     => 'string',
							'required' => true,
						),
					),
				);
				$caps[] = array(
					'id'             => 'delete-everything',
					'url'            => 'https://example.org/wp-json/wp/v2/posts',
					'authentication' => 'cookie',
				);
				return $caps;
			}
		);
		$caps = ( new CapabilityRegistry( Plugin::instance()->get( 'settings' ) ) )->all();
		$ids  = $this->ids( $caps );
		$this->assertContains( 'book-appointment', $ids );
		$this->assertNotContains( 'delete-everything', $ids );
		$added = $caps[ array_search( 'book-appointment', $ids, true ) ];
		$this->assertSame( 'GET', $added['method'] );
		$this->assertSame( 'none', $added['authentication'] );
		$this->assertSame( 'query', $added['parameters'][0]['in'] );
		$this->assertTrue( $added['parameters'][0]['required'] );
	}

	public function test_agent_skills_document() {
		$this->settings( array( 'contact_email' => 'agents@example.org' ) );
		$doc = $this->builder->agent_skills();
		$this->assertArrayHasKey( '@context', $doc );
		$this->assertSame( 'WebSite', $doc['@type'] );
		$this->assertSame( home_url( '/' ), $doc['url'] );
		$this->assertSame( 'agents@example.org', $doc['publisher']['email'] );
		$this->assertSame( '1.0', $doc['manifestVersion'] );
		$this->assertNotEmpty( $doc['capabilities'] );
		$this->assertSame( 'no', $doc['contentSignals']['ai-train'] );
		$json = ManifestBuilder::encode( $doc );
		$this->assertNotNull( json_decode( $json, true ) );
	}

	public function test_agent_skills_route() {
		ob_start();
		$this->go_to( home_url( '/agent-skills.json' ) );
		$out  = ob_get_clean();
		$data = json_decode( $out, true );
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( '@context', $data );
		$this->assertNotEmpty( $data['capabilities'] );
		$this->assertSame( get_option( 'admin_email' ), $data['publisher']['email'], 'Falls back to the admin email.' );
		$this->assertSame( 'application/ld+json; charset=utf-8', $this->router->headers( ManifestRouter::SKILLS_PATH )['Content-Type'] );
		$this->assertTrue( $this->storage->exists( ManifestBuilder::SKILLS_FILE ) );
		$this->assertTrue( $this->storage->exists( ManifestBuilder::OPENAPI_FILE ) );
	}

	public function test_routes_are_404_when_disabled() {
		$this->settings( array( 'manifest_enabled' => false ) );
		foreach ( array( '/agent-skills.json', '/.well-known/api-catalog' ) as $path ) {
			ob_start();
			$this->go_to( home_url( $path ) );
			$out = ob_get_clean();
			$this->assertSame( '', $out, $path );
			$this->assertTrue( is_404(), $path );
		}
		$response = $this->router->rest_openapi();
		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	public function test_openapi_document() {
		$doc = $this->builder->openapi();
		$this->assertSame( '3.1.0', $doc['openapi'] );
		$this->assertArrayHasKey( 'title', $doc['info'] );
		$this->assertSame( get_option( 'admin_email' ), $doc['info']['contact']['email'] );
		$this->assertSame( untrailingslashit( rest_url() ), $doc['servers'][0]['url'] );
		$this->assertNotEmpty( $doc['paths'] );

		$this->assertArrayHasKey( '/wp/v2/posts', $doc['paths'] );
		$this->assertSame( array( 'get' ), array_keys( $doc['paths']['/wp/v2/posts'] ) );
		$this->assertSame( 'array', $doc['paths']['/wp/v2/posts']['get']['responses']['200']['content']['application/json']['schema']['type'] );

		$this->assertArrayHasKey( '/wp/v2/posts/{id}', $doc['paths'] );
		$single = $doc['paths']['/wp/v2/posts/{id}']['get'];
		$this->assertSame( 'path', $single['parameters'][0]['in'] );
		$this->assertTrue( $single['parameters'][0]['required'] );
		$this->assertSame( 'post', $single['responses']['200']['content']['application/json']['schema']['title'] );

		$this->assertArrayHasKey( '/wp/v2/search', $doc['paths'] );
		$this->assertArrayHasKey( '/wpasl/v1/openapi', $doc['paths'] );
		$this->assertArrayNotHasKey( '/{path}.md', $doc['paths'], 'Non-REST capabilities are not paths.' );
		$this->assertArrayHasKey( 'Error', $doc['components']['schemas'] );
	}

	public function test_openapi_error_schema_and_responses() {
		$doc   = $this->builder->openapi();
		$error = $doc['components']['schemas']['Error'];
		$this->assertSame( 'object', $error['type'] );
		$this->assertSame( array( 'code', 'message' ), $error['required'] );
		$this->assertSame( 'string', $error['properties']['code']['type'] );
		$this->assertSame( 'string', $error['properties']['message']['type'] );
		$this->assertSame( 'object', $error['properties']['data']['type'] );
		$this->assertSame( 'integer', $error['properties']['data']['properties']['status']['type'] );
		$this->assertTrue( $error['properties']['data']['additionalProperties'] );
		$this->assertTrue( $error['additionalProperties'] );

		foreach ( array( '/wp/v2/posts', '/wp/v2/posts/{id}', '/wp/v2/search', '/wpasl/v1/openapi' ) as $path ) {
			$responses = $doc['paths'][ $path ]['get']['responses'];
			$this->assertSame( array( '200', '400', '404', 'default' ), array_map( 'strval', array_keys( $responses ) ), $path );
			foreach ( array( '400', '404', 'default' ) as $code ) {
				$this->assertSame( '#/components/schemas/Error', $responses[ $code ]['content']['application/json']['schema']['$ref'], $path . ' ' . $code );
				$this->assertNotEmpty( $responses[ $code ]['description'], $path . ' ' . $code );
			}
		}
		$this->assertSame( 'Invalid parameter.', $doc['paths']['/wp/v2/posts']['get']['responses']['400']['description'] );
		$this->assertSame( 'Not found.', $doc['paths']['/wp/v2/posts']['get']['responses']['404']['description'] );
	}

	public function test_openapi_has_no_problem_json() {
		$json = ManifestBuilder::encode( $this->builder->openapi() );
		$this->assertStringNotContainsString( 'application/problem+json', $json );
		$this->assertStringNotContainsString( 'problem+json', $json );
		$this->assertNotNull( json_decode( $json, true ) );
	}

	public function test_openapi_rest_route() {
		do_action( 'rest_api_init' );
		$request  = new WP_REST_Request( 'GET', '/wpasl/v1/openapi' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( '3.1.0', $data['openapi'] );
		$headers = $response->get_headers();
		$this->assertStringStartsWith( 'public, max-age=', $headers['Cache-Control'] );
		$this->assertSame( 'search=yes, ai-input=yes, ai-train=no', $headers['Content-Signal'] );
		$this->assertSame( 'train-ai=n, search=y', $headers['Content-Usage'] );

		update_option( Settings::OPTION, array( 'content_usage_header' => false ) );
		Plugin::instance()->get( 'settings' )->flush_cache();
		$headers = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wpasl/v1/openapi' ) )->get_headers();
		$this->assertArrayHasKey( 'Content-Signal', $headers );
		$this->assertArrayNotHasKey( 'Content-Usage', $headers );
	}

	public function test_api_catalog_route() {
		ob_start();
		$this->go_to( home_url( '/.well-known/api-catalog' ) );
		$out  = ob_get_clean();
		$data = json_decode( $out, true );
		$this->assertIsArray( $data );
		$this->assertCount( 2, $data['linkset'] );

		// RFC 9727 appendix A.2: the catalog entry lists the APIs with "item".
		$catalog = $data['linkset'][0];
		$this->assertSame( home_url( '/.well-known/api-catalog' ), $catalog['anchor'] );
		$this->assertSame( array( array( 'href' => untrailingslashit( rest_url() ) ) ), $catalog['item'] );
		$this->assertArrayNotHasKey( 'service-desc', $catalog );
		$this->assertArrayNotHasKey( 'service-doc', $catalog );

		// RFC 9727 appendix A.1: the API entry carries the description and documentation.
		$api = $data['linkset'][1];
		$this->assertSame( untrailingslashit( rest_url() ), $api['anchor'] );
		$this->assertSame( $catalog['item'][0]['href'], $api['anchor'], 'The item points at the API entry.' );
		$this->assertSame( ManifestBuilder::openapi_url(), $api['service-desc'][0]['href'] );
		$this->assertSame( 'application/openapi+json', $api['service-desc'][0]['type'] );
		$this->assertSame( array( home_url( '/llms.txt' ), home_url( '/auth.md' ), home_url( '/agent-skills.json' ) ), array_column( $api['service-doc'], 'href' ) );
		$this->assertSame( 'text/markdown', $api['service-doc'][1]['type'] );
		$this->assertArrayNotHasKey( 'item', $api );

		$this->assertSame( 'application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"', $this->router->headers( ManifestRouter::CATALOG_PATH )['Content-Type'], 'RFC 9264 type with the RFC 9727 profile.' );
		$this->assertSame( 'application/ld+json; charset=utf-8', $this->router->headers( ManifestRouter::SKILLS_PATH )['Content-Type'] );

		$this->settings( array( 'auth_md_enabled' => false ) );
		$docs = $this->builder->api_catalog()['linkset'][1]['service-doc'];
		$this->assertSame( array( home_url( '/llms.txt' ), home_url( '/agent-skills.json' ) ), array_column( $docs, 'href' ), 'auth.md leaves the catalog when unpublished.' );
		$this->assertArrayNotHasKey( 'service-doc', $this->builder->api_catalog()['linkset'][0] );
	}

	public function test_api_catalog_with_plain_permalinks() {
		$this->set_permalink_structure( '' );
		$this->assertStringContainsString( '?rest_route=', rest_url() );
		$data = $this->builder->api_catalog();
		$api  = untrailingslashit( rest_url() );
		$this->assertStringEndsWith( '?rest_route=', $api, 'The REST base the site serves with plain permalinks.' );
		$this->assertSame( home_url( '/.well-known/api-catalog' ), $data['linkset'][0]['anchor'] );
		$this->assertSame( $api, $data['linkset'][0]['item'][0]['href'] );
		$this->assertSame( $api, $data['linkset'][1]['anchor'] );
		$this->assertSame( ManifestBuilder::openapi_url(), $data['linkset'][1]['service-desc'][0]['href'] );
	}

	public function test_non_canonical_root_paths_are_not_served() {
		foreach ( array( '/agent-skills.json/', '//agent-skills.json', '/.well-known/api-catalog/', '/.well-known//api-catalog' ) as $path ) {
			$this->assertNull( ManifestRouter::requested_path( $path ), $path );
			ob_start();
			$this->go_to( untrailingslashit( home_url() ) . $path ); // home_url() would collapse the double slash.
			$this->assertSame( '', ob_get_clean(), $path . ' is left to core.' );
		}
		$this->assertSame( ManifestRouter::SKILLS_PATH, ManifestRouter::requested_path( '/agent-skills.json' ) );
	}

	public function test_read_markdown_template_uses_reserved_expansion() {
		$capabilities = Plugin::instance()->get( 'manifest' )->agent_skills()['capabilities'];
		$read         = array_values( array_filter( $capabilities, static function ( $c ) { return 'read-markdown' === $c['id']; } ) ); // phpcs:ignore
		$this->assertCount( 1, $read );
		$template = $read[0]['urlTemplate'];
		$this->assertSame( home_url( '/{+path}.md' ), $template );
		// RFC 6570: reserved expansion keeps "/" of a hierarchical path; simple expansion would encode it.
		$this->assertSame( home_url( '/parent/child.md' ), str_replace( '{+path}', 'parent/child', $template ) );
		$this->assertSame( 'path', $read[0]['parameters'][0]['name'] );
	}

	public function test_openapi_servers_url_has_no_query_string_with_plain_permalinks() {
		$this->set_permalink_structure( '' );
		$doc    = $this->builder->openapi();
		$server = $doc['servers'][0]['url'];
		$this->assertStringNotContainsString( '?', $server );
		$this->assertSame( untrailingslashit( home_url() ), $server );
		$this->assertArrayHasKey( '/?rest_route=/wp/v2/posts', $doc['paths'] );
		// rest_url() adds index.php for an nginx quirk; "/?rest_route=" is the documented plain-permalink form and resolves the same.
		$this->assertSame( home_url( '/?rest_route=/wp/v2/posts' ), $server . '/?rest_route=/wp/v2/posts', 'Server + path key reaches the route.' );

		$this->set_permalink_structure( '/%postname%/' );
		$doc = $this->builder->openapi();
		$this->assertSame( untrailingslashit( rest_url() ), $doc['servers'][0]['url'] );
		$this->assertArrayHasKey( '/wp/v2/posts', $doc['paths'] );
	}

	public function test_contact_email_change_is_reflected_on_next_request() {
		ob_start();
		$this->go_to( home_url( '/agent-skills.json' ) );
		ob_get_clean();
		$this->assertTrue( $this->storage->exists( ManifestBuilder::SKILLS_FILE ) );

		$settings                  = Settings::defaults();
		$settings['contact_email'] = 'new@example.org';
		update_option( Settings::OPTION, $settings );
		$this->assertFalse( $this->storage->exists( ManifestBuilder::SKILLS_FILE ) );

		ob_start();
		$this->go_to( home_url( '/agent-skills.json' ) );
		$out = ob_get_clean();
		$this->assertSame( 'new@example.org', json_decode( $out, true )['publisher']['email'] );
	}

	public function test_builder_is_an_artifact_generator_and_respects_the_toggle() {
		$status = Plugin::instance()->get( 'runner' )->status();
		$this->assertContains( 'manifests', $status['artifacts'] );
		Plugin::instance()->get( 'runner' )->run_cycle();
		$this->assertTrue( $this->storage->exists( ManifestBuilder::CATALOG_FILE ) );

		$this->settings( array( 'manifest_enabled' => false ) );
		Plugin::instance()->get( 'runner' )->run_cycle();
		$this->assertFalse( $this->storage->exists( ManifestBuilder::CATALOG_FILE ) );
	}

	public function test_manifests_tab_renders() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$tabs = Plugin::instance()->get( 'page' )->tabs();
		$this->assertArrayHasKey( 'manifests', $tabs );
		$this->assertSame( array( 'general', 'signals', 'crawlers', 'llms', 'manifests', 'diagnostics' ), array_keys( $tabs ) );
		ob_start();
		$tabs['manifests']->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'wpasl_settings[manifest_enabled]', $html );
		$this->assertStringContainsString( 'wpasl_settings[contact_email]', $html );
		$this->assertStringContainsString( home_url( '/.well-known/api-catalog' ), $html );
	}
}
