<?php
/**
 * Diagnostics entry point.
 *
 * @package WPASL
 */

namespace WPASL\Diagnostics;

use WPASL\Admin\Page;
use WPASL\Admin\Tabs\DiagnosticsTab;
use WPASL\Generation\Runner;

/**
 * Runs the probe on request (capability + nonce) in batches chained by redirects, stores the report and registers the tab.
 */
final class DiagnosticsController {

	const ACTION = 'wpasl_run_diagnostics';
	const NONCE  = 'wpasl_run_diagnostics_nonce';

	/**
	 * Transient prefix of a run in progress (suffixed with the user id).
	 */
	const RUN_TRANSIENT = 'wpasl_diagnostics_run_';

	/**
	 * Seconds a run in progress is kept.
	 */
	const RUN_TTL = HOUR_IN_SECONDS;

	/**
	 * Default seconds of probing per admin request. Well below the 100 s after which proxies such as
	 * Cloudflare abort the origin request.
	 */
	const DEFAULT_TIME_BUDGET = 30;

	/**
	 * Probe.
	 *
	 * @var CrawlerProbe
	 */
	private $probe;

	/**
	 * Report builder.
	 *
	 * @var Report
	 */
	private $report;

	/**
	 * Page.
	 *
	 * @var Page
	 */
	private $page;

	/**
	 * Page cache detection and snippets.
	 *
	 * @var PageCache
	 */
	private $page_cache;

	/**
	 * Runner (for the rendered-page failures notice of the tab).
	 *
	 * @var Runner|null
	 */
	private $runner;

	/**
	 * Constructor.
	 *
	 * @param CrawlerProbe $probe      Probe.
	 * @param Report       $report     Report builder.
	 * @param Page         $page       Page.
	 * @param PageCache    $page_cache Page cache detection and snippets.
	 * @param Runner|null  $runner     Runner.
	 */
	public function __construct( CrawlerProbe $probe, Report $report, Page $page, PageCache $page_cache, ?Runner $runner = null ) {
		$this->probe      = $probe;
		$this->report     = $report;
		$this->page       = $page;
		$this->page_cache = $page_cache;
		$this->runner     = $runner;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wpasl_register_tabs', array( $this, 'register_tab' ) );
	}

	/**
	 * Adds the Diagnostics tab.
	 *
	 * @param Page $page Settings page.
	 * @return void
	 */
	public function register_tab( Page $page ) {
		$page->add_tab( new DiagnosticsTab( $this->probe, $this->page, $this->page_cache, $this->runner ) );
	}

	/**
	 * Runs the whole probe synchronously and stores the report (WP-CLI and tests).
	 *
	 * @return array<string, mixed> The report.
	 */
	public function run() {
		$report = $this->report->build( $this->probe->run() );
		Report::save( $report );
		return $report;
	}

	/**
	 * Transient key of the current user's run in progress.
	 *
	 * @return string
	 */
	public static function run_key() {
		return self::RUN_TRANSIENT . get_current_user_id();
	}

	/**
	 * The current user's run in progress, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function pending_run() {
		$run = get_transient( self::run_key() );
		return is_array( $run ) && ! empty( $run['pending'] ) ? $run : null;
	}

	/**
	 * Seconds one admin request may spend probing before handing over to the next request.
	 *
	 * @return float
	 */
	public static function time_budget() {
		/**
		 * Filters the time budget of one diagnostics request, in seconds. Each request probes at least one
		 * crawler, so the worst case is the budget plus one crawler (three requests of CrawlerProbe::TIMEOUT).
		 *
		 * @param float $budget Seconds.
		 */
		return (float) apply_filters( 'wpasl_diagnostics_time_budget', self::DEFAULT_TIME_BUDGET );
	}

	/**
	 * Executes one batch of the run: resumes the stored run (or starts one), probes crawlers until the
	 * budget is spent and either persists the progress or publishes the report.
	 *
	 * @return bool True when the report was published; false when crawlers remain for the next request.
	 */
	public function step() {
		$started = microtime( true );
		$key     = self::run_key();
		$run     = get_transient( $key );
		if ( ! is_array( $run ) || ! isset( $run['pending'] ) ) {
			$run = $this->probe->begin();
		}

		$budget = self::time_budget();
		$post   = ! empty( $run['sample_post'] ) ? get_post( (int) $run['sample_post'] ) : null;
		// At least one crawler per request guarantees progress even with a zero budget.
		do {
			if ( empty( $run['pending'] ) ) {
				break;
			}
			$agent                     = (string) array_shift( $run['pending'] );
			$run['crawlers'][ $agent ] = $this->probe->probe_crawler( $agent, $post );
		} while ( ! empty( $run['pending'] ) && ( microtime( true ) - $started ) < $budget );

		if ( ! empty( $run['pending'] ) ) {
			set_transient( $key, $run, self::RUN_TTL );
			return false;
		}

		delete_transient( $key );
		unset( $run['pending'] );
		Report::save( $this->report->build( $run ) );
		return true;
	}

	/**
	 * URL of the next batch (admin-post with the action and a fresh nonce).
	 *
	 * @return string
	 */
	public function step_url() {
		return add_query_arg(
			array(
				'action'    => self::ACTION,
				self::NONCE => wp_create_nonce( self::ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Handles the admin-post request (the form POST and every chained GET).
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! current_user_can( Page::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-agent-support-layer' ), 403 );
		}
		check_admin_referer( self::ACTION, self::NONCE );

		if ( $this->step() ) {
			wp_safe_redirect( add_query_arg( 'wpasl_notice', 'diagnostics', $this->page->url( 'diagnostics' ) ) );
			exit;
		}
		wp_safe_redirect( $this->step_url(), 303 );
		exit;
	}
}
