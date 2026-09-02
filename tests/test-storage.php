<?php
/**
 * Storage tests.
 *
 * @package WPASL
 */

use WPASL\Storage;

/**
 * Covers WPASL\Storage.
 */
class Test_Storage extends WP_UnitTestCase {

	public function tear_down() {
		Storage::delete_all();
		parent::tear_down();
	}

	public function test_write_read_delete_roundtrip() {
		$storage = new Storage();
		$storage->ensure();

		$this->assertFalse( $storage->exists( 'md/post/5.md' ) );
		$this->assertTrue( $storage->write( 'md/post/5.md', "# Hello\n" ) );
		$this->assertTrue( $storage->exists( 'md/post/5.md' ) );
		$this->assertSame( "# Hello\n", $storage->read( 'md/post/5.md' ) );
		$this->assertIsInt( $storage->mtime( 'md/post/5.md' ) );
		$this->assertSame( array( 'md/post/5.md' ), $storage->list_files( 'md' ) );
		$this->assertTrue( $storage->delete( 'md/post/5.md' ) );
		$this->assertNull( $storage->read( 'md/post/5.md' ) );
	}

	public function test_paths_cannot_escape_the_base_directory() {
		$storage = new Storage();
		$path    = $storage->path( '../../wp-config.php' );
		$this->assertStringStartsWith( $storage->base_dir(), $path );
		$this->assertStringNotContainsString( '..', $path );
	}

	public function test_base_dir_is_inside_uploads_and_uses_token() {
		$storage = new Storage();
		$uploads = wp_upload_dir( null, false );
		$this->assertStringStartsWith( $uploads['basedir'] . '/wp-agent-support-layer/', $storage->base_dir() );
		$this->assertStringEndsWith( Storage::token(), $storage->base_dir() );
	}

	public function test_token_is_stable() {
		$this->assertSame( Storage::token(), Storage::token() );
	}

	public function test_htaccess_denies_access() {
		$storage = new Storage();
		$storage->ensure();
		$rules = file_get_contents( Storage::root_dir() . '/.htaccess' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( 'Require all denied', $rules );
		$this->assertStringContainsString( 'Deny from all', $rules );
	}
}
