<?php
/**
 * Response header emission.
 *
 * @package WPASL
 */

namespace WPASL;

/**
 * Thin wrapper around header() / header_remove() that also records what was sent, so the effective
 * headers of a response can be inspected (tests, WP-CLI) even when PHP already flushed output.
 */
final class Http {

	/**
	 * Recorded operations: [op, name, value, replace].
	 *
	 * @var array<int, array{0:string,1:string,2:string,3:bool}>
	 */
	private static $log = array();

	/**
	 * Sends a header.
	 *
	 * @param string $name    Header name.
	 * @param string $value   Header value.
	 * @param bool   $replace Whether to replace a previous header of the same name.
	 * @return void
	 */
	public static function send_header( $name, $value, $replace = true ) {
		self::$log[] = array( 'set', (string) $name, (string) $value, (bool) $replace );
		if ( ! headers_sent() ) {
			header( $name . ': ' . $value, (bool) $replace );
		}
	}

	/**
	 * Removes a header sent earlier in this request.
	 *
	 * @param string $name Header name.
	 * @return void
	 */
	public static function remove_header( $name ) {
		self::$log[] = array( 'remove', (string) $name, '', true );
		if ( ! headers_sent() ) {
			header_remove( $name );
		}
	}

	/**
	 * Headers in effect after replaying every recorded operation.
	 *
	 * @return array<string, string[]> Lowercase name => values.
	 */
	public static function effective_headers() {
		$headers = array();
		foreach ( self::$log as $entry ) {
			$key = strtolower( $entry[1] );
			if ( 'remove' === $entry[0] ) {
				unset( $headers[ $key ] );
			} elseif ( $entry[3] || ! isset( $headers[ $key ] ) ) {
				$headers[ $key ] = array( $entry[2] );
			} else {
				$headers[ $key ][] = $entry[2];
			}
		}
		return $headers;
	}

	/**
	 * Recorded operations, in order.
	 *
	 * @return array<int, array{0:string,1:string,2:string,3:bool}>
	 */
	public static function log() {
		return self::$log;
	}

	/**
	 * Forgets the recorded operations.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$log = array();
	}
}
