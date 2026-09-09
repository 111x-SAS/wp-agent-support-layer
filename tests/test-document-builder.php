<?php
/**
 * Document builder tests.
 *
 * @package WPASL
 */

use WPASL\Markdown\DocumentBuilder;
use WPASL\Markdown\LeagueConverter;
use WPASL\Plugin;
use WPASL\Settings;

/**
 * Covers WPASL\Markdown\DocumentBuilder.
 */
class Test_Document_Builder extends WP_UnitTestCase {

	/**
	 * @var DocumentBuilder
	 */
	private $builder;

	/**
	 * Requests seen by the fake loopback.
	 *
	 * @var array<int, array{url:string, args:array}>
	 */
	private $requests = array();

	/**
	 * Fake loopback response: array( code, headers, body ) or a WP_Error; null answers with the Elementor fixture.
	 *
	 * @var mixed
	 */
	private $response = null;

	/**
	 * Resolutions announced through wpasl_content_source_resolved.
	 *
	 * @var array<int, array{0:int, 1:array}>
	 */
	private $resolutions = array();

	/**
	 * Previous error_log setting (the runner logs every rendered-page failure).
	 *
	 * @var string|false
	 */
	private $error_log_ini;

	public function set_up() {
		parent::set_up();
		$this->builder = Plugin::instance()->get( 'builder' );
		$this->assertInstanceOf( DocumentBuilder::class, $this->builder );
		$this->requests      = array();
		$this->response      = null;
		$this->resolutions   = array();
		$this->error_log_ini = ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$this->set_permalink_structure( '/%postname%/' );
		add_action( 'wpasl_content_source_resolved', array( $this, 'record_resolution' ), 10, 2 );
	}

	public function tear_down() {
		ini_set( 'error_log', (string) $this->error_log_ini ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		remove_filter( 'pre_http_request', array( $this, 'fake_loopback' ), 20 );
		remove_action( 'wpasl_content_source_resolved', array( $this, 'record_resolution' ), 10 );
		remove_all_filters( 'wpasl_markdown_html' );
		remove_all_filters( 'wpasl_editor_min_chars' );
		delete_option( Settings::OPTION );
		Plugin::instance()->get( 'settings' )->flush_cache();
		parent::tear_down();
	}

	public function record_resolution( $post, $info ) {
		$this->resolutions[] = array( $post->ID, $info );
	}

	/**
	 * Fake loopback: records the request and answers with the configured response.
	 */
	public function fake_loopback( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		$spec             = $this->response;
		if ( null === $spec ) {
			$spec = array( 200, array( 'content-type' => 'text/html; charset=utf-8' ), file_get_contents( __DIR__ . '/fixtures/rendered-elementor.html' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		if ( is_wp_error( $spec ) ) {
			return $spec;
		}
		return array(
			'response' => array( 'code' => $spec[0], 'message' => 'x' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( $spec[1] ),
			'body'     => $spec[2],
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Installs the fake loopback.
	 *
	 * @param mixed $response Response spec (see fake_loopback()).
	 * @return void
	 */
	private function use_loopback( $response = null ) {
		$this->response = $response;
		add_filter( 'pre_http_request', array( $this, 'fake_loopback' ), 20, 3 );
	}

	/**
	 * Creates a post built with Elementor (rendered content source, reason builder:elementor).
	 *
	 * @param array<string, mixed> $args Post arguments.
	 * @return WP_Post
	 */
	private function elementor_post( array $args = array() ) {
		$post = self::factory()->post->create_and_get(
			array_merge(
				array(
					'post_title'   => 'Blackboard',
					'post_name'    => 'blackboard',
					'post_excerpt' => '',
					'post_content' => '<p>Editor placeholder text of the Elementor page.</p>',
				),
				$args
			)
		);
		update_post_meta( $post->ID, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post->ID, '_elementor_data', '[{"id":"abc"}]' );
		return $post;
	}

	public function test_front_matter_contains_required_keys_and_taxonomies() {
		$author = self::factory()->user->create(
			array(
				'role'         => 'author',
				'display_name' => 'Ana Autora',
			)
		);
		$cat    = self::factory()->category->create( array( 'name' => 'Noticias' ) );
		$tag    = self::factory()->tag->create( array( 'name' => 'IA' ) );
		$post   = self::factory()->post->create_and_get(
			array(
				'post_title'    => 'Título con "comillas"',
				'post_content'  => '<p>Primer párrafo.</p><h2>Sub</h2><p>Segundo.</p>',
				'post_excerpt'  => 'Resumen manual',
				'post_author'   => $author,
				'post_category' => array( $cat ),
				'tags_input'    => array( 'IA' ),
			)
		);

		$doc = $this->builder->generate( $post );

		$this->assertStringStartsWith( "---\n", $doc );
		$this->assertStringContainsString( 'title: "Título con \\"comillas\\""', $doc );
		$this->assertStringContainsString( 'url: "' . get_permalink( $post ) . '"', $doc );
		$this->assertStringContainsString( 'type: "post"', $doc );
		$this->assertStringContainsString( 'date: "' . get_the_date( 'c', $post ) . '"', $doc );
		$this->assertStringContainsString( 'modified: "' . get_the_modified_date( 'c', $post ) . '"', $doc );
		$this->assertStringContainsString( 'author: "Ana Autora"', $doc );
		$this->assertStringContainsString( 'lang: "' . get_bloginfo( 'language' ) . '"' . "\nsource: \"editor\"\ndescription: \"Resumen manual\"\n", $doc, 'source follows lang and precedes description.' );
		$this->assertStringContainsString( "categories:\n  - \"Noticias\"", $doc );
		$this->assertStringContainsString( "tags:\n  - \"IA\"", $doc );
		$this->assertStringContainsString( "---\n\n# Título con \"comillas\"\n\n", $doc );
		$this->assertStringContainsString( "Primer párrafo.\n\n## Sub\n\nSegundo.", $doc );
	}

	public function test_front_matter_has_source_editor_by_default() {
		$post = self::factory()->post->create_and_get( array( 'post_content' => '<p>Texto</p>' ) );
		$doc  = $this->builder->generate( $post );
		$this->assertStringContainsString( "\nsource: \"editor\"\n", $doc );
		$this->assertSame( 1, substr_count( $doc, 'source:' ) );
		$this->assertMatchesRegularExpression( '/^lang: "[^"]*"\nsource: "editor"\n/m', $doc );
		$this->assertSame( array(), $this->requests, 'No loopback for the editor source.' );
		$this->assertCount( 1, $this->resolutions );
		$this->assertSame(
			array( 'source' => 'editor', 'reason' => 'default', 'fallback' => false, 'error' => '', 'deferred' => false ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->resolutions[0][1]
		);
	}

	public function test_rendered_source_uses_loopback_body() {
		$post = $this->elementor_post();
		$this->use_loopback();

		$doc = $this->builder->generate( $post );

		$this->assertStringContainsString( "\nsource: \"rendered\"\n", $doc );
		$this->assertMatchesRegularExpression( '/^lang: "[^"]*"\nsource: "rendered"\n/m', $doc );
		$this->assertStringContainsString( "# Blackboard\n\n", $doc );
		$this->assertSame( 1, substr_count( $doc, "# Blackboard\n" ), 'The H1 of the page is not duplicated.' );
		$this->assertStringContainsString( 'Blackboard Learn es la plataforma LMS', $doc );
		$this->assertStringContainsString( '## Beneficios de Blackboard', $doc );
		$this->assertStringContainsString( '[Solicitar demo](' . home_url( '/contacto/' ) . ')', $doc );
		$this->assertStringNotContainsString( 'Editor placeholder', $doc );
		$this->assertStringNotContainsString( 'header text', $doc );
		$this->assertStringNotContainsString( 'footer text', $doc );
		$this->assertStringContainsString( 'description: "Blackboard Learn es la plataforma LMS', $doc, 'The description derives from the rendered body.' );
		$this->assertCount( 1, $this->requests, 'Exactly one request.' );
		$this->assertSame( get_permalink( $post ) . '?wpasl_render=1', $this->requests[0]['url'] );
		$this->assertSame( '1', $this->requests[0]['args']['headers']['X-WPASL-Render'] );
		$this->assertSame(
			array( 'source' => 'rendered', 'reason' => 'builder:elementor', 'fallback' => false, 'error' => '', 'deferred' => false ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			end( $this->resolutions )[1]
		);
	}

	public function test_loopback_failure_falls_back_to_editor_and_reports() {
		$post = $this->elementor_post();
		$this->use_loopback( array( 503, array( 'content-type' => 'text/html' ), 'Service Unavailable' ) );

		$doc = $this->builder->generate( $post );

		$this->assertStringContainsString( "\nsource: \"editor\"\n", $doc );
		$this->assertStringContainsString( 'Editor placeholder text of the Elementor page.', $doc );
		$this->assertStringNotContainsString( 'Service Unavailable', $doc );
		$this->assertCount( 1, $this->requests );
		$this->assertSame(
			array( 'source' => 'editor', 'reason' => 'builder:elementor', 'fallback' => true, 'error' => 'http_503', 'deferred' => false ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			end( $this->resolutions )[1]
		);

		// A connection error and a timeout carry the WP_Error code.
		$this->response = new WP_Error( 'http_request_failed', 'cURL error 28' );
		$doc            = $this->builder->generate( $post );
		$this->assertStringContainsString( "\nsource: \"editor\"\n", $doc );
		$this->assertSame( 'request_error:http_request_failed', end( $this->resolutions )[1]['error'] );
		$this->assertTrue( end( $this->resolutions )[1]['fallback'] );
	}

	public function test_no_content_region_falls_back() {
		$post = $this->elementor_post();
		$this->use_loopback( array( 200, array( 'content-type' => 'text/html' ), '<html><body><nav>Menu</nav><main><script>x()</script></main><footer>Foot</footer></body></html>' ) );

		$doc = $this->builder->generate( $post );

		$this->assertStringContainsString( "\nsource: \"editor\"\n", $doc );
		$this->assertStringContainsString( 'Editor placeholder text of the Elementor page.', $doc );
		$this->assertStringNotContainsString( 'Menu', $doc );
		$this->assertSame( 'no_content', end( $this->resolutions )[1]['error'] );
		$this->assertTrue( end( $this->resolutions )[1]['fallback'] );
	}

	public function test_html_filter_applies_to_rendered_fragment() {
		add_filter(
			'wpasl_markdown_html',
			static function ( $html ) {
				return $html . '<p>Added paragraph by filter.</p>';
			}
		);
		$editor = self::factory()->post->create_and_get( array( 'post_content' => '<p>Editor text.</p>' ) );
		$doc    = $this->builder->generate( $editor );
		$this->assertStringContainsString( 'Editor text.', $doc );
		$this->assertStringContainsString( 'Added paragraph by filter.', $doc );
		$this->assertStringContainsString( "\nsource: \"editor\"\n", $doc );

		$rendered = $this->elementor_post();
		$this->use_loopback();
		$doc = $this->builder->generate( $rendered );
		$this->assertStringContainsString( "\nsource: \"rendered\"\n", $doc );
		$this->assertStringContainsString( 'Blackboard Learn es la plataforma LMS', $doc );
		$this->assertStringContainsString( 'Added paragraph by filter.', $doc );
		$this->assertSame( 1, substr_count( $doc, 'Added paragraph by filter.' ), 'Applied once to the rendered fragment.' );
	}

	public function test_builder_without_services_behaves_as_before() {
		$post    = $this->elementor_post();
		$builder = new DocumentBuilder( new LeagueConverter() );
		$this->use_loopback();

		$doc = $builder->generate( $post );

		$this->assertStringContainsString( "\nsource: \"editor\"\n", $doc );
		$this->assertStringContainsString( 'Editor placeholder text of the Elementor page.', $doc );
		$this->assertStringNotContainsString( 'Blackboard Learn', $doc );
		$this->assertSame( array(), $this->requests, 'No loopback without the services.' );
		$this->assertSame( 'default', end( $this->resolutions )[1]['reason'] );
	}

	public function test_empty_editor_is_rendered_and_its_body_is_reused_on_fallback() {
		// The rule is off by default; a filter enables it with a threshold.
		add_filter( 'wpasl_editor_min_chars', static function () { return 100; } );
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Blackboard', 'post_content' => '<p>Short.</p>' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->use_loopback();
		$doc = $this->builder->generate( $post );
		$this->assertStringContainsString( "\nsource: \"rendered\"\n", $doc );
		$this->assertSame( 'empty_editor', end( $this->resolutions )[1]['reason'] );
		$this->assertStringContainsString( 'Blackboard Learn es la plataforma LMS', $doc );

		$this->response = array( 403, array( 'content-type' => 'text/html' ), 'Forbidden' );
		$doc            = $this->builder->generate( $post );
		$this->assertStringContainsString( "\nsource: \"editor\"\n", $doc );
		$this->assertStringContainsString( "# Blackboard\n\nShort.\n", $doc, 'The body converted by the empty-editor check is reused.' );
		$this->assertSame( 'http_403', end( $this->resolutions )[1]['error'] );
	}

	public function test_description_falls_back_to_content_and_taxonomies_are_omitted_when_empty() {
		$page = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Página',
				'post_excerpt' => '',
				'post_content' => '<p>Texto de la página sin extracto.</p>',
			)
		);
		$doc  = $this->builder->generate( $page );
		$this->assertStringContainsString( 'description: "Texto de la página sin extracto."', $doc );
		$this->assertStringNotContainsString( 'categories:', $doc );
		$this->assertStringNotContainsString( 'tags:', $doc );
		$this->assertStringContainsString( 'type: "page"', $doc );
	}

	public function test_blocks_and_shortcodes_are_rendered() {
		add_shortcode( 'wpasl_hello', array( $this, 'hello_shortcode' ) );
		$post = self::factory()->post->create_and_get(
			array(
				'post_content' => "<!-- wp:paragraph -->\n<p>Bloque</p>\n<!-- /wp:paragraph -->\n\n[wpasl_hello]",
			)
		);
		$doc  = $this->builder->generate( $post );
		remove_shortcode( 'wpasl_hello' );

		$this->assertStringContainsString( 'Bloque', $doc );
		$this->assertStringContainsString( '**hola**', $doc );
		$this->assertStringNotContainsString( 'wp:paragraph', $doc );
		$this->assertStringNotContainsString( '[wpasl_hello]', $doc );
	}

	public function hello_shortcode() {
		return '<strong>hola</strong>';
	}

	public function test_scripts_forms_iframes_are_stripped_and_links_absolutized() {
		kses_remove_filters();
		$post = self::factory()->post->create_and_get(
			array(
				'post_content' => '<p><a href="/contacto/">Contacto</a></p><script>x()</script><form></form><iframe src="https://e.test"></iframe>',
			)
		);
		kses_init_filters();
		$doc = $this->builder->generate( $post );
		$this->assertStringContainsString( '[Contacto](' . home_url( '/contacto/' ) . ')', $doc );
		$this->assertStringNotContainsString( 'x()', $doc );
		$this->assertStringNotContainsString( 'e.test', $doc );
	}

	public function test_filters_can_adjust_the_document() {
		add_filter( 'wpasl_markdown_document', array( $this, 'append_footer' ) );
		$post = self::factory()->post->create_and_get();
		$doc  = $this->builder->generate( $post );
		remove_filter( 'wpasl_markdown_document', array( $this, 'append_footer' ) );
		$this->assertStringEndsWith( "FOOTER\n", $doc );
	}

	public function append_footer( $doc ) {
		return $doc . "FOOTER\n";
	}

	public function test_document_contains_both_halves_of_more_and_every_nextpage() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_content' => "<p>Primera mitad.</p>\n<!--more-->\n<p>Segunda mitad.</p>\n<!--nextpage-->\n<p>Página dos.</p>",
			)
		);
		$this->assertFalse( is_singular() );

		$doc = $this->builder->generate( $post );

		$this->assertStringContainsString( 'Primera mitad.', $doc );
		$this->assertStringContainsString( 'Segunda mitad.', $doc );
		$this->assertStringContainsString( 'Página dos.', $doc );
		$this->assertStringNotContainsString( 'more-link', $doc );
		$this->assertStringNotContainsString( '(more', $doc );
		$this->assertStringNotContainsString( 'nextpage', $doc );
	}

	public function test_block_more_and_nextpage_wrappers_are_removed() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_content' => "<!-- wp:paragraph -->\n<p>Intro</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:more {\"noTeaser\":true} -->\n<!--more-->\n<!-- /wp:more -->\n\n<!-- wp:paragraph -->\n<p>Resto</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:nextpage -->\n<!--nextpage-->\n<!-- /wp:nextpage -->\n\n<!-- wp:paragraph -->\n<p>Final</p>\n<!-- /wp:paragraph -->",
			)
		);
		$doc  = $this->builder->generate( $post );
		$this->assertStringContainsString( 'Intro', $doc );
		$this->assertStringContainsString( 'Resto', $doc );
		$this->assertStringContainsString( 'Final', $doc );
		$this->assertStringNotContainsString( 'more', $doc );
		$this->assertStringNotContainsString( 'nextpage', $doc );
	}

	public function test_render_restores_more_and_page_globals() {
		$GLOBALS['more'] = 0; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['page'] = 3; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post            = self::factory()->post->create_and_get( array( 'post_content' => 'a<!--more-->b' ) );
		$this->builder->generate( $post );
		$this->assertSame( 0, $GLOBALS['more'] );
		$this->assertSame( 3, $GLOBALS['page'] );
	}

	public function test_converter_interface_requires_base_url() {
		$converter = new class() implements WPASL\Markdown\ConverterInterface {
			public $base_url = null;
			public function set_base_url( $url ) {
				$this->base_url = $url;
			}
			public function convert( $html ) {
				return 'body';
			}
		};
		$post      = self::factory()->post->create_and_get( array( 'post_name' => 'iface' ) );
		$doc       = ( new DocumentBuilder( $converter ) )->generate( $post );
		$this->assertSame( get_permalink( $post ), $converter->base_url, 'The builder hands the canonical URL to every converter, through the interface.' );
		$this->assertStringEndsWith( "\n\nbody", $doc );
		$this->assertTrue( method_exists( WPASL\Markdown\ConverterInterface::class, 'set_base_url' ) );
	}

	public function test_building_documents_restores_all_post_globals() {
		$this->assertFalse( is_singular() );
		$sentinels = array(
			'id'         => 123456,
			'authordata' => (object) array( 'ID' => 99 ),
			'pages'      => array( 'sentinel' ),
			'numpages'   => 7,
			'multipage'  => 1,
		);
		foreach ( $sentinels as $name => $value ) {
			$GLOBALS[ $name ] = $value; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		$this->builder->generate( self::factory()->post->create_and_get( array( 'post_content' => 'uno<!--nextpage-->dos' ) ) );
		$this->builder->generate( self::factory()->post->create_and_get( array( 'post_content' => 'tres' ) ) );
		foreach ( $sentinels as $name => $value ) {
			$this->assertSame( $value, $GLOBALS[ $name ], "\${$name} is restored." );
			unset( $GLOBALS[ $name ] );
		}
	}

	public function test_absolutize_resolves_parent_segments_and_protocol_relative_urls() {
		$converter = new LeagueConverter( 'https://example.com/blog/entrada/' );
		$this->assertSame( 'https://example.com/blog/img/foto.png', $converter->absolutize( '../img/foto.png' ) );
		$this->assertSame( 'https://cdn.example.com/doc.pdf', $converter->absolutize( '//cdn.example.com/doc.pdf' ) );
		$this->assertSame( 'https://example.com/blog/entrada/x.png', $converter->absolutize( './x.png' ) );
		$this->assertSame( 'https://example.com/blog/entrada/y.png', $converter->absolutize( 'y.png' ) );
		$this->assertSame( 'https://example.com/b', $converter->absolutize( '/a/../b' ) );
		$this->assertSame( 'https://example.com/x', $converter->absolutize( '/../x' ) );
		$this->assertSame( 'https://example.com/blog/', $converter->absolutize( '..' ) );
		$this->assertSame( 'https://example.com/blog/entrada?q=1', $converter->absolutize( '?q=1' ) );
		$this->assertSame( 'https://example.com/a/b?x=../y#f', $converter->absolutize( '/a/./b?x=../y#f' ) );
		$this->assertSame( '#seccion', $converter->absolutize( '#seccion' ) );
		$this->assertSame( 'mailto:a@b.c', $converter->absolutize( 'mailto:a@b.c' ) );
		$this->assertSame( 'https://other.example/p', $converter->absolutize( 'https://other.example/p' ) );

		$converter = new LeagueConverter( 'http://example.org:8080/' );
		$this->assertSame( 'http://cdn.example/x.png', $converter->absolutize( '//cdn.example/x.png' ), 'Scheme of the base URL, not is_ssl().' );
		$this->assertSame( 'http://example.org:8080/p/', $converter->absolutize( '/p/' ) );

		// Documents resolve against their own permalink.
		kses_remove_filters();
		$post = self::factory()->post->create_and_get(
			array(
				'post_name'    => 'entrada',
				'post_content' => '<p><img src="../img/foto.png" alt="f"> <a href="//cdn.example.com/doc.pdf">Doc</a> <a href="#arriba">Arriba</a></p>',
			)
		);
		kses_init_filters();
		$doc = $this->builder->generate( $post );
		$this->assertStringContainsString( '![f](' . home_url( '/img/foto.png' ) . ')', $doc );
		$this->assertStringContainsString( '[Doc](' . wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://cdn.example.com/doc.pdf)', $doc );
		$this->assertStringContainsString( '[Arriba](#arriba)', $doc );
	}

	public function test_token_estimate() {
		$this->assertSame( 1000, DocumentBuilder::estimate_tokens( str_repeat( 'a', 4000 ) ) );
		$this->assertSame( 1, DocumentBuilder::estimate_tokens( 'ab' ) );
	}
}
