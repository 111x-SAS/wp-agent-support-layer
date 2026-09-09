<?php
/**
 * Generation state persisted between runs.
 *
 * @package WPASL
 */

namespace WPASL\Generation;

/**
 * Wraps the wpasl_state option: work queue, per-item generation times, rendered-page failures and run
 * statistics.
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
			'render_failed'              => array(),
			'last_render_error'          => null,
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
	 * which forget()/prune() use to delete entries. Rendered-page failures are merged the same way: the
	 * highest counter wins and the most recent last error wins, except items listed in the transient
	 * "_render_cleared" key (a loopback that succeeded meanwhile, or a pruned item). The queue in memory is
	 * authoritative.
	 *
	 * @param array<string, mixed> $state State.
	 * @param bool                 $merge Whether to merge with the stored state (false replaces it).
	 * @return void
	 */
	public function save( array $state, $merge = true ) {
		$removed = isset( $state['_removed'] ) ? array_map( 'intval', (array) $state['_removed'] ) : array();
		$cleared = isset( $state['_render_cleared'] ) ? array_map( 'intval', (array) $state['_render_cleared'] ) : array();
		unset( $state['_removed'], $state['_render_cleared'] );
		$state = wp_parse_args( $state, self::defaults() );

		if ( $merge ) {
			wp_cache_delete( self::OPTION, 'options' );
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();
			foreach ( (array) ( isset( $stored['generated'] ) ? $stored['generated'] : array() ) as $post_id => $time ) {
				$post_id = (int) $post_id;
				if ( in_array( $post_id, $removed, true ) ) {
					continue;
				}
				if ( ! isset( $state['generated'][ $post_id ] ) || (int) $state['generated'][ $post_id ] < (int) $time ) {
					$state['generated'][ $post_id ] = (int) $time;
				}
			}
			foreach ( (array) ( isset( $stored['render_failed'] ) ? $stored['render_failed'] : array() ) as $post_id => $count ) {
				$post_id = (int) $post_id;
				if ( in_array( $post_id, $removed, true ) || in_array( $post_id, $cleared, true ) ) {
					continue;
				}
				if ( ! isset( $state['render_failed'][ $post_id ] ) || (int) $state['render_failed'][ $post_id ] < (int) $count ) {
					$state['render_failed'][ $post_id ] = (int) $count;
				}
			}
			$stored_error = isset( $stored['last_render_error'] ) && is_array( $stored['last_render_error'] ) ? $stored['last_render_error'] : null;
			if ( null !== $stored_error && ( ! is_array( $state['last_render_error'] ) || (int) $state['last_render_error']['time'] < (int) $stored_error['time'] ) ) {
				$state['last_render_error'] = $stored_error;
			}
		}//end if
		$drop = array_fill_keys( array_merge( $removed, $cleared ), true );
		if ( ! empty( $removed ) ) {
			$state['generated'] = array_diff_key( $state['generated'], array_fill_keys( $removed, true ) );
			$state['failed']    = array_diff_key( (array) $state['failed'], array_fill_keys( $removed, true ) );
			$state['queue']     = array_values( array_diff( array_map( 'intval', $state['queue'] ), $removed ) );
		}
		if ( ! empty( $drop ) ) {
			$state['render_failed'] = array_diff_key( (array) $state['render_failed'], $drop );
		}

		update_option( self::OPTION, $state, false );
	}

	/**
	 * Records a rendered-page failure of an item (outside a generation run).
	 *
	 * @param int    $post_id Post id.
	 * @param string $reason  Reason.
	 * @return int Consecutive failures so far.
	 */
	public function record_render_failure( $post_id, $reason ) {
		$post_id = (int) $post_id;
		$state   = $this->load();
		$count   = ( isset( $state['render_failed'][ $post_id ] ) ? (int) $state['render_failed'][ $post_id ] : 0 ) + 1;

		$state['render_failed'][ $post_id ] = $count;
		$state['last_render_error']         = self::render_error( $post_id, $reason );
		$this->save( $state );
		return $count;
	}

	/**
	 * Forgets the rendered-page failures of an item (its loopback succeeded). Writes only when there was one.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function clear_render_failure( $post_id ) {
		$post_id = (int) $post_id;
		$state   = $this->load();
		if ( ! isset( $state['render_failed'][ $post_id ] ) ) {
			return;
		}
		unset( $state['render_failed'][ $post_id ] );
		$state['_render_cleared'] = array( $post_id );
		$this->save( $state );
	}

	/**
	 * Shape of the last rendered-page failure.
	 *
	 * @param int    $post_id Post id.
	 * @param string $reason  Reason.
	 * @return array{post_id:int, reason:string, time:int}
	 */
	public static function render_error( $post_id, $reason ) {
		return array(
			'post_id' => (int) $post_id,
			'reason'  => (string) $reason,
			'time'    => time(),
		);
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
