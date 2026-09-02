<?php
/**
 * Per-item document generator contract.
 *
 * @package WPASL
 */

namespace WPASL\Generation;

/**
 * Produces the stored document for one post.
 */
interface ItemGeneratorInterface {

	/**
	 * Builds the document for a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return string|null Document contents, or null to skip the post.
	 */
	public function generate( \WP_Post $post );
}
