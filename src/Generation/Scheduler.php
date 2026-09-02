<?php
/**
 * WP-Cron scheduling for the generation runner.
 *
 * @package WPASL
 */

namespace WPASL\Generation;

use WPASL\Settings;

/**
 * Owns the single recurring generation event and the one-off "run now" event.
 */
final class Scheduler {

	const HOOK = 'wpasl_generate';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings_updated' ), 10, 2 );
	}

	/**
	 * Reschedules when the interval changes.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $value     New option value.
	 * @return void
	 */
	public function on_settings_updated( $old_value, $value ) {
		$old = is_array( $old_value ) && isset( $old_value['schedule'] ) ? $old_value['schedule'] : 'daily';
		$new = is_array( $value ) && isset( $value['schedule'] ) ? $value['schedule'] : 'daily';
		if ( $old !== $new ) {
			$this->settings->flush_cache();
			$this->schedule( $new );
		}
	}

	/**
	 * Ensures exactly one recurring event exists with the given interval.
	 *
	 * @param string|null $interval Interval name; defaults to the configured one.
	 * @return void
	 */
	public function schedule( $interval = null ) {
		$interval = null === $interval ? (string) $this->settings->get( 'schedule' ) : $interval;
		if ( ! in_array( $interval, Settings::SCHEDULES, true ) ) {
			$interval = 'daily';
		}

		$current = $this->current_interval();
		if ( $current === $interval ) {
			return;
		}

		$this->unschedule();
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, self::HOOK );
	}

	/**
	 * Removes every scheduled generation event.
	 *
	 * @return void
	 */
	public function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Interval of the current recurring event, or null when none is scheduled.
	 *
	 * @return string|null
	 */
	public function current_interval() {
		$event = wp_get_scheduled_event( self::HOOK );
		return $event && ! empty( $event->schedule ) ? $event->schedule : null;
	}

	/**
	 * Timestamp of the next run, recurring or one-off.
	 *
	 * @return int|null
	 */
	public function next_run() {
		$next = wp_next_scheduled( self::HOOK );
		return false === $next ? null : (int) $next;
	}

	/**
	 * Makes the recurring event due immediately and asks WordPress to spawn cron.
	 *
	 * WordPress refuses single events within ten minutes of an existing one for the same hook,
	 * so the recurring event itself is moved to "now" and keeps recurring from there.
	 *
	 * @return void
	 */
	public function run_soon() {
		$event = wp_get_scheduled_event( self::HOOK );
		if ( $event && $event->timestamp <= time() ) {
			spawn_cron();
			return;
		}

		$interval = $this->current_interval();
		if ( null === $interval ) {
			$interval = (string) $this->settings->get( 'schedule' );
		}
		$this->unschedule();
		wp_schedule_event( time() - 1, $interval, self::HOOK );
		spawn_cron();
	}
}
