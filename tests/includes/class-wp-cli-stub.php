<?php
/**
 * Minimal WP_CLI stub so the command class can be exercised in PHPUnit.
 *
 * @package WPASL
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Captures messages.
	 */
	class WP_CLI {
		/**
		 * Captured lines.
		 *
		 * @var string[]
		 */
		public static $log = array();

		/**
		 * Records a success line.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function success( $message ) {
			self::$log[] = 'Success: ' . $message;
		}

		/**
		 * Records an error line and aborts, as the real command runner does (exit).
		 *
		 * @param string $message Message.
		 * @return void
		 * @throws \RuntimeException Always.
		 */
		public static function error( $message ) {
			self::$log[] = 'Error: ' . $message;
			throw new \RuntimeException( 'WP_CLI::error: ' . $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test stub.
		}

		/**
		 * Records a plain line.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function log( $message ) {
			self::$log[] = $message;
		}
	}
}
