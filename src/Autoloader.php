<?php
/**
 * Minimal PSR-4 autoloader.
 *
 * @package WPASL
 */

namespace WPASL;

/**
 * Registers PSR-4 prefixes without depending on Composer at runtime.
 */
final class Autoloader {

	/**
	 * Registered prefix => base directory map.
	 *
	 * @var array<string, string>
	 */
	private static $prefixes = array();

	/**
	 * Registers a namespace prefix.
	 *
	 * @param string $prefix   Namespace prefix, including trailing backslash.
	 * @param string $base_dir Absolute directory holding the classes.
	 * @return void
	 */
	public static function register( $prefix, $base_dir ) {
		if ( empty( self::$prefixes ) ) {
			spl_autoload_register( array( __CLASS__, 'load' ) );
		}
		self::$prefixes[ $prefix ] = rtrim( $base_dir, '/\\' ) . '/';
	}

	/**
	 * Loads a class file if it belongs to a registered prefix.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		foreach ( self::$prefixes as $prefix => $base_dir ) {
			if ( 0 !== strpos( $class_name, $prefix ) ) {
				continue;
			}
			$relative = substr( $class_name, strlen( $prefix ) );
			$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
			return;
		}
	}
}
