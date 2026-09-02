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
	 * Recorded operations: [op, name, value, replace, sent]. "sent" is false when PHP had already flushed
	 * the headers, so the operation was only recorded (tests, WP-CLI) and never reached header().
	 *
	 * @var array<int, array{0:string,1:string,2:string,3:bool,4:bool}>
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
		$sent = ! headers_sent();
		if ( $sent ) {
			header( $name . ': ' . $value, (bool) $replace );
		}
		self::$log[] = array( 'set', (string) $name, (string) $value, (bool) $replace, $sent );
	}

	/**
	 * Removes a header sent earlier in this request.
	 *
	 * @param string $name Header name.
	 * @return void
	 */
	public static function remove_header( $name ) {
		$sent = ! headers_sent();
		if ( $sent ) {
			header_remove( $name );
		}
		self::$log[] = array( 'remove', (string) $name, '', true, $sent );
	}

	/**
	 * Headers in effect after replaying every recorded operation.
	 *
	 * @param bool $only_sent Replay only the operations that actually reached header(); by default the
	 *                        in-memory replica is returned, which is what tests inspect.
	 * @return array<string, string[]> Lowercase name => values.
	 */
	public static function effective_headers( $only_sent = false ) {
		$headers = array();
		foreach ( self::$log as $entry ) {
			if ( $only_sent && empty( $entry[4] ) ) {
				continue;
			}
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
	 * @return array<int, array{0:string,1:string,2:string,3:bool,4:bool}>
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
