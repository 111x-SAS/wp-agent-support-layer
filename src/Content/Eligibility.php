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
		$enabled = $this->settings->enabled_post_types();
		$types   = null === $post_types ? $enabled : array_values( array_intersect( (array) $post_types, $enabled ) );
		if ( empty( $types ) ) {
			return array();
		}

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
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exclusion flag is a single boolean meta.
			'meta_query'             => array(
				'relation' => 'OR',
				array(
					'key'     => ExcludeMetaBox::META,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => ExcludeMetaBox::META,
					'value'   => '1',
					'compare' => '!=',
				),
			),
		);

		$query = new \WP_Query( array_merge( $defaults, $args ) );
		$ids   = array_map( 'intval', $query->posts );
		return array_values( array_filter( $ids, array( $this, 'is_eligible' ) ) );
	}
}
