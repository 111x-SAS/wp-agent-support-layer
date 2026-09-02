<?php
/**
 * Serves Markdown documents.
 *
 * @package WPASL
 */

namespace WPASL\Markdown;

use WPASL\Content\Eligibility;
use WPASL\Generation\Runner;
use WPASL\Http;
use WPASL\Settings;
use WPASL\Storage;

/**
 * Content negotiation (Accept: text/markdown), the ".md" alternate URL, response headers and discovery links.
 */
final class Delivery {

	const QUERY_VAR = 'wpasl';
	const MIME      = 'text/markdown';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	private $storage;

	/**
	 * Eligibility.
	 *
	 * @var Eligibility
	 */
	private $eligibility;

	/**
	 * Runner (for lazy fill).
	 *
	 * @var Runner
	 */
	private $runner;

	/**
	 * Post id resolved from a ".md" request, if any.
	 *
	 * @var int
	 */
	private $md_request_post_id = 0;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings    Settings.
	 * @param Storage     $storage     Storage.
	 * @param Eligibility $eligibility Eligibility.
	 * @param Runner      $runner      Runner.
	 */
	public function __construct( Settings $settings, Storage $storage, Eligibility $eligibility, Runner $runner ) {
		$this->settings    = $settings;
		$this->storage     = $storage;
		$this->eligibility = $eligibility;
		$this->runner      = $runner;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'parse_request', array( $this, 'handle_md_suffix' ), 1 );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ), 1 );
		add_action( 'template_redirect', array( $this, 'send_html_headers' ), 2 );
		add_action( 'wp_head', array( $this, 'print_alternate_link' ), 5 );
	}

	/**
	 * Registers the "wpasl" query variable.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Markdown URL of a post: ".md" suffix with pretty permalinks, query argument otherwise.
	 *
	 * @param int|\WP_Post $post Post.
	 * @return string
	 */
	public function markdown_url( $post ) {
		$permalink = get_permalink( $post );
		if ( ! $permalink ) {
			return '';
		}
		if ( self::needs_query_arg( $permalink ) ) {
			return add_query_arg( self::QUERY_VAR, 'md', $permalink );
		}
		return untrailingslashit( $permalink ) . '.md';
	}

	/**
	 * Whether the ".md" suffix cannot be appended to a permalink: plain permalinks, a permalink that already
	 * carries a query string (post types without rewrite rules) or the site root (static front page).
	 *
	 * @param string $permalink Permalink.
	 * @return bool
	 */
	private static function needs_query_arg( $permalink ) {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return true;
		}
		if ( false !== strpos( $permalink, '?' ) ) {
			return true;
		}
		return untrailingslashit( $permalink ) === untrailingslashit( home_url( '/' ) );
	}

	/**
	 * Whether an Accept header prefers Markdown over HTML.
	 *
	 * @param string|null $accept Accept header value.
	 * @return bool
	 */
	public static function prefers_markdown( $accept ) {
		$accept = trim( (string) $accept );
		if ( '' === $accept ) {
			return false;
		}

		$markdown = null;
		$html     = null;
		foreach ( explode( ',', $accept ) as $range ) {
			$parts = array_map( 'trim', explode( ';', $range ) );
			$type  = strtolower( array_shift( $parts ) );
			$q     = 1.0;
			foreach ( $parts as $param ) {
				if ( 0 === stripos( $param, 'q=' ) ) {
					$q = (float) substr( $param, 2 );
				}
			}
			if ( self::MIME === $type ) {
				$markdown = max( (float) $markdown, $q );
			} elseif ( in_array( $type, array( 'text/html', 'application/xhtml+xml', 'text/*', '*/*' ), true ) ) {
				$html = max( (float) $html, $q );
			}
		}

		if ( null === $markdown || $markdown <= 0 ) {
			return false;
		}
		return null === $html || $markdown >= $html;
	}

	/**
	 * Resolves "/path.md" and "/path/.md" requests before WordPress parses the query.
	 *
	 * @param \WP $wp WordPress environment.
	 * @return void
	 */
	public function handle_md_suffix( $wp ) {
		$this->md_request_post_id = 0;
		// A new request is being parsed.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );

		if ( preg_match( '#^(.*?)/?\.md$#', $path, $m ) ) {
			$this->serve_md_suffix( $wp, self::relative_path( $m[1] ) );
			return;
		}

		// "/?wpasl=md" on a static front page: WordPress does not resolve the root URL to the page when an
		// extra query variable is present (WP_Query::parse_query), so the request is served here.
		$requested = isset( $wp->query_vars[ self::QUERY_VAR ] ) && 'md' === $wp->query_vars[ self::QUERY_VAR ];
		if ( $requested && '' === self::relative_path( $path ) ) {
			$front = self::front_page_id();
			if ( $front > 0 && $this->eligibility->is_eligible( $front ) ) {
				$this->md_request_post_id = $front;
				$this->serve( get_post( $front ) );
			}
		}
	}

	/**
	 * Serves a ".md" request: "/path.md", "/path/.md" or "/.md" (static front page).
	 *
	 * @param \WP    $wp       WordPress environment.
	 * @param string $relative Path relative to the site root, without slashes.
	 * @return void
	 */
	private function serve_md_suffix( $wp, $relative ) {
		$this->md_request_post_id = -1;
		// Marks a ".md" request that did not resolve.
		add_filter( 'redirect_canonical', '__return_false' );

		if ( '' === $relative ) {
			$post_id = self::front_page_id();
		} else {
			$post_id = url_to_postid( home_url( '/' . $relative . '/' ) );
			if ( $post_id <= 0 ) {
				$post_id = url_to_postid( home_url( '/' . $relative ) );
			}
		}
		if ( $post_id <= 0 || ! $this->eligibility->is_eligible( $post_id ) ) {
			$this->force_404( $wp );
			return;
		}

		$this->md_request_post_id = $post_id;
		$this->serve( get_post( $post_id ) );
	}

	/**
	 * Strips the site's base path (subdirectory installs) and surrounding slashes from a request path.
	 *
	 * @param string $path Request path.
	 * @return string
	 */
	private static function relative_path( $path ) {
		$base_path = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base_path && 0 === strpos( $path, $base_path ) ) {
			$path = substr( $path, strlen( $base_path ) );
		}
		return trim( (string) $path, '/' );
	}

	/**
	 * Id of the static front page, or 0 when the front page lists posts.
	 *
	 * @return int
	 */
	private static function front_page_id() {
		return 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
	}

	/**
	 * Serves Markdown on the canonical URL when requested via Accept or the query variable.
	 *
	 * @return bool Whether a document was served.
	 */
	public function maybe_serve() {
		if ( $this->md_request_post_id > 0 || ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! $this->eligibility->is_eligible( $post ) ) {
			return false;
		}

		$accept    = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
		$requested = 'md' === get_query_var( self::QUERY_VAR ) || self::prefers_markdown( $accept );
		if ( ! $requested ) {
			return false;
		}

		return $this->serve( $post );
	}

	/**
	 * Adds Vary and Link headers to the HTML response of eligible content.
	 *
	 * @return void
	 */
	public function send_html_headers() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! $this->eligibility->is_eligible( $post ) ) {
			return;
		}
		// Never replace: other components may have sent their own Vary or Link headers.
		foreach ( $this->html_headers( $post ) as $name => $value ) {
			Http::send_header( $name, $value, false );
		}
	}

	/**
	 * Headers added to the HTML response of an eligible post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, string>
	 */
	public function html_headers( \WP_Post $post ) {
		return array(
			'Vary' => 'Accept',
			'Link' => '<' . $this->markdown_url( $post ) . '>; rel="alternate"; type="text/markdown"',
		);
	}

	/**
	 * Prints the discovery link in the HTML head.
	 *
	 * @return void
	 */
	public function print_alternate_link() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! $this->eligibility->is_eligible( $post ) ) {
			return;
		}
		printf( '<link rel="alternate" type="text/markdown" href="%s" />' . "\n", esc_url( $this->markdown_url( $post ) ) );
	}

	/**
	 * Headers of a Markdown response.
	 *
	 * @param \WP_Post $post     Post.
	 * @param string   $document Document.
	 * @return array<string, string>
	 */
	public function markdown_headers( \WP_Post $post, $document ) {
		return array(
			'Content-Type'           => self::MIME . '; charset=utf-8',
			'Vary'                   => 'Accept',
			'X-Markdown-Tokens'      => (string) DocumentBuilder::estimate_tokens( $document ),
			'Link'                   => '<' . get_permalink( $post ) . '>; rel="canonical"',
			'Cache-Control'          => 'public, max-age=' . $this->max_age(),
			'X-Content-Type-Options' => 'nosniff',
		);
	}

	/**
	 * Cache lifetime: the regeneration interval in seconds.
	 *
	 * @return int
	 */
	public function max_age() {
		$schedules = wp_get_schedules();
		$schedule  = (string) $this->settings->get( 'schedule' );
		return isset( $schedules[ $schedule ] ) ? (int) $schedules[ $schedule ]['interval'] : DAY_IN_SECONDS;
	}

	/**
	 * Returns the stored document, generating it on demand when missing.
	 *
	 * @param \WP_Post $post Post.
	 * @return string|null
	 */
	public function document( \WP_Post $post ) {
		$document = $this->storage->read( Runner::document_path( $post->post_type, $post->ID ) );
		if ( null === $document ) {
			$document = $this->runner->generate_item( $post );
		}
		return $document;
	}

	/**
	 * Sends the Markdown response and ends the request.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool False when no document could be produced.
	 */
	public function serve( \WP_Post $post ) {
		$document = $this->document( $post );
		if ( null === $document ) {
			return false;
		}

		/**
		 * Fires right before a Markdown document is sent. Modules add headers here.
		 *
		 * @param string   $context "markdown".
		 * @param \WP_Post $post    Post.
		 */
		do_action( 'wpasl_before_serve', 'markdown', $post );

		if ( ! headers_sent() ) {
			status_header( 200 );
		}
		foreach ( $this->markdown_headers( $post, $document ) as $name => $value ) {
			Http::send_header( $name, $value, 'Vary' !== $name );
		}

		echo $document; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text/markdown body.

		/**
		 * Filters whether the request ends after serving a document. Tests disable it.
		 *
		 * @param bool $terminate Whether to exit.
		 */
		if ( apply_filters( 'wpasl_terminate_after_serve', true ) ) {
			exit;
		}
		return true;
	}

	/**
	 * Turns the current request into a 404.
	 *
	 * @param \WP $wp WordPress environment.
	 * @return void
	 */
	private function force_404( $wp ) {
		$wp->query_vars = array( 'error' => '404' );
	}
}
