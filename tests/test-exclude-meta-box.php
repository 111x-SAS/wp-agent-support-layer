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
		unset( $_POST[ ExcludeMetaBox::NONCE ], $_POST[ ExcludeMetaBox::META ] );
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	public function test_meta_is_registered_only_for_enabled_post_types() {
		register_post_type( 'wpasl_book', array( 'public' => true ) );
		$box = Plugin::instance()->get( 'exclude' );
		$box->register_meta();

		$this->assertTrue( registered_meta_key_exists( 'post', ExcludeMetaBox::META, 'post' ) );
		$this->assertTrue( registered_meta_key_exists( 'post', ExcludeMetaBox::META, 'page' ) );
		$this->assertFalse( registered_meta_key_exists( 'post', ExcludeMetaBox::META, 'wpasl_book' ) );
		unregister_post_type( 'wpasl_book' );
	}

	public function test_meta_box_is_added_only_for_enabled_post_types() {
		global $wp_meta_boxes;
		$box = Plugin::instance()->get( 'exclude' );

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
		$box     = Plugin::instance()->get( 'exclude' );

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
		$box     = Plugin::instance()->get( 'exclude' );

		$_POST[ ExcludeMetaBox::META ] = '1';
		$box->save( $post_id, get_post( $post_id ) );
		$this->assertFalse( ExcludeMetaBox::is_excluded( $post_id ) );
	}

	public function test_save_by_user_without_permission_is_ignored() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$post_id = self::factory()->post->create();
		$box     = Plugin::instance()->get( 'exclude' );

		$_POST[ ExcludeMetaBox::NONCE ] = wp_create_nonce( ExcludeMetaBox::NONCE );
		$_POST[ ExcludeMetaBox::META ]  = '1';
		$box->save( $post_id, get_post( $post_id ) );
		$this->assertFalse( ExcludeMetaBox::is_excluded( $post_id ) );
	}

	public function test_meta_is_exposed_in_rest_for_editors_only() {
		$box = Plugin::instance()->get( 'exclude' );
		$box->register_meta();
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertTrue( $box->can_edit( false, ExcludeMetaBox::META, $post_id ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( $box->can_edit( false, ExcludeMetaBox::META, $post_id ) );
	}
}
