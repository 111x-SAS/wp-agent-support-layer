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
	 * Arguments of the one-off "Regenerate now" event (distinct from the recurring event's empty args).
	 *
	 * @var string[]
	 */
	const MANUAL_ARGS = array( 'manual' );

	/**
	 * One-off event that builds llms-full.txt in the background when a request finds it missing.
	 */
	const LLMS_FULL_HOOK = 'wpasl_build_llms_full';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * State.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Constructor.
	 *
	 * @param Settings   $settings Settings.
	 * @param State|null $state    State; a fresh instance when omitted.
	 */
	public function __construct( Settings $settings, ?State $state = null ) {
		$this->settings = $settings;
		$this->state    = null === $state ? new State() : $state;
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
	 * Reschedules when the interval changes and discards the queue when the post types, the content source
	 * of a post type or the content selector change (the stored documents and their marks are kept).
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
		if ( self::post_types_of( $old_value ) !== self::post_types_of( $value )
			|| self::content_sources_of( $old_value ) !== self::content_sources_of( $value )
			|| self::content_selector_of( $old_value ) !== self::content_selector_of( $value ) ) {
			$this->state->clear_queue();
		}
	}

	/**
	 * Normalised forced content sources of a raw settings value (only editor/rendered, sorted by type).
	 *
	 * @param mixed $value Raw option value.
	 * @return array<string, string>
	 */
	private static function content_sources_of( $value ) {
		$sources = is_array( $value ) && isset( $value['content_source'] ) ? (array) $value['content_source'] : array();
		$clean   = array();
		foreach ( $sources as $type => $source ) {
			if ( in_array( $source, Settings::CONTENT_SOURCES, true ) ) {
				$clean[ (string) $type ] = (string) $source;
			}
		}
		ksort( $clean );
		return $clean;
	}

	/**
	 * Content selector of a raw settings value.
	 *
	 * @param mixed $value Raw option value.
	 * @return string
	 */
	private static function content_selector_of( $value ) {
		return is_array( $value ) && isset( $value['content_selector'] ) ? trim( (string) $value['content_selector'] ) : '';
	}

	/**
	 * Normalised post types of a raw settings value (defaults when absent).
	 *
	 * @param mixed $value Raw option value.
	 * @return string[]
	 */
	private static function post_types_of( $value ) {
		$types = is_array( $value ) && isset( $value['post_types'] ) ? (array) $value['post_types'] : Settings::defaults()['post_types'];
		$types = array_values( array_unique( array_map( 'strval', $types ) ) );
		sort( $types );
		return $types;
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
		wp_clear_scheduled_hook( self::HOOK, self::MANUAL_ARGS );
		wp_clear_scheduled_hook( self::LLMS_FULL_HOOK );
	}

	/**
	 * Schedules the background build of llms-full.txt once (no duplicates while one is pending).
	 *
	 * @return void
	 */
	public function schedule_llms_full() {
		if ( false === wp_next_scheduled( self::LLMS_FULL_HOOK ) ) {
			wp_schedule_single_event( time(), self::LLMS_FULL_HOOK );
		}
		spawn_cron();
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
		$candidates = array_filter( array( wp_next_scheduled( self::HOOK ), wp_next_scheduled( self::HOOK, self::MANUAL_ARGS ) ) );
		return empty( $candidates ) ? null : (int) min( $candidates );
	}

	/**
	 * Schedules a one-off run now and asks WordPress to spawn cron. The recurring event is left untouched, so
	 * its schedule anchor never drifts. Distinct args keep WordPress from deduplicating it against the
	 * recurring event within its ten-minute window.
	 *
	 * @return void
	 */
	public function run_soon() {
		if ( false === wp_next_scheduled( self::HOOK, self::MANUAL_ARGS ) ) {
			wp_schedule_single_event( time(), self::HOOK, self::MANUAL_ARGS );
		}
		spawn_cron();
	}
}
