<?php
/**
 * Content Signals.
 *
 * @package WPASL
 */

namespace WPASL\Signals;

use WPASL\Admin\Page;
use WPASL\Admin\Tabs\SignalsTab;
use WPASL\Http;
use WPASL\Settings;

/**
 * Declares search / ai-input / ai-train preferences in robots.txt, HTTP headers and the robots meta tag.
 */
final class ContentSignals {

	const HEADER        = 'Content-Signal';
	const USAGE_HEADER  = 'Content-Usage';
	const ROBOTS_HEADER = 'X-Robots-Tag';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), 10 );
		add_action( 'send_headers', array( $this, 'send' ) );
		add_action( 'wpasl_before_serve', array( $this, 'send' ) );
		add_filter( 'wp_robots', array( $this, 'filter_wp_robots' ) );
		add_action( 'wpasl_register_tabs', array( $this, 'register_tab' ) );
	}

	/**
	 * Adds the Signals tab.
	 *
	 * @param Page $page Settings page.
	 * @return void
	 */
	public function register_tab( Page $page ) {
		$page->add_tab( new SignalsTab( $this->settings ) );
	}

	/**
	 * Current signal values.
	 *
	 * @return array<string, string> Keys search, ai-input, ai-train; values "yes" or "no".
	 */
	public function values() {
		return array(
			'search'   => 'yes' === $this->settings->get( 'signal_search' ) ? 'yes' : 'no',
			'ai-input' => 'yes' === $this->settings->get( 'signal_ai_input' ) ? 'yes' : 'no',
			'ai-train' => 'yes' === $this->settings->get( 'signal_ai_train' ) ? 'yes' : 'no',
		);
	}

	/**
	 * Whether AI training is allowed.
	 *
	 * @return bool
	 */
	public function allows_training() {
		return 'yes' === $this->values()['ai-train'];
	}

	/**
	 * Value of the Content-Signal header and robots.txt directive.
	 *
	 * @return string e.g. "search=yes, ai-input=yes, ai-train=no".
	 */
	public function header_value() {
		$pairs = array();
		foreach ( $this->values() as $signal => $value ) {
			$pairs[] = $signal . '=' . $value;
		}
		return implode( ', ', $pairs );
	}

	/**
	 * Value of the experimental IETF AIPREF Content-Usage header.
	 *
	 * The draft vocabulary defines train-ai and search; ai-input has no counterpart yet.
	 *
	 * @return string e.g. "train-ai=n, search=y".
	 */
	public function content_usage_value() {
		$values = $this->values();
		return 'train-ai=' . ( 'yes' === $values['ai-train'] ? 'y' : 'n' ) . ', search=' . ( 'yes' === $values['search'] ? 'y' : 'n' );
	}

	/**
	 * Whether the Content-Usage header is enabled.
	 *
	 * @return bool
	 */
	public function content_usage_enabled() {
		return (bool) $this->settings->get( 'content_usage_header' );
	}

	/**
	 * Headers sent on front-end responses.
	 *
	 * @param bool $html Whether the response is an HTML page (adds X-Robots-Tag).
	 * @return array<string, string>
	 */
	public function headers( $html = true ) {
		$headers = array( self::HEADER => $this->header_value() );
		if ( $this->content_usage_enabled() ) {
			$headers[ self::USAGE_HEADER ] = $this->content_usage_value();
		}
		if ( $html && ! $this->allows_training() ) {
			$headers[ self::ROBOTS_HEADER ] = 'noai, noimageai';
		}
		return $headers;
	}

	/**
	 * Sends the headers on front-end responses. Never in the admin.
	 *
	 * @param mixed $context The WP object on send_headers; "markdown", "llms-txt" or "manifest" when fired
	 *                       by wpasl_before_serve.
	 * @return void
	 */
	public function send( $context = null ) {
		if ( is_admin() ) {
			return;
		}
		$html = ! is_string( $context ) && ! ( $context instanceof \WP && self::is_non_html_query( $context->query_vars ) );
		if ( is_string( $context ) ) {
			// send_headers already emitted the HTML set (including X-Robots-Tag) before content negotiation
			// picked Markdown; the noai directives are for HTML responses only.
			Http::remove_header( self::ROBOTS_HEADER );
		}
		foreach ( $this->headers( $html ) as $name => $value ) {
			Http::send_header( $name, $value, self::ROBOTS_HEADER !== $name );
		}
		if ( ( $html || 'markdown' === $context ) && $this->settings->get( 'manifest_enabled' ) ) {
			// RFC 9727 §4: discovery of the API catalog from any resource of the origin. Never replace other Link headers.
			Http::send_header( 'Link', '<' . home_url( '/.well-known/api-catalog' ) . '>; rel="api-catalog"', false );
		}
	}

	/**
	 * Whether the main query targets a non-HTML resource (robots.txt, a feed or a sitemap). is_feed() and
	 * is_robots() are not reliable yet on send_headers, so the query vars are inspected directly.
	 *
	 * @param array<string, mixed> $query_vars Query vars.
	 * @return bool
	 */
	private static function is_non_html_query( array $query_vars ) {
		foreach ( array( 'feed', 'robots', 'sitemap', 'sitemap-stylesheet' ) as $var ) {
			if ( ! empty( $query_vars[ $var ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Inserts the Content-Signal directive in the "User-agent: *" group of the virtual robots.txt.
	 *
	 * @param string $output robots.txt output.
	 * @return string
	 */
	public function filter_robots_txt( $output ) {
		$block = "# Content Signals - https://contentsignals.org/\n" . self::HEADER . ': ' . $this->header_value() . "\n";

		$lines    = explode( "\n", (string) $output );
		$inserted = false;
		foreach ( $lines as $index => $line ) {
			if ( ! $inserted && preg_match( '/^user-agent:\s*\*\s*$/i', trim( $line ) ) ) {
				array_splice( $lines, $index + 1, 0, rtrim( $block, "\n" ) );
				$inserted = true;
				break;
			}
		}

		if ( ! $inserted ) {
			$lines[] = 'User-agent: *';
			$lines[] = rtrim( $block, "\n" );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Adds noai / noimageai to the robots meta tag when training is not allowed.
	 *
	 * @param array<string, bool|string> $robots Directives.
	 * @return array<string, bool|string>
	 */
	public function filter_wp_robots( $robots ) {
		if ( is_admin() || $this->allows_training() ) {
			return $robots;
		}
		$robots['noai']      = true;
		$robots['noimageai'] = true;
		return $robots;
	}
}
