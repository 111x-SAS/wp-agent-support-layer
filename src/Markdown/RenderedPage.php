<?php
/**
 * Loopback fetch of a rendered page.
 *
 * @package WPASL
 */

namespace WPASL\Markdown;

/**
 * Fetches the HTML of a post from this site's own host with a render marker (query argument and header)
 * that Delivery recognizes so the response is never Markdown and never triggers generation. Never follows a
 * redirect automatically: same-host redirects are followed manually (at most two), other hosts fail. During
 * a generation run the request timeout is capped by the run's deadline.
 */
final class RenderedPage {

	/**
	 * Query argument marking a render request. Not a registered query var on purpose: a registered one
	 * would keep WordPress from resolving the static front page (WP_Query::parse_query()).
	 */
	const QUERY_ARG = 'wpasl_render';

	/**
	 * Header marking a render request.
	 */
	const HEADER = 'X-WPASL-Render';

	/**
	 * Default request timeout, in seconds.
	 */
	const DEFAULT_TIMEOUT = 10;

	/**
	 * Default maximum response size, in bytes.
	 */
	const MAX_RESPONSE_BYTES = 2097152;

	/**
	 * Same-host redirects followed at most.
	 */
	const MAX_REDIRECTS = 2;

	/**
	 * Seconds left in the run below which the fetch is deferred instead of attempted.
	 */
	const MIN_TIMEOUT = 2;

	/**
	 * Deadline (microtime) of the generation run in progress, or null outside a run.
	 *
	 * @var float|null
	 */
	private $deadline = null;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wpasl_run_started', array( $this, 'on_run_started' ) );
		add_action( 'wpasl_run_finished', array( $this, 'on_run_finished' ) );
	}

	/**
	 * Records the deadline of the generation run that just started.
	 *
	 * @param float $deadline Microtime after which the run stops.
	 * @return void
	 */
	public function on_run_started( $deadline ) {
		$this->deadline = (float) $deadline;
	}

	/**
	 * Forgets the deadline.
	 *
	 * @return void
	 */
	public function on_run_finished() {
		$this->deadline = null;
	}

	/**
	 * URL requested to render a post: its permalink with the render query argument.
	 *
	 * @param int|\WP_Post $post Post.
	 * @return string
	 */
	public static function url( $post ) {
		$permalink = get_permalink( $post );
		return $permalink ? add_query_arg( self::QUERY_ARG, '1', $permalink ) : '';
	}

	/**
	 * Fetches the rendered page of a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{ok:bool, body:string, error:string, status:int} On failure "error" is one of external_host,
	 *                                                               request_error:<code>, http_<status>,
	 *                                                               redirect_external_host, redirect_loop,
	 *                                                               not_html, empty_body or deferred.
	 */
	public function fetch( \WP_Post $post ) {
		$url = self::url( $post );
		if ( '' === $url || ! self::is_own_host( $url ) ) {
			return self::failure( 'external_host' );
		}

		$args = array(
			'timeout'             => self::DEFAULT_TIMEOUT,
			'redirection'         => 0,
			'limit_response_size' => self::MAX_RESPONSE_BYTES,
			'reject_unsafe_urls'  => true,
			'user-agent'          => 'WP-Agent-Support-Layer-Render/' . WPASL_VERSION,
			'headers'             => array(
				'Accept'     => 'text/html',
				self::HEADER => '1',
			),
			/** This filter is documented in wp-includes/class-wp-http-streams.php */
			'sslverify'           => apply_filters( 'https_local_ssl_verify', false, $url ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		);
		/**
		 * Filters the arguments of the loopback request that fetches the rendered page of a post.
		 *
		 * @param array<string, mixed> $args wp_remote_get() arguments (timeout, limit_response_size, headers...).
		 * @param \WP_Post             $post Post.
		 */
		$args = (array) apply_filters( 'wpasl_render_request_args', $args, $post );

		$hops = 0;
		while ( true ) {
			if ( null !== $this->deadline ) {
				$remaining = $this->deadline - microtime( true );
				if ( $remaining < self::MIN_TIMEOUT ) {
					return self::failure( 'deferred' );
				}
				$timeout         = isset( $args['timeout'] ) ? (float) $args['timeout'] : (float) self::DEFAULT_TIMEOUT;
				$args['timeout'] = round( min( $timeout, $remaining ), 2 );
			}

			$response = wp_remote_get( $url, $args );
			if ( is_wp_error( $response ) ) {
				return self::failure( 'request_error:' . $response->get_error_code() );
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				$location = is_array( $location ) ? (string) reset( $location ) : (string) $location;
				if ( '' === $location ) {
					return self::failure( 'http_' . $status, $status );
				}
				$location = \WP_Http::make_absolute_url( $location, $url );
				if ( ! self::is_own_host( $location ) ) {
					return self::failure( 'redirect_external_host', $status );
				}
				if ( $hops >= self::MAX_REDIRECTS ) {
					return self::failure( 'redirect_loop', $status );
				}
				++$hops;
				$url = $location;
				continue;
			}
			if ( 200 !== $status ) {
				return self::failure( 'http_' . $status, $status );
			}

			$type = wp_remote_retrieve_header( $response, 'content-type' );
			$type = is_array( $type ) ? implode( ', ', $type ) : (string) $type;
			if ( false === stripos( $type, 'text/html' ) ) {
				return self::failure( 'not_html', $status );
			}

			$body = (string) wp_remote_retrieve_body( $response );
			if ( '' === trim( $body ) ) {
				return self::failure( 'empty_body', $status );
			}

			return array(
				'ok'     => true,
				'body'   => $body,
				'error'  => '',
				'status' => $status,
			);
		}//end while
	}

	/**
	 * Whether a URL points at this site's host.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_own_host( $url ) {
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return '' !== $url_host && $url_host === $home_host;
	}

	/**
	 * Builds a failed result.
	 *
	 * @param string $error  Reason.
	 * @param int    $status HTTP status, if any.
	 * @return array{ok:bool, body:string, error:string, status:int}
	 */
	private static function failure( $error, $status = 0 ) {
		return array(
			'ok'     => false,
			'body'   => '',
			'error'  => $error,
			'status' => (int) $status,
		);
	}
}
