<?php
/**
 * Stub of WP_CLI\Utils\format_items for tests.
 *
 * @package WPASL
 */

if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
	/**
	 * Captures formatted items as "key=value" lines.
	 *
	 * @param string $format Format.
	 * @param array  $items  Items.
	 * @param array  $fields Fields.
	 * @return void
	 */
	function wpasl_test_format_items( $format, $items, $fields ) {
		foreach ( $items as $item ) {
			WP_CLI::$log[] = $item[ $fields[0] ] . '=' . $item[ $fields[1] ];
		}
	}
	eval( 'namespace WP_CLI\Utils; function format_items( $format, $items, $fields ) { \wpasl_test_format_items( $format, $items, $fields ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Namespaced function stub for tests.
}
