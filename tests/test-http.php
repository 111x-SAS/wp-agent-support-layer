<?php
/**
 * Header emission tests.
 *
 * @package WPASL
 */

use WPASL\Http;

/**
 * Covers WPASL\Http.
 */
class Test_Http extends WP_UnitTestCase {

	public function tear_down() {
		Http::reset();
		parent::tear_down();
	}

	public function test_log_marks_headers_not_sent() {
		$this->assertTrue( headers_sent(), 'PHPUnit already flushed output, so header() cannot be called.' );
		Http::reset();
		Http::send_header( 'X-Test', 'one' );
		Http::send_header( 'X-Test', 'two', false );
		Http::remove_header( 'X-Gone' );

		$log = Http::log();
		$this->assertCount( 3, $log );
		foreach ( $log as $entry ) {
			$this->assertFalse( $entry[4], 'Recorded, not sent.' );
		}
		$this->assertSame( array( 'one', 'two' ), Http::effective_headers()['x-test'] );
		$this->assertSame( array(), Http::effective_headers( true ), 'Nothing was actually sent.' );
	}
}
