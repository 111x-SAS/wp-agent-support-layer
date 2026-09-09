<?php
/**
 * Content source resolution tests.
 *
 * @package WPASL
 */

use WPASL\Admin\ExcludeMetaBox;
use WPASL\Markdown\ContentSource;
use WPASL\Markdown\LeagueConverter;
use WPASL\Plugin;
use WPASL\Settings;

/**
 * Covers WPASL\Markdown\ContentSource.
 */
class Test_Content_Source extends WP_UnitTestCase {

	/**
	 * @var ContentSource
	 */
	private $source;

	/**
	 * Files created inside the active theme, removed on tear down.
	 *
	 * @var string[]
	 */
	private $theme_files = array();

	/**
	 * Temporary directory of the templates handed over through wpasl_template_file.
	 *
	 * @var string
	 */
	private $tmp;

	public function set_up() {
		parent::set_up();
		$this->source = new ContentSource( Plugin::instance()->get( 'settings' ), array( $this, 'convert_editor' ) );
		ContentSource::flush_template_cache();
		$this->tmp = trailingslashit( get_temp_dir() ) . 'wpasl-templates-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->tmp );
	}

	public function tear_down() {
		foreach ( array( 'wpasl_content_source', 'wpasl_builder_meta_keys', 'wpasl_template_candidates', 'wpasl_template_file', 'wpasl_editor_min_chars' ) as $hook ) {
			remove_all_filters( $hook );
		}
		foreach ( $this->theme_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		$parts = realpath( get_stylesheet_directory() ) . '/template-parts';
		if ( is_dir( $parts ) ) {
			rmdir( $parts ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		foreach ( (array) glob( $this->tmp . '/*' ) as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		if ( is_dir( $this->tmp ) ) {
			rmdir( $this->tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		if ( WP_DEFAULT_THEME !== get_stylesheet() ) {
			switch_theme( WP_DEFAULT_THEME );
		}
		ContentSource::flush_template_cache();
		delete_option( Settings::OPTION );
		Plugin::instance()->get( 'settings' )->flush_cache();
		parent::tear_down();
	}

	/**
	 * Editor conversion injected into the resolver (what the document builder does).
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public function convert_editor( $post ) {
		$converter = new LeagueConverter( get_permalink( $post ) );
		return $converter->convert( apply_filters( 'the_content', $post->post_content ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * Creates a post whose editor content is long enough to be "normal".
	 *
	 * @param array<string, mixed> $args Post arguments.
	 * @return WP_Post
	 */
	private function normal_post( array $args = array() ) {
		return self::factory()->post->create_and_get(
			array_merge(
				array( 'post_content' => '<p>' . str_repeat( 'Contenido normal del editor con texto suficiente. ', 6 ) . '</p>' ),
				$args
			)
		);
	}

	/**
	 * Marks a post as built with Elementor.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	private function make_elementor( $post ) {
		update_post_meta( $post->ID, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post->ID, '_elementor_data', '[{"id":"abc","elType":"section"}]' );
	}

	/**
	 * Writes a template into the temporary directory and points wpasl_template_file at it.
	 *
	 * @param string $name File name.
	 * @param string $code PHP code.
	 * @return string Path.
	 */
	private function use_template( $name, $code ) {
		$path = $this->tmp . '/' . $name;
		file_put_contents( $path, $code ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		remove_all_filters( 'wpasl_template_file' );
		add_filter(
			'wpasl_template_file',
			static function () use ( $path ) {
				return $path;
			}
		);
		return $path;
	}

	/**
	 * Writes a template part into the active theme.
	 *
	 * @param string $relative Path relative to the theme.
	 * @param string $code     PHP code.
	 * @return string Path.
	 */
	private function theme_file( $relative, $code ) {
		$path = realpath( get_stylesheet_directory() ) . '/' . $relative;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}
		file_put_contents( $path, $code ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->theme_files[] = $path;
		return $path;
	}

	public function test_post_override_wins() {
		$post = $this->normal_post();
		$this->assertSame( array( 'source' => 'editor', 'reason' => 'default' ), $this->source->resolve( $post ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		update_post_meta( $post->ID, ExcludeMetaBox::SOURCE_META, 'rendered' );
		$this->assertSame( array( 'source' => 'rendered', 'reason' => 'post_override' ), $this->source->resolve( $post ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$elementor = $this->normal_post();
		$this->make_elementor( $elementor );
		$this->assertSame( 'rendered', $this->source->resolve( $elementor )['source'] );
		update_post_meta( $elementor->ID, ExcludeMetaBox::SOURCE_META, 'editor' );
		$this->assertSame( array( 'source' => 'editor', 'reason' => 'post_override' ), $this->source->resolve( $elementor ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		update_post_meta( $elementor->ID, ExcludeMetaBox::SOURCE_META, 'foo' );
		$this->assertSame( 'builder:elementor', $this->source->resolve( $elementor )['reason'], 'An unknown override value is ignored.' );
	}

	public function test_post_type_setting_wins_over_auto_conditions() {
		update_option(
			Settings::OPTION,
			array(
				'content_source' => array(
					'post' => 'rendered',
					'page' => 'editor',
				),
			)
		);
		Plugin::instance()->get( 'settings' )->flush_cache();

		$post = $this->normal_post();
		$this->assertSame( array( 'source' => 'rendered', 'reason' => 'post_type_setting' ), $this->source->resolve( $post ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$page = $this->normal_post( array( 'post_type' => 'page' ) );
		$this->make_elementor( $page );
		$this->assertSame( array( 'source' => 'editor', 'reason' => 'post_type_setting' ), $this->source->resolve( $page ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		update_post_meta( $page->ID, ExcludeMetaBox::SOURCE_META, 'rendered' );
		$this->assertSame( 'post_override', $this->source->resolve( $page )['reason'], 'The per-post override beats the setting.' );
	}

	public function test_assigned_page_template_triggers_rendered() {
		$page = $this->normal_post( array( 'post_type' => 'page' ) );
		update_post_meta( $page->ID, '_wp_page_template', 'templates/landing.php' );
		$this->assertSame( array( 'source' => 'rendered', 'reason' => 'page_template' ), $this->source->resolve( $page ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		update_post_meta( $page->ID, '_wp_page_template', 'default' );
		$this->assertSame( array( 'source' => 'editor', 'reason' => 'default' ), $this->source->resolve( $page ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		delete_post_meta( $page->ID, '_wp_page_template' );
		$this->assertSame( 'default', $this->source->resolve( $page )['reason'] );
	}

	public function test_builder_meta_triggers_rendered() {
		$post = $this->normal_post();
		update_post_meta( $post->ID, '_elementor_edit_mode', 'builder' );
		$this->assertSame( 'default', $this->source->resolve( $post )['reason'], 'Without _elementor_data the condition does not apply.' );
		update_post_meta( $post->ID, '_elementor_data', '[]' );
		$this->assertSame( 'default', $this->source->resolve( $post )['reason'], 'An empty element list does not count.' );
		update_post_meta( $post->ID, '_elementor_data', '[{"id":"abc"}]' );
		$this->assertSame( array( 'source' => 'rendered', 'reason' => 'builder:elementor' ), $this->source->resolve( $post ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		update_post_meta( $post->ID, '_elementor_edit_mode', 'editor' );
		$this->assertSame( 'default', $this->source->resolve( $post )['reason'], 'The exact value is required.' );

		$divi = $this->normal_post();
		update_post_meta( $divi->ID, '_et_pb_use_builder', 'off' );
		$this->assertSame( 'default', $this->source->resolve( $divi )['reason'] );
		update_post_meta( $divi->ID, '_et_pb_use_builder', 'on' );
		$this->assertSame( 'builder:divi', $this->source->resolve( $divi )['reason'] );

		$beaver = $this->normal_post();
		update_post_meta( $beaver->ID, '_fl_builder_enabled', '' );
		$this->assertSame( 'default', $this->source->resolve( $beaver )['reason'], 'Disabled stores an empty value.' );
		update_post_meta( $beaver->ID, '_fl_builder_enabled', '1' );
		$this->assertSame( 'builder:beaver-builder', $this->source->resolve( $beaver )['reason'] );

		$bricks = $this->normal_post();
		update_post_meta( $bricks->ID, '_bricks_page_content_2', '{"elements":[{"id":"x"}]}' );
		$this->assertSame( 'default', $this->source->resolve( $bricks )['reason'], 'Rendering with WordPress keeps the Bricks data unused.' );
		update_post_meta( $bricks->ID, '_bricks_editor_mode', 'bricks' );
		$this->assertSame( 'builder:bricks', $this->source->resolve( $bricks )['reason'] );

		$oxygen = $this->normal_post();
		update_post_meta( $oxygen->ID, 'ct_builder_json', '{"children":[]}' );
		$this->assertSame( 'builder:oxygen', $this->source->resolve( $oxygen )['reason'], 'Either Oxygen key counts.' );
		delete_post_meta( $oxygen->ID, 'ct_builder_json' );
		update_post_meta( $oxygen->ID, 'ct_builder_shortcodes', '[ct_section][/ct_section]' );
		$this->assertSame( 'builder:oxygen', $this->source->resolve( $oxygen )['reason'] );

		$breakdance = $this->normal_post();
		update_post_meta( $breakdance->ID, '_breakdance_data', '{"tree":{"children":[]}}' );
		$this->assertSame( 'builder:breakdance', $this->source->resolve( $breakdance )['reason'] );

		// A builder added through the filter is evaluated like the known ones.
		$acme = $this->normal_post();
		update_post_meta( $acme->ID, '_acme_built', 'yes' );
		$this->assertSame( 'default', $this->source->resolve( $acme )['reason'] );
		add_filter(
			'wpasl_builder_meta_keys',
			static function ( $builders ) {
				$builders['acme'] = array( '_acme_built', 'yes', null );
				return $builders;
			}
		);
		$this->assertSame( array( 'source' => 'rendered', 'reason' => 'builder:acme' ), $this->source->resolve( $acme ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'acme', ContentSource::builder_of( $acme ) );
		$this->assertSame( '', ContentSource::builder_of( $this->normal_post() ) );
	}

	public function test_template_file_without_the_content_triggers_rendered() {
		$post = $this->normal_post( array( 'post_type' => 'post', 'post_name' => 'blackboard' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( array( 'single-post-blackboard.php', 'single-post.php' ), $this->source->template_candidates( $post ) );
		$page = $this->normal_post( array( 'post_type' => 'page', 'post_name' => 'about' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( array( 'page-about.php', 'page-' . $page->ID . '.php' ), $this->source->template_candidates( $page ) );
		update_post_meta( $page->ID, '_wp_page_template', 'templates/landing.php' );
		$this->assertSame( array( 'templates/landing.php', 'page-about.php', 'page-' . $page->ID . '.php' ), $this->source->template_candidates( $page ) );
		$this->assertSame( array( 'source' => 'editor', 'reason' => 'default' ), $this->source->resolve( $post ), 'The test theme has no specific template.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$received = null;
		add_filter(
			'wpasl_template_candidates',
			static function ( $candidates, $filtered ) use ( &$received ) {
				$received = array( $candidates, $filtered->ID );
				return $candidates;
			},
			10,
			2
		);
		$this->use_template( 'single-post.php', "<?php get_header(); ?>\n<main><h1><?php the_title(); ?></h1><p>Fixed marketing copy.</p></main>\n<?php get_footer();\n" );
		$this->assertSame( array( 'source' => 'rendered', 'reason' => 'template_file:single-post.php' ), $this->source->resolve( $post ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( array( array( 'single-post-blackboard.php', 'single-post.php' ), $post->ID ), $received );

		// A missing template part prints nothing and does not change the verdict.
		$this->use_template( 'single-post.php', "<?php get_template_part( 'template-parts/does-not-exist' ); ?><p>Fixed.</p>" );
		$this->assertSame( 'template_file:single-post.php', $this->source->resolve( $post )['reason'] );
	}

	public function test_template_file_with_the_content_or_dynamic_part_is_inconclusive() {
		$post  = $this->normal_post();
		$cases = array(
			'the_content'           => '<?php get_header(); the_content(); get_footer();',
			'get_the_content'       => '<?php echo get_the_content();',
			'apply_filters'         => "<?php echo apply_filters( 'the_content', \$post->post_content );",
			'wp:post-content'       => "<?php echo do_blocks( '<!-- wp:post-content /-->' );",
			'dynamic template part' => "<?php get_template_part( 'template-parts/content', get_post_type() );",
			'locate_template'       => "<?php locate_template( array( 'parts/content.php' ), true );",
			'dynamic include'       => "<?php include get_template_directory() . '/parts/content.php';",
			'dynamic require_once'  => '<?php require_once $file;',
			'unreadable'            => null,
		);
		foreach ( $cases as $label => $code ) {
			ContentSource::flush_template_cache();
			if ( null === $code ) {
				remove_all_filters( 'wpasl_template_file' );
				add_filter(
					'wpasl_template_file',
					function () {
						return $this->tmp . '/missing.php';
					}
				);
			} else {
				$this->use_template( 'single-post.php', $code );
			}
			$this->assertSame( array( 'source' => 'editor', 'reason' => 'default' ), $this->source->resolve( $post ), $label ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}

		// An empty path from the filter means no template.
		remove_all_filters( 'wpasl_template_file' );
		add_filter( 'wpasl_template_file', '__return_empty_string' );
		$this->assertSame( 'default', $this->source->resolve( $post )['reason'] );
	}

	public function test_template_part_literal_is_followed_one_level() {
		$post = $this->normal_post();
		$this->use_template( 'single-post.php', "<?php get_header(); get_template_part( 'template-parts/content', 'solucion' ); get_footer();" );

		$part = $this->theme_file( 'template-parts/content-solucion.php', '<section><p>Fixed section copy.</p></section>' );
		$this->assertSame( array( 'source' => 'rendered', 'reason' => 'template_file:single-post.php' ), $this->source->resolve( $post ), 'A fixed part keeps the verdict.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		ContentSource::flush_template_cache();
		file_put_contents( $part, '<section><?php the_content(); ?></section>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertSame( 'default', $this->source->resolve( $post )['reason'], 'A part that prints the editor counts as printing it.' );

		ContentSource::flush_template_cache();
		file_put_contents( $part, "<?php get_template_part( 'template-parts/inner' ); ?>" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->theme_file( 'template-parts/inner.php', '<p>Fixed.</p>' );
		$this->assertSame( 'default', $this->source->resolve( $post )['reason'], 'Only one level is followed: a nested part is inconclusive.' );

		// The generic slug is used when the named variant does not exist.
		ContentSource::flush_template_cache();
		unlink( $part ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->theme_file( 'template-parts/content.php', '<p>Generic fixed part.</p>' );
		$this->assertSame( 'template_file:single-post.php', $this->source->resolve( $post )['reason'] );

		// A plain literal include is followed the same way.
		ContentSource::flush_template_cache();
		$this->use_template( 'single-post.php', "<?php include 'fixed-part.php'; ?>" );
		file_put_contents( $this->tmp . '/fixed-part.php', '<p>Fixed included copy.</p>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertSame( 'template_file:single-post.php', $this->source->resolve( $post )['reason'] );
		ContentSource::flush_template_cache();
		file_put_contents( $this->tmp . '/fixed-part.php', '<?php the_content();' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertSame( 'default', $this->source->resolve( $post )['reason'] );
	}

	public function test_empty_editor_rule_is_off_by_default_and_enabled_by_filter() {
		$this->assertSame( 0, ContentSource::DEFAULT_MIN_CHARS );
		$empty = self::factory()->post->create_and_get( array( 'post_content' => '' ) );
		$short = self::factory()->post->create_and_get( array( 'post_content' => '<p>Hola</p>' ) );

		// Off by default: an empty editor stays on the editor and nothing is converted.
		$this->assertSame( array( 'source' => 'editor', 'reason' => 'default' ), $this->source->resolve( $empty ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertNull( $this->source->take_editor_body( $empty->ID ), 'Nothing converted while the rule is off.' );
		$this->assertSame( 'default', $this->source->resolve( $short )['reason'] );

		$received = array();
		add_filter(
			'wpasl_editor_min_chars',
			static function ( $min, $post ) use ( &$received ) {
				$received[] = array( $min, $post->ID );
				return 100;
			},
			10,
			2
		);
		$this->assertSame( array( 'source' => 'rendered', 'reason' => 'empty_editor' ), $this->source->resolve( $empty ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( '', $this->source->take_editor_body( $empty->ID ), 'The converted (empty) body is kept for reuse.' );
		$this->assertNull( $this->source->take_editor_body( $empty->ID ), 'Consumed once.' );
		$this->assertSame( array( 'source' => 'rendered', 'reason' => 'empty_editor' ), $this->source->resolve( $short ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( "Hola\n", $this->source->take_editor_body( $short->ID ) );
		$this->assertSame( array( array( 0, $empty->ID ), array( 0, $short->ID ) ), $received, 'The filter receives the default (0).' );

		remove_all_filters( 'wpasl_editor_min_chars' );
		add_filter( 'wpasl_editor_min_chars', static function () { return 2; } );
		$this->assertSame( array( 'source' => 'editor', 'reason' => 'default' ), $this->source->resolve( $short ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		// Without an injected conversion the plain post content is measured.
		remove_all_filters( 'wpasl_editor_min_chars' );
		add_filter( 'wpasl_editor_min_chars', static function () { return 100; } );
		$plain = new ContentSource( Plugin::instance()->get( 'settings' ) );
		$this->assertSame( 'empty_editor', $plain->resolve( $short )['reason'] );
		$this->assertSame( 'default', $plain->resolve( $this->normal_post() )['reason'] );
		$this->assertNull( $plain->take_editor_body( $short->ID ) );
		remove_all_filters( 'wpasl_editor_min_chars' );
	}

	public function test_normal_content_defaults_to_editor() {
		$post = $this->normal_post();
		$this->assertSame( array( 'source' => 'editor', 'reason' => 'default' ), $this->source->resolve( $post ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertNull( $this->source->take_editor_body( $post->ID ), 'Nothing is converted while the empty-editor rule is off.' );
	}

	public function test_resolution_filter() {
		$post = $this->normal_post();
		$seen = null;
		add_filter(
			'wpasl_content_source',
			static function ( $result, $filtered ) use ( &$seen, $post ) {
				$seen = $result;
				if ( $filtered->ID === $post->ID ) {
					return array(
						'source' => 'rendered',
						'reason' => 'custom',
					);
				}
				return $result;
			},
			10,
			2
		);
		$this->assertSame( array( 'source' => 'rendered', 'reason' => 'custom' ), $this->source->resolve( $post ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( array( 'source' => 'editor', 'reason' => 'default' ), $seen, 'The filter receives the resolved result.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		remove_all_filters( 'wpasl_content_source' );
		add_filter( 'wpasl_content_source', '__return_true' );
		$this->assertSame( array( 'source' => 'editor', 'reason' => 'default' ), $this->source->resolve( $post ), 'Garbage from the filter falls back to the editor.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_template_cache_keyed_by_mtime() {
		$post = $this->normal_post();
		$path = $this->use_template( 'single-post.php', '<p>Fixed.</p>' );
		clearstatcache( true, $path );
		$mtime = filemtime( $path );
		$this->assertSame( 'template_file:single-post.php', $this->source->resolve( $post )['reason'] );

		// Same mtime: the cached verdict is reused, the file is not read again.
		file_put_contents( $path, '<?php the_content();' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		touch( $path, $mtime ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
		clearstatcache( true, $path );
		$this->assertSame( 'template_file:single-post.php', $this->source->resolve( $post )['reason'] );

		// A newer mtime invalidates the entry.
		touch( $path, $mtime + 10 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
		clearstatcache( true, $path );
		$this->assertSame( 'default', $this->source->resolve( $post )['reason'] );

		// And an explicit flush forgets everything.
		file_put_contents( $path, '<p>Fixed again.</p>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		touch( $path, $mtime + 10 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
		clearstatcache( true, $path );
		$this->assertSame( 'default', $this->source->resolve( $post )['reason'], 'Still cached.' );
		ContentSource::flush_template_cache();
		$this->assertSame( 'template_file:single-post.php', $this->source->resolve( $post )['reason'] );
	}

	public function test_block_theme_template_without_post_content_triggers_rendered() {
		if ( ! wp_get_theme( 'block-theme' )->exists() ) {
			$this->markTestSkipped( 'The "block-theme" of the WordPress test suite data is not available.' );
		}
		switch_theme( 'block-theme' );
		$this->assertTrue( wp_is_block_theme() );

		// page-home.html of the test theme prints a fixed paragraph and no wp:post-content block.
		$home = $this->normal_post( array( 'post_type' => 'page', 'post_name' => 'home' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( array( 'source' => 'rendered', 'reason' => 'template_file:page-home' ), $this->source->resolve( $home ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		// No specific template for this page: the generic page.html is never inspected.
		$other = $this->normal_post( array( 'post_type' => 'page', 'post_name' => 'other' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( array( 'source' => 'editor', 'reason' => 'default' ), $this->source->resolve( $other ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		// The generic templates are only inspected when a filter asks for them: page.html prints a fixed
		// paragraph, single.html prints the wp:post-content block.
		add_filter(
			'wpasl_template_candidates',
			static function () {
				return array( 'page.php' );
			}
		);
		$this->assertSame( 'template_file:page', $this->source->resolve( $other )['reason'] );
		remove_all_filters( 'wpasl_template_candidates' );
		add_filter(
			'wpasl_template_candidates',
			static function () {
				return array( 'single.php' );
			}
		);
		$this->assertSame( 'default', $this->source->resolve( $this->normal_post() )['reason'], 'single.html carries wp:post-content.' );
		remove_all_filters( 'wpasl_template_candidates' );

		// A template part may print the editor content: inconclusive.

		add_filter(
			'get_block_templates',
			static function ( $templates, $query ) {
				if ( ! empty( $query['slug__in'] ) && in_array( 'page-other', $query['slug__in'], true ) ) {
					$template          = new WP_Block_Template();
					$template->slug    = 'page-other';
					$template->theme   = get_stylesheet();
					$template->source  = 'theme';
					$template->type    = 'wp_template';
					$template->content = '<!-- wp:template-part {"slug":"small-header"} /--><!-- wp:paragraph --><p>Fixed</p><!-- /wp:paragraph -->';
					$templates[]       = $template;
				}
				return $templates;
			},
			10,
			2
		);
		$this->assertSame( 'default', $this->source->resolve( $other )['reason'], 'A template part may print the editor content.' );
		remove_all_filters( 'get_block_templates' );
		$this->assertSame( 'default', $this->source->resolve( $other )['reason'] );

		$post = $this->normal_post( array( 'post_name' => 'entrada' ) );
		$this->assertSame( 'default', $this->source->resolve( $post )['reason'], 'single.html is generic and not inspected.' );
	}
}
