<?php
/**
 * HTML to Markdown converter tests.
 *
 * @package WPASL
 */

use WPASL\Markdown\LeagueConverter;

/**
 * Covers WPASL\Markdown\LeagueConverter.
 */
class Test_Converter extends WP_UnitTestCase {

	/**
	 * @var LeagueConverter
	 */
	private $converter;

	public function set_up() {
		parent::set_up();
		$this->converter = new LeagueConverter( 'https://example.org/' );
	}

	public function test_headings_paragraphs_and_lists() {
		$md = $this->converter->convert( '<h2>Section</h2><p>Hello <strong>world</strong>.</p><ul><li>One</li><li>Two</li></ul>' );
		$this->assertStringContainsString( "## Section\n", $md );
		$this->assertStringContainsString( 'Hello **world**.', $md );
		$this->assertStringContainsString( "- One\n- Two", $md );
	}

	public function test_non_content_elements_are_removed() {
		$html = '<p>Keep</p><script>alert(1)</script><style>p{}</style><form><input name="x"></form><iframe src="https://x.test"></iframe><noscript>no</noscript><!-- secret comment -->';
		$md   = $this->converter->convert( $html );
		$this->assertStringContainsString( 'Keep', $md );
		$this->assertStringNotContainsString( 'alert', $md );
		$this->assertStringNotContainsString( 'p{}', $md );
		$this->assertStringNotContainsString( 'x.test', $md );
		$this->assertStringNotContainsString( 'no', trim( str_replace( 'Keep', '', $md ) ) );
		$this->assertStringNotContainsString( 'secret', $md );
	}

	public function test_relative_links_and_images_become_absolute() {
		$md = $this->converter->convert( '<p><a href="/contacto/">Contacto</a> <img src="/wp-content/uploads/a.png" alt="A"> <a href="relativo">rel</a> <a href="?p=1">q</a></p>' );
		$this->assertStringContainsString( '[Contacto](https://example.org/contacto/)', $md );
		$this->assertStringContainsString( '![A](https://example.org/wp-content/uploads/a.png)', $md );
		$this->assertStringContainsString( '(https://example.org/relativo)', $md );
		$this->assertStringContainsString( '(https://example.org/?p=1)', $md );
	}

	public function test_absolute_and_special_links_are_untouched() {
		$md = $this->converter->convert( '<p><a href="https://other.test/x">x</a> <a href="mailto:a@b.co">m</a> <a href="#top">t</a></p>' );
		$this->assertStringContainsString( '(https://other.test/x)', $md );
		$this->assertStringContainsString( '(mailto:a@b.co)', $md );
		$this->assertStringContainsString( '(#top)', $md );
	}

	public function test_tables_are_converted() {
		$md = $this->converter->convert( '<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>' );
		$this->assertStringContainsString( '| A | B |', $md );
		$this->assertStringContainsString( '| 1 | 2 |', $md );
	}

	public function test_utf8_is_preserved() {
		$md = $this->converter->convert( '<p>Camión — español · 日本語</p>' );
		$this->assertStringContainsString( 'Camión — español · 日本語', $md );
	}

	public function test_empty_input() {
		$this->assertSame( '', $this->converter->convert( '' ) );
	}
}
