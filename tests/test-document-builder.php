<?php
/**
 * Document builder tests.
 *
 * @package WPASL
 */

use WPASL\Markdown\DocumentBuilder;
use WPASL\Plugin;

/**
 * Covers WPASL\Markdown\DocumentBuilder.
 */
class Test_Document_Builder extends WP_UnitTestCase {

	/**
	 * @var DocumentBuilder
	 */
	private $builder;

	public function set_up() {
		parent::set_up();
		$this->builder = Plugin::instance()->get( 'builder' );
		$this->assertInstanceOf( DocumentBuilder::class, $this->builder );
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
		$this->assertStringContainsString( 'lang: "' . get_bloginfo( 'language' ) . '"', $doc );
		$this->assertStringContainsString( 'description: "Resumen manual"', $doc );
		$this->assertStringContainsString( "categories:\n  - \"Noticias\"", $doc );
		$this->assertStringContainsString( "tags:\n  - \"IA\"", $doc );
		$this->assertStringContainsString( "---\n\n# Título con \"comillas\"\n\n", $doc );
		$this->assertStringContainsString( "Primer párrafo.\n\n## Sub\n\nSegundo.", $doc );
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

	public function test_token_estimate() {
		$this->assertSame( 1000, DocumentBuilder::estimate_tokens( str_repeat( 'a', 4000 ) ) );
		$this->assertSame( 1, DocumentBuilder::estimate_tokens( 'ab' ) );
	}
}
