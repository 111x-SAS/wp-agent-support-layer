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
		Plugin::instance()->get( 'settings' )->flush_cache();
		$GLOBALS['wp_settings_errors'] = array();
		unset( $_GET['settings-updated'], $_GET['tab'] );
		parent::tear_down();
	}

	public function test_defaults() {
		$settings = new Settings();
		$this->assertSame( array( 'post', 'page' ), $settings->enabled_post_types() );
		$this->assertSame( 'daily', $settings->get( 'schedule' ) );
		$this->assertSame( 50, $settings->get( 'batch_size' ) );
		$this->assertSame( array(), $settings->get( 'content_source' ) );
		$this->assertSame( '', $settings->get( 'content_selector' ) );
		$this->assertSame( 'auto', $settings->content_source( 'page' ) );
		$this->assertContains( 'content_source', Settings::TAB_KEYS['general'] );
		$this->assertContains( 'content_selector', Settings::TAB_KEYS['general'] );
		$this->assertSame( 'yes', $settings->get( 'signal_search' ) );
		$this->assertSame( 'yes', $settings->get( 'signal_ai_input' ) );
		$this->assertSame( 'no', $settings->get( 'signal_ai_train' ) );
		$this->assertTrue( $settings->get( 'content_usage_header' ) );
		$this->assertFalse( $settings->get( 'llms_full_enabled' ) );
		$this->assertSame( 10, $settings->get( 'llms_preview_limit' ) );
		$this->assertSame( 1000, $settings->get( 'llms_type_limit' ) );
		$this->assertArrayNotHasKey( 'llms_limit', Settings::defaults() );
		$this->assertNotContains( 'llms_limit', Settings::TAB_KEYS['llms'] );
		$this->assertTrue( $settings->get( 'manifest_enabled' ) );
		$this->assertTrue( $settings->get( 'auth_md_enabled' ) );
		$this->assertSame( '', $settings->get( 'auth_md_notes' ) );
		$this->assertSame( '', $settings->get( 'llms_when_to_use' ) );
		$this->assertContains( 'llms_when_to_use', Settings::TAB_KEYS['llms'] );
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
			'content_source'         => array(
				'page' => 'rendered',
				'post' => 'foo',
			),
			'content_selector'       => ' div.entry-content>.inner ,  main   article ',
			'signal_search'          => 'yes',
			'signal_ai_input'        => 'no',
			'signal_ai_train'        => 'yes',
			'content_usage_header'   => '1',
			'crawler_overrides'      => array( 'GPTBot' => 'allow' ),
			'llms_description'       => 'Desc',
			'llms_intro'             => "Intro\nline",
			'llms_when_to_use'       => str_repeat( 'w', Settings::LLMS_WHEN_TO_USE_MAX + 100 ) . '<i>x</i>',
			'llms_preview_limit'     => '40',
			'llms_type_limit'        => '2000',
			'llms_full_enabled'      => '1',
			'llms_full_max_bytes_mb' => '5',
			'manifest_enabled'       => '1',
			'auth_md_enabled'        => '1',
			'contact_email'          => 'a@example.org',
			'auth_md_notes'          => str_repeat( 'n', Settings::AUTH_MD_NOTES_MAX + 500 ) . "\n<b>bold</b>",
		);
	}

	public function test_sanitize_is_idempotent_for_every_field() {
		$settings = new Settings();
		$once     = $settings->sanitize( $this->full_input() );
		$twice    = $settings->sanitize( $once );
		$this->assertSame( $once, $twice );
		$this->assertSame( array( 'page' => 'rendered' ), $once['content_source'], 'Only forced values are stored; "foo" is auto.' );
		$this->assertSame( 'div.entry-content > .inner, main article', $once['content_selector'] );
		$this->assertSame( 5 * MB_IN_BYTES, $once['llms_full_max_bytes'] );
		$this->assertArrayNotHasKey( 'llms_full_max_bytes_mb', $once );
		$this->assertSame( Settings::AUTH_MD_NOTES_MAX, mb_strlen( $once['auth_md_notes'] ), 'Notes above the limit are truncated once.' );
		$this->assertSame( Settings::LLMS_WHEN_TO_USE_MAX, mb_strlen( $once['llms_when_to_use'] ) );

		// The real trigger: update_option() on a missing option falls back to add_option() and sanitizes twice.
		Plugin::instance()->get( 'page' )->register_setting();
		delete_option( Settings::OPTION );
		update_option( Settings::OPTION, array_merge( $this->full_input(), array( '_tab' => 'llms' ) ) );
		$stored = get_option( Settings::OPTION );
		$this->assertSame( 5 * MB_IN_BYTES, $stored['llms_full_max_bytes'] );
		$this->assertSame( 40, $stored['llms_preview_limit'] );
		$this->assertSame( 2000, $stored['llms_type_limit'] );
	}

	public function test_auth_md_notes_are_truncated_to_4000_chars() {
		$settings = new Settings();
		$notes    = str_repeat( 'á', 5000 );
		$once     = $settings->sanitize(
			array(
				'_tab'          => 'manifests',
				'auth_md_notes' => $notes,
			)
		);
		$this->assertSame( 4000, mb_strlen( $once['auth_md_notes'] ) );
		$this->assertSame( str_repeat( 'á', 4000 ), $once['auth_md_notes'], 'Multibyte characters are never cut in half.' );
		$twice = $settings->sanitize( array_merge( $once, array( '_tab' => 'manifests' ) ) );
		$this->assertSame( $once['auth_md_notes'], $twice['auth_md_notes'] );

		$clean = $settings->sanitize(
			array(
				'_tab'          => 'manifests',
				'auth_md_notes' => "Rate limit: <b>60 requests</b>\nper minute.",
			)
		);
		$this->assertSame( "Rate limit: 60 requests\nper minute.", $clean['auth_md_notes'], 'Tags are stripped, line breaks kept.' );
		$this->assertFalse( $clean['auth_md_enabled'], 'An absent checkbox of the submitted tab is unchecked.' );
	}

	public function test_llms_when_to_use_is_sanitized_and_truncated() {
		$settings = new Settings();
		$clean    = $settings->sanitize(
			array(
				'_tab'             => 'llms',
				'llms_when_to_use' => "Use this site for <b>official</b> course descriptions.\n\n- Read llms.txt first.",
			)
		);
		$this->assertSame( "Use this site for official course descriptions.\n\n- Read llms.txt first.", $clean['llms_when_to_use'], 'Tags are stripped, line breaks kept.' );

		$once = $settings->sanitize(
			array(
				'_tab'             => 'llms',
				'llms_when_to_use' => str_repeat( 'é', 5000 ),
			)
		);
		$this->assertSame( 4000, mb_strlen( $once['llms_when_to_use'] ) );
		$this->assertSame( str_repeat( 'é', 4000 ), $once['llms_when_to_use'] );
		$twice = $settings->sanitize( array_merge( $once, array( '_tab' => 'llms' ) ) );
		$this->assertSame( $once['llms_when_to_use'], $twice['llms_when_to_use'], 'Idempotent.' );

		$clean = $settings->sanitize( array( '_tab' => 'llms' ) );
		$this->assertSame( '', $clean['llms_when_to_use'], 'Absent field of the submitted tab: empty.' );
	}

	public function test_saving_general_tab_keeps_llms_settings() {
		$stored   = $this->store_non_defaults();
		$settings = new Settings();
		$clean    = $settings->sanitize(
			array(
				'_tab'       => 'general',
				'post_types' => array( 'post' ),
			)
		);
		$this->assertSame( $stored['llms_when_to_use'], $clean['llms_when_to_use'] );
		$this->assertSame( $stored['llms_description'], $clean['llms_description'] );
		$this->assertSame( $stored['llms_intro'], $clean['llms_intro'] );
		$this->assertSame( array( 'post' ), $clean['post_types'] );
	}

	public function test_saving_another_tab_keeps_auth_md_settings() {
		$stored   = $this->store_non_defaults();
		$settings = new Settings();
		$clean    = $settings->sanitize(
			array(
				'_tab'       => 'general',
				'post_types' => array( 'post' ),
			)
		);
		$this->assertFalse( $clean['auth_md_enabled'] );
		$this->assertSame( $stored['auth_md_notes'], $clean['auth_md_notes'] );
		$this->assertSame( array( 'post' ), $clean['post_types'] );

		// Saving the Signals tab keeps the content source and the selector of the General tab.
		$clean = $settings->sanitize(
			array(
				'_tab'          => 'signals',
				'signal_search' => 'yes',
			)
		);
		$this->assertSame( array( 'page' => 'rendered' ), $clean['content_source'] );
		$this->assertSame( 'main article', $clean['content_selector'] );
		$this->assertSame( 'yes', $clean['signal_search'] );
	}

	public function test_content_source_and_selector_defaults_and_sanitization() {
		$settings = new Settings();
		$this->assertSame( array(), $settings->get( 'content_source' ) );
		$this->assertSame( '', $settings->get( 'content_selector' ) );
		$this->assertSame( 'auto', $settings->content_source( 'post' ) );

		$clean = $settings->sanitize(
			array(
				'_tab'             => 'general',
				'post_types'       => array( 'post', 'page' ),
				'content_source'   => array(
					'page' => 'rendered',
					'post' => 'foo',
					'nope' => 'editor',
				),
				'content_selector' => ' main   article ',
			)
		);
		$this->assertSame( array( 'page' => 'rendered' ), $clean['content_source'], '"foo" is stored as auto (absent); unknown types are dropped.' );
		$this->assertSame( 'main article', $clean['content_selector'] );
		$twice = $settings->sanitize( array_merge( $clean, array( '_tab' => 'general' ) ) );
		$this->assertSame( $clean['content_source'], $twice['content_source'], 'Idempotent.' );
		$this->assertSame( $clean['content_selector'], $twice['content_selector'], 'Idempotent.' );
		$this->assertSame( array(), get_settings_errors( Settings::OPTION ) );

		update_option( Settings::OPTION, $clean );
		$settings->flush_cache();
		$this->assertSame( 'rendered', $settings->content_source( 'page' ) );
		$this->assertSame( 'auto', $settings->content_source( 'post' ) );

		// An invalid selector keeps the stored value and registers a Settings API error.
		update_option( Settings::OPTION, array( 'content_selector' => 'main' ) );
		$settings->flush_cache();
		foreach ( array( 'div:has(p)', 'a::before' ) as $invalid ) {
			$GLOBALS['wp_settings_errors'] = array();
			$clean                         = $settings->sanitize(
				array(
					'_tab'             => 'general',
					'post_types'       => array( 'post', 'page' ),
					'content_selector' => $invalid,
				)
			);
			$this->assertSame( 'main', $clean['content_selector'], $invalid );
			$errors = get_settings_errors( Settings::OPTION );
			$this->assertCount( 1, $errors, $invalid );
			$this->assertSame( 'content_selector', $errors[0]['code'] );
			$this->assertSame( 'error', $errors[0]['type'] );
			$this->assertStringContainsString( 'descendant and child combinators', $errors[0]['message'] );
		}

		$GLOBALS['wp_settings_errors'] = array();
		$clean                         = $settings->sanitize(
			array(
				'_tab'             => 'general',
				'content_selector' => 'div.entry-content, main article',
			)
		);
		$this->assertSame( 'div.entry-content, main article', $clean['content_selector'] );
		$this->assertSame( array(), get_settings_errors( Settings::OPTION ) );

		$clean = $settings->sanitize( array( '_tab' => 'general' ) );
		$this->assertSame( '', $clean['content_selector'], 'An empty field means automatic detection.' );
		$this->assertSame( array(), $clean['content_source'] );
	}

	public function test_general_tab_renders_content_selector() {
		$html          = $this->render_page( 'general' );
		$settings_form = $this->form_html( $html, 'options.php' );
		$this->assertStringNotContainsString( 'Content source', $settings_form );
		$this->assertStringNotContainsString( 'wpasl_settings[content_source]', $settings_form );
		$this->assertStringContainsString( 'Content selector (CSS)', $settings_form );
		$this->assertMatchesRegularExpression( '/<input type="text" id="wpasl-content-selector" name="wpasl_settings\[content_selector\]" value=""/', $settings_form );
		$this->assertStringContainsString( 'Leave empty for automatic detection', $settings_form );

		update_option(
			Settings::OPTION,
			array(
				'post_types'       => array( 'page' ),
				'content_source'   => array( 'page' => 'rendered' ),
				'content_selector' => 'div.entry-content > .inner',
			)
		);
		Plugin::instance()->get( 'settings' )->flush_cache();
		$html          = $this->render_page( 'general' );
		$settings_form = $this->form_html( $html, 'options.php' );
		$this->assertStringNotContainsString( 'wpasl_settings[content_source]', $settings_form );
		$this->assertStringContainsString( 'value="div.entry-content &gt; .inner"', $settings_form );
	}

	public function test_saving_general_tab_clears_a_forced_content_source() {
		update_option(
			Settings::OPTION,
			array(
				'post_types'     => array( 'page' ),
				'content_source' => array( 'page' => 'rendered' ),
			)
		);
		$settings = new Settings();
		$this->assertSame( 'rendered', $settings->content_source( 'page' ) );

		$clean = $settings->sanitize(
			array(
				'_tab'       => 'general',
				'post_types' => array( 'page' ),
				'schedule'   => 'daily',
				'batch_size' => 50,
			)
		);
		$this->assertSame( array(), $clean['content_source'] );

		update_option( Settings::OPTION, $clean );
		$settings->flush_cache();
		$this->assertSame( 'auto', $settings->content_source( 'page' ) );
	}

	/**
	 * Stores non-default values for every tab and returns them.
	 *
	 * @return array<string, mixed>
	 */
	private function store_non_defaults() {
		$stored = array(
			'post_types'           => array( 'page' ),
			'schedule'             => 'weekly',
			'batch_size'           => 25,
			'content_source'       => array( 'page' => 'rendered' ),
			'content_selector'     => 'main article',
			'signal_search'        => 'no',
			'signal_ai_input'      => 'no',
			'signal_ai_train'      => 'yes',
			'content_usage_header' => false,
			'crawler_overrides'    => array( 'GPTBot' => 'allow' ),
			'llms_description'     => 'Desc',
			'llms_intro'           => 'Intro',
			'llms_when_to_use'     => 'Use this site for official course descriptions.',
			'llms_preview_limit'   => 40,
			'llms_type_limit'      => 2000,
			'llms_full_enabled'    => true,
			'llms_full_max_bytes'  => 7 * MB_IN_BYTES,
			'manifest_enabled'     => false,
			'auth_md_enabled'      => false,
			'contact_email'        => 'a@example.org',
			'auth_md_notes'        => 'Rate limit: 60 requests per minute.',
		);
		update_option( Settings::OPTION, $stored );
		Plugin::instance()->get( 'settings' )->flush_cache();
		return $stored;
	}

	public function test_sanitize_with_unknown_tab_keeps_every_stored_value() {
		$stored   = $this->store_non_defaults();
		$settings = new Settings();
		$clean    = $settings->sanitize( array( '_tab' => 'otro', 'post_types' => array( 'post' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( $stored, array_intersect_key( $clean, $stored ) );
	}

	public function test_sanitize_without_tab_updates_only_present_keys() {
		$stored   = $this->store_non_defaults();
		$settings = new Settings();

		$clean = $settings->sanitize( array( 'batch_size' => '10' ) );
		$this->assertSame( 10, $clean['batch_size'] );
		unset( $stored['batch_size'] );
		$this->assertSame( $stored, array_intersect_key( $clean, $stored ), 'Every other value, including post types and signals, is preserved.' );

		$clean = $settings->sanitize( array( 'llms_full_max_bytes_mb' => '3' ) );
		$this->assertSame( 3 * MB_IN_BYTES, $clean['llms_full_max_bytes'], 'The MB form field still feeds the byte setting.' );
		$this->assertSame( array( 'page' ), $clean['post_types'] );

		// The Settings API path: update_option() with a partial array goes through the registered sanitizer.
		Plugin::instance()->get( 'page' )->register_setting();
		update_option( Settings::OPTION, array( 'llms_preview_limit' => 12 ) );
		$after = get_option( Settings::OPTION );
		$this->assertSame( 12, $after['llms_preview_limit'] );
		$this->assertSame( array( 'page' ), $after['post_types'] );
		$this->assertSame( 'yes', $after['signal_ai_train'] );
	}

	public function test_third_party_tab_saves_without_wiping_settings() {
		$stored = $this->store_non_defaults();
		$tab    = new class() implements WPASL\Admin\Tab {
			public function slug() {
				return 'acme';
			}
			public function label() {
				return 'Acme';
			}
			public function has_form() {
				return true;
			}
			public function render() {
				echo '<input type="text" name="acme_field" value="x" />';
			}
		};
		// The shared Page already collected its tabs (wpasl_register_tabs ran once), so the third-party tab is
		// added the same way that hook's callbacks do.
		Plugin::instance()->get( 'page' )->add_tab( $tab );
		$html = $this->render_page( 'acme' );

		$this->assertStringContainsString( 'name="wpasl_settings[_tab]" value="acme"', $html );
		$this->assertStringContainsString( 'name="acme_field"', $html );

		$clean = ( new Settings() )->sanitize( array( '_tab' => 'acme', 'acme_field' => 'x' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( $stored, array_intersect_key( $clean, $stored ) );
		$this->assertSame( array( 'page' ), $clean['post_types'] );
	}

	public function test_page_shows_settings_saved_notice() {
		$_GET['settings-updated'] = 'true';
		set_transient( 'settings_errors', array( array( 'setting' => 'general', 'code' => 'settings_updated', 'message' => 'Settings saved.', 'type' => 'success' ) ), 30 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$html = $this->render_page( 'signals' );
		unset( $_GET['settings-updated'] );

		$this->assertSame( 1, substr_count( $html, 'Settings saved.' ) );
	}

	public function test_llms_limits_ranges_and_defaults() {
		$settings = new Settings();
		$clean    = $settings->sanitize( array( '_tab' => 'llms', 'llms_preview_limit' => '5', 'llms_type_limit' => '2000' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 5, $clean['llms_preview_limit'] );
		$this->assertSame( 2000, $clean['llms_type_limit'] );

		$clean = $settings->sanitize( array( '_tab' => 'llms', 'llms_preview_limit' => '500', 'llms_type_limit' => '50000' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 100, $clean['llms_preview_limit'] );
		$this->assertSame( 10000, $clean['llms_type_limit'] );

		foreach ( array( '0', '', null ) as $empty ) {
			$clean = $settings->sanitize( array( '_tab' => 'llms', 'llms_preview_limit' => $empty, 'llms_type_limit' => $empty ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->assertSame( 10, $clean['llms_preview_limit'], wp_json_encode( $empty ) );
			$this->assertSame( 1000, $clean['llms_type_limit'], wp_json_encode( $empty ) );
		}

		$once  = $settings->sanitize( array( '_tab' => 'llms', 'llms_preview_limit' => '7', 'llms_type_limit' => '70' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$twice = $settings->sanitize( array_merge( $once, array( '_tab' => 'llms' ) ) );
		$this->assertSame( $once, $twice, 'Idempotent.' );

		$stored = $this->store_non_defaults();
		$clean  = $settings->sanitize( array( '_tab' => 'general', 'post_types' => array( 'post' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( $stored['llms_preview_limit'], $clean['llms_preview_limit'], 'Saving another tab keeps the limits.' );
		$this->assertSame( $stored['llms_type_limit'], $clean['llms_type_limit'] );
	}

	public function test_stored_llms_limit_from_previous_version_is_inert() {
		$stored = Settings::defaults();
		unset( $stored['llms_preview_limit'], $stored['llms_type_limit'] );
		$stored['llms_limit'] = 100;
		update_option( Settings::OPTION, $stored );
		$settings = new Settings();
		$this->assertSame( 10, $settings->get( 'llms_preview_limit' ), 'New limits take their defaults.' );
		$this->assertSame( 1000, $settings->get( 'llms_type_limit' ) );

		$clean = $settings->sanitize( array( '_tab' => 'llms', 'llms_preview_limit' => '3' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 3, $clean['llms_preview_limit'] );
		$this->assertSame( 1000, $clean['llms_type_limit'] );
		$this->assertSame( 100, $clean['llms_limit'], 'Neither sanitized nor removed: the stored key is simply ignored.' );

		$clean = $settings->sanitize( array( 'llms_limit' => 5 ) );
		$this->assertSame( 10, $clean['llms_preview_limit'], 'A submitted llms_limit no longer feeds any setting.' );
		$this->assertSame( 100, $clean['llms_limit'] );

		Plugin::instance()->get( 'settings' )->flush_cache();
		self::factory()->post->create_many( 12 );
		$txt = Plugin::instance()->get( 'llms' )->build();
		$this->assertSame( 10, substr_count( $txt, "\n- [Post title" ), 'llms.txt uses the preview limit, not llms_limit.' );
		$this->assertStringContainsString( '- [Full list of Posts (12 items)](' . home_url( '/llms-post.txt' ) . ')', $txt );
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

	/**
	 * Renders the settings page for an administrator and returns the HTML.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private function render_page( $tab = 'general' ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['tab'] = $tab;
		ob_start();
		Plugin::instance()->get( 'page' )->render();
		unset( $_GET['tab'] );
		return ob_get_clean();
	}

	public function test_general_tab_renders_status_form_outside_settings_form() {
		$html = $this->render_page( 'general' );

		// Exactly two forms: the Settings API form and the manual-action form, none nested.
		preg_match_all( '/<form\b[^>]*>|<\/form>/', $html, $tags );
		$this->assertSame( array( 'open', 'close', 'open', 'close' ), array_map( array( $this, 'form_tag_kind' ), $tags[0] ) );

		$settings_form = $this->form_html( $html, 'options.php' );
		$this->assertStringContainsString( 'name="submit"', $settings_form );
		$this->assertStringContainsString( 'name="wpasl_settings[_tab]" value="general"', $settings_form );
		$this->assertStringNotContainsString( 'wpasl_regenerate_nonce', $settings_form );

		$action_form = $this->form_html( $html, 'admin-post.php' );
		$this->assertStringContainsString( 'wpasl_regenerate_nonce', $action_form );
		$this->assertStringContainsString( 'name="action" value="wpasl_regenerate"', $action_form );
		$this->assertStringContainsString( 'Regenerate everything', $action_form );
		$this->assertStringNotContainsString( 'wpasl_settings[', $action_form );
	}

	public function test_general_tab_after_hook_still_fires_inside_form() {
		$marker = '<!-- wpasl-third-party-marker -->';
		$print  = static function () use ( $marker ) {
			echo $marker; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		};
		add_action( 'wpasl_general_tab_after', $print );
		$html = $this->render_page( 'general' );
		remove_action( 'wpasl_general_tab_after', $print );

		$settings_form = $this->form_html( $html, 'options.php' );
		$this->assertStringContainsString( $marker, $settings_form );
		$this->assertStringContainsString( $marker, $html );
	}

	public function test_page_after_form_hook_receives_current_tab() {
		$received = array();
		$capture  = static function ( $current, $page ) use ( &$received ) {
			$received[] = array( $current, $page );
			echo '<!-- after-form -->';
		};
		add_action( 'wpasl_page_after_form', $capture, 10, 2 );
		$html = $this->render_page( 'signals' );
		remove_action( 'wpasl_page_after_form', $capture, 10 );

		$this->assertCount( 1, $received );
		$this->assertSame( 'signals', $received[0][0] );
		$this->assertInstanceOf( Page::class, $received[0][1] );
		$this->assertStringNotContainsString( 'wpasl_regenerate_nonce', $html );
		$this->assertGreaterThan( strrpos( $html, '</form>' ), strpos( $html, '<!-- after-form -->' ) );
	}

	/**
	 * @param string $tag A <form ...> or </form> tag.
	 * @return string
	 */
	public function form_tag_kind( $tag ) {
		return 0 === strpos( $tag, '</' ) ? 'close' : 'open';
	}

	/**
	 * Returns the markup of the form whose action contains $action_fragment.
	 *
	 * @param string $html            Page HTML.
	 * @param string $action_fragment Fragment of the action attribute.
	 * @return string
	 */
	private function form_html( $html, $action_fragment ) {
		preg_match_all( '/<form\b[^>]*>.*?<\/form>/s', $html, $forms );
		foreach ( $forms[0] as $form ) {
			if ( preg_match( '/<form\b[^>]*action="[^"]*' . preg_quote( $action_fragment, '/' ) . '[^"]*"/', $form ) ) {
				return $form;
			}
		}
		$this->fail( 'No form with action containing ' . $action_fragment );
		return '';
	}

	public function test_option_is_registered_with_settings_api() {
		Plugin::instance()->get( 'page' )->register_setting();
		$registered = get_registered_settings();
		$this->assertArrayHasKey( Settings::OPTION, $registered );
		$this->assertSame( 'wpasl', $registered[ Settings::OPTION ]['group'] );
	}
}
