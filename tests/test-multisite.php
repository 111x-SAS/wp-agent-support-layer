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
use WPASL\Uninstaller;

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

	public function test_uninstall_removes_storage_and_options_on_every_site() {
		$site_b = self::factory()->blog->create();
		Lifecycle::activate( true );

		$post_a = self::factory()->post->create();
		update_post_meta( $post_a, Uninstaller::EXCLUDE_META, true );
		Plugin::instance()->get( 'runner' )->run_cycle();
		$dir_a = ( new Storage() )->base_dir();
		$this->assertDirectoryExists( $dir_a );

		switch_to_blog( $site_b );
		Plugin::instance()->get( 'settings' )->flush_cache();
		$post_b = self::factory()->post->create();
		update_post_meta( $post_b, Uninstaller::EXCLUDE_META, true );
		Plugin::instance()->get( 'runner' )->run_cycle();
		$dir_b = ( new Storage() )->base_dir();
		$this->assertDirectoryExists( $dir_b );
		set_transient( 'wpasl_diagnostics_report', array( 'x' ), HOUR_IN_SECONDS );
		restore_current_blog();
		Plugin::instance()->get( 'settings' )->flush_cache();

		Uninstaller::run();

		foreach ( array( get_current_blog_id(), $site_b ) as $site_id ) {
			switch_to_blog( $site_id );
			$this->assertFalse( get_option( Settings::OPTION ), "Site {$site_id} settings removed." );
			$this->assertFalse( get_option( Storage::TOKEN_OPTION ), "Site {$site_id} token removed." );
			$this->assertFalse( get_transient( 'wpasl_diagnostics_report' ), "Site {$site_id} report removed." );
			$this->assertFalse( wp_next_scheduled( \WPASL\Generation\Scheduler::HOOK ), "Site {$site_id} cron removed." );
			$this->assertDirectoryDoesNotExist( Storage::root_dir(), "Site {$site_id} storage removed." );
			restore_current_blog();
		}
		$this->assertSame( '', get_post_meta( $post_a, Uninstaller::EXCLUDE_META, true ) );
		switch_to_blog( $site_b );
		$this->assertSame( '', get_post_meta( $post_b, Uninstaller::EXCLUDE_META, true ) );
		$this->assertInstanceOf( 'WP_Post', get_post( $post_b ), 'Content is untouched.' );
		restore_current_blog();
		Plugin::instance()->get( 'settings' )->flush_cache();
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

	public function test_site_created_after_network_activation_is_configured() {
		update_site_option( 'active_sitewide_plugins', array( plugin_basename( WPASL_FILE ) => time() ) );
		Lifecycle::activate( true );

		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		Plugin::instance()->get( 'settings' )->flush_cache();
		$this->assertNotFalse( get_option( Settings::OPTION, false ), 'Defaults stored on creation.' );
		$this->assertNotFalse( wp_next_scheduled( WPASL\Generation\Scheduler::HOOK ), 'Recurring event scheduled on creation.' );
		$this->assertDirectoryExists( ( new Storage() )->base_dir() );
		Storage::delete_all();
		delete_option( Settings::OPTION );
		wp_clear_scheduled_hook( WPASL\Generation\Scheduler::HOOK );
		restore_current_blog();
		Plugin::instance()->get( 'settings' )->flush_cache();

		Lifecycle::deactivate( true );
		Storage::delete_all();
		delete_option( Settings::OPTION );
		delete_site_option( 'active_sitewide_plugins' );
	}

	public function test_site_created_without_network_activation_is_untouched() {
		delete_site_option( 'active_sitewide_plugins' );
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		$this->assertFalse( get_option( Settings::OPTION, false ) );
		$this->assertFalse( wp_next_scheduled( WPASL\Generation\Scheduler::HOOK ) );
		restore_current_blog();
	}
}
