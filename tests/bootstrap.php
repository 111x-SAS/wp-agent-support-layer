<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package WPASL
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false === $_phpunit_polyfills_path ) {
	$_phpunit_polyfills_path = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills';
}
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/wp-agent-support-layer.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

/**
 * Safety net: tests never make real HTTP requests. Test classes that simulate responses hook
 * pre_http_request at priority 10 or higher and override this error.
 *
 * @param false|array|WP_Error $pre  Short-circuit value.
 * @param array                $args Request arguments.
 * @param string               $url  URL.
 * @return WP_Error
 */
function wpasl_tests_block_http( $pre, $args, $url ) {
	return new WP_Error( 'wpasl_tests_http_blocked', 'Tests never make HTTP requests: ' . $url );
}
tests_add_filter( 'pre_http_request', 'wpasl_tests_block_http', 1, 3 );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";
