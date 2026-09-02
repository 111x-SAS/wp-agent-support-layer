<?php
/**
 * llms.txt tests.
 *
 * @package WPASL
 */

use WPASL\Admin\ExcludeMetaBox;
use WPASL\Llms\LlmsTxtBuilder;
use WPASL\Llms\LlmsTxtRouter;
use WPASL\Plugin;
use WPASL\Settings;
use WPASL\Storage;

/**
 * Covers WPASL\Llms\LlmsTxtBuilder and LlmsTxtRouter.
 */
class Test_Llms_Txt extends WP_UnitTestCase {

	/**
	 * @var LlmsTxtBuilder
	 */
	private $builder;

	/**
	 * @var LlmsTxtRouter
	 */
	private $router;

	/**
	 * @var Storage
	 */
	private $storage;

	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->builder = Plugin::instance()->get( 'llms' );
		$this->router  = Plugin::instance()->get( 'llms_router' );
		$this->storage = Plugin::instance()->get( 'storage' );
		Plugin::instance()->get( 'runner' )->clear();
		add_filter( 'wpasl_terminate_after_serve', '__return_false' );
	}

	public function tear_down() {
		remove_filter( 'wpasl_terminate_after_serve', '__return_false' );
		remove_all_filters( 'wpasl_physical_llms_path' );
		Plugin::instance()->get( 'runner' )->clear();
		delete_option( Settings::OPTION );
		Plugin::instance()->get( 'settings' )->flush_cache();
		parent::tear_down();
	}

	private function settings( array $values ) {
		update_option( Settings::OPTION, $values );
		Plugin::instance()->get( 'settings' )->flush_cache();
	}

	public function test_structure_with_pages_and_posts() {
		update_option( 'blogdescription', 'Just another site' );
		$page = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Acerca de',
				'post_name'    => 'acerca',
				'post_excerpt' => 'Quiénes somos',
			)
		);
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Primera [nota]',
				'post_name'    => 'primera',
				'post_excerpt' => '',
				'post_content' => '<p>Contenido de la nota con <strong>énfasis</strong>.</p>',
			)
		);

		$txt = $this->builder->build();

		$this->assertStringStartsWith( '# ' . get_bloginfo( 'name' ) . "\n\n> Just another site\n\n", $txt );
		$this->assertStringContainsString( "## Pages\n\n- [Acerca de](" . home_url( '/acerca.md' ) . "): Quiénes somos\n", $txt );
		$this->assertStringContainsString( "## Posts\n\n- [Primera \\[nota\\]](" . home_url( '/primera.md' ) . "): Contenido de la nota con énfasis.\n", $txt );
		$this->assertLessThan( strpos( $txt, '## Posts' ), strpos( $txt, '## Pages' ), 'Pages come first.' );
		$this->assertStringContainsString( "## Optional\n\n", $txt );
		$this->assertStringContainsString( '](' . home_url( '/agent-skills.json' ) . ')', $txt );
		$this->assertStringContainsString( '](' . rest_url( 'wpasl/v1/openapi' ) . ')', $txt );
		$this->assertStringContainsString( '](' . home_url( '/wp-sitemap.xml' ) . ')', $txt );
	}

	public function test_blockquote_falls_back_when_the_tagline_is_empty() {
		update_option( 'blogdescription', '' );
		$txt = $this->builder->build();
		$this->assertStringStartsWith( '# ' . get_bloginfo( 'name' ) . "\n\n> Content index of " . get_bloginfo( 'name' ) . ".\n\n", $txt );
	}

	public function test_custom_description_and_intro() {
		update_option( 'blogdescription', 'Just another site' );
		$this->settings(
			array(
				'llms_description' => 'Descripción propia',
				'llms_intro'       => "Intro **libre**.\n\n- punto",
			)
		);
		$txt = $this->builder->build();
		$this->assertStringContainsString( "> Descripción propia\n\nIntro **libre**.\n\n- punto\n\n", $txt );
		$this->assertStringNotContainsString( 'Just another site', $txt );
	}

	public function test_limit_keeps_the_most_recent_posts() {
		$this->settings( array( 'llms_limit' => 5 ) );
		$ids = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_title' => 'Entrada ' . $i,
					'post_date'  => gmdate( 'Y-m-d H:i:s', time() - ( 8 - $i ) * DAY_IN_SECONDS ),
				)
			);
		}
		$txt = $this->builder->build();
		for ( $i = 3; $i < 8; $i++ ) {
			$this->assertStringContainsString( '[Entrada ' . $i . ']', $txt );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertStringNotContainsString( '[Entrada ' . $i . ']', $txt );
		}
		$this->assertLessThan( strpos( $txt, '[Entrada 6]' ), strpos( $txt, '[Entrada 7]' ), 'Newest first.' );
	}

	public function test_pages_are_ordered_by_menu_order_then_title() {
		self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Zeta', 'menu_order' => 1 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Alfa', 'menu_order' => 2 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Beta', 'menu_order' => 1 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$txt = $this->builder->build();
		$this->assertLessThan( strpos( $txt, '[Zeta]' ), strpos( $txt, '[Beta]' ) );
		$this->assertLessThan( strpos( $txt, '[Alfa]' ), strpos( $txt, '[Zeta]' ) );
	}

	public function test_excluded_and_non_eligible_items_are_not_listed() {
		$excluded = self::factory()->post->create( array( 'post_title' => 'Excluida' ) );
		update_post_meta( $excluded, ExcludeMetaBox::META, true );
		self::factory()->post->create( array( 'post_title' => 'Borrador', 'post_status' => 'draft' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::factory()->post->create( array( 'post_title' => 'Protegida', 'post_password' => 'x' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::factory()->post->create( array( 'post_title' => 'Visible' ) );
		$txt = $this->builder->build();
		$this->assertStringContainsString( '[Visible]', $txt );
		$this->assertStringNotContainsString( '[Excluida]', $txt );
		$this->assertStringNotContainsString( '[Borrador]', $txt );
		$this->assertStringNotContainsString( '[Protegida]', $txt );
	}

	public function test_route_serves_llms_txt_with_lazy_generation() {
		self::factory()->post->create( array( 'post_title' => 'Servida' ) );
		$this->assertFalse( $this->storage->exists( LlmsTxtBuilder::FILE ) );

		ob_start();
		$this->go_to( home_url( '/llms.txt' ) );
		$out = ob_get_clean();

		$this->assertStringStartsWith( '# ', $out );
		$this->assertStringContainsString( '[Servida]', $out );
		$this->assertTrue( $this->storage->exists( LlmsTxtBuilder::FILE ) );
		$this->assertSame( 'text/markdown; charset=utf-8', $this->router->headers( $out )['Content-Type'] );
	}

	public function test_route_serves_stored_file_without_rebuilding() {
		$this->storage->ensure();
		$this->storage->write( LlmsTxtBuilder::FILE, "# Stored\n" );
		ob_start();
		$this->go_to( home_url( '/llms.txt' ) );
		$out = ob_get_clean();
		$this->assertSame( "# Stored\n", $out );
	}

	public function test_physical_file_takes_precedence() {
		$file = wp_tempnam( 'llms' );
		file_put_contents( $file, '# physical' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		add_filter(
			'wpasl_physical_llms_path',
			static function () use ( $file ) {
				return $file;
			}
		);
		$this->assertTrue( LlmsTxtRouter::physical_file_exists() );
		ob_start();
		$this->go_to( home_url( '/llms.txt' ) );
		$out = ob_get_clean();
		$this->assertSame( '', $out, 'The plugin steps aside; the web server serves the physical file.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$tabs = Plugin::instance()->get( 'page' )->tabs();
		ob_start();
		$tabs['llms']->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'A physical llms.txt file exists', $html );
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	public function test_llms_full_disabled_is_404() {
		ob_start();
		$this->go_to( home_url( '/llms-full.txt' ) );
		$out = ob_get_clean();
		$this->assertSame( '', $out );
		$this->assertTrue( is_404() );
	}

	public function test_llms_full_enabled_concatenates_documents() {
		$this->settings( array( 'llms_full_enabled' => true ) );
		self::factory()->post->create( array( 'post_title' => 'Uno', 'post_content' => '<p>Cuerpo uno</p>' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::factory()->post->create( array( 'post_title' => 'Dos', 'post_content' => '<p>Cuerpo dos</p>' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		ob_start();
		$this->go_to( home_url( '/llms-full.txt' ) );
		$out = ob_get_clean();

		$this->assertStringContainsString( "\n\n---\n\n---\ntitle: \"Dos\"", $out );
		$this->assertStringContainsString( '# Uno', $out );
		$this->assertStringContainsString( 'Cuerpo uno', $out );
		$this->assertStringContainsString( 'Cuerpo dos', $out );
		$this->assertStringNotContainsString( 'Truncated', $out );
		$this->assertTrue( $this->storage->exists( LlmsTxtBuilder::FULL_FILE ) );
	}

	public function test_llms_full_truncates_at_the_last_complete_item() {
		$this->settings(
			array(
				'llms_full_enabled'   => true,
				'llms_full_max_bytes' => 900,
			)
		);
		self::factory()->post->create( array( 'post_title' => 'Corta', 'post_content' => '<p>x</p>' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::factory()->post->create( array( 'post_title' => 'Larga', 'post_content' => '<p>' . str_repeat( 'palabra ', 300 ) . '</p>' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$full = $this->builder->build_full();
		$this->assertLessThanOrEqual( 900, strlen( $full ) );
		$this->assertStringContainsString( 'Truncated', $full );
		$this->assertStringNotContainsString( '# Larga', $full );
	}

	public function test_settings_change_invalidates_stored_files() {
		self::factory()->post->create();
		$this->router->document( LlmsTxtBuilder::FILE );
		$this->assertTrue( $this->storage->exists( LlmsTxtBuilder::FILE ) );

		$settings                     = Settings::defaults();
		$settings['llms_description'] = 'Nueva';
		update_option( Settings::OPTION, $settings );

		$this->assertFalse( $this->storage->exists( LlmsTxtBuilder::FILE ) );
		$this->assertStringContainsString( '> Nueva', $this->router->document( LlmsTxtBuilder::FILE ) );
	}

	public function test_builder_is_registered_as_artifact_generator() {
		$status = Plugin::instance()->get( 'runner' )->status();
		$this->assertContains( 'llms-txt', $status['artifacts'] );
		self::factory()->post->create();
		Plugin::instance()->get( 'runner' )->run_cycle();
		$this->assertTrue( $this->storage->exists( LlmsTxtBuilder::FILE ) );
		$this->assertFalse( $this->storage->exists( LlmsTxtBuilder::FULL_FILE ), 'Disabled by default.' );
	}

	public function test_tab_renders_and_sanitizes_mb() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$tabs = Plugin::instance()->get( 'page' )->tabs();
		$this->assertArrayHasKey( 'llms', $tabs );
		ob_start();
		$tabs['llms']->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'wpasl_settings[llms_full_max_bytes]', $html );
		$this->assertMatchesRegularExpression( '/name="wpasl_settings\[llms_full_max_bytes\]" value="5"/', $html );

		$clean = Plugin::instance()->get( 'settings' )->sanitize(
			array(
				'_tab'                => 'llms',
				'llms_full_max_bytes' => '2',
				'llms_limit'          => '0',
				'llms_description'    => '<b>x</b>',
			)
		);
		$this->assertSame( 2 * MB_IN_BYTES, $clean['llms_full_max_bytes'] );
		$this->assertSame( 100, $clean['llms_limit'] );
		$this->assertSame( 'x', $clean['llms_description'] );
		$this->assertFalse( $clean['llms_full_enabled'] );
	}
}
