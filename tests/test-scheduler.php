<?php
/**
 * Scheduler tests.
 *
 * @package WPASL
 */

use WPASL\Generation\Scheduler;
use WPASL\Plugin;
use WPASL\Settings;

/**
 * Covers WPASL\Generation\Scheduler.
 */
class Test_Scheduler extends WP_UnitTestCase {

	/**
	 * @var Scheduler
	 */
	private $scheduler;

	public function set_up() {
		parent::set_up();
		$this->scheduler = Plugin::instance()->get( 'scheduler' );
		$this->scheduler->unschedule();
		add_filter( 'pre_http_request', array( $this, 'block_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'block_http' ), 10 );
		$this->scheduler->unschedule();
		delete_option( Settings::OPTION );
		Plugin::instance()->get( 'settings' )->flush_cache();
		parent::tear_down();
	}

	public function block_http( $pre, $args, $url ) {
		return new WP_Error( 'blocked', 'No HTTP in tests: ' . $url );
	}

	public function test_schedule_uses_configured_interval() {
		update_option( Settings::OPTION, array( 'schedule' => 'twicedaily' ) );
		Plugin::instance()->get( 'settings' )->flush_cache();
		$this->scheduler->schedule();
		$this->assertSame( 'twicedaily', $this->scheduler->current_interval() );
	}

	public function test_changing_the_interval_reschedules_without_duplicates() {
		update_option( Settings::OPTION, Settings::defaults() );
		Plugin::instance()->get( 'settings' )->flush_cache();
		$this->scheduler->schedule();
		$this->assertSame( 'daily', $this->scheduler->current_interval() );

		$settings             = Settings::defaults();
		$settings['schedule'] = 'hourly';
		update_option( Settings::OPTION, $settings );

		$this->assertSame( 'hourly', $this->scheduler->current_interval() );
		$this->assertSame( 1, $this->count_events() );
	}

	public function test_run_soon_schedules_single_event_and_keeps_recurring_timestamp() {
		$this->scheduler->schedule( 'daily' );
		$recurring = wp_next_scheduled( Scheduler::HOOK );
		$this->assertGreaterThan( time(), $recurring, 'The recurring event is in the future.' );

		$this->scheduler->run_soon();

		$this->assertSame( $recurring, wp_next_scheduled( Scheduler::HOOK ), 'The recurring event did not move.' );
		$manual = wp_next_scheduled( Scheduler::HOOK, Scheduler::MANUAL_ARGS );
		$this->assertNotFalse( $manual );
		$this->assertLessThanOrEqual( time(), $manual );
		$this->assertSame( $manual, $this->scheduler->next_run(), 'next_run() reports the earliest of both.' );
		$this->assertSame( 'daily', $this->scheduler->current_interval(), 'Still recurring.' );
		$this->assertSame( 2, $this->count_events() );
	}

	public function test_run_soon_does_not_duplicate_a_due_event() {
		$this->scheduler->run_soon();
		$this->scheduler->run_soon();
		$this->assertSame( 1, $this->count_events() );
	}

	public function test_deactivation_clears_manual_events() {
		$this->scheduler->schedule( 'daily' );
		$this->scheduler->run_soon();
		$this->assertSame( 2, $this->count_events() );
		$this->scheduler->unschedule();
		$this->assertSame( 0, $this->count_events() );
		$this->assertNull( $this->scheduler->next_run() );
	}

	private function count_events() {
		$count = 0;
		foreach ( _get_cron_array() as $hooks ) {
			if ( isset( $hooks[ Scheduler::HOOK ] ) ) {
				$count += count( $hooks[ Scheduler::HOOK ] );
			}
		}
		return $count;
	}
}
