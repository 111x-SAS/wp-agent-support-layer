<?php
/**
 * HTML to Markdown converter contract.
 *
 * @package WPASL
 */

namespace WPASL\Markdown;

/**
 * Converts rendered HTML into Markdown.
 */
interface ConverterInterface {

	/**
	 * Sets the URL of the content being converted. Links and images MUST be resolved against it so the
	 * document only carries absolute URLs (see the markdown-delivery specification).
	 *
	 * @param string $url Canonical URL of the content.
	 * @return void
	 */
	public function set_base_url( $url );

	/**
	 * Converts an HTML fragment.
	 *
	 * @param string $html HTML fragment.
	 * @return string Markdown.
	 */
	public function convert( $html );
}
