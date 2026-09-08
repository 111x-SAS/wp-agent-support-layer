<?php
/**
 * Content extractor tests.
 *
 * @package WPASL
 */

use WPASL\Markdown\ContentExtractor;
use WPASL\Markdown\LeagueConverter;
use WPASL\Plugin;
use WPASL\Settings;

/**
 * Covers WPASL\Markdown\ContentExtractor.
 */
class Test_Content_Extractor extends WP_UnitTestCase {

	/**
	 * @var ContentExtractor
	 */
	private $extractor;

	public function set_up() {
		parent::set_up();
		$this->extractor = new ContentExtractor( Plugin::instance()->get( 'settings' ) );
	}

	public function tear_down() {
		remove_all_filters( 'wpasl_content_selector' );
		remove_all_filters( 'wpasl_rendered_content_selectors' );
		remove_all_filters( 'wpasl_rendered_remove_selectors' );
		delete_option( Settings::OPTION );
		Plugin::instance()->get( 'settings' )->flush_cache();
		parent::tear_down();
	}

	/**
	 * Reads a fixture.
	 *
	 * @param string $name File name in tests/fixtures.
	 * @return string
	 */
	private function fixture( $name ) {
		return (string) file_get_contents( __DIR__ . '/fixtures/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Extracts and converts to Markdown, as the document builder does.
	 *
	 * @param string   $html HTML.
	 * @param \WP_Post $post Post.
	 * @return array{result:array, markdown:string}
	 */
	private function extract_markdown( $html, $post ) {
		$result    = $this->extractor->extract( $html, $post );
		$converter = new LeagueConverter( get_permalink( $post ) );
		return array(
			'result'   => $result,
			'markdown' => $result['ok'] ? $converter->convert( $result['html'] ) : '',
		);
	}

	/**
	 * @dataProvider selector_provider
	 */
	public function test_selector_to_xpath( $selector, $expected ) {
		$this->assertSame( $expected, ContentExtractor::to_xpath( $selector ) );
	}

	public function selector_provider() {
		$class = static function ( $name ) {
			return "[contains(concat(' ', normalize-space(@class), ' '), ' {$name} ')]";
		};
		return array(
			'tag'                   => array( 'main', '//main' ),
			'child combinator'      => array( 'div.entry-content > .inner', '//div' . $class( 'entry-content' ) . '/*' . $class( 'inner' ) ),
			'descendant combinator' => array( '#content article', "//*[@id='content']//article" ),
			'attribute value'       => array( '[role="main"]', "//*[@role='main']" ),
			'attribute presence'    => array( '.elementor[data-elementor-type]', '//*' . $class( 'elementor' ) . '[@data-elementor-type]' ),
			'comma list'            => array( 'a, b', '//a | //b' ),
			'single quoted value'   => array( "[data-x='y']", "//*[@data-x='y']" ),
			'value with quote'      => array( '[title="it\'s"]', '//*[@title="it\'s"]' ),
			'universal'             => array( '*', '//*' ),
			'extra whitespace'      => array( '  main   >  article  ', '//main/article' ),
		);
	}

	public function test_invalid_selectors_are_rejected() {
		foreach ( array( 'div:has(p)', 'a::before', 'a + b', '[x^="y"]', '', 'a ~ b', 'a >', '> a', 'div:first-child', '[x*="y"]', '[x$="y"]', '.', '#', '1div' ) as $selector ) {
			$this->assertNull( ContentExtractor::to_xpath( $selector ), $selector );
		}
		$this->assertNull( ContentExtractor::normalize_selector( 'div:has(p)' ) );
		$this->assertSame( '', ContentExtractor::normalize_selector( '  ' ) );
		$this->assertSame( 'div.entry-content > .inner, main article', ContentExtractor::normalize_selector( ' div.entry-content>.inner ,main   article ' ) );
		$this->assertSame( 'main', ContentExtractor::normalize_selector( ContentExtractor::normalize_selector( ' main ' ) ), 'Idempotent.' );
	}

	public function test_elementor_page_keeps_widgets_and_drops_chrome() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title' => 'Blackboard',
				'post_name'  => 'blackboard',
			)
		);
		$out  = $this->extract_markdown( $this->fixture( 'rendered-elementor.html' ), $post );

		$this->assertTrue( $out['result']['ok'] );
		$this->assertSame( 'main', $out['result']['selector'] );
		$md = $out['markdown'];
		$this->assertStringContainsString( 'Blackboard Learn es la plataforma LMS', $md );
		$this->assertStringContainsString( '## Beneficios de Blackboard', $md );
		$this->assertStringContainsString( 'Aulas virtuales', $md );
		$this->assertStringContainsString( '[Solicitar demo](' . home_url( '/contacto/' ) . ')', $md, 'Links are absolutized by the converter.' );
		$this->assertStringNotContainsString( 'header text', $md );
		$this->assertStringNotContainsString( 'footer text', $md );
		$this->assertStringNotContainsString( 'Inicio', $md );
		$this->assertStringNotContainsString( 'Contacto', $md );
		$this->assertStringNotContainsString( 'dataLayer', $md );
		$this->assertStringNotContainsString( 'elementor-hidden', $md );
		$this->assertSame( 0, preg_match( '/^# /m', $md ), 'The H1 with the title is dropped; the builder writes it once.' );
	}

	public function test_classic_theme_uses_article_not_aside() {
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Classic post' ) );
		$out  = $this->extract_markdown( $this->fixture( 'rendered-classic.html' ), $post );

		$this->assertTrue( $out['result']['ok'] );
		$this->assertSame( 'main', $out['result']['selector'] );
		$md = $out['markdown'];
		$this->assertStringContainsString( 'First paragraph of the classic article.', $md );
		$this->assertStringContainsString( '## Section heading', $md );
		$this->assertStringContainsString( '[a link](' . home_url( '/more/' ) . ')', $md );
		$this->assertStringNotContainsString( 'Other sidebar post', $md );
		$this->assertStringNotContainsString( 'Recent Posts', $md );
		$this->assertStringNotContainsString( 'Search', $md );
		$this->assertStringNotContainsString( 'Classic theme header text', $md );
		$this->assertStringNotContainsString( 'Classic theme footer text', $md );
		$this->assertStringNotContainsString( 'Posted on', $md, 'The entry footer is a footer element.' );
		$this->assertStringNotContainsString( '# Classic post', $md );
	}

	public function test_without_region_falls_back_to_body_without_nav() {
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Minimal page' ) );
		$out  = $this->extract_markdown( $this->fixture( 'rendered-no-region.html' ), $post );

		$this->assertTrue( $out['result']['ok'] );
		$this->assertSame( '', $out['result']['selector'], 'Body is the last resort.' );
		$this->assertStringContainsString( 'First minimal paragraph.', $out['markdown'] );
		$this->assertStringContainsString( 'Second minimal paragraph.', $out['markdown'] );
		$this->assertStringNotContainsString( 'Contact menu item', $out['markdown'] );
		$this->assertStringNotContainsString( 'Home', $out['markdown'] );
		$this->assertSame( '', ContentExtractor::find_region( $this->fixture( 'rendered-no-region.html' ) ) );
		$this->assertSame( 'main', ContentExtractor::find_region( $this->fixture( 'rendered-classic.html' ) ) );
	}

	public function test_removed_elements_do_not_leak() {
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Cleanup' ) );
		$html = '<html><body><main><p>Visible text.</p>'
			. '<script>var leak_script = 1;</script>'
			. '<style>.leak-style{}</style>'
			. '<form><label>leak form</label><input value="leak input"></form>'
			. '<button>leak button</button>'
			. '<div hidden>leak hidden</div>'
			. '<span aria-hidden="true">leak aria</span>'
			. '<span class="screen-reader-text">leak sr</span>'
			. '<noscript>leak noscript</noscript><template>leak template</template>'
			. '<nav>leak nav</nav><header>leak header</header><footer>leak footer</footer><aside>leak aside</aside>'
			. '<iframe src="https://e.test">leak iframe</iframe><svg><text>leak svg</text></svg>'
			. '<select><option>leak select</option></select><textarea>leak textarea</textarea>'
			. '<div class="elementor-location-header">leak elementor header</div>'
			. '<div data-elementor-type="popup">leak popup</div>'
			. '<div class="et-l et-l--footer">leak divi footer</div>'
			. '<p>Also visible.</p></main></body></html>';
		$out  = $this->extract_markdown( $html, $post );

		$this->assertTrue( $out['result']['ok'] );
		$this->assertStringContainsString( 'Visible text.', $out['markdown'] );
		$this->assertStringContainsString( 'Also visible.', $out['markdown'] );
		$this->assertStringNotContainsString( 'leak', $out['markdown'] );
		$this->assertStringNotContainsString( 'leak', $out['result']['html'] );
	}

	public function test_duplicate_h1_is_removed_once() {
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Título &amp; "dúplice"' ) );
		$html = '<html><body><main><h1>  título &amp;   "Dúplice" </h1><p>Body.</p><h1>Título &amp; "dúplice"</h1><h1>Other heading</h1></main></body></html>';
		$out  = $this->extract_markdown( $html, $post );

		$this->assertTrue( $out['result']['ok'] );
		$this->assertSame( 2, preg_match_all( '/^# /m', $out['markdown'] ), 'Only the first matching H1 is removed; a different H1 stays.' );
		$this->assertStringContainsString( '# Other heading', $out['markdown'] );
		$this->assertSame( 1, substr_count( $out['result']['html'], '<h1>Título &amp; "dúplice"</h1>' ) );
	}

	public function test_configured_selector_wins_and_falls_back_when_missing() {
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Selector' ) );
		$html = '<html><body><main><p>' . str_repeat( 'Main text with much more content. ', 10 ) . '</p>'
			. '<div class="entry-content"><div class="inner"><p>Inner text only.</p></div><p>Sibling text.</p></div></main></body></html>';

		update_option( Settings::OPTION, array( 'content_selector' => 'div.entry-content > .inner' ) );
		Plugin::instance()->get( 'settings' )->flush_cache();
		$out = $this->extract_markdown( $html, $post );
		$this->assertTrue( $out['result']['ok'] );
		$this->assertSame( 'div.entry-content > .inner', $out['result']['selector'] );
		$this->assertStringContainsString( 'Inner text only.', $out['markdown'] );
		$this->assertStringNotContainsString( 'Main text', $out['markdown'] );
		$this->assertStringNotContainsString( 'Sibling text', $out['markdown'] );
		$this->assertSame( 'div.entry-content > .inner', ContentExtractor::find_region( $html, 'div.entry-content > .inner' ) );

		update_option( Settings::OPTION, array( 'content_selector' => '.does-not-exist' ) );
		Plugin::instance()->get( 'settings' )->flush_cache();
		$out = $this->extract_markdown( $html, $post );
		$this->assertTrue( $out['result']['ok'] );
		$this->assertSame( 'main', $out['result']['selector'], 'Automatic detection continues when the configured selector has no match.' );
		$this->assertStringContainsString( 'Main text', $out['markdown'] );
		$this->assertStringContainsString( 'Inner text only.', $out['markdown'] );

		// The filter replaces the configured selector.
		add_filter(
			'wpasl_content_selector',
			static function ( $selector, $filtered_post ) use ( $post ) {
				return $filtered_post->ID === $post->ID ? '.inner' : $selector;
			},
			10,
			2
		);
		$out = $this->extract_markdown( $html, $post );
		$this->assertSame( '.inner', $out['result']['selector'] );
		$this->assertStringNotContainsString( 'Main text', $out['markdown'] );
	}

	public function test_selector_list_filter() {
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Lista' ) );
		$html = '<html><body><main><p>' . str_repeat( 'Main text. ', 20 ) . '</p></main><div class="mi-contenido"><p>Custom region text.</p></div></body></html>';

		$out = $this->extract_markdown( $html, $post );
		$this->assertSame( 'main', $out['result']['selector'] );

		add_filter(
			'wpasl_rendered_content_selectors',
			static function ( $selectors ) {
				array_unshift( $selectors, '.mi-contenido' );
				return $selectors;
			}
		);
		$out = $this->extract_markdown( $html, $post );
		$this->assertSame( '.mi-contenido', $out['result']['selector'] );
		$this->assertStringContainsString( 'Custom region text.', $out['markdown'] );
		$this->assertStringNotContainsString( 'Main text', $out['markdown'] );

		// The removal list is filterable too.
		add_filter(
			'wpasl_rendered_remove_selectors',
			static function ( $selectors ) {
				$selectors[] = '.mi-contenido';
				return $selectors;
			}
		);
		$out = $this->extract_markdown( $html, $post );
		$this->assertSame( 'main', $out['result']['selector'], 'A removed container cannot be the region.' );
	}

	public function test_no_content_is_reported() {
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Vacía' ) );
		foreach ( array(
			'',
			'   ',
			'<html><body></body></html>',
			'<html><body><main><script>x()</script></main><nav>Menu</nav><footer>Foot</footer></body></html>',
			'<html><body><main><h1>Vacía</h1></main></body></html>',
			'<html><body><main>&nbsp; &nbsp;</main></body></html>',
		) as $html ) {
			$result = $this->extractor->extract( $html, $post );
			$this->assertFalse( $result['ok'], $html );
			$this->assertSame( 'no_content', $result['error'], $html );
			$this->assertSame( '', $result['html'], $html );
		}
		$this->assertSame( '', ContentExtractor::find_region( '<html><body><nav>Menu</nav></body></html>' ) );
	}
}
