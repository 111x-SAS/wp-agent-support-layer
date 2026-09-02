<?php
/**
 * Diagnostics entry point.
 *
 * @package WPASL
 */

namespace WPASL\Diagnostics;

use WPASL\Admin\Page;
use WPASL\Admin\Tabs\DiagnosticsTab;

/**
 * Runs the probe on request (capability + nonce), stores the report and registers the tab.
 */
final class DiagnosticsController {

	const ACTION = 'wpasl_run_diagnostics';
	const NONCE  = 'wpasl_run_diagnostics_nonce';

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
	 * Constructor.
	 *
	 * @param CrawlerProbe $probe  Probe.
	 * @param Report       $report Report builder.
	 * @param Page         $page   Page.
	 */
	public function __construct( CrawlerProbe $probe, Report $report, Page $page ) {
		$this->probe  = $probe;
		$this->report = $report;
		$this->page   = $page;
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
		$page->add_tab( new DiagnosticsTab( $this->probe, $this->page ) );
	}

	/**
	 * Runs the probe and stores the report.
	 *
	 * @return array<string, mixed> The report.
	 */
	public function run() {
		$report = $this->report->build( $this->probe->run() );
		Report::save( $report );
		return $report;
	}

	/**
	 * Handles the admin-post request.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! current_user_can( Page::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-agent-support-layer' ), 403 );
		}
		check_admin_referer( self::ACTION, self::NONCE );

		$this->run();

		wp_safe_redirect( add_query_arg( 'wpasl_notice', 'diagnostics', $this->page->url( 'diagnostics' ) ) );
		exit;
	}
}
