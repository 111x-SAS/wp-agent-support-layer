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
