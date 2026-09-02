<?php
/**
 * Markdown delivery tests.
 *
 * @package WPASL
 */

use WPASL\Admin\ExcludeMetaBox;
use WPASL\Generation\Runner;
use WPASL\Http;
use WPASL\Markdown\Delivery;
use WPASL\Plugin;
use WPASL\Settings;
use WPASL\Storage;

/**
 * Covers WPASL\Markdown\Delivery.
 */
class Test_Delivery extends WP_UnitTestCase {

	/**
	 * @var Delivery
	 */
	private $delivery;

	/**
	 * @var Storage
	 */
	private $storage;

	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->delivery = Plugin::instance()->get( 'delivery' );
		$this->storage  = Plugin::instance()->get( 'storage' );
		Plugin::instance()->get( 'runner' )->clear();
		add_filter( 'wpasl_terminate_after_serve', '__return_false' );
		unset( $_SERVER['HTTP_ACCEPT'] );
	}

	public function tear_down() {
		remove_filter( 'wpasl_terminate_after_serve', '__return_false' );
		remove_filter( 'redirect_canonical', '__return_false' );
		unset( $_SERVER['HTTP_ACCEPT'] );
		Plugin::instance()->get( 'runner' )->clear();
		delete_option( Settings::OPTION );
		Plugin::instance()->get( 'settings' )->flush_cache();
		parent::tear_down();
	}

	/**
	 * @dataProvider accept_provider
	 */
	public function test_prefers_markdown( $accept, $expected ) {
		$this->assertSame( $expected, Delivery::prefers_markdown( $accept ) );
	}

	public function accept_provider() {
		return array(
			'markdown only'           => array( 'text/markdown', true ),
			'browser'                 => array( 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', false ),
			'html preferred'          => array( 'text/html, text/markdown;q=0.5', false ),
			'equal preference'        => array( 'text/markdown, text/html', true ),
			'agent with fallbacks'    => array( 'text/markdown, text/html;q=0.9, */*;q=0.8', true ),
			'wildcard only'           => array( '*/*', false ),
			'markdown below wildcard' => array( 'text/markdown;q=0.3, */*', false ),
			'empty'                   => array( '', false ),
			'null'                    => array( null, false ),
			'case insensitive'        => array( 'Text/Markdown', true ),
			'zero quality markdown'   => array( 'text/markdown;q=0, text/html', false ),
		);
	}

	public function test_markdown_url_with_pretty_permalinks() {
		$post = self::factory()->post->create_and_get( array( 'post_name' => 'mi-entrada' ) );
		$this->assertSame( home_url( '/mi-entrada.md' ), $this->delivery->markdown_url( $post ) );
	}

	public function test_markdown_url_with_plain_permalinks() {
		$this->set_permalink_structure( '' );
		$post = self::factory()->post->create_and_get();
		$this->assertSame( home_url( '/?p=' . $post->ID . '&wpasl=md' ), $this->delivery->markdown_url( $post ) );
	}

	public function test_markdown_headers() {
		$post    = self::factory()->post->create_and_get();
		$headers = $this->delivery->markdown_headers( $post, str_repeat( 'x', 4000 ) );
		$this->assertSame( 'text/markdown; charset=utf-8', $headers['Content-Type'] );
		$this->assertSame( 'Accept', $headers['Vary'] );
		$this->assertSame( '1000', $headers['X-Markdown-Tokens'] );
		$this->assertSame( '<' . get_permalink( $post ) . '>; rel="canonical"', $headers['Link'] );
		$this->assertSame( 'public, max-age=' . DAY_IN_SECONDS, $headers['Cache-Control'] );
	}

	public function test_cache_max_age_follows_the_schedule() {
		update_option( Settings::OPTION, array( 'schedule' => 'hourly' ) );
		Plugin::instance()->get( 'settings' )->flush_cache();
		$this->assertSame( HOUR_IN_SECONDS, $this->delivery->max_age() );
	}

	public function test_html_headers_and_alternate_link_for_eligible_post() {
		$post = self::factory()->post->create_and_get( array( 'post_name' => 'alt' ) );
		$this->go_to( get_permalink( $post ) );
		$this->assertTrue( is_singular() );

		$headers = $this->delivery->html_headers( $post );
		$this->assertSame( 'Accept', $headers['Vary'] );
		$this->assertSame( '<' . home_url( '/alt.md' ) . '>; rel="alternate"; type="text/markdown"', $headers['Link'] );

		ob_start();
		$this->delivery->print_alternate_link();
		$html = ob_get_clean();
		$this->assertStringContainsString( '<link rel="alternate" type="text/markdown" href="' . home_url( '/alt.md' ) . '" />', $html );
	}

	public function test_no_alternate_link_for_disabled_post_type() {
		update_option( Settings::OPTION, array( 'post_types' => array( 'post' ) ) );
		Plugin::instance()->get( 'settings' )->flush_cache();
		$page = self::factory()->post->create_and_get( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $page ) );

		ob_start();
		$this->delivery->print_alternate_link();
		$html = ob_get_clean();
		$this->assertSame( '', $html );
	}

	public function test_accept_header_serves_markdown_on_canonical_url() {
		$post                   = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Negociada',
				'post_content' => '<p>Cuerpo</p>',
			)
		);
		$_SERVER['HTTP_ACCEPT'] = 'text/markdown';
		$this->go_to( get_permalink( $post ) );

		ob_start();
		$served = $this->delivery->maybe_serve();
		$out    = ob_get_clean();

		$this->assertTrue( $served );
		$this->assertStringContainsString( "# Negociada\n", $out );
		$this->assertStringContainsString( 'Cuerpo', $out );
		$this->assertTrue( $this->storage->exists( Runner::document_path( 'post', $post->ID ) ), 'Lazy fill stored the document.' );
	}

	public function test_document_is_identical_from_cron_and_from_singular_request() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Paginada',
				'post_content' => "<p>Teaser</p>\n<!--more-->\n<p>Cuerpo completo</p>\n<!--nextpage-->\n<p>Segunda página</p>",
			)
		);

		// Generated outside any singular view, as WP-Cron does.
		$this->assertFalse( is_singular() );
		$from_cron = Plugin::instance()->get( 'runner' )->generate_item( $post );
		$this->assertStringContainsString( 'Cuerpo completo', $from_cron );
		$this->assertStringContainsString( 'Segunda página', $from_cron );

		// Same post, regenerated from scratch inside the singular request.
		Plugin::instance()->get( 'runner' )->clear();
		$_SERVER['HTTP_ACCEPT'] = 'text/markdown';
		$this->go_to( get_permalink( $post ) );
		$this->assertTrue( is_singular() );

		ob_start();
		$this->delivery->maybe_serve();
		$from_request = ob_get_clean();

		$this->assertSame( $from_cron, $from_request );
	}

	public function test_negotiated_markdown_has_no_x_robots_tag() {
		$this->assertSame( 'no', Plugin::instance()->get( 'settings' )->get( 'signal_ai_train' ), 'Training not allowed by default.' );
		$post                   = self::factory()->post->create_and_get( array( 'post_name' => 'sin-noai' ) );
		$_SERVER['HTTP_ACCEPT'] = 'text/markdown';
		Http::reset();
		$this->go_to( get_permalink( $post ) );
		$this->assertArrayHasKey( 'x-robots-tag', Http::effective_headers(), 'send_headers emitted the HTML set first.' );

		ob_start();
		$this->assertTrue( $this->delivery->maybe_serve() );
		ob_get_clean();

		$headers = Http::effective_headers();
		$this->assertArrayNotHasKey( 'x-robots-tag', $headers );
		$this->assertSame( array( 'text/markdown; charset=utf-8' ), $headers['content-type'] );
		$this->assertSame( array( 'nosniff' ), $headers['x-content-type-options'] );
		$this->assertArrayHasKey( 'content-signal', $headers );

		// The .md route removes it as well (the request would end right after serving).
		Http::reset();
		unset( $_SERVER['HTTP_ACCEPT'] );
		ob_start();
		$this->go_to( home_url( '/sin-noai.md' ) );
		ob_get_clean();
		$removed = array_filter( Http::log(), static function ( $entry ) { return 'remove' === $entry[0] && 'X-Robots-Tag' === $entry[1]; } ); // phpcs:ignore
		$this->assertCount( 1, $removed );
	}

	public function test_markdown_and_json_responses_send_nosniff() {
		$post = self::factory()->post->create_and_get();
		$this->assertSame( 'nosniff', $this->delivery->markdown_headers( $post, 'x' )['X-Content-Type-Options'] );
		$this->assertSame( 'nosniff', Plugin::instance()->get( 'llms_router' )->headers( 'x' )['X-Content-Type-Options'] );
		$manifest = Plugin::instance()->get( 'manifest_router' );
		$this->assertSame( 'nosniff', $manifest->headers( \WPASL\Manifest\ManifestRouter::SKILLS_PATH )['X-Content-Type-Options'] );
		$this->assertSame( 'nosniff', $manifest->headers( \WPASL\Manifest\ManifestRouter::CATALOG_PATH )['X-Content-Type-Options'] );
	}

	public function test_html_link_header_does_not_replace_existing_link() {
		$post = self::factory()->post->create_and_get( array( 'post_name' => 'con-link' ) );
		Http::reset();
		Http::send_header( 'Link', '</style.css>; rel="preload"; as="style"' );
		$this->go_to( get_permalink( $post ) );
		$this->delivery->send_html_headers();

		$headers = Http::effective_headers();
		$this->assertCount( 2, $headers['link'] );
		$this->assertSame( '</style.css>; rel="preload"; as="style"', $headers['link'][0] );
		$this->assertStringContainsString( 'rel="alternate"; type="text/markdown"', $headers['link'][1] );
		$this->assertContains( 'Accept', $headers['vary'] );
	}

	public function test_browser_accept_gets_html() {
		$post                   = self::factory()->post->create_and_get();
		$_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml,*/*;q=0.8';
		$this->go_to( get_permalink( $post ) );
		ob_start();
		$served = $this->delivery->maybe_serve();
		$out    = ob_get_clean();
		$this->assertFalse( $served );
		$this->assertSame( '', $out );
	}

	public function test_query_var_serves_markdown_regardless_of_accept() {
		$post                   = self::factory()->post->create_and_get( array( 'post_title' => 'QV' ) );
		$_SERVER['HTTP_ACCEPT'] = 'text/html';
		$this->go_to( add_query_arg( 'wpasl', 'md', get_permalink( $post ) ) );
		ob_start();
		$served = $this->delivery->maybe_serve();
		$out    = ob_get_clean();
		$this->assertTrue( $served );
		$this->assertStringContainsString( '# QV', $out );
	}

	public function test_md_suffix_serves_markdown() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_name'  => 'sufijo',
				'post_title' => 'Sufijo',
			)
		);
		foreach ( array( '/sufijo.md', '/sufijo/.md' ) as $path ) {
			ob_start();
			$this->go_to( home_url( $path ) );
			$out = ob_get_clean();
			$this->assertStringContainsString( '# Sufijo', $out, $path );
		}
	}

	public function test_md_suffix_for_page_hierarchy() {
		$parent = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'padre',
			)
		);
		$child  = self::factory()->post->create_and_get(
			array(
				'post_type'   => 'page',
				'post_name'   => 'hija',
				'post_parent' => $parent,
				'post_title'  => 'Hija',
			)
		);
		$this->assertSame( home_url( '/padre/hija.md' ), $this->delivery->markdown_url( $child ) );
		ob_start();
		$this->go_to( home_url( '/padre/hija.md' ) );
		$out = ob_get_clean();
		$this->assertStringContainsString( '# Hija', $out );
	}

	public function test_md_suffix_for_unknown_content_is_404() {
		ob_start();
		$this->go_to( home_url( '/no-existe.md' ) );
		$out = ob_get_clean();
		$this->assertSame( '', $out );
		$this->assertTrue( is_404() );
	}

	public function test_md_suffix_for_home_is_404() {
		update_option( 'show_on_front', 'posts' );
		ob_start();
		$this->go_to( home_url( '/.md' ) );
		ob_get_clean();
		$this->assertTrue( is_404() );
	}

	/**
	 * Configures a static front page and returns it.
	 *
	 * @return \WP_Post
	 */
	private function make_static_front_page() {
		$front = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'page',
				'post_name'    => 'portada',
				'post_title'   => 'Portada',
				'post_content' => '<p>Bienvenida</p>',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front->ID );
		$this->assertSame( home_url( '/' ), get_permalink( $front ) );
		return $front;
	}

	public function test_markdown_url_for_static_front_page_uses_query_arg() {
		$front = $this->make_static_front_page();
		$this->assertSame( home_url( '/?wpasl=md' ), $this->delivery->markdown_url( $front ) );
	}

	public function test_markdown_url_for_post_type_without_rewrite_uses_query_arg() {
		register_post_type(
			'wpasl_doc',
			array(
				'public'  => true,
				'rewrite' => false,
			)
		);
		update_option( Settings::OPTION, array( 'post_types' => array( 'post', 'page', 'wpasl_doc' ) ) );
		Plugin::instance()->get( 'settings' )->flush_cache();
		$doc       = self::factory()->post->create_and_get( array( 'post_type' => 'wpasl_doc' ) );
		$permalink = get_permalink( $doc );
		$this->assertStringContainsString( '?wpasl_doc=', $permalink, 'A post type without rewrite rules keeps a query-string permalink.' );

		$url = $this->delivery->markdown_url( $doc );
		$this->assertSame( $permalink . '&wpasl=md', $url );

		ob_start();
		$this->go_to( $url );
		$served = $this->delivery->maybe_serve();
		$out    = ob_get_clean();
		unregister_post_type( 'wpasl_doc' );

		$this->assertTrue( $served );
		$this->assertStringContainsString( '# ' . $doc->post_title, $out );
	}

	public function test_md_suffix_for_home_serves_static_front_page() {
		$this->make_static_front_page();
		ob_start();
		$this->go_to( home_url( '/.md' ) );
		$out = ob_get_clean();
		$this->assertStringContainsString( "# Portada\n", $out );
		$this->assertStringContainsString( 'Bienvenida', $out );
	}

	public function test_md_suffix_for_home_is_404_when_front_page_is_excluded() {
		$front = $this->make_static_front_page();
		update_post_meta( $front->ID, ExcludeMetaBox::META, true );
		ob_start();
		$this->go_to( home_url( '/.md' ) );
		ob_get_clean();
		$this->assertTrue( is_404() );
	}

	public function test_static_front_page_alternate_links_are_servable() {
		$front = $this->make_static_front_page();

		$this->go_to( home_url( '/' ) );
		$this->assertTrue( is_singular() );
		$headers = $this->delivery->html_headers( $front );
		$this->assertSame( '<' . home_url( '/?wpasl=md' ) . '>; rel="alternate"; type="text/markdown"', $headers['Link'] );

		ob_start();
		$this->delivery->print_alternate_link();
		$link = ob_get_clean();
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/?wpasl=md' ) ) . '"', $link );

		ob_start();
		$this->go_to( home_url( '/?wpasl=md' ) );
		$this->delivery->maybe_serve();
		$out = ob_get_clean();
		$this->assertStringContainsString( "# Portada\n", $out );
		$this->assertSame( 1, substr_count( $out, "# Portada\n" ), 'Served exactly once.' );
	}

	/**
	 * Configures a static front page plus a "Posts page" at /blog/ and returns the latter.
	 *
	 * @return \WP_Post
	 */
	private function make_posts_page() {
		$this->make_static_front_page();
		$blog = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'page',
				'post_name'    => 'blog',
				'post_title'   => 'Blog',
				'post_content' => '<p>Todas las entradas</p>',
			)
		);
		update_option( 'page_for_posts', $blog->ID );
		$this->assertSame( home_url( '/blog/' ), get_permalink( $blog ) );
		return $blog;
	}

	public function test_posts_page_is_served_by_md_suffix_accept_and_query_arg() {
		$blog = $this->make_posts_page();
		$this->assertSame( home_url( '/blog.md' ), $this->delivery->markdown_url( $blog ) );

		ob_start();
		$this->go_to( home_url( '/blog.md' ) );
		$out = ob_get_clean();
		$this->assertStringStartsWith( "---\n", $out );
		$this->assertStringContainsString( "# Blog\n", $out );
		$this->assertStringContainsString( 'Todas las entradas', $out );

		$_SERVER['HTTP_ACCEPT'] = 'text/markdown';
		ob_start();
		$this->go_to( home_url( '/blog/' ) );
		$this->assertTrue( is_home() );
		$this->assertFalse( is_singular() );
		$served = $this->delivery->maybe_serve();
		$out    = ob_get_clean();
		$this->assertTrue( $served );
		$this->assertStringContainsString( "# Blog\n", $out );

		unset( $_SERVER['HTTP_ACCEPT'] );
		ob_start();
		$this->go_to( home_url( '/blog/?wpasl=md' ) );
		$served = $this->delivery->maybe_serve();
		$out    = ob_get_clean();
		$this->assertTrue( $served );
		$this->assertStringContainsString( "# Blog\n", $out );
	}

	public function test_posts_page_html_announces_alternate_link() {
		$blog = $this->make_posts_page();
		$this->go_to( home_url( '/blog/' ) );
		$this->assertTrue( is_home() );

		Http::reset();
		$this->delivery->send_html_headers();
		$sent = Http::effective_headers();
		$this->assertArrayHasKey( 'link', $sent );
		$this->assertContains( '<' . home_url( '/blog.md' ) . '>; rel="alternate"; type="text/markdown"', $sent['link'] );

		ob_start();
		$this->delivery->print_alternate_link();
		$html = ob_get_clean();
		$this->assertStringContainsString( '<link rel="alternate" type="text/markdown" href="' . home_url( '/blog.md' ) . '" />', $html );

		// The blog index of a site that lists posts on the front page has nothing to announce.
		update_option( 'show_on_front', 'posts' );
		$this->go_to( home_url( '/' ) );
		$this->assertTrue( is_home() );
		ob_start();
		$this->delivery->print_alternate_link();
		$this->assertSame( '', ob_get_clean() );
		$this->assertGreaterThan( 0, $blog->ID );
	}

	public function test_non_eligible_content_is_never_served_as_markdown() {
		$draft     = self::factory()->post->create_and_get( array( 'post_status' => 'draft', 'post_name' => 'borrador' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$protected = self::factory()->post->create_and_get( array( 'post_password' => 'x', 'post_name' => 'protegida' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$excluded  = self::factory()->post->create_and_get( array( 'post_name' => 'excluida' ) );
		update_post_meta( $excluded->ID, ExcludeMetaBox::META, true );

		foreach ( array( '/borrador.md', '/protegida.md', '/excluida.md' ) as $path ) {
			ob_start();
			$this->go_to( home_url( $path ) );
			$out = ob_get_clean();
			$this->assertSame( '', $out, $path );
			$this->assertTrue( is_404(), $path );
		}

		$_SERVER['HTTP_ACCEPT'] = 'text/markdown';
		$this->go_to( get_permalink( $protected ) );
		ob_start();
		$served = $this->delivery->maybe_serve();
		ob_get_clean();
		$this->assertFalse( $served, 'Password protected post keeps its HTML response.' );
	}

	public function test_stored_document_is_served_without_reconverting() {
		$post = self::factory()->post->create_and_get( array( 'post_name' => 'cacheada' ) );
		$this->storage->ensure();
		$this->storage->write( Runner::document_path( 'post', $post->ID ), "# Stored version\n" );

		ob_start();
		$this->go_to( home_url( '/cacheada.md' ) );
		$out = ob_get_clean();
		$this->assertSame( "# Stored version\n", $out );
	}

	public function test_unpublished_after_generation_is_not_served() {
		$post = self::factory()->post->create_and_get( array( 'post_name' => 'antes-publica' ) );
		$this->storage->ensure();
		$path = Runner::document_path( 'post', $post->ID );
		$this->storage->write( $path, "# Old\n" );

		// Simulate a stale file that survived (bypass the transition hook by writing after the status change).
		wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'draft',
			)
		);
		$this->storage->write( $path, "# Old\n" );

		ob_start();
		$this->go_to( home_url( '/antes-publica.md' ) );
		$out = ob_get_clean();
		$this->assertSame( '', $out );
		$this->assertTrue( is_404() );
	}

	public function test_editing_does_not_change_the_served_document() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_name'  => 'editada',
				'post_title' => 'Original',
			)
		);
		ob_start();
		$this->go_to( home_url( '/editada.md' ) );
		$first = ob_get_clean();
		$this->assertStringContainsString( '# Original', $first );

		wp_update_post(
			array(
				'ID'         => $post->ID,
				'post_title' => 'Cambiada',
			)
		);
		ob_start();
		$this->go_to( home_url( '/editada.md' ) );
		$second = ob_get_clean();
		$this->assertStringContainsString( '# Original', $second, 'Stored document is unchanged until the next scheduled run.' );
	}
}
