<?php
/**
 * Generation state tests.
 *
 * @package WPASL
 */

use WPASL\Generation\Runner;
use WPASL\Generation\State;
use WPASL\Plugin;

require_once __DIR__ . '/includes/class-wpasl-test-item-generator.php';

/**
 * Covers WPASL\Generation\State, in particular concurrent writes.
 */
class Test_State extends WP_UnitTestCase {

	/**
	 * @var State
	 */
	private $state;

	public function set_up() {
		parent::set_up();
		$this->state = new State();
		$this->state->reset();
	}

	public function tear_down() {
		Plugin::instance()->get( 'runner' )->set_item_generator( null );
		Plugin::instance()->get( 'runner' )->clear();
		parent::tear_down();
	}

	public function test_lazy_fill_mark_survives_runner_save() {
		$in_memory                  = $this->state->load(); // A cron run loads the state...
		$in_memory['generated'][11] = 100;
		$in_memory['queue']         = array( 12, 13 );
		$in_memory['last_run']      = 100;

		$this->state->mark_generated( 12 ); // ...a front-end request lazily fills another item meanwhile...

		$this->state->save( $in_memory ); // ...and the cron run saves what it had.

		$saved = $this->state->load();
		$this->assertSame( 100, $saved['generated'][11] );
		$this->assertArrayHasKey( 12, $saved['generated'], 'The lazy-fill mark is not lost.' );
		$this->assertSame( array( 12, 13 ), $saved['queue'], 'The queue in memory is authoritative.' );
		$this->assertSame( 100, $saved['last_run'] );
	}

	public function test_mark_generated_does_not_resurrect_a_concurrently_forgotten_item() {
		$this->state->save( array( 'generated' => array( 5 => 100 ) ) );
		$this->state->forget( 5 ); // A concurrent request invalidates a different, unrelated post...

		// ...but this process's own cache still thinks it exists (e.g. cached by an earlier load() in
		// this same request, before the concurrent forget()); load() alone does not bust the cache.
		wp_cache_set( State::OPTION, array( 'generated' => array( 5 => 100 ) ), 'options' );

		$this->state->mark_generated( 12 ); // ...while this lazy fill marks its own, different post.

		$this->assertArrayHasKey( 12, $this->state->load()['generated'] );
		$this->assertArrayNotHasKey( 5, $this->state->load()['generated'], 'mark_generated() must not resurrect a concurrently forgotten, unrelated item.' );
	}

	public function test_forget_does_not_resurrect_a_different_concurrently_forgotten_item() {
		$this->state->save(
			array(
				'generated' => array(
					5 => 100,
					6 => 200,
				),
			)
		);
		$this->state->forget( 5 ); // A concurrent request forgets a different post...
		wp_cache_set(
			State::OPTION,
			array(
				'generated' => array(
					5 => 100,
					6 => 200,
				),
			),
			'options'
		);

		$this->state->forget( 6 ); // ...while this call forgets its own, unrelated post.

		$saved = $this->state->load();
		$this->assertArrayNotHasKey( 5, $saved['generated'], 'forget() must not resurrect a different, concurrently forgotten item.' );
		$this->assertArrayNotHasKey( 6, $saved['generated'] );
	}

	public function test_record_render_failure_does_not_resurrect_concurrent_changes_to_other_items() {
		$this->state->save(
			array(
				'generated'     => array( 5 => 100 ),
				'render_failed' => array( 6 => 1 ),
			)
		);
		$this->state->forget( 5 ); // A concurrent request forgets an unrelated generated post...
		$this->state->clear_render_failure( 6 ); // ...and clears an unrelated render failure...
		wp_cache_set(
			State::OPTION,
			array(
				'generated'     => array( 5 => 100 ),
				'render_failed' => array( 6 => 1 ),
			),
			'options'
		);

		$this->state->record_render_failure( 9, 'timeout' ); // ...while this call records its own.

		$saved = $this->state->load();
		$this->assertArrayNotHasKey( 5, $saved['generated'], 'An unrelated concurrently forgotten item is not resurrected.' );
		$this->assertArrayNotHasKey( 6, $saved['render_failed'], 'An unrelated concurrently cleared failure is not resurrected.' );
		$this->assertSame( 1, $saved['render_failed'][9] );
	}

	public function test_clear_render_failure_does_not_resurrect_concurrent_changes_to_other_items() {
		$this->state->save(
			array(
				'generated'     => array( 5 => 100 ),
				'render_failed' => array(
					6 => 1,
					9 => 1,
				),
			)
		);
		$this->state->forget( 5 ); // A concurrent request forgets an unrelated generated post...
		wp_cache_set(
			State::OPTION,
			array(
				'generated'     => array( 5 => 100 ),
				'render_failed' => array(
					6 => 1,
					9 => 1,
				),
			),
			'options'
		);

		$this->state->clear_render_failure( 9 ); // ...while this call clears its own.

		$saved = $this->state->load();
		$this->assertArrayNotHasKey( 5, $saved['generated'], 'An unrelated concurrently forgotten item is not resurrected.' );
		$this->assertArrayNotHasKey( 9, $saved['render_failed'] );
		$this->assertSame( 1, $saved['render_failed'][6], 'An untouched, still-current failure is reconstructed from the fresh reload.' );
	}

	public function test_newest_timestamp_wins_on_merge() {
		$this->state->save( array( 'generated' => array( 5 => 200 ) ) );
		$this->state->save( array( 'generated' => array( 5 => 150 ) ) );
		$this->assertSame( 200, $this->state->load()['generated'][5] );
		$this->state->save( array( 'generated' => array( 5 => 300 ) ) );
		$this->assertSame( 300, $this->state->load()['generated'][5] );
	}

	public function test_prune_removal_is_not_resurrected_by_merge() {
		$runner = Plugin::instance()->get( 'runner' );
		$runner->set_item_generator( new WPASL_Test_Item_Generator() );
		$keep = self::factory()->post->create();
		$gone = self::factory()->post->create();
		$runner->run_cycle();
		$this->assertArrayHasKey( $gone, $this->state->load()['generated'] );

		add_filter(
			'wpasl_is_eligible',
			static function ( $eligible, $post ) use ( $gone ) {
				return $post->ID === $gone ? false : $eligible;
			},
			10,
			2
		);
		$runner->prune();
		remove_all_filters( 'wpasl_is_eligible' );

		$saved = $this->state->load();
		$this->assertArrayHasKey( $keep, $saved['generated'] );
		$this->assertArrayNotHasKey( $gone, $saved['generated'], 'Pruned entries are deleted even though save() merges.' );
		$this->assertFalse( Plugin::instance()->get( 'storage' )->exists( Runner::document_path( 'post', $gone ) ) );

		$this->state->forget( $keep );
		$this->assertArrayNotHasKey( $keep, $this->state->load()['generated'] );
	}

	public function test_forget_removes_from_queue_and_generated() {
		$this->state->save(
			array(
				'queue'     => array( 1, 2, 3 ),
				'generated' => array( 2 => 10 ),
			)
		);
		$this->state->forget( 2 );
		$saved = $this->state->load();
		$this->assertSame( array( 1, 3 ), $saved['queue'] );
		$this->assertSame( array(), $saved['generated'] );
	}

	public function test_a_caller_passing_only_its_own_touched_id_does_not_resurrect_a_concurrent_removal() {
		$this->state->save(
			array(
				'generated' => array(
					1 => 10,
					2 => 20,
				),
			)
		);
		$this->state->forget( 1 ); // A concurrent process invalidates one of them.

		// A well-behaved caller only passes the id it actually touched (see save()'s docblock), not a
		// stale carried-over copy of 'generated' from its own earlier load().
		$this->state->save( array( 'generated' => array( 3 => 30 ) ) );

		$saved = $this->state->load();
		$this->assertArrayNotHasKey( 1, $saved['generated'], 'The concurrently forgotten id is not resurrected.' );
		$this->assertSame( 20, $saved['generated'][2], 'An untouched, still-current id is reconstructed from the fresh reload.' );
		$this->assertSame( 30, $saved['generated'][3] );
	}

	public function test_save_merge_bypasses_a_stale_local_option_cache() {
		$this->state->save( array( 'generated' => array( 1 => 10 ) ) );
		$this->state->forget( 1 );

		// Poison the local object cache with a snapshot from before the forget(), simulating a process
		// that cached the option early (e.g. via load()) and never learned of a concurrent removal;
		// load() alone does not bust the cache, only save() does.
		wp_cache_set( State::OPTION, array( 'generated' => array( 1 => 10 ) ), 'options' );
		$this->assertSame( 10, $this->state->load()['generated'][1], 'Confirms the cache is indeed poisoned.' );

		$this->state->save( array( 'generated' => array( 2 => 20 ) ) );

		$this->assertArrayNotHasKey( 1, $this->state->load()['generated'], 'save() must bust the cache itself, not trust a stale local read.' );
	}

	public function test_removed_key_is_never_persisted() {
		$this->state->save(
			array(
				'generated' => array( 1 => 10 ),
				'_removed'  => array( 99 ),
			)
		);
		$this->assertArrayNotHasKey( '_removed', get_option( State::OPTION ) );
		$this->assertArrayNotHasKey( '_removed', $this->state->load() );
	}

	public function test_reset_cycle_replaces_instead_of_merging() {
		$runner = Plugin::instance()->get( 'runner' );
		$runner->set_item_generator( new WPASL_Test_Item_Generator() );
		self::factory()->post->create_many( 2 );
		$runner->run_cycle();
		$this->assertCount( 2, $this->state->load()['generated'] );

		$runner->reset_cycle();
		$this->assertSame( array(), $this->state->load()['generated'] );
		$this->assertSame( 2, $runner->status()['pending'] );
	}

	public function test_render_failures_are_recorded_cleared_and_merged() {
		$defaults = State::defaults();
		$this->assertSame( array(), $defaults['render_failed'] );
		$this->assertNull( $defaults['last_render_error'] );

		$this->assertSame( 1, $this->state->record_render_failure( 7, 'http_503' ) );
		$this->assertSame( 2, $this->state->record_render_failure( 7, 'timeout' ) );
		$this->assertSame( 1, $this->state->record_render_failure( 8, 'no_content' ) );
		$saved = $this->state->load();
		ksort( $saved['render_failed'] ); // Key order is not meaningful; only the counts are.
		$this->assertSame(
			array(
				7 => 2,
				8 => 1,
			),
			$saved['render_failed']
		);
		$this->assertSame( 8, $saved['last_render_error']['post_id'] );
		$this->assertSame( 'no_content', $saved['last_render_error']['reason'] );
		$this->assertEqualsWithDelta( time(), $saved['last_render_error']['time'], 5 );

		// A run loaded the state before a lazy fill recorded a failure and another cleared one: on save the
		// highest counter and the newest error win, and cleared items stay cleared.
		$in_memory = $this->state->load();
		$this->state->record_render_failure( 9, 'http_403' );
		$this->state->record_render_failure( 7, 'http_403' );
		$in_memory['render_failed'][8] = 1;
		$in_memory['_render_cleared']  = array( 8 );
		unset( $in_memory['render_failed'][8] );
		$in_memory['last_render_error'] = array(
			'post_id' => 7,
			'reason'  => 'older',
			'time'    => time() - 100,
		);
		$this->state->save( $in_memory );
		$saved = $this->state->load();
		$this->assertSame( 3, $saved['render_failed'][7], 'The concurrent increment is kept.' );
		$this->assertSame( 1, $saved['render_failed'][9], 'The concurrent new failure is kept.' );
		$this->assertArrayNotHasKey( 8, $saved['render_failed'], 'Cleared meanwhile: not resurrected.' );
		$this->assertSame( 'http_403', $saved['last_render_error']['reason'], 'The most recent error wins.' );
		$this->assertArrayNotHasKey( '_render_cleared', get_option( State::OPTION ) );

		$writes = 0;
		$count  = static function ( $value ) use ( &$writes ) {
			++$writes;
			return $value;
		};
		add_filter( 'pre_update_option_' . State::OPTION, $count );
		$this->state->clear_render_failure( 123 );
		$this->assertSame( 0, $writes, 'Clearing an item without failures writes nothing.' );
		$this->state->clear_render_failure( 7 );
		$this->assertSame( 1, $writes );
		remove_filter( 'pre_update_option_' . State::OPTION, $count );
		$saved = $this->state->load();
		$this->assertSame( array( 9 => 1 ), $saved['render_failed'] );
		$this->assertSame( 'http_403', $saved['last_render_error']['reason'], 'The last error is informational and stays.' );

		// _removed drops the counters as well.
		$this->state->forget( 9 );
		$this->assertSame( array(), $this->state->load()['render_failed'] );

		// A replace (merge = false) stores exactly what is given.
		$this->state->save( array( 'render_failed' => array( 5 => 4 ) ), false );
		$this->assertSame( array( 5 => 4 ), $this->state->load()['render_failed'] );
		$this->assertNull( $this->state->load()['last_render_error'] );
	}

	public function test_clear_queue_keeps_generated() {
		$this->state->save(
			array(
				'queue'         => array( 1, 2 ),
				'generated'     => array( 3 => 10 ),
				'cycle_started' => 50,
			)
		);
		$this->state->clear_queue();
		$saved = $this->state->load();
		$this->assertSame( array(), $saved['queue'] );
		$this->assertSame( 0, $saved['cycle_started'] );
		$this->assertSame( array( 3 => 10 ), $saved['generated'] );
	}
}
