<?php
/**
 * Multisite isolation tests. Only run under tests/multisite.xml.dist.
 *
 * @package WPASL
 */

use WPASL\Generation\Runner;
use WPASL\Lifecycle;
use WPASL\Plugin;
use WPASL\Settings;
use WPASL\Storage;

/**
 * Each site of a network keeps its own settings, storage and documents.
 */
class Test_Multisite extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
	}

	public function test_each_site_has_its_own_storage_and_documents() {
		$site_b = self::factory()->blog->create();

		Lifecycle::activate_site();
		$storage_a = new Storage();
		$dir_a     = $storage_a->base_dir();
		$post_a    = self::factory()->post->create( array( 'post_title' => 'Site A' ) );
		Plugin::instance()->get( 'runner' )->run_cycle();
		$this->assertTrue( $storage_a->exists( Runner::document_path( 'post', $post_a ) ) );

		switch_to_blog( $site_b );
		Plugin::instance()->get( 'settings' )->flush_cache();
		Lifecycle::activate_site();
		$storage_b = new Storage();
		$dir_b     = $storage_b->base_dir();
		$this->assertNotSame( $dir_a, $dir_b );
		$this->assertStringContainsString( '/sites/' . $site_b . '/', $dir_b );
		$this->assertFalse( $storage_b->exists( Runner::document_path( 'post', $post_a ) ), 'Site B does not see site A documents.' );
		$this->assertSame( array(), $storage_b->list_files( 'md' ) );

		$post_b = self::factory()->post->create( array( 'post_title' => 'Site B' ) );
		Plugin::instance()->get( 'runner' )->run_cycle();
		$this->assertTrue( $storage_b->exists( Runner::document_path( 'post', $post_b ) ) );
		Storage::delete_all();
		restore_current_blog();
		Plugin::instance()->get( 'settings' )->flush_cache();

		$this->assertTrue( $storage_a->exists( Runner::document_path( 'post', $post_a ) ), 'Site A documents survive site B cleanup.' );
		Plugin::instance()->get( 'runner' )->clear();
		Storage::delete_all();
		delete_option( Settings::OPTION );
	}

	public function test_network_activation_sets_up_every_site() {
		$site_b = self::factory()->blog->create();
		Lifecycle::activate( true );

		$this->assertNotFalse( get_option( Settings::OPTION ) );
		switch_to_blog( $site_b );
		$this->assertNotFalse( get_option( Settings::OPTION ) );
		$this->assertDirectoryExists( ( new Storage() )->base_dir() );
		Storage::delete_all();
		delete_option( Settings::OPTION );
		restore_current_blog();

		Lifecycle::deactivate( true );
		Storage::delete_all();
		delete_option( Settings::OPTION );
	}
}
