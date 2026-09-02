<?php
/**
 * Settings and admin page tests.
 *
 * @package WPASL
 */

use WPASL\Admin\Page;
use WPASL\Plugin;
use WPASL\Settings;

/**
 * Covers WPASL\Settings and WPASL\Admin\Page.
 */
class Test_Settings extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	public function test_defaults() {
		$settings = new Settings();
		$this->assertSame( array( 'post', 'page' ), $settings->enabled_post_types() );
		$this->assertSame( 'daily', $settings->get( 'schedule' ) );
		$this->assertSame( 50, $settings->get( 'batch_size' ) );
		$this->assertSame( 'yes', $settings->get( 'signal_search' ) );
		$this->assertSame( 'yes', $settings->get( 'signal_ai_input' ) );
		$this->assertSame( 'no', $settings->get( 'signal_ai_train' ) );
		$this->assertTrue( $settings->get( 'content_usage_header' ) );
		$this->assertFalse( $settings->get( 'llms_full_enabled' ) );
		$this->assertSame( 100, $settings->get( 'llms_limit' ) );
		$this->assertTrue( $settings->get( 'manifest_enabled' ) );
		$this->assertSame( get_option( 'admin_email' ), $settings->contact_email() );
	}

	public function test_selectable_post_types_exclude_attachment_and_non_public() {
		register_post_type( 'wpasl_hidden', array( 'public' => false ) );
		register_post_type( 'wpasl_book', array( 'public' => true ) );

		$types = Settings::selectable_post_types();
		$this->assertContains( 'post', $types );
		$this->assertContains( 'page', $types );
		$this->assertContains( 'wpasl_book', $types );
		$this->assertNotContains( 'attachment', $types );
		$this->assertNotContains( 'wpasl_hidden', $types );

		unregister_post_type( 'wpasl_hidden' );
		unregister_post_type( 'wpasl_book' );
	}

	public function test_sanitize_only_touches_submitted_tab() {
		$settings = new Settings();
		update_option( Settings::OPTION, array( 'signal_ai_train' => 'yes' ) );
		$settings->flush_cache();

		$clean = $settings->sanitize(
			array(
				'_tab'       => 'general',
				'post_types' => array( 'page', 'attachment', 'nope' ),
				'schedule'   => 'weekly',
				'batch_size' => '9999',
			)
		);

		$this->assertSame( array( 'page' ), $clean['post_types'] );
		$this->assertSame( 'weekly', $clean['schedule'] );
		$this->assertSame( 500, $clean['batch_size'] );
		$this->assertSame( 'yes', $clean['signal_ai_train'], 'Other tabs keep their stored values.' );
	}

	public function test_sanitize_unchecked_boxes_of_submitted_tab_become_false() {
		$settings = new Settings();
		$clean    = $settings->sanitize( array( '_tab' => 'signals' ) );
		$this->assertSame( 'no', $clean['signal_search'] );
		$this->assertSame( 'no', $clean['signal_ai_input'] );
		$this->assertFalse( $clean['content_usage_header'] );
	}

	public function test_sanitize_rejects_invalid_values() {
		$settings = new Settings();
		$clean    = $settings->sanitize(
			array(
				'_tab'          => 'manifests',
				'contact_email' => 'not-an-email',
			)
		);
		$this->assertSame( '', $clean['contact_email'] );

		$clean = $settings->sanitize(
			array(
				'_tab'     => 'general',
				'schedule' => 'every-minute',
			)
		);
		$this->assertSame( 'daily', $clean['schedule'] );
	}

	/**
	 * A complete form submission touching every setting.
	 *
	 * @return array<string, mixed>
	 */
	private function full_input() {
		return array(
			'post_types'             => array( 'page' ),
			'schedule'               => 'weekly',
			'batch_size'             => '25',
			'signal_search'          => 'yes',
			'signal_ai_input'        => 'no',
			'signal_ai_train'        => 'yes',
			'content_usage_header'   => '1',
			'crawler_overrides'      => array( 'GPTBot' => 'allow' ),
			'llms_description'       => 'Desc',
			'llms_intro'             => "Intro\nline",
			'llms_limit'             => '40',
			'llms_full_enabled'      => '1',
			'llms_full_max_bytes_mb' => '5',
			'manifest_enabled'       => '1',
			'contact_email'          => 'a@example.org',
		);
	}

	public function test_sanitize_is_idempotent_for_every_field() {
		$settings = new Settings();
		$once     = $settings->sanitize( $this->full_input() );
		$twice    = $settings->sanitize( $once );
		$this->assertSame( $once, $twice );
		$this->assertSame( 5 * MB_IN_BYTES, $once['llms_full_max_bytes'] );
		$this->assertArrayNotHasKey( 'llms_full_max_bytes_mb', $once );

		// The real trigger: update_option() on a missing option falls back to add_option() and sanitizes twice.
		Plugin::instance()->get( 'page' )->register_setting();
		delete_option( Settings::OPTION );
		update_option( Settings::OPTION, array_merge( $this->full_input(), array( '_tab' => 'llms' ) ) );
		$stored = get_option( Settings::OPTION );
		$this->assertSame( 5 * MB_IN_BYTES, $stored['llms_full_max_bytes'] );
		$this->assertSame( 40, $stored['llms_limit'] );
	}

	public function test_llms_full_max_out_of_range_clamps_to_100_mb() {
		$settings = new Settings();
		$clean    = $settings->sanitize( array( '_tab' => 'llms', 'llms_full_max_bytes_mb' => '500' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 100 * MB_IN_BYTES, $clean['llms_full_max_bytes'] );

		$clean = $settings->sanitize( array( '_tab' => 'llms', 'llms_full_max_bytes_mb' => '0' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 5 * MB_IN_BYTES, $clean['llms_full_max_bytes'], 'Zero or absent: default.' );

		$clean = $settings->sanitize( array( 'llms_full_max_bytes' => 900 ) );
		$this->assertSame( 900, $clean['llms_full_max_bytes'], 'A stored byte value passes through unchanged.' );
		$clean = $settings->sanitize( array( 'llms_full_max_bytes' => 500 * MB_IN_BYTES ) );
		$this->assertSame( 100 * MB_IN_BYTES, $clean['llms_full_max_bytes'] );
	}

	public function test_page_is_registered_under_tools_for_administrators() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
		do_action( 'admin_menu' );

		global $submenu;
		$found = false;
		foreach ( (array) ( isset( $submenu['tools.php'] ) ? $submenu['tools.php'] : array() ) as $item ) {
			if ( Page::SLUG === $item[2] ) {
				$found = true;
				$this->assertSame( 'manage_options', $item[1] );
			}
		}
		$this->assertTrue( $found, 'Submenu registered under Tools.' );
	}

	public function test_page_denies_editors() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$page = Plugin::instance()->get( 'page' );
		$this->assertInstanceOf( Page::class, $page );

		$this->expectException( 'WPDieException' );
		$page->render();
	}

	public function test_page_renders_general_tab_for_administrators() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$page = Plugin::instance()->get( 'page' );

		ob_start();
		$page->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'nav-tab-active', $html );
		$this->assertStringContainsString( 'name="wpasl_settings[_tab]" value="general"', $html );
		$this->assertStringContainsString( 'wpasl_settings[post_types][]', $html );
		$this->assertMatchesRegularExpression( '/value="post"\s+checked/', $html );
		$this->assertMatchesRegularExpression( '/value="page"\s+checked/', $html );
	}

	public function test_option_is_registered_with_settings_api() {
		Plugin::instance()->get( 'page' )->register_setting();
		$registered = get_registered_settings();
		$this->assertArrayHasKey( Settings::OPTION, $registered );
		$this->assertSame( 'wpasl', $registered[ Settings::OPTION ]['group'] );
	}
}
