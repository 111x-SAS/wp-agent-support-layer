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

	public function test_storage_write_failure_is_logged() {
		$storage = new Storage();
		$storage->ensure();
		$dir = dirname( $storage->path( 'md/post/1.md' ) );
		wp_mkdir_p( $dir );
		$log = tempnam( get_temp_dir(), 'wpasl-log' );
		$ini = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		chmod( $dir, 0500 );
		try {
			$result = $storage->write( 'md/post/1.md', "# x\n" );
		} finally {
			chmod( $dir, 0755 );
			ini_set( 'error_log', (string) $ini ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
		$contents = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		unlink( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertFalse( $result );
		$this->assertStringContainsString( 'Storage write failed: file_put_contents(' . $dir, $contents );
		$this->assertFalse( $storage->exists( 'md/post/1.md' ) );
	}

	public function test_write_creates_index_in_new_subdirectories() {
		$storage = new Storage();
		$storage->ensure();
		$this->assertTrue( $storage->write( 'md/post/7.md', "# x\n" ) );
		$this->assertFileExists( $storage->base_dir() . '/md/index.php' );
		$this->assertFileExists( $storage->base_dir() . '/md/post/index.php' );
		$this->assertSame( Storage::INDEX_GUARD, file_get_contents( $storage->base_dir() . '/md/post/index.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( array( 'md/post/7.md' ), $storage->list_files( 'md' ), 'Guards are not listed as documents.' );
	}

	public function test_ensure_writes_web_config() {
		$storage = new Storage();
		$this->assertTrue( $storage->ensure() );
		$config = file_get_contents( Storage::root_dir() . '/web.config' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( '<add accessType="Deny" users="*" />', $config );
		$this->assertFileExists( Storage::root_dir() . '/.htaccess' );
	}
}
