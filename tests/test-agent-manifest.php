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
		foreach ( array( 'search-content', 'list-post', 'read-post', 'list-page', 'read-page', 'read-markdown', 'site-index', 'openapi' ) as $id ) {
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
		$this->assertSame( home_url( '/{path}.md' ), $markdown['urlTemplate'] );
		$this->assertSame( 'text/markdown', $markdown['responseType'] );
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
	}

	public function test_openapi_rest_route() {
		do_action( 'rest_api_init' );
		$request  = new WP_REST_Request( 'GET', '/wpasl/v1/openapi' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( '3.1.0', $data['openapi'] );
		$this->assertStringStartsWith( 'public, max-age=', $response->get_headers()['Cache-Control'] );
	}

	public function test_api_catalog_route() {
		ob_start();
		$this->go_to( home_url( '/.well-known/api-catalog' ) );
		$out  = ob_get_clean();
		$data = json_decode( $out, true );
		$this->assertIsArray( $data );
		$this->assertSame( ManifestBuilder::openapi_url(), $data['linkset'][0]['service-desc'][0]['href'] );
		$this->assertSame( 'application/openapi+json', $data['linkset'][0]['service-desc'][0]['type'] );
		$this->assertSame( 'application/linkset+json; charset=utf-8', $this->router->headers( ManifestRouter::CATALOG_PATH )['Content-Type'] );
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
