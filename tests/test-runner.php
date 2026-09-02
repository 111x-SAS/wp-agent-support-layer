<?php
/**
 * Generation runner tests.
 *
 * @package WPASL
 */

use WPASL\Generation\Runner;
use WPASL\Generation\State;
use WPASL\Plugin;
use WPASL\Settings;
use WPASL\Storage;

require_once __DIR__ . '/includes/class-wpasl-test-item-generator.php';
require_once __DIR__ . '/includes/class-wpasl-test-artifact-generator.php';

/**
 * Covers WPASL\Generation\Runner.
 */
class Test_Runner extends WP_UnitTestCase {

	/**
	 * @var Runner
	 */
	private $runner;

	/**
	 * @var Storage
	 */
	private $storage;

	/**
	 * @var WPASL_Test_Item_Generator
	 */
	private $items;

	/**
	 * @var WPASL_Test_Artifact_Generator
	 */
	private $artifacts;

	public function set_up() {
		parent::set_up();
		$this->runner    = Plugin::instance()->get( 'runner' );
		$this->storage   = Plugin::instance()->get( 'storage' );
		$this->items     = new WPASL_Test_Item_Generator();
		$this->artifacts = new WPASL_Test_Artifact_Generator();
		$this->runner->set_item_generator( $this->items );
		$this->runner->add_artifact_generator( $this->artifacts );
		$this->runner->clear();
	}

	public function tear_down() {
		$this->runner->set_item_generator( null );
		$this->runner->clear();
		Storage::delete_all();
		delete_option( Settings::OPTION );
		Plugin::instance()->get( 'settings' )->flush_cache();
		parent::tear_down();
	}

	private function set_batch_size( $size ) {
		update_option( Settings::OPTION, array( 'batch_size' => $size ) );
		Plugin::instance()->get( 'settings' )->flush_cache();
	}

	public function test_120_items_with_batch_50_take_three_runs() {
		$ids = self::factory()->post->create_many( 120 );
		$this->set_batch_size( 50 );

		$first = $this->runner->run();
		$this->assertSame( 50, $first['processed'] );
		$this->assertSame( 70, $first['remaining'] );
		$this->assertFalse( $first['cycle_completed'] );
		$this->assertSame( 1, $this->artifacts->calls, 'Never-generated discovery files are produced on the first run.' );

		$second = $this->runner->run();
		$this->assertSame( 50, $second['processed'] );
		$this->assertFalse( $second['cycle_completed'] );
		$this->assertSame( 1, $this->artifacts->calls, 'Then they wait for the end of the cycle or one interval.' );

		$third = $this->runner->run();
		$this->assertSame( 20, $third['processed'] );
		$this->assertTrue( $third['cycle_completed'] );
		$this->assertSame( 2, $this->artifacts->calls );
		$this->assertSame( 120, $this->items->calls );

		foreach ( $ids as $id ) {
			$this->assertTrue( $this->storage->exists( Runner::document_path( 'post', $id ) ) );
		}
		$status = $this->runner->status();
		$this->assertSame( 120, $status['eligible'] );
		$this->assertSame( 120, $status['generated'] );
		$this->assertSame( 0, $status['pending'] );
	}

	public function test_time_budget_stops_the_run_and_the_next_run_continues() {
		self::factory()->post->create_many( 5 );
		$this->set_batch_size( 50 );

		$first = $this->runner->run( null, 0.0 );
		$this->assertSame( 1, $first['processed'], 'At least one item per run, then the budget stops it.' );
		$this->assertSame( 4, $first['remaining'] );

		$state = ( new State() )->load();
		$this->assertCount( 4, $state['queue'], 'Position is saved for the next run.' );

		$second = $this->runner->run();
		$this->assertSame( 4, $second['processed'] );
		$this->assertTrue( $second['cycle_completed'] );
	}

	public function test_oldest_generated_items_come_first() {
		$ids = self::factory()->post->create_many( 3 );
		$this->runner->run_cycle();

		$state                        = new State();
		$data                         = $state->load();
		$data['generated'][ $ids[0] ] = time() + 100;
		$data['generated'][ $ids[1] ] = time() - 100;
		$data['generated'][ $ids[2] ] = time();
		$state->save( $data, false ); // Replace: a merge would keep the newest timestamps.

		$this->runner->run( 1 );
		$data = $state->load();
		$this->assertSame( array( $ids[2], $ids[0] ), $data['queue'], 'The least recently generated item was processed first.' );
	}

	public function test_post_published_mid_cycle_is_processed_in_next_run() {
		$ids = self::factory()->post->create_many( 5 );
		$this->set_batch_size( 2 );

		$first = $this->runner->run();
		$this->assertSame( 2, $first['processed'] );
		$this->assertSame( 3, $first['remaining'] );

		$fresh = self::factory()->post->create( array( 'post_title' => 'Fresh' ) );
		$this->assertFalse( $this->storage->exists( Runner::document_path( 'post', $fresh ) ), 'Publishing never generates.' );

		$second = $this->runner->run();
		$this->assertSame( 2, $second['processed'] );
		$this->assertTrue( $this->storage->exists( Runner::document_path( 'post', $fresh ) ), 'The new item went first.' );
		$this->assertSame( 2, $second['remaining'], 'The rest of the queue is untouched.' );

		$state = ( new State() )->load();
		$this->assertEqualSets( array_slice( $ids, 3 ), $state['queue'] );
		$this->assertArrayHasKey( $fresh, $state['generated'] );
	}

	public function test_artifacts_regenerate_when_cycle_is_older_than_interval() {
		self::factory()->post->create_many( 200 );
		$this->set_batch_size( 50 );
		$state = new State();

		$this->runner->run();
		$this->assertSame( 1, $this->artifacts->calls, 'First run: never generated before.' );
		$this->runner->run();
		$this->assertSame( 1, $this->artifacts->calls, 'Fresh enough: not regenerated.' );

		$data                               = $state->load();
		$data['last_artifacts_regenerated'] = time() - 2 * DAY_IN_SECONDS;
		$state->save( $data );
		$result = $this->runner->run();
		$this->assertFalse( $result['cycle_completed'] );
		$this->assertSame( 2, $this->artifacts->calls, 'Older than the daily interval: regenerated mid-cycle.' );
		$this->assertGreaterThan( time() - 5, $state->load()['last_artifacts_regenerated'] );

		$result = $this->runner->run();
		$this->assertTrue( $result['cycle_completed'] );
		$this->assertSame( 3, $this->artifacts->calls, 'And again when the cycle completes.' );
	}

	public function test_changing_post_types_resets_queue() {
		self::factory()->post->create_many( 3 );
		self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->set_batch_size( 1 );
		$this->runner->run();
		$state = new State();
		$this->assertCount( 3, $state->load()['queue'] );

		update_option( Settings::OPTION, array( 'batch_size' => 1, 'schedule' => 'hourly' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Plugin::instance()->get( 'settings' )->flush_cache();
		$this->assertCount( 3, $state->load()['queue'], 'Same post types: the queue survives.' );

		update_option( Settings::OPTION, array( 'batch_size' => 1, 'post_types' => array( 'post' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Plugin::instance()->get( 'settings' )->flush_cache();
		$this->assertSame( array(), $state->load()['queue'], 'Different post types: the queue is discarded.' );
		$this->assertSame( 0, $state->load()['cycle_started'] );

		$result = $this->runner->run();
		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 2, $result['remaining'], 'Rebuilt with posts only (3 posts, 1 already generated... queued oldest first).' );
	}

	public function test_prune_removes_documents_of_disabled_post_type() {
		$post = self::factory()->post->create();
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->runner->run_cycle();
		$this->assertTrue( $this->storage->exists( Runner::document_path( 'page', $page ) ) );

		update_option( Settings::OPTION, array( 'post_types' => array( 'post' ) ) );
		Plugin::instance()->get( 'settings' )->flush_cache();
		$this->runner->run_cycle();

		$this->assertFalse( $this->storage->exists( Runner::document_path( 'page', $page ) ) );
		$this->assertTrue( $this->storage->exists( Runner::document_path( 'post', $post ) ) );
	}

	public function test_trashing_a_post_deletes_its_document_immediately_without_generating() {
		$id = self::factory()->post->create();
		$this->runner->run_cycle();
		$path = Runner::document_path( 'post', $id );
		$this->assertTrue( $this->storage->exists( $path ) );

		$calls = $this->items->calls;
		wp_trash_post( $id );
		$this->assertFalse( $this->storage->exists( $path ) );
		$this->assertSame( $calls, $this->items->calls );
		$this->assertSame( 0, $this->runner->status()['generated'] );
	}

	public function test_deleting_a_post_deletes_its_document() {
		$id = self::factory()->post->create();
		$this->runner->run_cycle();
		wp_delete_post( $id, true );
		$this->assertFalse( $this->storage->exists( Runner::document_path( 'post', $id ) ) );
	}

	public function test_publishing_or_updating_never_generates() {
		$id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_publish_post( $id );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => 'Updated',
			)
		);
		$this->assertSame( 0, $this->items->calls );
		$this->assertFalse( $this->storage->exists( Runner::document_path( 'post', $id ) ) );
		$this->assertSame( 1, $this->runner->status()['pending'] );
	}

	public function test_updating_a_post_keeps_the_stored_document_until_the_next_cycle() {
		$id = self::factory()->post->create( array( 'post_title' => 'Before' ) );
		$this->runner->run_cycle();
		$path = Runner::document_path( 'post', $id );
		$this->assertSame( "# Before\n", $this->storage->read( $path ) );

		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'After',
			)
		);
		$this->assertSame( "# Before\n", $this->storage->read( $path ) );

		$this->runner->run_cycle();
		$this->assertSame( "# After\n", $this->storage->read( $path ) );
	}

	public function test_generate_item_lazily_fills_and_records() {
		$id       = self::factory()->post->create( array( 'post_title' => 'Lazy' ) );
		$document = $this->runner->generate_item( $id );
		$this->assertSame( "# Lazy\n", $document );
		$this->assertTrue( $this->storage->exists( Runner::document_path( 'post', $id ) ) );
		$this->assertSame( 1, $this->runner->status()['generated'] );

		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->assertNull( $this->runner->generate_item( $draft ) );
	}

	public function test_clear_empties_storage_and_state() {
		self::factory()->post->create_many( 2 );
		$this->runner->run_cycle();
		$this->assertNotEmpty( $this->storage->list_files( '' ) );

		$this->runner->clear();
		$this->assertSame( array(), $this->storage->list_files( '' ) );
		$this->assertFalse( get_option( State::OPTION ) );
		$this->assertSame( 2, $this->runner->status()['pending'] );
	}

	public function test_run_cycle_for_one_post_type() {
		$post = self::factory()->post->create();
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->runner->run_cycle( array( 'page' ) );
		$this->assertTrue( $this->storage->exists( Runner::document_path( 'page', $page ) ) );
		$this->assertFalse( $this->storage->exists( Runner::document_path( 'post', $post ) ) );
	}

	/**
	 * Item generator that fails for the given ids.
	 *
	 * @param int[] $fail_ids Ids whose generation returns false.
	 * @return WPASL_Test_Item_Generator
	 */
	private function failing_generator( array $fail_ids ) {
		$generator           = new WPASL_Test_Item_Generator();
		$generator->fail_ids = $fail_ids;
		$this->runner->set_item_generator( $generator );
		return $generator;
	}

	public function test_failed_items_are_counted_and_deprioritized() {
		$bad  = self::factory()->post->create( array( 'post_title' => 'Bad' ) );
		$good = self::factory()->post->create( array( 'post_title' => 'Good' ) );
		$this->failing_generator( array( $bad ) );
		$events = array();
		add_action(
			'wpasl_generation_failed',
			static function ( $post, $reason, $attempts ) use ( &$events ) {
				$events[] = array( $post->ID, $reason, $attempts );
			},
			10,
			3
		);

		$log = tempnam( get_temp_dir(), 'wpasl-log' );
		$ini = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		try {
			$this->runner->run( 10 );
			$this->runner->run( 10 );
		} finally {
			ini_set( 'error_log', (string) $ini ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
		$logged = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		unlink( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertStringContainsString( "post #{$bad} (empty_document), attempt 1", $logged );
		$state = ( new State() )->load();
		$this->assertSame( 2, $state['failed'][ $bad ] );
		$this->assertArrayNotHasKey( $bad, $state['generated'] );
		$this->assertArrayHasKey( $good, $state['generated'] );
		$this->assertSame( array( array( $bad, 'empty_document', 1 ), array( $bad, 'empty_document', 2 ) ), $events );
		$this->assertSame( 1, $this->runner->status()['failed'] );

		$this->runner->run( 10 );
		$this->assertSame( 3, ( new State() )->load()['failed'][ $bad ], 'Third failure reaches the threshold.' );

		// A fresh item and a batch of one: the exhausted item must not take the slot.
		$fresh = self::factory()->post->create( array( 'post_title' => 'Fresh' ) );
		$this->runner->run( 1 );
		$state = ( new State() )->load();
		$this->assertArrayHasKey( $fresh, $state['generated'], 'The healthy item was processed first.' );
		$this->assertSame( 3, $state['failed'][ $bad ], 'The exhausted item was not retried in that batch.' );
		$this->assertSame( $bad, end( $state['queue'] ), 'It waits at the end of the queue.' );

		// Mid-cycle top-up: the exhausted item is never re-inserted at the front.
		$later = self::factory()->post->create( array( 'post_title' => 'Later' ) );
		$this->runner->run( 1 );
		$state = ( new State() )->load();
		$this->assertArrayHasKey( $later, $state['generated'] );
		$this->assertSame( 3, $state['failed'][ $bad ] );
	}

	public function test_successful_generation_clears_failure_counter() {
		$post      = self::factory()->post->create();
		$generator = $this->failing_generator( array( $post ) );
		$ini       = ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$this->runner->run( 10 );
		ini_set( 'error_log', (string) $ini ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$this->assertSame( 1, ( new State() )->load()['failed'][ $post ] );

		$generator->fail_ids = array();
		$this->runner->run( 10 );
		$state = ( new State() )->load();
		$this->assertSame( array(), $state['failed'] );
		$this->assertArrayHasKey( $post, $state['generated'] );
		$this->assertSame( 0, $this->runner->status()['failed'] );
	}

	/**
	 * Counts writes of the state option during a callback.
	 *
	 * @param callable $callback Callback.
	 * @return int
	 */
	private function count_state_writes( callable $callback ) {
		$writes  = 0;
		$counter = static function ( $value ) use ( &$writes ) {
			++$writes;
			return $value;
		};
		add_filter( 'pre_update_option_' . State::OPTION, $counter );
		add_filter( 'pre_add_option_' . State::OPTION, $counter );
		$callback();
		remove_filter( 'pre_update_option_' . State::OPTION, $counter );
		remove_filter( 'pre_add_option_' . State::OPTION, $counter );
		return $writes;
	}

	public function test_trashing_non_enabled_post_type_does_not_write_state() {
		update_option( Settings::OPTION, array( 'post_types' => array( 'post' ) ) );
		Plugin::instance()->get( 'settings' )->flush_cache();
		self::factory()->post->create();
		$this->runner->run_cycle();
		$page   = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$before = get_option( State::OPTION );

		$writes = $this->count_state_writes(
			static function () use ( $page ) {
				wp_trash_post( $page );
			}
		);

		$this->assertSame( 0, $writes );
		$this->assertSame( $before, get_option( State::OPTION ) );
	}

	public function test_run_with_full_llms_writes_state_once() {
		$ids = self::factory()->post->create_many( 100 );
		update_option( Settings::OPTION, array( 'llms_full_enabled' => true, 'llms_limit' => 100 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Plugin::instance()->get( 'settings' )->flush_cache();
		$this->assertTrue( Plugin::instance()->get( 'llms' )->full_enabled() );

		// A run that processes nothing itself still refreshes the (never generated) discovery files, and
		// llms-full.txt lazily generates every missing document.
		$runner = $this->runner;
		$writes = $this->count_state_writes(
			static function () use ( $runner ) {
				$runner->run( 0 );
			}
		);

		$this->assertSame( 1, $writes );
		$state = ( new State() )->load();
		$this->assertCount( 100, array_intersect_key( $state['generated'], array_fill_keys( $ids, true ) ) );
		$this->assertSame( 100, $this->items->calls );
		$this->assertTrue( $this->storage->exists( 'llms-full.txt' ) );
	}

	public function test_reset_cycle_without_types_forgets_everything() {
		$post = self::factory()->post->create();
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->runner->run_cycle();
		$this->assertCount( 2, ( new State() )->load()['generated'] );

		$this->runner->reset_cycle( array( 'page' ) );
		$state = ( new State() )->load();
		$this->assertSame( array(), $state['queue'] );
		$this->assertArrayHasKey( $post, $state['generated'] );
		$this->assertArrayNotHasKey( $page, $state['generated'] );

		$this->runner->reset_cycle();
		$state = ( new State() )->load();
		$this->assertSame( array(), $state['generated'] );
		$this->assertSame( array(), $state['queue'] );
	}

	public function test_without_item_generator_runs_only_artifacts() {
		self::factory()->post->create();
		$this->runner->set_item_generator( null );
		$result = $this->runner->run();
		$this->assertSame( 0, $result['processed'] );
		$this->assertTrue( $result['cycle_completed'] );
		$this->assertSame( 1, $this->artifacts->calls );
	}

	public function test_cron_hook_is_wired() {
		$this->assertSame( 10, has_action( 'wpasl_generate', array( $this->runner, 'run' ) ) );
	}

	public function test_manual_event_argument_does_not_limit_the_run() {
		self::factory()->post->create_many( 3 );
		do_action( 'wpasl_generate', 'manual' ); // The one-off event passes its args to the hook.
		$this->assertSame( 3, $this->items->calls, 'The "manual" argument was not taken as a batch limit of 0.' );
	}
}
