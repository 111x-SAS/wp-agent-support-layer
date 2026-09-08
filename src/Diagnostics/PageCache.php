<?php
/**
 * Page cache detection and web server snippets.
 *
 * @package WPASL
 */

namespace WPASL\Diagnostics;

use WPASL\Settings;
use WPASL\Signals\ContentSignals;

/**
 * Detects a page cache plugin that serves HTML without PHP (Cache Enabler) and generates the web server
 * configuration that restores, on the cached HTML, the headers this plugin sends.
 */
final class PageCache {

	/**
	 * Value of the X-Cache-Handler header sent by Cache Enabler's advanced-cache.php drop-in.
	 */
	const CACHE_ENABLER_HANDLER = 'cache-enabler-engine';

	/**
	 * Identifier of Cache Enabler in the detection result.
	 */
	const CACHE_ENABLER_ID = 'cache-enabler';

	/**
	 * Display name of Cache Enabler (a product name, never translated).
	 */
	const CACHE_ENABLER_NAME = 'Cache Enabler';

	/**
	 * Heredoc marker of the multi-line extraHeaders form of OpenLiteSpeed.
	 */
	const OLS_MARKER = 'END_extraHeaders';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Signals.
	 *
	 * @var ContentSignals
	 */
	private $signals;

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Settings.
	 * @param ContentSignals $signals  Signals.
	 */
	public function __construct( Settings $settings, ContentSignals $signals ) {
		$this->settings = $settings;
		$this->signals  = $signals;
	}

	/**
	 * The page cache plugin active on this site, or null.
	 *
	 * Cache Enabler is identified by its class or its version constant, which is what the plugin really is
	 * (its directory may be renamed). The result can be replaced with a filter: the only way to simulate
	 * the plugin in tests, where a constant cannot be undefined.
	 *
	 * @return array{id:string, name:string, version:string}|null
	 */
	public static function detect() {
		$detected = null;
		if ( class_exists( 'Cache_Enabler', false ) || defined( 'CACHE_ENABLER_VERSION' ) ) {
			$detected = array(
				'id'      => self::CACHE_ENABLER_ID,
				'name'    => self::CACHE_ENABLER_NAME,
				'version' => defined( 'CACHE_ENABLER_VERSION' ) ? (string) CACHE_ENABLER_VERSION : '',
			);
		}

		/**
		 * Filters the page cache plugin detected on this site.
		 *
		 * Return null to hide the page cache notices; return an array with "id" (cache-enabler for Cache
		 * Enabler), "name" and "version" to force them.
		 *
		 * @param array{id:string, name:string, version:string}|null $detected Detected page cache, or null.
		 */
		$detected = apply_filters( 'wpasl_diagnostics_page_cache', $detected );

		if ( ! is_array( $detected ) || ! isset( $detected['id'] ) || '' === (string) $detected['id'] ) {
			return null;
		}
		return array(
			'id'      => (string) $detected['id'],
			'name'    => isset( $detected['name'] ) && '' !== (string) $detected['name'] ? (string) $detected['name'] : (string) $detected['id'],
			'version' => isset( $detected['version'] ) ? (string) $detected['version'] : '',
		);
	}

	/**
	 * Whether a detection result is Cache Enabler.
	 *
	 * @param mixed $detected Result of detect() (or the stored copy of it).
	 * @return bool
	 */
	public static function is_cache_enabler( $detected ) {
		return is_array( $detected ) && isset( $detected['id'] ) && self::CACHE_ENABLER_ID === $detected['id'];
	}

	/**
	 * Label of a page cache from the value of its X-Cache-Handler response header.
	 *
	 * @param string $handler Header value.
	 * @return string "Cache Enabler" for cache-enabler-engine; any other value as is.
	 */
	public static function label( $handler ) {
		$handler = (string) $handler;
		return self::CACHE_ENABLER_HANDLER === strtolower( trim( $handler ) ) ? self::CACHE_ENABLER_NAME : $handler;
	}

	/**
	 * Headers the web server should add to cached HTML responses: the same set this plugin sends on an HTML
	 * page (Content-Signal, Content-Usage when enabled, X-Robots-Tag when training is not allowed) plus the
	 * API catalog Link when the manifest is enabled.
	 *
	 * @return array<string, string> Header name => value.
	 */
	public function headers() {
		$headers = $this->signals->headers( true );
		if ( $this->settings->get( 'manifest_enabled' ) ) {
			$headers['Link'] = $this->signals->api_catalog_link();
		}
		return $headers;
	}

	/**
	 * Headers for the OpenLiteSpeed block: the same set without X-Robots-Tag, because OpenLiteSpeed cannot
	 * limit headers to HTML responses and "noai" must not reach Markdown or robots.txt. The Link relation
	 * is written without inner quotes (rel=api-catalog, equivalent per RFC 8288) because the parser is not
	 * known to accept escaped quotes inside a value.
	 *
	 * @return array<string, string> Header name => value.
	 */
	public function openlitespeed_headers() {
		$headers = $this->headers();
		unset( $headers[ ContentSignals::ROBOTS_HEADER ] );
		if ( isset( $headers['Link'] ) ) {
			$headers['Link'] = str_replace( '"', '', $headers['Link'] );
		}
		return $headers;
	}

	/**
	 * Block for .htaccess (Apache 2.4.7+ and LiteSpeed Enterprise, mod_headers), limited to HTML responses.
	 *
	 * Content-Signal and Content-Usage are unset in the onsuccess table and set in the always table so the
	 * response that regenerates the cache carries them exactly once with mod_php (onsuccess) and with
	 * PHP-FPM (always). X-Robots-Tag and Link use setifempty so a value sent by PHP or another plugin is kept.
	 *
	 * @return string
	 */
	public function htaccess_snippet() {
		$condition = '"expr=%{CONTENT_TYPE} =~ m#^text/html#"';
		$replace   = array( ContentSignals::HEADER, ContentSignals::USAGE_HEADER );
		$lines     = array(
			'# WP Agent Support Layer: headers that Cache Enabler drops on cached HTML (Apache 2.4.7+ / LiteSpeed Enterprise, mod_headers).',
			'# HTML responses only. Content-Signal and Content-Usage replace the value PHP sends, so they never repeat.',
			'<IfModule mod_headers.c>',
		);
		foreach ( $this->headers() as $name => $value ) {
			if ( in_array( $name, $replace, true ) ) {
				$lines[] = "\tHeader onsuccess unset {$name} {$condition}";
				$lines[] = "\tHeader always set {$name} " . self::apache_quote( $value ) . " {$condition}";
			} else {
				$lines[] = "\tHeader always setifempty {$name} " . self::apache_quote( $value ) . " {$condition}";
			}
		}
		$lines[] = '</IfModule>';
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Block for nginx: one map per header in http { } (an empty value means the header is not added, so only
	 * HTML responses get them) and the add_header lines for the server { } block.
	 *
	 * @return string
	 */
	public function nginx_snippet() {
		$host    = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$headers = $this->headers();
		$lines   = array(
			'# WP Agent Support Layer: headers that Cache Enabler drops on cached HTML (nginx).',
			'# 1) Inside http { }: an empty value means the header is not added, so only HTML responses get them.',
		);
		foreach ( $headers as $name => $value ) {
			$lines[] = 'map $sent_http_content_type $' . self::nginx_variable( $name ) . ' {';
			$lines[] = "\tdefault \"\";";
			$lines[] = "\t\"~^text/html\" " . self::nginx_quote( $value ) . ';';
			$lines[] = '}';
		}
		$lines[] = "# 2) Inside the server { } block of {$host}. A location { } with its own add_header lines";
		$lines[] = '#    stops inheriting these; repeat them there. The response that regenerates the cache passes';
		$lines[] = '#    through PHP and carries Content-Signal and Content-Usage twice with the same value.';
		foreach ( $headers as $name => $value ) {
			$lines[] = "add_header {$name} \$" . self::nginx_variable( $name ) . ' always;';
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Block for OpenLiteSpeed: extraHeaders inside the context / of the virtual host, in the shape of the
	 * CyberPanel knowledge base block, with the multi-line heredoc form. Without X-Robots-Tag on purpose.
	 *
	 * @return string
	 */
	public function openlitespeed_snippet() {
		$lines = array(
			'# WP Agent Support Layer: headers that Cache Enabler drops on cached HTML (OpenLiteSpeed).',
			'# CyberPanel: Websites > List Websites > Manage > vHost Conf, at the end of the file. If the file already has a',
			'# "context / { }" block, add the extraHeaders lines inside it instead of adding a second block.',
			'# OpenLiteSpeed WebAdmin: Virtual Hosts > your site > Context > "/" > Header Operations: paste only the lines',
			'# between the ' . self::OLS_MARKER . ' markers.',
			'# OpenLiteSpeed cannot limit headers to HTML responses, so X-Robots-Tag is left out on purpose: "noai" must not',
			'# reach Markdown or robots.txt. The response that regenerates the cache passes through PHP and may carry',
			'# Content-Signal and Content-Usage twice with the same value.',
			'# Then restart OpenLiteSpeed gracefully (WebAdmin > Actions > Graceful Restart, or: systemctl restart lsws).',
			'context / {',
			'  allowBrowse             1',
			'  extraHeaders            <<<' . self::OLS_MARKER,
		);
		foreach ( $this->openlitespeed_headers() as $name => $value ) {
			$operator = 'Link' === $name ? 'merge' : 'set';
			$lines[]  = "{$operator} {$name} \"{$value}\"";
		}
		$lines[] = '  ' . self::OLS_MARKER;
		$lines[] = '  rewrite  {';
		$lines[] = '  }';
		$lines[] = '  addDefaultCharset       off';
		$lines[] = '  phpIniOverride  {';
		$lines[] = '  }';
		$lines[] = '}';
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Reminder of the Cloudflare alternative, when the last report detected Cloudflare in front of the site.
	 *
	 * @param string $cdn CDN name of the stored report ("Cloudflare" or anything else).
	 * @return string Empty when the CDN is not Cloudflare.
	 */
	public function cloudflare_note( $cdn ) {
		if ( 'Cloudflare' !== (string) $cdn ) {
			return '';
		}
		$text = __( 'Cloudflare was detected in front of the site: instead of the web server you can add these headers with a Transform Rule (Rules > Transform Rules > Modify Response Header, Set static), one per header, limited to HTML responses if the rule expression allows it:', 'wp-agent-support-layer' );
		foreach ( $this->headers() as $name => $value ) {
			$text .= "\n" . $name . ': ' . $value;
		}
		return $text;
	}

	/**
	 * Title of the Diagnostics notice.
	 *
	 * @param array{id:string, name:string, version:string} $detected Result of detect().
	 * @return string
	 */
	public function notice_title( array $detected ) {
		if ( '' !== $detected['version'] ) {
			/* translators: 1: page cache plugin name, 2: its version. */
			return sprintf( __( '%1$s %2$s is active on this site', 'wp-agent-support-layer' ), $detected['name'], $detected['version'] );
		}
		/* translators: %s: page cache plugin name. */
		return sprintf( __( '%s is active on this site', 'wp-agent-support-layer' ), $detected['name'] );
	}

	/**
	 * Paragraphs of the Diagnostics notice: what is lost, what is not affected and where the fix is.
	 *
	 * @param array{id:string, name:string, version:string} $detected Result of detect().
	 * @return string[]
	 */
	public function notice_paragraphs( array $detected ) {
		return array(
			sprintf(
				/* translators: %s: page cache plugin name. */
				__( 'HTML served from the %s page cache does not carry the Content-Signal, Content-Usage, X-Robots-Tag, Link rel="api-catalog" and Link rel="alternate" type="text/markdown" headers this plugin sends: the cache stores the HTML body only and delivers it before WordPress loads. A request with Accept: text/markdown that also accepts text/html receives the cached HTML instead of Markdown, because the cache key ignores Accept. Only the request that regenerates the cache carries the headers, so they seem to appear once and then disappear. The .md URLs, llms.txt, robots.txt and the manifests are not affected, and the alternate <link> in the HTML head survives in the cached page.', 'wp-agent-support-layer' ),
				$detected['name']
			),
			__( 'The fix belongs to the web server or CDN, which can add the headers to every HTML response; this plugin never writes .htaccess or server configuration. Paste the block for your server below; the values are the ones this site sends. The Link rel="alternate" header cannot be restored this way because its value depends on each URL.', 'wp-agent-support-layer' ),
		);
	}

	/**
	 * Ready-to-copy snippets, one per web server.
	 *
	 * @return array<int, array{title:string, note:string, text:string}>
	 */
	public function snippets() {
		return array(
			array(
				'title' => __( '.htaccess (Apache 2.4.7+ / LiteSpeed Enterprise, mod_headers)', 'wp-agent-support-layer' ),
				'note'  => '',
				'text'  => $this->htaccess_snippet(),
			),
			array(
				'title' => __( 'nginx', 'wp-agent-support-layer' ),
				'note'  => '',
				'text'  => $this->nginx_snippet(),
			),
			array(
				'title' => __( 'OpenLiteSpeed (CyberPanel vHost Conf or WebAdmin Header Operations)', 'wp-agent-support-layer' ),
				'note'  => __( 'X-Robots-Tag is not included: OpenLiteSpeed cannot limit headers to HTML responses and "noai" must not reach Markdown or robots.txt.', 'wp-agent-support-layer' ),
				'text'  => $this->openlitespeed_snippet(),
			),
		);
	}

	/**
	 * Quotes a header value for an Apache Header directive.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function apache_quote( $value ) {
		return '"' . addcslashes( (string) $value, '"\\' ) . '"';
	}

	/**
	 * Quotes a header value for nginx: single quotes when the value contains double quotes (the Link header).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function nginx_quote( $value ) {
		$value = (string) $value;
		if ( false !== strpos( $value, '"' ) && false === strpos( $value, "'" ) ) {
			return "'" . $value . "'";
		}
		return '"' . addcslashes( $value, '"\\' ) . '"';
	}

	/**
	 * Name of the nginx map variable of a header (e.g. Content-Signal => wpasl_content_signal).
	 *
	 * @param string $name Header name.
	 * @return string
	 */
	public static function nginx_variable( $name ) {
		return 'wpasl_' . strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '_', (string) $name ) );
	}
}
