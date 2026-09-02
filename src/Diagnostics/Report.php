<?php
/**
 * Diagnostics report.
 *
 * @package WPASL
 */

namespace WPASL\Diagnostics;

use WPASL\Robots\Policy;
use WPASL\Signals\ContentSignals;

/**
 * Turns raw probe results into labelled checks (ok / warning / error) and infrastructure findings.
 */
final class Report {

	const TRANSIENT = 'wpasl_diagnostics_report';
	const TTL       = HOUR_IN_SECONDS;

	const OK      = 'ok';
	const WARNING = 'warning';
	const ERROR   = 'error';

	/**
	 * Policy.
	 *
	 * @var Policy
	 */
	private $policy;

	/**
	 * Signals.
	 *
	 * @var ContentSignals
	 */
	private $signals;

	/**
	 * Constructor.
	 *
	 * @param Policy         $policy  Policy.
	 * @param ContentSignals $signals Signals.
	 */
	public function __construct( Policy $policy, ContentSignals $signals ) {
		$this->policy  = $policy;
		$this->signals = $signals;
	}

	/**
	 * Builds the report from raw probe results.
	 *
	 * @param array<string, mixed> $raw Probe results.
	 * @return array<string, mixed>
	 */
	public function build( array $raw ) {
		$report = array(
			'generated_at'   => isset( $raw['generated_at'] ) ? (int) $raw['generated_at'] : time(),
			'sample_post'    => isset( $raw['sample_post'] ) ? (int) $raw['sample_post'] : 0,
			'infrastructure' => $this->infrastructure( $raw ),
			'site'           => $this->site_checks( $raw ),
			'crawlers'       => array(),
		);
		foreach ( (array) ( isset( $raw['crawlers'] ) ? $raw['crawlers'] : array() ) as $agent => $checks ) {
			$report['crawlers'][ $agent ] = $this->crawler_checks( $agent, (array) $checks, $report['infrastructure'] );
		}
		return $report;
	}

	/**
	 * Stores a report for one hour.
	 *
	 * @param array<string, mixed> $report Report.
	 * @return void
	 */
	public static function save( array $report ) {
		set_transient( self::TRANSIENT, $report, self::TTL );
	}

	/**
	 * Loads the stored report.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function load() {
		$report = get_transient( self::TRANSIENT );
		return is_array( $report ) ? $report : null;
	}

	/**
	 * CDN / proxy detection and edge-conversion warning.
	 *
	 * @param array<string, mixed> $raw Probe results.
	 * @return array<string, mixed>
	 */
	private function infrastructure( array $raw ) {
		$info = array(
			'cdn'             => '',
			'server'          => '',
			'edge_markdown'   => false,
			'storage_exposed' => false,
		);

		$all = array();
		foreach ( (array) ( isset( $raw['site'] ) ? $raw['site'] : array() ) as $result ) {
			$all[] = $result;
		}
		foreach ( (array) ( isset( $raw['crawlers'] ) ? $raw['crawlers'] : array() ) as $checks ) {
			foreach ( (array) $checks as $result ) {
				$all[] = $result;
			}
		}

		foreach ( $all as $result ) {
			$headers = isset( $result['headers'] ) ? (array) $result['headers'] : array();
			if ( isset( $headers['cf-ray'] ) || isset( $headers['cf-cache-status'] ) ) {
				$info['cdn'] = 'Cloudflare';
			} elseif ( '' === $info['cdn'] && ( isset( $headers['x-cache'] ) || isset( $headers['via'] ) || isset( $headers['x-served-by'] ) ) ) {
				$info['cdn'] = isset( $headers['via'] ) ? $headers['via'] : ( isset( $headers['x-served-by'] ) ? $headers['x-served-by'] : $headers['x-cache'] );
			}
			if ( '' === $info['server'] && isset( $headers['server'] ) ) {
				$info['server'] = $headers['server'];
			}
		}

		if ( 'Cloudflare' === $info['cdn'] ) {
			foreach ( (array) ( isset( $raw['crawlers'] ) ? $raw['crawlers'] : array() ) as $checks ) {
				$md = isset( $checks['post_markdown'] ) ? (array) $checks['post_markdown'] : array();
				if ( self::is_markdown( $md ) && ! self::has_canonical_link( $md ) ) {
					$info['edge_markdown'] = true;
					break;
				}
			}
		}

		if ( isset( $raw['site']['storage'] ) ) {
			$info['storage_exposed'] = 200 === (int) $raw['site']['storage']['status'];
		}

		return $info;
	}

	/**
	 * Site-wide checks.
	 *
	 * @param array<string, mixed> $raw Probe results.
	 * @return array<string, array{status:string, message:string}>
	 */
	private function site_checks( array $raw ) {
		$site   = (array) ( isset( $raw['site'] ) ? $raw['site'] : array() );
		$checks = array();

		$expect = array(
			'robots'       => array( 'text/plain', __( 'robots.txt', 'wp-agent-support-layer' ) ),
			'llms'         => array( 'text/markdown', __( 'llms.txt', 'wp-agent-support-layer' ) ),
			'skills'       => array( 'application/ld+json', __( 'agent-skills.json', 'wp-agent-support-layer' ) ),
			'catalog'      => array( 'application/linkset+json', __( 'API catalog', 'wp-agent-support-layer' ) ),
			'markdown_url' => array( 'text/markdown', __( 'Markdown URL of the sample item', 'wp-agent-support-layer' ) ),
		);
		foreach ( $expect as $key => $spec ) {
			if ( ! isset( $site[ $key ] ) ) {
				continue;
			}
			$result = (array) $site[ $key ];
			$type   = isset( $result['headers']['content-type'] ) ? $result['headers']['content-type'] : '';
			if ( self::is_redirect( $result ) ) {
				$checks[ $key ] = self::check( self::WARNING, self::redirect_message( $spec[1], $result ) );
			} elseif ( 200 !== (int) $result['status'] ) {
				$checks[ $key ] = self::check( self::ERROR, sprintf( '%1$s: HTTP %2$s %3$s', $spec[1], $result['status'], $result['error'] ) );
			} elseif ( false === stripos( $type, $spec[0] ) ) {
				$checks[ $key ] = self::check( self::WARNING, sprintf( '%1$s: unexpected Content-Type "%2$s" (expected %3$s).', $spec[1], $type, $spec[0] ) );
			} else {
				$checks[ $key ] = self::check( self::OK, sprintf( '%1$s: HTTP 200, %2$s.', $spec[1], $type ) );
			}
		}

		if ( isset( $site['storage'] ) ) {
			$exposed           = 200 === (int) $site['storage']['status'];
			$checks['storage'] = $exposed
				? self::check( self::ERROR, __( 'Generated documents are publicly accessible by direct URL. Apache: keep the .htaccess in uploads/wp-agent-support-layer. Nginx: add "location ^~ /wp-content/uploads/wp-agent-support-layer/ { deny all; }".', 'wp-agent-support-layer' ) )
				: self::check( self::OK, __( 'Direct access to the generated documents is denied.', 'wp-agent-support-layer' ) );
		}

		return $checks;
	}

	/**
	 * Checks for one crawler.
	 *
	 * @param string               $agent  Agent token.
	 * @param array<string, mixed> $checks Raw results.
	 * @param array<string, mixed> $infra  Infrastructure findings.
	 * @return array<string, mixed>
	 */
	private function crawler_checks( $agent, array $checks, array $infra ) {
		$policy = $this->policy->for_agent( $agent );
		$out    = array(
			'policy' => $policy,
			'checks' => array(),
		);

		$out['checks']['robots'] = Policy::BLOCK === $policy
			? self::check( self::OK, __( 'Blocked by robots.txt, as configured.', 'wp-agent-support-layer' ) )
			: self::check( self::OK, __( 'Allowed by robots.txt, as configured.', 'wp-agent-support-layer' ) );

		foreach ( array( 'home', 'post_html' ) as $key ) {
			if ( ! isset( $checks[ $key ] ) ) {
				continue;
			}
			$result = (array) $checks[ $key ];
			$label  = 'home' === $key ? __( 'Home page', 'wp-agent-support-layer' ) : __( 'Sample item (HTML)', 'wp-agent-support-layer' );
			if ( 200 === (int) $result['status'] ) {
				$out['checks'][ $key ] = self::check( self::OK, $label . ': HTTP 200.' );
			} elseif ( self::is_redirect( $result ) ) {
				$out['checks'][ $key ] = self::check( self::WARNING, self::redirect_message( $label, $result ) );
			} elseif ( in_array( (int) $result['status'], array( 403, 429, 503 ), true ) ) {
				$out['checks'][ $key ] = self::check( self::ERROR, sprintf( '%1$s: HTTP %2$d. A WAF, rate limit or bot filter is blocking this user-agent.', $label, $result['status'] ) );
			} else {
				$out['checks'][ $key ] = self::check( self::ERROR, sprintf( '%1$s: HTTP %2$s %3$s', $label, $result['status'], $result['error'] ) );
			}
		}

		if ( isset( $checks['post_html'] ) ) {
			$html                            = (array) $checks['post_html'];
			$headers                         = (array) $html['headers'];
			$out['checks']['content_signal'] = isset( $headers['content-signal'] )
				? self::check( self::OK, 'Content-Signal: ' . $headers['content-signal'] )
				: self::check( self::WARNING, __( 'Content-Signal header missing on the HTML response (a cache or proxy may strip it).', 'wp-agent-support-layer' ) );

			if ( ! $this->signals->allows_training() ) {
				$out['checks']['x_robots_tag'] = isset( $headers['x-robots-tag'] ) && false !== stripos( $headers['x-robots-tag'], 'noai' )
					? self::check( self::OK, 'X-Robots-Tag: ' . $headers['x-robots-tag'] )
					: self::check( self::WARNING, __( 'X-Robots-Tag noai missing on the HTML response.', 'wp-agent-support-layer' ) );
			}

			$has_link                        = ! empty( $html['has_md_link'] ) || ( isset( $headers['link'] ) && false !== stripos( $headers['link'], 'text/markdown' ) );
			$out['checks']['alternate_link'] = $has_link
				? self::check( self::OK, __( 'Markdown alternate link present.', 'wp-agent-support-layer' ) )
				: self::check( self::WARNING, __( 'No link rel="alternate" type="text/markdown" found (theme without wp_head, or headers stripped).', 'wp-agent-support-layer' ) );
		}

		if ( isset( $checks['post_markdown'] ) ) {
			$md = (array) $checks['post_markdown'];
			if ( self::is_markdown( $md ) ) {
				$message = __( 'Markdown served for Accept: text/markdown.', 'wp-agent-support-layer' );
				if ( ! empty( $infra['edge_markdown'] ) && ! self::has_canonical_link( $md ) ) {
					$out['checks']['negotiation'] = self::check( self::WARNING, $message . ' ' . __( 'It appears to be converted by Cloudflare "Markdown for Agents" instead of this plugin; keep only one of the two.', 'wp-agent-support-layer' ) );
				} else {
					$out['checks']['negotiation'] = self::check( self::OK, $message );
				}
			} elseif ( 200 === (int) $md['status'] ) {
				$out['checks']['negotiation'] = self::check( self::ERROR, __( 'HTML returned for Accept: text/markdown. A page cache or CDN that ignores "Vary: Accept" is probably interfering; agents can still use the .md URL.', 'wp-agent-support-layer' ) );
			} else {
				$out['checks']['negotiation'] = self::check( self::ERROR, sprintf( 'Accept: text/markdown -> HTTP %1$s %2$s', $md['status'], $md['error'] ) );
			}
		}

		return $out;
	}

	/**
	 * Whether a result is a redirect (never followed by the probe).
	 *
	 * @param array<string, mixed> $result Result.
	 * @return bool
	 */
	private static function is_redirect( array $result ) {
		$status = (int) ( isset( $result['status'] ) ? $result['status'] : 0 );
		return $status >= 300 && $status < 400;
	}

	/**
	 * Warning text for a redirected target.
	 *
	 * @param string               $label  Target label.
	 * @param array<string, mixed> $result Result.
	 * @return string
	 */
	private static function redirect_message( $label, array $result ) {
		$location = isset( $result['headers']['location'] ) ? (string) $result['headers']['location'] : '';
		return sprintf(
			/* translators: 1: target label, 2: HTTP status, 3: Location header. */
			__( '%1$s: HTTP %2$d redirect to %3$s. The diagnostics never follow redirects; crawlers may not either. Serve the content on the canonical host.', 'wp-agent-support-layer' ),
			$label,
			(int) $result['status'],
			'' === $location ? __( '(no Location header)', 'wp-agent-support-layer' ) : $location
		);
	}

	/**
	 * Whether a result is a Markdown response.
	 *
	 * @param array<string, mixed> $result Result.
	 * @return bool
	 */
	private static function is_markdown( array $result ) {
		return 200 === (int) ( isset( $result['status'] ) ? $result['status'] : 0 )
			&& isset( $result['headers']['content-type'] )
			&& false !== stripos( $result['headers']['content-type'], 'text/markdown' );
	}

	/**
	 * Whether a result carries this plugin's canonical Link header (absent when converted at the edge).
	 *
	 * @param array<string, mixed> $result Result.
	 * @return bool
	 */
	private static function has_canonical_link( array $result ) {
		return isset( $result['headers']['link'] ) && false !== stripos( $result['headers']['link'], 'rel="canonical"' );
	}

	/**
	 * Builds a check entry.
	 *
	 * @param string $status  ok, warning or error.
	 * @param string $message Message.
	 * @return array{status:string, message:string}
	 */
	private static function check( $status, $message ) {
		return array(
			'status'  => $status,
			'message' => $message,
		);
	}
}
