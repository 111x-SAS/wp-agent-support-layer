<?php
/**
 * Serves Markdown documents.
 *
 * @package WPASL
 */

namespace WPASL\Markdown;

use WPASL\Content\Eligibility;
use WPASL\Content\SitemapLocator;
use WPASL\Generation\Runner;
use WPASL\Http;
use WPASL\Manifest\AuthMdBuilder;
use WPASL\Manifest\AuthMdRouter;
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
		// After redirect_canonical() and wp_old_slug_redirect() (priority 10): a 404 is final only once
		// WordPress gave up redirecting the URL.
		add_action( 'template_redirect', array( $this, 'maybe_serve_404' ), 11 );
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
	 * carries a query string (post types without rewrite rules), the site root (static front page) or a
	 * permalink whose ".md" form would be the reserved /auth.md route.
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
		if ( untrailingslashit( $permalink ) === untrailingslashit( home_url( '/' ) ) ) {
			return true;
		}
		// "/auth/" + ".md" would collide with the site's auth.md, which owns that path.
		return 'auth' === self::relative_path( (string) wp_parse_url( $permalink, PHP_URL_PATH ) );
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
	 * Whether the current request is a render request: the loopback that fetches the rendered page of a
	 * post (RenderedPage) marks it with the X-WPASL-Render header and the wpasl_render query argument. Such
	 * a request always gets the usual HTML response, never Markdown, and never generates a document, so a
	 * Markdown request that generates on demand cannot recurse through the loopback.
	 *
	 * @return bool
	 */
	public static function is_render_request() {
		$header = isset( $_SERVER['HTTP_X_WPASL_RENDER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WPASL_RENDER'] ) ) : '';
		if ( '' !== $header ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only marker; the response is the regular page.
		$param = isset( $_GET[ RenderedPage::QUERY_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ RenderedPage::QUERY_ARG ] ) ) : '';
		return '' !== $param;
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
		if ( self::is_render_request() ) {
			return;
		}
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );

		// /auth.md is reserved for the site's auth.md document (served or 404'd by AuthMdRouter), never for
		// the Markdown of content with slug "auth". "/auth/.md" is not the reserved path and still resolves.
		if ( AuthMdRouter::requested( $request_uri ) ) {
			return;
		}

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
		} elseif ( self::posts_page_path() === $relative ) {
			// url_to_postid() returns 0 for the posts page: core treats it as the home query, not singular.
			$post_id = self::posts_page_id();
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
			$rest = substr( $path, strlen( $base_path ) );
			// Only a whole segment counts: on a site under /blog/, "/blogx.md" is not "/blog/x.md".
			if ( '' === $rest || '/' === $rest[0] ) {
				$path = $rest;
			}
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
	 * Id of the page configured as "Posts page", or 0 when there is none.
	 *
	 * @return int
	 */
	private static function posts_page_id() {
		return 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_for_posts' ) : 0;
	}

	/**
	 * Path of the posts page relative to the site root, without slashes; '' when there is none.
	 *
	 * @return string
	 */
	private static function posts_page_path() {
		$posts_page = self::posts_page_id();
		if ( $posts_page <= 0 ) {
			return '';
		}
		$permalink = get_permalink( $posts_page );
		return $permalink ? self::relative_path( (string) wp_parse_url( $permalink, PHP_URL_PATH ) ) : '';
	}

	/**
	 * Post of the current main query when it can have a Markdown version: a singular view, or the
	 * posts page (which core reports as the home query with the page as queried object).
	 *
	 * @return \WP_Post|null
	 */
	private static function queried_markdown_post() {
		if ( ! is_singular() && ! ( is_home() && self::posts_page_id() > 0 ) ) {
			return null;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		if ( ! is_singular() && self::posts_page_id() !== $post->ID ) {
			return null;
		}
		return $post;
	}

	/**
	 * Serves Markdown on the canonical URL when requested via Accept or the query variable.
	 *
	 * @return bool Whether a document was served.
	 */
	public function maybe_serve() {
		if ( $this->md_request_post_id > 0 || self::is_render_request() ) {
			return false;
		}
		$post = self::queried_markdown_post();
		if ( null === $post || ! $this->eligibility->is_eligible( $post ) ) {
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
		$post = self::queried_markdown_post();
		if ( null === $post || ! $this->eligibility->is_eligible( $post ) ) {
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
		$post = self::queried_markdown_post();
		if ( null === $post || ! $this->eligibility->is_eligible( $post ) ) {
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

		// send_headers may already have announced the API catalog (and other Link relations) for the HTML
		// representation before content negotiation picked Markdown; the Markdown response builds its own
		// Link set, so every relation is announced exactly once.
		Http::remove_header( 'Link' );

		/**
		 * Fires right before a Markdown document is sent, before the response's own headers. Modules add
		 * headers here; a Link header must be sent with replace = false so it survives the canonical Link.
		 *
		 * @param string   $context "markdown".
		 * @param \WP_Post $post    Post.
		 */
		do_action( 'wpasl_before_serve', 'markdown', $post );

		if ( ! headers_sent() ) {
			status_header( 200 );
		}
		// Never replace Vary or Link: modules listening to wpasl_before_serve add theirs to this response.
		foreach ( $this->markdown_headers( $post, $document ) as $name => $value ) {
			Http::send_header( $name, $value, ! in_array( $name, array( 'Vary', 'Link' ), true ) );
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
	 * Serves the Markdown 404 document when a 404 response goes to a client that asked for Markdown: a ".md"
	 * request that did not resolve, "?wpasl=md", or an Accept header preferring text/markdown. Browsers and
	 * any other client keep the theme's HTML 404 page.
	 *
	 * @return bool Whether the document was served.
	 */
	public function maybe_serve_404() {
		if ( ! is_404() || self::is_render_request() ) {
			return false;
		}
		$accept    = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
		$requested = -1 === $this->md_request_post_id || 'md' === get_query_var( self::QUERY_VAR ) || self::prefers_markdown( $accept );
		if ( ! $requested ) {
			return false;
		}

		$document = $this->not_found_document();

		// send_headers announced the API catalog (and possibly other Link relations) for the HTML response;
		// the Markdown 404 announces the catalog itself through wpasl_before_serve, exactly once.
		Http::remove_header( 'Link' );

		/**
		 * Fires right before the Markdown 404 document is sent (see the "markdown" context above).
		 *
		 * @param string $context "markdown-404".
		 * @param null   $post    No post: the request did not resolve to content.
		 */
		do_action( 'wpasl_before_serve', 'markdown-404', null );

		if ( ! headers_sent() ) {
			status_header( 404 );
		}
		foreach ( $this->not_found_headers( $document ) as $name => $value ) {
			Http::send_header( $name, $value, ! in_array( $name, array( 'Vary', 'Link' ), true ) );
		}

		echo $document; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text/markdown body.

		/** This filter is documented above in serve(). */
		if ( apply_filters( 'wpasl_terminate_after_serve', true ) ) {
			exit;
		}
		return true;
	}

	/**
	 * Headers of the Markdown 404 response. No canonical Link (there is no resource) and no caching: the
	 * body depends on the settings and the URL may start to exist as soon as content is published.
	 *
	 * @param string $document Document.
	 * @return array<string, string>
	 */
	public function not_found_headers( $document ) {
		return array(
			'Content-Type'           => self::MIME . '; charset=utf-8',
			'Vary'                   => 'Accept',
			'X-Markdown-Tokens'      => (string) DocumentBuilder::estimate_tokens( $document ),
			'Cache-Control'          => 'no-store',
			'X-Content-Type-Options' => 'nosniff',
		);
	}

	/**
	 * The Markdown 404 document: a short English note pointing agents at the site's discovery documents.
	 * Written in English on purpose and not translated (its readers are agents), built from real site
	 * values only, and never echoing the requested URL or any other request data.
	 *
	 * @return string
	 */
	public function not_found_document() {
		$site_name = DocumentBuilder::plain_text( get_bloginfo( 'name' ) );

		$out  = "# Not found\n\n";
		$out .= 'The requested resource does not exist on ' . $site_name . ", is not public, or has no Markdown version.\n\n";
		$out .= "## Where to look instead\n\n";
		$out .= '- [Site index (llms.txt)](' . home_url( '/llms.txt' ) . "): curated Markdown index of the public content.\n";
		if ( AuthMdBuilder::is_published( $this->settings ) ) {
			$out .= '- [Agent access documentation (auth.md)](' . AuthMdBuilder::url() . "): how agents may access this site.\n";
		}
		$sitemap = SitemapLocator::url();
		if ( null !== $sitemap ) {
			$out .= '- [Sitemap](' . $sitemap . "): XML sitemap of the whole site.\n";
		}
		if ( $this->settings->get( 'manifest_enabled' ) ) {
			$out .= '- [API catalog](' . home_url( '/.well-known/api-catalog' ) . "): RFC 9727 linkset with the OpenAPI description of the public REST API.\n";
		}

		/**
		 * Filters the Markdown 404 document served to agents.
		 *
		 * @param string $out Markdown document.
		 */
		return (string) apply_filters( 'wpasl_markdown_404', $out );
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
