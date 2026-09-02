<?php
/**
 * Activation, deactivation and uninstall tests.
 *
 * @package WPASL
 */

use WPASL\Generation\Scheduler;
use WPASL\Lifecycle;
use WPASL\Plugin;
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

	/**
	 * Autoload flag of an option as stored in the database.
	 *
	 * @param string $option Option name.
	 * @return string
	 */
	private function autoload_of( $option ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function test_settings_and_token_options_are_autoloaded() {
		global $wpdb;
		$autoloaded = array( 'yes', 'on', 'auto-on' );

		Lifecycle::activate();
		$this->assertContains( $this->autoload_of( Settings::OPTION ), $autoloaded, 'Settings created on activation are autoloaded.' );
		$this->assertContains( $this->autoload_of( Storage::TOKEN_OPTION ), $autoloaded, 'Storage token is autoloaded.' );

		// An install created by 1.0.x stored both without autoload: the upgrade routine fixes it once.
		foreach ( array( Settings::OPTION, Storage::TOKEN_OPTION ) as $option ) {
			$wpdb->update( $wpdb->options, array( 'autoload' => 'no' ), array( 'option_name' => $option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		wp_cache_flush();
		delete_option( Plugin::VERSION_OPTION );
		$this->assertSame( 'no', $this->autoload_of( Settings::OPTION ) );

		Plugin::instance()->maybe_upgrade();
		$this->assertContains( $this->autoload_of( Settings::OPTION ), $autoloaded );
		$this->assertContains( $this->autoload_of( Storage::TOKEN_OPTION ), $autoloaded );
		$this->assertSame( WPASL_VERSION, get_option( Plugin::VERSION_OPTION ) );
		$this->assertSame( 10, has_action( 'admin_init', array( Plugin::instance(), 'maybe_upgrade' ) ) );

		$settings = new Settings();
		$settings->update( array_merge( Settings::defaults(), array( 'batch_size' => 7 ) ) );
		$this->assertContains( $this->autoload_of( Settings::OPTION ), $autoloaded, 'Saving from the settings page keeps autoload.' );
	}

	public function test_uninstall_removes_version_option() {
		Lifecycle::activate();
		update_option( Plugin::VERSION_OPTION, '1.0.2', true );
		set_transient( 'wpasl_diagnostics_run_1', array( 'pending' => array( 'GPTBot' ) ), HOUR_IN_SECONDS );
		set_transient( 'wpasl_diagnostics_run_7', array( 'pending' => array( 'GPTBot' ) ), HOUR_IN_SECONDS );

		Uninstaller::run();

		$this->assertFalse( get_option( Plugin::VERSION_OPTION ) );
		$this->assertFalse( get_transient( 'wpasl_diagnostics_run_1' ) );
		$this->assertFalse( get_transient( 'wpasl_diagnostics_run_7' ) );
		global $wpdb;
		$leftover = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%wpasl\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( '0', $leftover, 'No option or transient of the plugin remains.' );
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
