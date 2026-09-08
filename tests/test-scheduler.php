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

	public function test_changing_content_source_or_selector_clears_queue_and_keeps_generated() {
		$state = new WPASL\Generation\State();
		$queue = array(
			'queue'         => array( 1, 2 ),
			'generated'     => array( 3 => 10 ),
			'cycle_started' => 50,
		);
		update_option( Settings::OPTION, Settings::defaults() );
		Plugin::instance()->get( 'settings' )->flush_cache();

		$state->save( $queue, false );
		update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'content_source' => array( 'page' => 'rendered' ) ) ) );
		$saved = $state->load();
		$this->assertSame( array(), $saved['queue'], 'A forced content source discards the queue.' );
		$this->assertSame( 0, $saved['cycle_started'] );
		$this->assertSame( array( 3 => 10 ), $saved['generated'], 'Generation marks are kept.' );

		$state->save( $queue, false );
		update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'content_source' => array( 'page' => 'rendered' ), 'content_selector' => 'main article' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$saved = $state->load();
		$this->assertSame( array(), $saved['queue'], 'A new selector discards the queue.' );
		$this->assertSame( array( 3 => 10 ), $saved['generated'] );

		$state->save( $queue, false );
		update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'content_selector' => 'main article' ) ) );
		$this->assertSame( array(), $state->load()['queue'], 'Back to auto: discarded as well.' );
	}

	public function test_saving_general_without_changes_keeps_queue() {
		$state  = new WPASL\Generation\State();
		$stored = array_merge( Settings::defaults(), array( 'content_source' => array( 'page' => 'rendered' ), 'content_selector' => 'main article' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		update_option( Settings::OPTION, $stored );
		Plugin::instance()->get( 'settings' )->flush_cache();
		$state->save(
			array(
				'queue'     => array( 1, 2 ),
				'generated' => array( 3 => 10 ),
			),
			false
		);

		// Same values, different representation: "auto" is the absence of a value, the order is irrelevant
		// and another General setting changes.
		$changed                   = $stored;
		$changed['batch_size']     = 25;
		$changed['content_source'] = array(
			'post' => 'auto',
			'page' => 'rendered',
		);
		update_option( Settings::OPTION, $changed );
		$this->assertSame( array( 1, 2 ), $state->load()['queue'] );
		$this->assertSame( array( 3 => 10 ), $state->load()['generated'] );
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

	public function test_maybe_upgrade_reschedules_missing_event() {
		$this->scheduler->schedule();
		wp_clear_scheduled_hook( Scheduler::HOOK );
		$this->assertNull( $this->scheduler->current_interval() );
		update_option( Plugin::VERSION_OPTION, WPASL_VERSION );

		Plugin::instance()->maybe_upgrade();

		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK ) );
		$this->assertSame( 'daily', $this->scheduler->current_interval() );
	}
}
