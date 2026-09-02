<?php
/**
 * Generation state persisted between runs.
 *
 * @package WPASL
 */

namespace WPASL\Generation;

/**
 * Wraps the wpasl_state option: work queue, per-item generation times and run statistics.
 */
final class State {

	const OPTION = 'wpasl_state';

	/**
	 * Default state.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'queue'                => array(),
			'generated'            => array(),
			'cycle_started'        => 0,
			'last_run'             => 0,
			'last_run_count'       => 0,
			'last_cycle_completed' => 0,
			'artifacts_generated'  => 0,
		);
	}

	/**
	 * Loads the state.
	 *
	 * @return array<string, mixed>
	 */
	public function load() {
		$stored = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Saves the state.
	 *
	 * @param array<string, mixed> $state State.
	 * @return void
	 */
	public function save( array $state ) {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Resets the state completely.
	 *
	 * @return void
	 */
	public function reset() {
		delete_option( self::OPTION );
	}

	/**
	 * Records that an item was generated now.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function mark_generated( $post_id ) {
		$state                                = $this->load();
		$state['generated'][ (int) $post_id ] = time();
		$this->save( $state );
	}

	/**
	 * Forgets an item.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function forget( $post_id ) {
		$state = $this->load();
		if ( isset( $state['generated'][ (int) $post_id ] ) ) {
			unset( $state['generated'][ (int) $post_id ] );
			$state['queue'] = array_values( array_diff( $state['queue'], array( (int) $post_id ) ) );
			$this->save( $state );
		}
	}
}
