<?php
/**
 * Plugin Name:       WP Agent Support Layer
 * Plugin URI:        https://github.com/111x-SAS/wp-agent-support-layer
 * Description:       Makes your site discoverable, readable and operable by AI agents and crawlers: Markdown delivery, Content Signals, AI crawler rules in robots.txt, llms.txt and agent manifests.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Mao Rodriguez
 * Author URI:        https://111x.co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-agent-support-layer
 * Domain Path:       /languages
 *
 * WP Agent Support Layer
 * Copyright (C) 2026 Mao Rodriguez <mao@111x.co> - 111X S.A.S <contacto@111x.co>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * @package WPASL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPASL_VERSION', '0.1.0' );
define( 'WPASL_FILE', __FILE__ );
define( 'WPASL_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPASL_URL', plugin_dir_url( __FILE__ ) );
define( 'WPASL_MIN_PHP', '7.4' );
define( 'WPASL_MIN_WP', '7.0' );

/**
 * Checks the minimum PHP and WordPress versions.
 *
 * @param string|null $php_version PHP version to check; defaults to the running version.
 * @param string|null $wp_version  WordPress version to check; defaults to the running version.
 * @return string Empty string when requirements are met, otherwise the admin notice text.
 */
function wpasl_requirements_notice( $php_version = null, $wp_version = null ) {
	$php_version = null === $php_version ? PHP_VERSION : $php_version;
	$wp_version  = null === $wp_version ? get_bloginfo( 'version' ) : $wp_version;

	if ( version_compare( $php_version, WPASL_MIN_PHP, '<' ) ) {
		return sprintf(
			/* translators: 1: required PHP version, 2: current PHP version. */
			__( 'WP Agent Support Layer requires PHP %1$s or newer. This site runs PHP %2$s.', 'wp-agent-support-layer' ),
			WPASL_MIN_PHP,
			$php_version
		);
	}

	if ( version_compare( $wp_version, WPASL_MIN_WP, '<' ) ) {
		return sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version. */
			__( 'WP Agent Support Layer requires WordPress %1$s or newer. This site runs WordPress %2$s.', 'wp-agent-support-layer' ),
			WPASL_MIN_WP,
			$wp_version
		);
	}

	return '';
}

/**
 * Prints the requirements notice in the admin area.
 *
 * @return void
 */
function wpasl_print_requirements_notice() {
	$notice = wpasl_requirements_notice();
	if ( '' === $notice ) {
		return;
	}
	printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $notice ) );
}

/**
 * Registers the plugin autoloaders. Idempotent.
 *
 * @return void
 */
function wpasl_load_autoloader() {
	static $loaded = false;
	if ( $loaded ) {
		return;
	}
	$loaded = true;

	require_once WPASL_DIR . 'src/Autoloader.php';
	WPASL\Autoloader::register( 'WPASL\\', WPASL_DIR . 'src/' );

	// Third-party libraries are prefixed under WPASL\Vendor by Strauss at build time.
	if ( is_readable( WPASL_DIR . 'vendor-prefixed/autoload.php' ) ) {
		require_once WPASL_DIR . 'vendor-prefixed/autoload.php';
	}
}

/**
 * Boots the plugin when requirements are met.
 *
 * @return void
 */
function wpasl_boot() {
	if ( '' !== wpasl_requirements_notice() ) {
		add_action( 'admin_notices', 'wpasl_print_requirements_notice' );
		return;
	}

	wpasl_load_autoloader();
	WPASL\Plugin::instance()->boot();
}

/**
 * Activation hook: refuses to activate on unsupported environments, otherwise sets the plugin up.
 *
 * @param bool $network_wide Whether the plugin is being network-activated.
 * @return void
 */
function wpasl_activate( $network_wide ) {
	$notice = wpasl_requirements_notice();
	if ( '' !== $notice ) {
		deactivate_plugins( plugin_basename( WPASL_FILE ) );
		wp_die( esc_html( $notice ), '', array( 'back_link' => true ) );
	}

	wpasl_load_autoloader();
	WPASL\Lifecycle::activate( (bool) $network_wide );
}

/**
 * Deactivation hook.
 *
 * @param bool $network_wide Whether the plugin is being network-deactivated.
 * @return void
 */
function wpasl_deactivate( $network_wide ) {
	if ( '' !== wpasl_requirements_notice() ) {
		return;
	}

	wpasl_load_autoloader();
	WPASL\Lifecycle::deactivate( (bool) $network_wide );
}

register_activation_hook( __FILE__, 'wpasl_activate' );
register_deactivation_hook( __FILE__, 'wpasl_deactivate' );
add_action( 'plugins_loaded', 'wpasl_boot', 5 );
