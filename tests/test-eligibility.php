<?php
/**
 * Eligibility tests.
 *
 * @package WPASL
 */

use WPASL\Admin\ExcludeMetaBox;
use WPASL\Content\Eligibility;
use WPASL\Plugin;
use WPASL\Settings;

/**
 * Covers WPASL\Content\Eligibility.
 */
class Test_Eligibility extends WP_UnitTestCase {

	/**
	 * @var Eligibility
	 */
	private $eligibility;

	public function set_up() {
		parent::set_up();
		$this->eligibility = Plugin::instance()->get( 'eligibility' );
	}

	public function tear_down() {
		delete_option( Settings::OPTION );
		Plugin::instance()->get( 'settings' )->flush_cache();
		parent::tear_down();
	}

	public function test_published_post_of_enabled_type_is_eligible() {
		$id = self::factory()->post->create();
		$this->assertTrue( $this->eligibility->is_eligible( $id ) );
		$this->assertSame( '', $this->eligibility->reason( $id ) );
	}

	public function test_missing_post() {
		$this->assertSame( 'missing', $this->eligibility->reason( 999999 ) );
	}

	public function test_draft_is_not_eligible() {
		$id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->assertSame( 'status', $this->eligibility->reason( $id ) );
	}

	public function test_password_protected_is_not_eligible() {
		$id = self::factory()->post->create( array( 'post_password' => 'secret' ) );
		$this->assertSame( 'password', $this->eligibility->reason( $id ) );
	}

	public function test_disabled_post_type_is_not_eligible() {
		$id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertTrue( $this->eligibility->is_eligible( $id ) );

		update_option( Settings::OPTION, array( 'post_types' => array( 'post' ) ) );
		Plugin::instance()->get( 'settings' )->flush_cache();
		$this->assertSame( 'post_type', $this->eligibility->reason( $id ) );
	}

	public function test_non_canonical_exclusion_value_excludes_everywhere() {
		$post = self::factory()->post->create();
		update_post_meta( $post, WPASL\Admin\ExcludeMetaBox::META, 'yes' );
		WPASL\Content\Eligibility::flush_excluded_cache();

		$this->assertFalse( $this->eligibility->is_eligible( $post ) );
		$this->assertNotContains( $post, $this->eligibility->eligible_ids() );
		$this->assertContains( $post, WPASL\Content\Eligibility::excluded_ids() );

		update_post_meta( $post, WPASL\Admin\ExcludeMetaBox::META, '0' );
		WPASL\Content\Eligibility::flush_excluded_cache();
		$this->assertTrue( $this->eligibility->is_eligible( $post ) );
		$this->assertContains( $post, $this->eligibility->eligible_ids() );
	}

	public function test_excluded_post_is_not_eligible() {
		$id = self::factory()->post->create();
		update_post_meta( $id, ExcludeMetaBox::META, true );
		$this->assertSame( 'excluded', $this->eligibility->reason( $id ) );
	}

	public function test_filter_can_veto() {
		$id = self::factory()->post->create();
		add_filter( 'wpasl_is_eligible', '__return_false' );
		$this->assertSame( 'filtered', $this->eligibility->reason( $id ) );
		remove_filter( 'wpasl_is_eligible', '__return_false' );
	}

	public function test_eligible_ids_query_count_is_bounded() {
		global $wpdb;
		$ids = self::factory()->post->create_many( 60 );
		wp_cache_flush();

		$before   = $wpdb->num_queries;
		$eligible = $this->eligibility->eligible_ids();
		$queries  = $wpdb->num_queries - $before;

		$this->assertSame( 60, count( $eligible ) );
		$this->assertEqualSets( $ids, $eligible );
		$this->assertLessThan( 10, $queries, "eligible_ids() ran {$queries} queries for 60 items." );
	}

	public function test_eligible_ids_with_filter_primes_caches_in_batches() {
		global $wpdb;
		$ids      = self::factory()->post->create_many( 60 );
		$excluded = self::factory()->post->create();
		update_post_meta( $excluded, ExcludeMetaBox::META, true );
		$vetoed = self::factory()->post->create();
		add_filter(
			'wpasl_is_eligible',
			static function ( $eligible, $post ) use ( $vetoed ) {
				return $post->ID === $vetoed ? false : $eligible;
			},
			10,
			2
		);
		wp_cache_flush();

		$before   = $wpdb->num_queries;
		$eligible = $this->eligibility->eligible_ids();
		$queries  = $wpdb->num_queries - $before;
		remove_all_filters( 'wpasl_is_eligible' );

		$this->assertEqualSets( $ids, $eligible );
		$this->assertNotContains( $excluded, $eligible );
		$this->assertNotContains( $vetoed, $eligible );
		$this->assertLessThan( 10, $queries, "eligible_ids() with a filter ran {$queries} queries for 62 items." );
	}

	public function test_count_matches_eligible_ids() {
		self::factory()->post->create_many( 5 );
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( self::factory()->post->create(), ExcludeMetaBox::META, true );

		$this->assertSame( 6, $this->eligibility->count() );
		$this->assertSame( count( $this->eligibility->eligible_ids() ), $this->eligibility->count() );
		$this->assertSame( 1, $this->eligibility->count( array( 'page' ) ) );
		$this->assertSame( 0, $this->eligibility->count( array( 'attachment' ) ) );

		add_filter( 'wpasl_is_eligible', '__return_false' );
		$this->assertSame( 0, $this->eligibility->count() );
		remove_filter( 'wpasl_is_eligible', '__return_false' );
		$this->assertSame( $page, $this->eligibility->eligible_ids( array( 'page' ) )[0] );
	}

	public function test_eligible_ids_applies_every_rule() {
		$ok        = self::factory()->post->create();
		$page      = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$draft     = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$protected = self::factory()->post->create( array( 'post_password' => 'x' ) );
		$excluded  = self::factory()->post->create();
		update_post_meta( $excluded, ExcludeMetaBox::META, true );
		$unexcluded = self::factory()->post->create();
		update_post_meta( $unexcluded, ExcludeMetaBox::META, false );

		$ids = $this->eligibility->eligible_ids();
		$this->assertContains( $ok, $ids );
		$this->assertContains( $page, $ids );
		$this->assertContains( $unexcluded, $ids );
		$this->assertNotContains( $draft, $ids );
		$this->assertNotContains( $protected, $ids );
		$this->assertNotContains( $excluded, $ids );

		$only_pages = $this->eligibility->eligible_ids( array( 'page' ) );
		$this->assertSame( array( $page ), $only_pages );
	}
}
