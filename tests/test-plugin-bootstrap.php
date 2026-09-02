<?php
/**
 * Smoke tests for the plugin bootstrap.
 *
 * @package WPASL
 */

/**
 * Verifies that the plugin loads, boots and autoloads its prefixed dependencies.
 */
class Test_Plugin_Bootstrap extends WP_UnitTestCase {

	/**
	 * Constants are defined by the main plugin file.
	 */
	public function test_constants_are_defined() {
		$this->assertTrue( defined( 'WPASL_VERSION' ) );
		$this->assertTrue( defined( 'WPASL_DIR' ) );
		$this->assertSame( '7.4', WPASL_MIN_PHP );
		$this->assertSame( '7.0', WPASL_MIN_WP );
	}

	/**
	 * Requirements are met in the test environment, so no notice is produced.
	 */
	public function test_requirements_are_met() {
		$this->assertSame( '', wpasl_requirements_notice() );
	}

	/**
	 * The plugin boots exactly once on plugins_loaded.
	 */
	public function test_plugin_boots() {
		$this->assertTrue( WPASL\Plugin::instance()->is_booted() );
		$this->assertSame( 10, has_action( 'init', array( WPASL\Plugin::instance(), 'load_textdomain' ) ) );
	}

	/**
	 * The prefixed HTML-to-Markdown library is reachable through the autoloader.
	 */
	public function test_prefixed_vendor_is_autoloaded() {
		$this->assertTrue( class_exists( 'WPASL\\Vendor\\League\\HTMLToMarkdown\\HtmlConverter' ) );
		$converter = new WPASL\Vendor\League\HTMLToMarkdown\HtmlConverter( array( 'header_style' => 'atx' ) );
		$this->assertSame( '# Hello', trim( $converter->convert( '<h1>Hello</h1>' ) ) );
	}
}
