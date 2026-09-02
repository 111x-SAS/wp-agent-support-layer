<?php
/**
 * Generation status / "Regenerate now" tests.
 *
 * @package WPASL
 */

use WPASL\Admin\GenerationStatus;
use WPASL\Generation\Scheduler;
use WPASL\Plugin;

/**
 * Covers WPASL\Admin\GenerationStatus.
 */
class Test_Generation_Status extends WP_UnitTestCase {

	/**
	 * @var GenerationStatus
	 */
	private $status;

	public function set_up() {
		parent::set_up();
		$this->status = Plugin::instance()->get( 'status' );
		Plugin::instance()->get( 'scheduler' )->unschedule();
		add_filter( 'pre_http_request', array( $this, 'block_http' ), 10, 3 );
		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'block_http' ), 10 );
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ) );
		unset( $_POST[ GenerationStatus::NONCE ], $_POST['full'], $_REQUEST[ GenerationStatus::NONCE ] );
		Plugin::instance()->get( 'scheduler' )->unschedule();
		Plugin::instance()->get( 'runner' )->clear();
		parent::tear_down();
	}

	public function block_http( $pre, $args, $url ) {
		return new WP_Error( 'blocked', 'No HTTP in tests: ' . $url );
	}

	public function capture_redirect( $location ) {
		throw new Exception( 'redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	public function test_handler_requires_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( 'WPDieException' );
		$this->status->handle();
	}

	public function test_handler_requires_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->expectException( 'WPDieException' );
		$this->status->handle();
	}

	public function test_handler_schedules_and_redirects_without_generating() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->post->create();
		$_REQUEST[ GenerationStatus::NONCE ] = wp_create_nonce( GenerationStatus::ACTION );

		try {
			$this->status->handle();
			$this->fail( 'Expected a redirect.' );
		} catch ( Exception $e ) {
			$this->assertStringContainsString( 'redirect:', $e->getMessage() );
			$this->assertStringContainsString( 'wpasl_notice=scheduled', $e->getMessage() );
		}

		$next = wp_next_scheduled( Scheduler::HOOK );
		$this->assertNotFalse( $next );
		$this->assertLessThanOrEqual( time(), $next );
		$this->assertSame( 1, Plugin::instance()->get( 'runner' )->status()['pending'], 'Nothing was generated inline.' );
	}

	public function test_status_uses_count_query() {
		global $wpdb;
		$ids    = self::factory()->post->create_many( 30 );
		$runner = Plugin::instance()->get( 'runner' );
		$runner->generate_item( $ids[0] );
		$runner->generate_item( $ids[1] );
		wp_cache_flush();

		$before  = $wpdb->num_queries;
		$status  = $runner->status();
		$queries = $wpdb->num_queries - $before;

		$this->assertSame( 30, $status['eligible'] );
		$this->assertSame( 2, $status['generated'] );
		$this->assertSame( 28, $status['pending'] );
		$this->assertLessThan( 10, $queries, "status() ran {$queries} queries for 30 items." );
	}

	public function test_render_shows_status_and_cron_warning() {
		add_filter( 'wpasl_cron_disabled', '__return_true' );
		ob_start();
		$this->status->render();
		$html = ob_get_clean();
		remove_filter( 'wpasl_cron_disabled', '__return_true' );

		$this->assertStringContainsString( 'DISABLE_WP_CRON', $html );
		$this->assertStringContainsString( 'Regenerate now', $html );
		$this->assertStringContainsString( 'Regenerate everything', $html );
		$this->assertStringContainsString( 'Not scheduled', $html );
		$this->assertStringContainsString( 'name="' . GenerationStatus::NONCE . '"', $html );
	}

	public function test_render_without_warning_when_cron_enabled() {
		add_filter( 'wpasl_cron_disabled', '__return_false' );
		ob_start();
		$this->status->render();
		$html = ob_get_clean();
		remove_filter( 'wpasl_cron_disabled', '__return_false' );
		$this->assertStringNotContainsString( 'DISABLE_WP_CRON', $html );
	}
}
