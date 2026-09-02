<?php
/**
 * Removes all data created by WP Agent Support Layer.
 *
 * @package WPASL
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Placeholder: full cleanup (options, post meta, transients, storage directory)
// is implemented in the admin-settings capability.
delete_option( 'wpasl_settings' );
