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
			'queue'                      => array(),
			'generated'                  => array(),
			'failed'                     => array(),
			'cycle_started'              => 0,
			'last_run'                   => 0,
			'last_run_count'             => 0,
			'last_cycle_completed'       => 0,
			'artifacts_generated'        => 0,
			'last_artifacts_regenerated' => 0,
		);
	}

	/**
	 * Discards the queue in progress so the next run rebuilds it (e.g. after the post types change).
	 *
	 * @return void
	 */
	public function clear_queue() {
		$state                  = $this->load();
		$state['queue']         = array();
		$state['cycle_started'] = 0;
		$this->save( $state );
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
	 * Saves the state, merging it with what other requests stored meanwhile.
	 *
	 * Generation marks recorded by a concurrent request (lazy fill during a cron run, or the reverse) are
	 * kept: for every item the newest timestamp wins, except items listed in the transient "_removed" key,
	 * which forget()/prune() use to delete entries. The queue in memory is authoritative.
	 *
	 * @param array<string, mixed> $state State.
	 * @param bool                 $merge Whether to merge with the stored state (false replaces it).
	 * @return void
	 */
	public function save( array $state, $merge = true ) {
		$removed = isset( $state['_removed'] ) ? array_map( 'intval', (array) $state['_removed'] ) : array();
		unset( $state['_removed'] );
		$state = wp_parse_args( $state, self::defaults() );

		if ( $merge ) {
			wp_cache_delete( self::OPTION, 'options' );
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) && isset( $stored['generated'] ) ? (array) $stored['generated'] : array();
			foreach ( $stored as $post_id => $time ) {
				$post_id = (int) $post_id;
				if ( in_array( $post_id, $removed, true ) ) {
					continue;
				}
				if ( ! isset( $state['generated'][ $post_id ] ) || (int) $state['generated'][ $post_id ] < (int) $time ) {
					$state['generated'][ $post_id ] = (int) $time;
				}
			}
		}
		if ( ! empty( $removed ) ) {
			$state['generated'] = array_diff_key( $state['generated'], array_fill_keys( $removed, true ) );
			$state['failed']    = array_diff_key( (array) $state['failed'], array_fill_keys( $removed, true ) );
			$state['queue']     = array_values( array_diff( array_map( 'intval', $state['queue'] ), $removed ) );
		}

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
		$state             = $this->load();
		$state['_removed'] = array( (int) $post_id );
		$this->save( $state );
	}
}
