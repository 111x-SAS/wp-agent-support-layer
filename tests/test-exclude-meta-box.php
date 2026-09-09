<?php
/**
 * Exclusion meta box tests.
 *
 * @package WPASL
 */

use WPASL\Admin\ExcludeMetaBox;
use WPASL\Plugin;
use WPASL\Settings;

/**
 * Covers WPASL\Admin\ExcludeMetaBox.
 */
class Test_Exclude_Meta_Box extends WP_UnitTestCase {

	public function tear_down() {
		unset( $_POST[ ExcludeMetaBox::NONCE ], $_POST[ ExcludeMetaBox::META ], $_POST[ ExcludeMetaBox::SOURCE_META ] );
		delete_option( Settings::OPTION );
		Plugin::instance()->get( 'settings' )->flush_cache();
		parent::tear_down();
	}

	public function test_source_meta_is_registered_only_for_enabled_post_types() {
		register_post_type( 'wpasl_book', array( 'public' => true ) );
		$box = Plugin::instance()->get( 'exclude_meta_box' );
		$box->register_meta();

		$this->assertSame( '_wpasl_content_source', ExcludeMetaBox::SOURCE_META );
		$this->assertTrue( registered_meta_key_exists( 'post', ExcludeMetaBox::SOURCE_META, 'post' ) );
		$this->assertTrue( registered_meta_key_exists( 'post', ExcludeMetaBox::SOURCE_META, 'page' ) );
		$this->assertFalse( registered_meta_key_exists( 'post', ExcludeMetaBox::SOURCE_META, 'wpasl_book' ) );
		$registered = get_registered_meta_keys( 'post', 'post' );
		$this->assertSame( 'string', $registered[ ExcludeMetaBox::SOURCE_META ]['type'] );
		$this->assertTrue( $registered[ ExcludeMetaBox::SOURCE_META ]['single'] );
		$this->assertSame( '', $registered[ ExcludeMetaBox::SOURCE_META ]['default'] );
		$this->assertTrue( $registered[ ExcludeMetaBox::SOURCE_META ]['show_in_rest'] );
		$this->assertSame( '', ExcludeMetaBox::sanitize_source( 'foo' ) );
		$this->assertSame( 'rendered', ExcludeMetaBox::sanitize_source( 'rendered' ) );
		$this->assertSame( 'editor', ExcludeMetaBox::sanitize_source( 'Editor' ) );
		unregister_post_type( 'wpasl_book' );
	}

	public function test_save_stores_source_override_and_clears_default() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$post_id = self::factory()->post->create();
		$box     = Plugin::instance()->get( 'exclude_meta_box' );
		$storage = Plugin::instance()->get( 'storage' );

		$_POST[ ExcludeMetaBox::NONCE ]       = wp_create_nonce( ExcludeMetaBox::NONCE );
		$_POST[ ExcludeMetaBox::SOURCE_META ] = 'rendered';
		$box->save( $post_id, get_post( $post_id ) );
		$this->assertSame( 'rendered', get_post_meta( $post_id, ExcludeMetaBox::SOURCE_META, true ) );
		$this->assertSame( 'rendered', ExcludeMetaBox::source_override( $post_id ) );
		$this->assertFalse( ExcludeMetaBox::is_excluded( $post_id ), 'The exclusion checkbox is independent.' );
		$this->assertFalse( $storage->exists( \WPASL\Generation\Runner::document_path( 'post', $post_id ) ), 'Saving the meta box never generates.' );

		$_POST[ ExcludeMetaBox::SOURCE_META ] = 'editor';
		$box->save( $post_id, get_post( $post_id ) );
		$this->assertSame( 'editor', ExcludeMetaBox::source_override( $post_id ) );

		$_POST[ ExcludeMetaBox::SOURCE_META ] = '';
		$box->save( $post_id, get_post( $post_id ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, ExcludeMetaBox::SOURCE_META ), '"Follow the settings" is never stored.' );
		$this->assertSame( '', ExcludeMetaBox::source_override( $post_id ) );

		$_POST[ ExcludeMetaBox::SOURCE_META ] = 'foo';
		$box->save( $post_id, get_post( $post_id ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, ExcludeMetaBox::SOURCE_META ) );

		// Without a nonce nothing changes.
		update_post_meta( $post_id, ExcludeMetaBox::SOURCE_META, 'rendered' );
		unset( $_POST[ ExcludeMetaBox::NONCE ] );
		$_POST[ ExcludeMetaBox::SOURCE_META ] = 'editor';
		$box->save( $post_id, get_post( $post_id ) );
		$this->assertSame( 'rendered', ExcludeMetaBox::source_override( $post_id ) );
	}

	/**
	 * Writes the content source override of a post through the REST API as the current user.
	 *
	 * @param int    $post_id Post id.
	 * @param string $value   Value.
	 * @return WP_REST_Response
	 */
	private function rest_write_source( $post_id, $value ) {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test server.
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core action.

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params( array( 'meta' => array( ExcludeMetaBox::SOURCE_META => $value ) ) );
		return $wp_rest_server->dispatch( $request );
	}

	public function test_source_meta_hidden_for_anonymous_and_writable_by_editor() {
		Plugin::instance()->get( 'exclude_meta_box' )->register_meta();
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, ExcludeMetaBox::SOURCE_META, 'rendered' );

		wp_set_current_user( 0 );
		$data = $this->rest_post( $post_id );
		$this->assertArrayHasKey( 'meta', $data );
		$this->assertArrayNotHasKey( ExcludeMetaBox::SOURCE_META, $data['meta'] );
		$this->assertArrayNotHasKey( ExcludeMetaBox::META, $data['meta'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$data = $this->rest_post( $post_id );
		$this->assertArrayNotHasKey( ExcludeMetaBox::SOURCE_META, $data['meta'] );
		$response = $this->rest_write_source( $post_id, 'editor' );
		$this->assertSame( 403, $response->get_status(), 'A subscriber cannot write the override.' );
		$this->assertSame( 'rendered', ExcludeMetaBox::source_override( $post_id ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$data = $this->rest_post( $post_id, 'edit' );
		$this->assertSame( 'rendered', $data['meta'][ ExcludeMetaBox::SOURCE_META ] );

		update_post_meta( $post_id, ExcludeMetaBox::SOURCE_META, 'editor' );
		$response = $this->rest_write_source( $post_id, 'rendered' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'rendered', get_post_meta( $post_id, ExcludeMetaBox::SOURCE_META, true ) );

		$response = $this->rest_write_source( $post_id, 'foo' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', get_post_meta( $post_id, ExcludeMetaBox::SOURCE_META, true ), 'Sanitized to empty.' );
		$this->assertSame( '', ExcludeMetaBox::source_override( $post_id ) );
	}

	public function test_render_shows_select_only_for_enabled_types() {
		global $wp_meta_boxes;
		$box  = Plugin::instance()->get( 'exclude_meta_box' );
		$post = self::factory()->post->create_and_get();

		ob_start();
		$box->render( $post );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Markdown content source', $html );
		$this->assertMatchesRegularExpression( '/<select id="wpasl-content-source" name="_wpasl_content_source"[^>]*>\s*<option value=""\s+selected=\'selected\'>Follow the settings</', $html );
		$this->assertStringContainsString( '<option value="editor" >Editor content</option>', $html );
		$this->assertStringContainsString( '<option value="rendered" >Rendered page</option>', $html );
		$this->assertStringContainsString( 'name="' . ExcludeMetaBox::META . '"', $html, 'The exclusion checkbox is kept.' );

		update_post_meta( $post->ID, ExcludeMetaBox::SOURCE_META, 'rendered' );
		ob_start();
		$box->render( $post );
		$html = ob_get_clean();
		$this->assertStringContainsString( '<option value="rendered"  selected=\'selected\'>Rendered page</option>', $html );

		// The box (and with it the select) only exists on enabled post types.
		update_option( Settings::OPTION, array( 'post_types' => array( 'post' ) ) );
		Plugin::instance()->get( 'settings' )->flush_cache();
		$wp_meta_boxes = array();
		$box->add_meta_box( 'page' );
		$this->assertEmpty( $wp_meta_boxes );
		$box->add_meta_box( 'post' );
		$this->assertArrayHasKey( 'wpasl-exclude', $wp_meta_boxes['post']['side']['default'] );
	}

	public function test_meta_is_registered_only_for_enabled_post_types() {
		register_post_type( 'wpasl_book', array( 'public' => true ) );
		$box = Plugin::instance()->get( 'exclude_meta_box' );
		$box->register_meta();

		$this->assertTrue( registered_meta_key_exists( 'post', ExcludeMetaBox::META, 'post' ) );
		$this->assertTrue( registered_meta_key_exists( 'post', ExcludeMetaBox::META, 'page' ) );
		$this->assertFalse( registered_meta_key_exists( 'post', ExcludeMetaBox::META, 'wpasl_book' ) );
		unregister_post_type( 'wpasl_book' );
	}

	public function test_meta_box_is_added_only_for_enabled_post_types() {
		global $wp_meta_boxes;
		$box = Plugin::instance()->get( 'exclude_meta_box' );

		$wp_meta_boxes = array();
		$box->add_meta_box( 'post' );
		$this->assertArrayHasKey( 'wpasl-exclude', $wp_meta_boxes['post']['side']['default'] );

		$wp_meta_boxes = array();
		$box->add_meta_box( 'attachment' );
		$this->assertEmpty( $wp_meta_boxes );
	}

	public function test_save_with_valid_nonce_sets_and_clears_meta() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$post_id = self::factory()->post->create();
		$box     = Plugin::instance()->get( 'exclude_meta_box' );

		$_POST[ ExcludeMetaBox::NONCE ] = wp_create_nonce( ExcludeMetaBox::NONCE );
		$_POST[ ExcludeMetaBox::META ]  = '1';
		$box->save( $post_id, get_post( $post_id ) );
		$this->assertTrue( ExcludeMetaBox::is_excluded( $post_id ) );

		unset( $_POST[ ExcludeMetaBox::META ] );
		$box->save( $post_id, get_post( $post_id ) );
		$this->assertFalse( ExcludeMetaBox::is_excluded( $post_id ) );
	}

	public function test_save_without_nonce_is_ignored() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$post_id = self::factory()->post->create();
		$box     = Plugin::instance()->get( 'exclude_meta_box' );

		$_POST[ ExcludeMetaBox::META ] = '1';
		$box->save( $post_id, get_post( $post_id ) );
		$this->assertFalse( ExcludeMetaBox::is_excluded( $post_id ) );
	}

	public function test_save_by_user_without_permission_is_ignored() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$post_id = self::factory()->post->create();
		$box     = Plugin::instance()->get( 'exclude_meta_box' );

		$_POST[ ExcludeMetaBox::NONCE ] = wp_create_nonce( ExcludeMetaBox::NONCE );
		$_POST[ ExcludeMetaBox::META ]  = '1';
		$box->save( $post_id, get_post( $post_id ) );
		$this->assertFalse( ExcludeMetaBox::is_excluded( $post_id ) );
	}

	/**
	 * Dispatches a REST request for one post.
	 *
	 * @param int    $post_id Post id.
	 * @param string $context Request context.
	 * @return array<string, mixed>
	 */
	private function rest_post( $post_id, $context = 'view' ) {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test server.
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core action.

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'context', $context );
		$response = $wp_rest_server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}

	public function test_rest_read_hides_exclude_meta_for_anonymous() {
		Plugin::instance()->get( 'exclude_meta_box' )->register_meta();
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, ExcludeMetaBox::META, true );

		wp_set_current_user( 0 );
		$data = $this->rest_post( $post_id );
		$this->assertArrayHasKey( 'meta', $data );
		$this->assertArrayNotHasKey( ExcludeMetaBox::META, $data['meta'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$data = $this->rest_post( $post_id );
		$this->assertArrayNotHasKey( ExcludeMetaBox::META, $data['meta'] );
	}

	public function test_rest_read_shows_exclude_meta_for_editor_with_context_edit() {
		Plugin::instance()->get( 'exclude_meta_box' )->register_meta();
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, ExcludeMetaBox::META, true );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$data = $this->rest_post( $post_id, 'edit' );
		$this->assertArrayHasKey( ExcludeMetaBox::META, $data['meta'] );
		$this->assertTrue( $data['meta'][ ExcludeMetaBox::META ] );

		$data = $this->rest_post( $post_id, 'view' );
		$this->assertArrayHasKey( ExcludeMetaBox::META, $data['meta'], 'Editors keep seeing the flag in the view context.' );
	}

	public function test_meta_is_exposed_in_rest_for_editors_only() {
		$box = Plugin::instance()->get( 'exclude_meta_box' );
		$box->register_meta();
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertTrue( $box->can_edit( false, ExcludeMetaBox::META, $post_id ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( $box->can_edit( false, ExcludeMetaBox::META, $post_id ) );
	}
}
