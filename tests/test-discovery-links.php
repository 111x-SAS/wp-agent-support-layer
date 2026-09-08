<?php
/**
 * Discovery links tests.
 *
 * @package WPASL
 */

use WPASL\Manifest\DiscoveryLinks;
use WPASL\Manifest\ManifestBuilder;
use WPASL\Plugin;
use WPASL\Settings;

/**
 * Covers WPASL\Manifest\DiscoveryLinks: the <link> elements in wp_head and the [wpasl_agent_links] shortcode.
 */
class Test_Discovery_Links extends WP_UnitTestCase {

	/**
	 * @var DiscoveryLinks
	 */
	private $links;

	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->links = Plugin::instance()->get( 'discovery_links' );
		$this->assertInstanceOf( DiscoveryLinks::class, $this->links );
	}

	public function tear_down() {
		remove_all_filters( 'wpasl_discovery_links' );
		delete_option( Settings::OPTION );
		Plugin::instance()->get( 'settings' )->flush_cache();
		parent::tear_down();
	}

	private function settings( array $values ) {
		update_option( Settings::OPTION, $values );
		Plugin::instance()->get( 'settings' )->flush_cache();
	}

	/**
	 * Renders wp_head for the current query and returns its output.
	 *
	 * @return string
	 */
	private function head() {
		ob_start();
		do_action( 'wp_head' );
		return ob_get_clean();
	}

	private function expected_links() {
		return array(
			'<link rel="service-desc" type="application/openapi+json" href="' . esc_url( ManifestBuilder::openapi_url() ) . '" />',
			'<link rel="api-catalog" type="application/linkset+json" href="' . esc_url( home_url( '/.well-known/api-catalog' ) ) . '" />',
			'<link rel="describedby" type="text/markdown" href="' . esc_url( home_url( '/llms.txt' ) ) . '" />',
			'<link rel="service-doc" type="text/markdown" href="' . esc_url( home_url( '/auth.md' ) ) . '" />',
		);
	}

	public function test_head_links_on_front_page() {
		$this->assertSame( 5, has_action( 'wp_head', array( $this->links, 'print_links' ) ) );
		$this->go_to( home_url( '/' ) );
		$head = $this->head();
		$this->assertStringContainsString( implode( "\n", $this->expected_links() ) . "\n", $head, 'The four links, in order.' );
		foreach ( array( 'service-desc', 'api-catalog', 'describedby', 'service-doc' ) as $rel ) {
			$this->assertSame( 1, substr_count( $head, 'rel="' . $rel . '"' ), $rel . ' exactly once.' );
		}
		$this->assertStringNotContainsString( 'rel="alternate" type="text/markdown"', $head, 'No alternate on the posts index.' );
	}

	public function test_head_links_on_singular_post_alongside_alternate() {
		$post = self::factory()->post->create_and_get( array( 'post_name' => 'interior' ) );
		$this->go_to( get_permalink( $post ) );
		$this->assertTrue( is_singular() );
		$head = $this->head();
		foreach ( $this->expected_links() as $link ) {
			$this->assertStringContainsString( $link, $head );
		}
		$this->assertStringContainsString( '<link rel="alternate" type="text/markdown" href="' . esc_url( home_url( '/interior.md' ) ) . '" />', $head );
	}

	public function test_no_auth_md_link_when_unpublished() {
		$this->settings( array( 'auth_md_enabled' => false ) );
		$this->go_to( home_url( '/' ) );
		$head = $this->head();
		$this->assertStringNotContainsString( '/auth.md', $head );
		$this->assertStringNotContainsString( 'rel="service-doc"', $head );
		foreach ( array_slice( $this->expected_links(), 0, 3 ) as $link ) {
			$this->assertStringContainsString( $link, $head );
		}
		$this->assertSame( array( 'service-desc', 'api-catalog', 'describedby' ), array_column( $this->links->links(), 'rel' ) );
		$this->assertStringNotContainsString( 'auth.md', $this->links->shortcode() );
	}

	public function test_nothing_when_manifests_disabled() {
		$this->settings( array( 'manifest_enabled' => false ) );
		$this->go_to( home_url( '/' ) );
		$head = $this->head();
		foreach ( array( 'service-desc', 'api-catalog', 'describedby', 'service-doc' ) as $rel ) {
			$this->assertStringNotContainsString( 'rel="' . $rel . '"', $head, $rel );
		}
		$this->assertStringNotContainsString( 'llms.txt', $head );
		$this->assertSame( array(), $this->links->links() );
		$this->assertSame( '', $this->links->shortcode() );
		$this->assertSame( '', do_shortcode( '[wpasl_agent_links]' ) );
	}

	public function test_links_are_never_printed_in_the_admin() {
		set_current_screen( 'dashboard' );
		$this->assertTrue( is_admin() );
		ob_start();
		$this->links->print_links();
		$out = ob_get_clean();
		set_current_screen( 'front' );
		$this->assertSame( '', $out );
	}

	public function test_shortcode_renders_list() {
		$this->assertTrue( shortcode_exists( 'wpasl_agent_links' ) );
		$html = do_shortcode( 'Before [wpasl_agent_links] after' );
		$this->assertStringStartsWith( 'Before <ul class="wpasl-agent-links">', $html );
		$this->assertStringEndsWith( '</ul> after', $html );
		$this->assertSame( 4, substr_count( $html, '<li><a href="' ) );

		$hrefs = array();
		preg_match_all( '/<a href="([^"]+)" rel="([^"]+)" type="([^"]+)">([^<]+)<\/a>/', $html, $m, PREG_SET_ORDER );
		foreach ( $m as $link ) {
			$hrefs[ $link[2] ] = $link[1];
		}
		$this->assertSame(
			array(
				'service-doc'  => esc_url( home_url( '/auth.md' ) ),
				'service-desc' => esc_url( ManifestBuilder::openapi_url() ),
				'api-catalog'  => esc_url( home_url( '/.well-known/api-catalog' ) ),
				'describedby'  => esc_url( home_url( '/llms.txt' ) ),
			),
			$hrefs,
			'auth.md first, then the OpenAPI document, the catalog and llms.txt, with escaped URLs.'
		);
		$this->assertStringContainsString( '>Agent access documentation (auth.md)</a>', $html );
		$this->assertStringContainsString( '>Site index for agents (llms.txt)</a>', $html );
		$this->assertStringContainsString( '>API catalog (RFC 9727)</a>', $html );
		$this->assertStringContainsString( '>OpenAPI description of the public REST API</a>', $html );
		$this->assertStringContainsString( 'href="' . esc_url( ManifestBuilder::openapi_url() ) . '"', $html );
	}

	public function test_links_filter() {
		add_filter(
			'wpasl_discovery_links',
			static function ( $links ) {
				$links[] = array(
					'rel'   => 'help',
					'type'  => 'text/html',
					'href'  => home_url( '/for-agents/?a=1&b=<x>' ),
					'label' => 'Agent guide <b>',
				);
				return array_values( array_filter( $links, static function ( $link ) { return 'describedby' !== $link['rel']; } ) ); // phpcs:ignore
			}
		);
		$rels = array_column( $this->links->links(), 'rel' );
		$this->assertSame( array( 'service-desc', 'api-catalog', 'service-doc', 'help' ), $rels );

		$this->go_to( home_url( '/' ) );
		$head = $this->head();
		$this->assertStringNotContainsString( 'rel="describedby"', $head );
		$this->assertStringContainsString( '<link rel="help" type="text/html" href="' . esc_url( home_url( '/for-agents/?a=1&b=<x>' ) ) . '" />', $head );

		$html = $this->links->shortcode();
		$this->assertStringNotContainsString( 'llms.txt', $html );
		$this->assertStringContainsString( '>Agent guide &lt;b&gt;</a>', $html, 'Labels are escaped.' );
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/for-agents/?a=1&b=<x>' ) ) . '"', $html );
	}
}
