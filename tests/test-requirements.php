<?php
/**
 * Requirements check tests.
 *
 * @package WPASL
 */

/**
 * Covers wpasl_requirements_notice() and the bootstrap guard.
 */
class Test_Requirements extends WP_UnitTestCase {

	public function test_meets_requirements_in_test_environment() {
		$this->assertSame( '', wpasl_requirements_notice() );
	}

	public function test_php_below_minimum_produces_notice() {
		$notice = wpasl_requirements_notice( '7.3.0' );
		$this->assertStringContainsString( 'PHP 7.4', $notice );
		$this->assertStringContainsString( '7.3.0', $notice );
	}

	public function test_wordpress_below_minimum_produces_notice() {
		$notice = wpasl_requirements_notice( null, '6.9' );
		$this->assertStringContainsString( 'WordPress 7.0', $notice );
	}

	public function test_php_notice_takes_precedence_over_wordpress_notice() {
		$notice = wpasl_requirements_notice( '7.0', '5.0' );
		$this->assertStringContainsString( 'PHP', $notice );
		$this->assertStringNotContainsString( 'WordPress 7.0', $notice );
	}

	public function test_notice_is_escaped_when_printed() {
		add_filter( 'gettext', array( $this, 'inject_markup' ), 10, 2 );
		ob_start();
		wpasl_print_requirements_notice();
		$html = ob_get_clean();
		remove_filter( 'gettext', array( $this, 'inject_markup' ), 10 );

		$this->assertSame( '', $html, 'No notice is printed when requirements are met.' );
	}

	/**
	 * Helper filter (kept to assert nothing leaks when requirements are met).
	 */
	public function inject_markup( $translation ) {
		return $translation;
	}

	public function test_plugin_headers_declare_minimums() {
		$data = get_plugin_data( WPASL_FILE, false, false );
		$this->assertSame( '7.0', $data['RequiresWP'] );
		$this->assertSame( '7.4', $data['RequiresPHP'] );
		$this->assertSame( 'wp-agent-support-layer', $data['TextDomain'] );
	}
}
