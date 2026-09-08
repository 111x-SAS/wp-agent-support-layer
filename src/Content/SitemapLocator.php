<?php
/**
 * Sitemap URL detection.
 *
 * @package WPASL
 */

namespace WPASL\Content;

/**
 * Finds the URL of the XML sitemap index the site really serves, without any HTTP request: the core
 * sitemaps when enabled, the Sitemap: lines of the virtual robots.txt, or a known SEO plugin. Shared by
 * llms.txt and the Markdown 404 document; a wrong guess only produces a help link, never a request.
 */
final class SitemapLocator {

	/**
	 * URL of the sitemap index, or null when it cannot be determined locally.
	 *
	 * @return string|null
	 */
	public static function url() {
		$url = self::core();
		if ( null === $url ) {
			$url = self::from_robots_txt();
		}
		if ( null === $url ) {
			$url = self::from_seo_plugin();
		}

		/**
		 * Filters the sitemap URL linked from llms.txt and the Markdown 404 document.
		 *
		 * @param string|null $url Absolute URL of the sitemap index, or null to omit the link.
		 */
		$url = apply_filters( 'wpasl_sitemap_url', $url );
		return is_string( $url ) && '' !== $url ? $url : null;
	}

	/**
	 * The core sitemap index when the core sitemaps are enabled; follows the permalink structure
	 * ("?sitemap=index" with plain permalinks).
	 *
	 * @return string|null
	 */
	private static function core() {
		if ( ! function_exists( 'wp_sitemaps_get_server' ) || ! wp_sitemaps_get_server()->sitemaps_enabled() ) {
			return null;
		}
		return function_exists( 'get_sitemap_url' ) ? (string) get_sitemap_url( 'index' ) : home_url( '/wp-sitemap.xml' );
	}

	/**
	 * The first "Sitemap:" line of the virtual robots.txt that points at this host. Rank Math, AIOSEO,
	 * SEOPress and others announce their index there.
	 *
	 * @return string|null
	 */
	private static function from_robots_txt() {
		/** This filter is documented in wp-includes/functions.php */
		$robots = (string) apply_filters( 'robots_txt', '', (bool) get_option( 'blog_public' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		$host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		// The core line survives when the sitemaps are disabled after the hook was registered; core() said no.
		$core = function_exists( 'get_sitemap_url' ) ? (string) get_sitemap_url( 'index' ) : '';
		foreach ( preg_split( '/\r\n|\r|\n/', $robots ) as $line ) {
			if ( ! preg_match( '/^\s*sitemap\s*:\s*(\S+)/i', $line, $m ) ) {
				continue;
			}
			$candidate = $m[1];
			if ( '' !== $core && $candidate === $core ) {
				continue;
			}
			if ( strtolower( (string) wp_parse_url( $candidate, PHP_URL_HOST ) ) === $host && preg_match( '#^https?://#i', $candidate ) ) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * The index of a known SEO plugin whose sitemap module is active. Class and function names verified
	 * against Yoast SEO 28.4, Rank Math 1.0.278, All in One SEO 5.0.1 and SEOPress 10.2.
	 *
	 * @return string|null
	 */
	private static function from_seo_plugin() {
		// Yoast SEO instantiates its sitemap router only when the XML sitemap is enabled.
		if ( class_exists( 'WPSEO_Sitemaps_Router', false ) ) {
			return home_url( '/sitemap_index.xml' );
		}
		// Rank Math loads the router with the sitemap module; the index slug defaults to "sitemap_index".
		if ( class_exists( 'RankMath\Sitemap\Router', false ) ) {
			$slug = 'sitemap_index';
			if ( class_exists( 'RankMath\Sitemap\Sitemap', false ) && method_exists( 'RankMath\Sitemap\Sitemap', 'get_sitemap_index_slug' ) ) {
				$slug = (string) call_user_func( array( 'RankMath\Sitemap\Sitemap', 'get_sitemap_index_slug' ) );
			}
			return home_url( '/' . ( '' === $slug ? 'sitemap_index' : $slug ) . '.xml' );
		}
		// All in One SEO serves the index at /sitemap.xml when its general sitemap is enabled.
		if ( function_exists( 'aioseo' ) && class_exists( 'AIOSEO\Plugin\AIOSEO', false ) ) {
			$enabled = true;
			try {
				$enabled = (bool) aioseo()->options->sitemap->general->enable;
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Unknown option layout: assume enabled.
				// Keep the default.
			}
			return $enabled ? home_url( '/sitemap.xml' ) : null;
		}
		// SEOPress serves the index at /sitemaps.xml when the XML sitemap feature is on.
		if ( function_exists( 'seopress_init' ) ) {
			$enabled = ! function_exists( 'seopress_get_toggle_option' ) || '1' === (string) seopress_get_toggle_option( 'xml-sitemap' );
			return $enabled ? home_url( '/sitemaps.xml' ) : null;
		}
		return null;
	}
}
