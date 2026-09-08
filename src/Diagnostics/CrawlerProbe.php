<?php
/**
 * Loopback crawler simulation.
 *
 * @package WPASL
 */

namespace WPASL\Diagnostics;

use WPASL\Content\Eligibility;
use WPASL\Generation\Runner;
use WPASL\Markdown\Delivery;
use WPASL\Robots\Catalog;
use WPASL\Storage;

/**
 * Fetches the site's own URLs with the user-agents of the crawler catalog and records what each one sees.
 * Only the site's own host is ever contacted.
 */
final class CrawlerProbe {

	/**
	 * Seconds allowed per request. Small so that a batch of crawlers never approaches proxy limits.
	 */
	const TIMEOUT = 5;

	/**
	 * Bytes read from any response at most.
	 */
	const MAX_RESPONSE_BYTES = 1048576;

	/**
	 * Bytes of body kept for the discovery files (robots.txt, llms.txt).
	 */
	const MAX_BODY_KEPT = 65536;

	/**
	 * Response headers worth keeping.
	 *
	 * @var string[]
	 */
	const HEADERS = array( 'content-type', 'content-signal', 'content-usage', 'x-robots-tag', 'link', 'vary', 'x-markdown-tokens', 'cache-control', 'location', 'cf-ray', 'cf-cache-status', 'server', 'x-cache', 'via', 'x-served-by', 'x-cache-handler' );

	/**
	 * Site targets whose body is kept for the report.
	 *
	 * @var string[]
	 */
	const KEEP_BODY = array( 'robots', 'llms' );

	/**
	 * Eligibility.
	 *
	 * @var Eligibility
	 */
	private $eligibility;

	/**
	 * Delivery.
	 *
	 * @var Delivery
	 */
	private $delivery;

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	private $storage;

	/**
	 * Constructor.
	 *
	 * @param Eligibility $eligibility Eligibility.
	 * @param Delivery    $delivery    Delivery.
	 * @param Storage     $storage     Storage.
	 */
	public function __construct( Eligibility $eligibility, Delivery $delivery, Storage $storage ) {
		$this->eligibility = $eligibility;
		$this->delivery    = $delivery;
		$this->storage     = $storage;
	}

	/**
	 * The most recent eligible post used as sample, or null.
	 *
	 * @return \WP_Post|null
	 */
	public function sample_post() {
		$ids = $this->eligibility->query(
			null,
			array(
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		return empty( $ids ) ? null : get_post( $ids[0] );
	}

	/**
	 * URLs probed once, regardless of crawler.
	 *
	 * @return array<string, string> Key => URL.
	 */
	public function site_targets() {
		$targets = array(
			'robots'  => home_url( '/robots.txt' ),
			'llms'    => home_url( '/llms.txt' ),
			'auth'    => home_url( '/auth.md' ),
			'skills'  => home_url( '/agent-skills.json' ),
			'catalog' => home_url( '/.well-known/api-catalog' ),
		);
		$post    = $this->sample_post();
		if ( $post ) {
			$targets['markdown_url'] = $this->delivery->markdown_url( $post );
		}
		$direct = $this->storage_direct_url();
		if ( null !== $direct ) {
			$targets['storage'] = $direct;
		}
		return $targets;
	}

	/**
	 * Public URL of the probe file kept in the storage, to check that direct access is denied. Null when
	 * the storage directory cannot be created.
	 *
	 * @return string|null
	 */
	public function storage_direct_url() {
		if ( ! $this->storage->ensure() ) {
			return null;
		}
		$uploads  = wp_upload_dir( null, false );
		$relative = substr( $this->storage->base_dir(), strlen( $uploads['basedir'] ) );
		return $uploads['baseurl'] . $relative . '/' . Storage::PROBE_FILE;
	}

	/**
	 * Crawlers to simulate, keyed by agent token.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function crawlers() {
		/**
		 * Filters the crawlers simulated by the diagnostics.
		 *
		 * @param array<string, array<string, string>> $crawlers Catalog entries keyed by agent token.
		 */
		return (array) apply_filters( 'wpasl_diagnostics_crawlers', Catalog::all() );
	}

	/**
	 * Starts a run: probes the site-wide targets and lists the crawlers still to simulate.
	 *
	 * @return array<string, mixed> Run state: generated_at, sample_post, page_cache (local detection at the time
	 *                              of the run, or null), site, crawlers (empty), pending.
	 */
	public function begin() {
		$post = $this->sample_post();
		return array(
			'generated_at' => time(),
			'sample_post'  => $post ? $post->ID : 0,
			'page_cache'   => PageCache::detect(),
			'site'         => $this->probe_site(),
			'crawlers'     => array(),
			'pending'      => array_keys( $this->crawlers() ),
		);
	}

	/**
	 * Probes the site-wide targets with the diagnostics user-agent.
	 *
	 * @return array<string, array<string, mixed>> Key => result.
	 */
	public function probe_site() {
		$results = array();
		foreach ( $this->site_targets() as $key => $url ) {
			$results[ $key ] = $this->fetch(
				$url,
				'WP-Agent-Support-Layer-Diagnostics/' . WPASL_VERSION,
				'storage' === $key ? '*/*' : 'text/markdown, application/json;q=0.9, text/html;q=0.8, */*;q=0.5',
				in_array( $key, self::KEEP_BODY, true )
			);
		}
		return $results;
	}

	/**
	 * Probes, as one crawler, the home page, the sample item (HTML, negotiated Markdown and its .md URL) and
	 * robots.txt, so user-agent specific rules of a WAF or CDN show up per crawler.
	 *
	 * @param string        $agent Agent token.
	 * @param \WP_Post|null $post  Sample post, or null when the site has no eligible item.
	 * @return array<string, array<string, mixed>> Check => result.
	 */
	public function probe_crawler( $agent, $post ) {
		$user_agent = 'Mozilla/5.0 (compatible; ' . $agent . '/1.0; +https://example.invalid/' . rawurlencode( $agent ) . ')';
		$checks     = array(
			'home' => $this->fetch( home_url( '/' ), $user_agent, 'text/html,*/*;q=0.8' ),
		);
		if ( $post instanceof \WP_Post ) {
			$checks['post_html']     = $this->fetch( get_permalink( $post ), $user_agent, 'text/html,*/*;q=0.8' );
			$checks['post_markdown'] = $this->fetch( get_permalink( $post ), $user_agent, 'text/markdown, text/html;q=0.9, */*;q=0.8' );
			$checks['post_md']       = $this->fetch( $this->delivery->markdown_url( $post ), $user_agent, 'text/markdown, */*;q=0.5' );
		}
		$checks['robots'] = $this->fetch( home_url( '/robots.txt' ), $user_agent, 'text/plain, */*;q=0.5' );
		return $checks;
	}

	/**
	 * Runs the whole probe in one go (WP-CLI and tests; the admin action runs it in batches).
	 *
	 * @return array<string, mixed> Raw results: "site" (key => result) and "crawlers" (agent => check => result).
	 */
	public function run() {
		$run  = $this->begin();
		$post = $run['sample_post'] ? get_post( $run['sample_post'] ) : null;
		foreach ( $run['pending'] as $agent ) {
			$run['crawlers'][ $agent ] = $this->probe_crawler( $agent, $post );
		}
		unset( $run['pending'] );
		return $run;
	}

	/**
	 * Fetches one URL of this site.
	 *
	 * @param string $url        URL (must be on this site's host).
	 * @param string $user_agent User-Agent header.
	 * @param string $accept     Accept header.
	 * @param bool   $keep_body  Whether to keep (a prefix of) the body in the result.
	 * @return array<string, mixed>
	 */
	public function fetch( $url, $user_agent, $accept, $keep_body = false ) {
		$result = array(
			'url'         => $url,
			'user_agent'  => $user_agent,
			'accept'      => $accept,
			'status'      => 0,
			'headers'     => array(),
			'error'       => '',
			'has_md_link' => false,
			'has_md_meta' => false,
		);

		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $url_host || $url_host !== $home_host ) {
			$result['error'] = 'external host refused';
			return $result;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				// Never follow redirects: a redirect to "www." or a CDN host would leave the site.
				'redirection'         => 0,
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
				'user-agent'          => $user_agent,
				'headers'             => array( 'Accept' => $accept ),
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false, $url ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
			return $result;
		}

		$result['status'] = (int) wp_remote_retrieve_response_code( $response );
		foreach ( self::HEADERS as $name ) {
			$value = wp_remote_retrieve_header( $response, $name );
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}
			if ( '' !== (string) $value ) {
				$result['headers'][ $name ] = (string) $value;
			}
		}

		$body                  = (string) wp_remote_retrieve_body( $response );
		$result['has_md_link'] = (bool) preg_match( '/<link[^>]+type=["\']text\/markdown["\']/i', $body );
		$result['has_md_meta'] = (bool) preg_match( '/<meta[^>]+name=["\']robots["\'][^>]+noai/i', $body );
		if ( $keep_body ) {
			$result['body'] = substr( $body, 0, self::MAX_BODY_KEPT );
		}

		return $result;
	}
}
