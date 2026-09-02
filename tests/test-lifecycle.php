<?php
/**
 * Activation, deactivation and uninstall tests.
 *
 * @package WPASL
 */

use WPASL\Generation\Scheduler;
use WPASL\Lifecycle;
use WPASL\Settings;
use WPASL\Storage;
use WPASL\Uninstaller;

/**
 * Covers WPASL\Lifecycle and WPASL\Uninstaller.
 */
class Test_Lifecycle extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Uninstaller::run_site();
	}

	public function tear_down() {
		Uninstaller::run_site();
		parent::tear_down();
	}

	public function test_activation_registers_defaults_storage_and_cron() {
		Lifecycle::activate();

		$this->assertSame( Settings::defaults(), get_option( Settings::OPTION ) );

		$storage = new Storage();
		$this->assertDirectoryExists( $storage->base_dir() );
		$this->assertFileExists( Storage::root_dir() . '/.htaccess' );
		$this->assertFileExists( Storage::root_dir() . '/index.php' );
		$this->assertFileExists( $storage->base_dir() . '/index.php' );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', Storage::token() );

		$scheduler = new Scheduler( new Settings() );
		$this->assertSame( 'daily', $scheduler->current_interval() );
	}

	public function test_activation_keeps_existing_settings() {
		update_option( Settings::OPTION, array( 'schedule' => 'hourly' ) );
		Lifecycle::activate();
		$stored = get_option( Settings::OPTION );
		$this->assertSame( 'hourly', $stored['schedule'] );
	}

	public function test_activation_is_idempotent_for_cron() {
		Lifecycle::activate();
		Lifecycle::activate();
		$crons = _get_cron_array();
		$count = 0;
		foreach ( $crons as $hooks ) {
			if ( isset( $hooks[ Scheduler::HOOK ] ) ) {
				$count += count( $hooks[ Scheduler::HOOK ] );
			}
		}
		$this->assertSame( 1, $count );
	}

	public function test_deactivation_removes_cron() {
		Lifecycle::activate();
		Lifecycle::deactivate();
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK ) );
	}

	public function test_uninstall_removes_everything_but_content() {
		Lifecycle::activate();
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Uninstaller::EXCLUDE_META, true );
		set_transient( 'wpasl_diagnostics_report', array( 'x' ), HOUR_IN_SECONDS );
		$storage = new Storage();
		$storage->write( 'md/post/1.md', '# test' );

		Uninstaller::run();

		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( Storage::TOKEN_OPTION ) );
		$this->assertFalse( get_transient( 'wpasl_diagnostics_report' ) );
		$this->assertSame( '', get_post_meta( $post_id, Uninstaller::EXCLUDE_META, true ) );
		$this->assertDirectoryDoesNotExist( Storage::root_dir() );
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK ) );
		$this->assertInstanceOf( 'WP_Post', get_post( $post_id ), 'Content is untouched.' );

		global $wpdb;
		$leftover = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wpasl\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( '0', $leftover );
	}
}
