<?php
/**
 * Removes all data created by WP Agent Support Layer.
 *
 * @package WPASL
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Autoloader.php';
WPASL\Autoloader::register( 'WPASL\\', __DIR__ . '/src/' );

WPASL\Uninstaller::run();
