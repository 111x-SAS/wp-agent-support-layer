<?php
/**
 * Item generator stub for tests.
 *
 * @package WPASL
 */

use WPASL\Generation\ItemGeneratorInterface;

/**
 * Counts calls and returns a trivial document.
 */
class WPASL_Test_Item_Generator implements ItemGeneratorInterface {

	/**
	 * Number of generate() calls.
	 *
	 * @var int
	 */
	public $calls = 0;

	/**
	 * {@inheritDoc}
	 */
	public function generate( WP_Post $post ) {
		++$this->calls;
		return "# {$post->post_title}\n";
	}
}
