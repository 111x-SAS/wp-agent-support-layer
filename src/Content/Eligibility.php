<?php
/**
 * Content eligibility rules.
 *
 * @package WPASL
 */

namespace WPASL\Content;

use WPASL\Admin\ExcludeMetaBox;
use WPASL\Settings;

/**
 * Decides which content may be exposed to agents. Evaluated on every request, not only at generation time.
 */
final class Eligibility {

	/**
	 * Posts primed per batch when an external filter forces per-item evaluation.
	 */
	const PRIME_CHUNK = 500;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether a post may be exposed.
	 *
	 * @param int|\WP_Post|null $post Post or id.
	 * @return bool
	 */
	public function is_eligible( $post ) {
		return '' === $this->reason( $post );
	}

	/**
	 * Why a post is not eligible, or an empty string when it is.
	 *
	 * @param int|\WP_Post|null $post Post or id.
	 * @return string One of: missing, post_type, not_public, status, password, excluded, or ''.
	 */
	public function reason( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return 'missing';
		}
		if ( ! in_array( $post->post_type, $this->settings->enabled_post_types(), true ) ) {
			return 'post_type';
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->public ) {
			return 'not_public';
		}
		if ( 'publish' !== $post->post_status ) {
			return 'status';
		}
		if ( '' !== (string) $post->post_password ) {
			return 'password';
		}
		if ( ExcludeMetaBox::is_excluded( $post->ID ) ) {
			return 'excluded';
		}

		/**
		 * Filters whether a post is eligible for the agent layer.
		 *
		 * @param bool     $eligible Whether the post is eligible.
		 * @param \WP_Post $post     Post.
		 */
		return apply_filters( 'wpasl_is_eligible', true, $post ) ? '' : 'filtered';
	}

	/**
	 * Ids of every eligible item, oldest first.
	 *
	 * @param string[]|null $post_types Restrict to these enabled post types.
	 * @return int[]
	 */
	public function eligible_ids( $post_types = null ) {
		return $this->query( $post_types, array() );
	}

	/**
	 * Ids of eligible items with custom ordering and limit.
	 *
	 * @param string[]|null        $post_types Restrict to these enabled post types.
	 * @param array<string, mixed> $args       WP_Query overrides (orderby, order, posts_per_page).
	 * @return int[]
	 */
	public function query( $post_types, array $args ) {
		$types = $this->types( $post_types );
		if ( empty( $types ) ) {
			return array();
		}

		$query = new \WP_Query( array_merge( $this->base_args( $types ), $args ) );
		$ids   = array_map( 'intval', $query->posts );

		// Type, status, password and exclusion are already expressed in SQL; only an external filter
		// requires evaluating every item individually.
		if ( ! has_filter( 'wpasl_is_eligible' ) ) {
			return $ids;
		}
		foreach ( array_chunk( $ids, self::PRIME_CHUNK ) as $chunk ) {
			_prime_post_caches( $chunk, false, true );
		}
		return array_values( array_filter( $ids, array( $this, 'is_eligible' ) ) );
	}

	/**
	 * Number of eligible items, with a single COUNT query unless an external filter is registered.
	 *
	 * @param string[]|null $post_types Restrict to these enabled post types.
	 * @return int
	 */
	public function count( $post_types = null ) {
		if ( has_filter( 'wpasl_is_eligible' ) ) {
			return count( $this->eligible_ids( $post_types ) );
		}
		$types = $this->types( $post_types );
		if ( empty( $types ) ) {
			return 0;
		}
		$query = new \WP_Query(
			array_merge(
				$this->base_args( $types ),
				array(
					'posts_per_page' => 1,
					'no_found_rows'  => false,
				)
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Enabled post types, optionally restricted to a subset.
	 *
	 * @param string[]|null $post_types Requested subset.
	 * @return string[]
	 */
	private function types( $post_types ) {
		$enabled = $this->settings->enabled_post_types();
		return null === $post_types ? $enabled : array_values( array_intersect( (array) $post_types, $enabled ) );
	}

	/**
	 * WP_Query arguments that express every eligibility rule in SQL.
	 *
	 * @param string[] $types Post types.
	 * @return array<string, mixed>
	 */
	private function base_args( array $types ) {
		$defaults = array(
			'post_type'              => $types,
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$excluded = self::excluded_ids();
		if ( ! empty( $excluded ) ) {
			$defaults['post__not_in'] = $excluded; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Small, explicit exclusion list.
		}
		return $defaults;
	}

	/**
	 * Ids of posts flagged as excluded. One single-table query, cached per request.
	 *
	 * @return int[]
	 */
	public static function excluded_ids() {
		global $wpdb;

		$cache_key = 'wpasl_excluded_ids';
		$ids       = wp_cache_get( $cache_key, 'wpasl' );
		if ( false === $ids ) {
			// Same predicate as ExcludeMetaBox::is_excluded(): any value other than '' and '0' excludes.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Single indexed lookup on postmeta; results are cached.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value NOT IN ( '', '0' )", ExcludeMetaBox::META ) );
			$ids = array_map( 'intval', (array) $ids );
			wp_cache_set( $cache_key, $ids, 'wpasl', MINUTE_IN_SECONDS );
		}
		return $ids;
	}

	/**
	 * Clears the excluded-ids cache (call when the exclusion meta changes).
	 *
	 * @return void
	 */
	public static function flush_excluded_cache() {
		wp_cache_delete( 'wpasl_excluded_ids', 'wpasl' );
	}
}
