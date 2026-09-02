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
	 * Converts an HTML fragment.
	 *
	 * @param string $html HTML fragment.
	 * @return string Markdown.
	 */
	public function convert( $html );
}
