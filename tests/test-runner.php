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
		remove_filter( 'pre_http_request', array( $this, 'fake_loopback' ), 20 );
		remove_all_actions( 'wpasl_render_failed' );
		remove_all_actions( 'wpasl_run_started' );
		remove_all_actions( 'wpasl_run_finished' );
		$this->runner->set_item_generator( null );
		$this->runner->clear();
		Storage::delete_all();
		delete_option( Settings::OPTION );
		Plugin::instance()->get( 'settings' )->flush_cache();
		parent::tear_down();
	}

	/**
	 * Requests seen by the fake loopback.
	 *
	 * @var string[]
	 */
	private $loopback_requests = array();

	/**
	 * Fake loopback response: array( code, headers, body ) or a WP_Error; null answers with the Elementor fixture.
	 *
	 * @var mixed
	 */
	private $loopback_response = null;

	/**
	 * Fake loopback for the rendered page of a post.
	 */
	public function fake_loopback( $pre, $args, $url ) {
		$this->loopback_requests[] = $url;
		$spec                      = $this->loopback_response;
		if ( null === $spec ) {
			$spec = array( 200, array( 'content-type' => 'text/html; charset=utf-8' ), file_get_contents( __DIR__ . '/fixtures/rendered-elementor.html' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		if ( is_wp_error( $spec ) ) {
			return $spec;
		}
		return array(
			'response' => array( 'code' => $spec[0], 'message' => 'x' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( $spec[1] ),
			'body'     => $spec[2],
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Uses the real document builder with a fake loopback and returns a post built with Elementor.
	 *
	 * @param mixed $response Loopback response spec.
	 * @return WP_Post
	 */
	private function elementor_post_with_builder( $response = null ) {
		$this->runner->set_item_generator( Plugin::instance()->get( 'builder' ) );
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Blackboard',
				'post_content' => '<p>Editor placeholder text.</p>',
			)
		);
		update_post_meta( $post->ID, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post->ID, '_elementor_data', '[{"id":"abc"}]' );
		$this->loopback_requests = array();
		$this->loopback_response = $response;
		add_filter( 'pre_http_request', array( $this, 'fake_loopback' ), 20, 3 );
		return $post;
	}

	/**
	 * Runs a callback with the PHP error log sent to a temporary file and returns what was logged.
	 *
	 * @param callable $callback Callback.
	 * @return string
	 */
	private function capture_error_log( callable $callback ) {
		$log = tempnam( get_temp_dir(), 'wpasl-log' );
		$ini = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		try {
			$callback();
		} finally {
			ini_set( 'error_log', (string) $ini ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
		$logged = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		unlink( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		return $logged;
	}

	public function test_render_failure_is_recorded_without_counting_as_conversion_failure() {
		$post   = $this->elementor_post_with_builder( array( 503, array( 'content-type' => 'text/html' ), 'down' ) );
		$other  = self::factory()->post->create( array( 'post_title' => 'Normal' ) );
		$events = array();
		add_action(
			'wpasl_render_failed',
			static function ( $failed, $reason, $attempts ) use ( &$events ) {
				$events[] = array( $failed->ID, $reason, $attempts );
			},
			10,
			3
		);

		$runner = $this->runner;
		$logged = $this->capture_error_log(
			static function () use ( $runner ) {
				$runner->run( 10 );
			}
		);

		$path = Runner::document_path( 'post', $post->ID );
		$this->assertTrue( $this->storage->exists( $path ), 'The fallback document is stored.' );
		$this->assertStringContainsString( "\nsource: \"editor\"\n", $this->storage->read( $path ) );
		$this->assertStringContainsString( 'Editor placeholder text.', $this->storage->read( $path ) );
		$state = ( new State() )->load();
		$this->assertArrayHasKey( $post->ID, $state['generated'], 'Marked as generated.' );
		$this->assertArrayHasKey( $other, $state['generated'] );
		$this->assertSame( array(), $state['failed'], 'Not a conversion failure.' );
		$this->assertSame( array( $post->ID => 1 ), $state['render_failed'] );
		$this->assertSame( $post->ID, $state['last_render_error']['post_id'] );
		$this->assertSame( 'http_503', $state['last_render_error']['reason'] );
		$this->assertSame( array( array( $post->ID, 'http_503', 1 ) ), $events );
		$this->assertStringContainsString( "Could not fetch the rendered page of post #{$post->ID} (http_503), attempt 1; the editor content was used.", $logged );
		$this->assertStringNotContainsString( 'Could not generate', $logged );
		$this->assertCount( 1, $this->loopback_requests );

		$status = $this->runner->status();
		$this->assertSame( 0, $status['failed'] );
		$this->assertSame( 0, $status['pending'] );
		$this->assertSame( 1, $status['render_failed'] );
		$this->assertSame( 'http_503', $status['last_render_error']['reason'] );

		// The second run increments the counter.
		$this->capture_error_log(
			static function () use ( $runner ) {
				$runner->run( 10 );
			}
		);
		$this->assertSame( array( $post->ID => 2 ), ( new State() )->load()['render_failed'] );
		$this->assertSame( array( $post->ID, 'http_503', 2 ), end( $events ) );
	}

	public function test_render_success_clears_counter() {
		$post                      = $this->elementor_post_with_builder();
		$state                     = new State();
		$data                      = $state->load();
		$data['render_failed']     = array( $post->ID => 2 );
		$data['last_render_error'] = State::render_error( $post->ID, 'http_503' );
		$state->save( $data, false );

		$this->runner->run( 10 );

		$saved = $state->load();
		$this->assertSame( array(), $saved['render_failed'] );
		$this->assertSame( 'http_503', $saved['last_render_error']['reason'], 'The last error is informational.' );
		$document = $this->storage->read( Runner::document_path( 'post', $post->ID ) );
		$this->assertStringContainsString( "\nsource: \"rendered\"\n", $document );
		$this->assertStringContainsString( 'Blackboard Learn es la plataforma LMS', $document );
		$this->assertSame( 0, $this->runner->status()['render_failed'] );

		// A lazy fill outside a run clears the counter too, and writes nothing when there is none.
		$state->save( array_merge( $state->load(), array( 'render_failed' => array( $post->ID => 1 ) ) ), false );
		$this->runner->generate_item( $post );
		$this->assertSame( array(), $state->load()['render_failed'] );
		$writes = $this->count_state_writes(
			function () use ( $post ) {
				$this->runner->generate_item( $post );
			}
		);
		$this->assertSame( 1, $writes, 'Only the generation mark is written.' );
	}

	public function test_render_failed_items_go_first_after_fresh_ones() {
		$ids = self::factory()->post->create_many( 5 );
		$this->runner->run_cycle();
		$state                 = new State();
		$data                  = $state->load();
		$data['render_failed'] = array(
			$ids[3] => 1,
			$ids[4] => 2,
		);
		$state->save( $data, false );

		// A fresh queue: the never-generated item first, then the items to retry, then the rest.
		$fresh = self::factory()->post->create( array( 'post_title' => 'Fresh' ) );
		$this->runner->run( 1 );
		$data = $state->load();
		$this->assertArrayHasKey( $fresh, $data['generated'], 'The fresh item was processed first.' );
		$this->assertSame( array( $ids[3], $ids[4], $ids[0], $ids[1], $ids[2] ), $data['queue'] );

		// A queue in progress: the fresh item goes first, the failed one right after it.
		$data['queue'] = array( $ids[0], $ids[1], $ids[2], $ids[3], $ids[4] );
		$state->save( $data, false );
		$later = self::factory()->post->create( array( 'post_title' => 'Later' ) );
		$this->runner->run( 1 );
		$data = $state->load();
		$this->assertArrayHasKey( $later, $data['generated'] );
		$this->assertSame( array( $ids[3], $ids[4], $ids[0], $ids[1], $ids[2] ), $data['queue'] );
	}

	public function test_render_failed_items_at_threshold_keep_normal_order() {
		$ids = self::factory()->post->create_many( 4 );
		$this->runner->run_cycle();
		$state                 = new State();
		$data                  = $state->load();
		$data['render_failed'] = array(
			$ids[2] => 3,
			$ids[3] => 1,
		);
		$data['queue']         = array( $ids[0], $ids[1], $ids[2], $ids[3] );
		$state->save( $data, false );

		$this->runner->run( 1 );
		$this->assertSame( array( $ids[0], $ids[1], $ids[2] ), $state->load()['queue'], 'Below the threshold goes first; at the threshold keeps its place.' );
		$this->assertSame( 3, $state->load()['render_failed'][ $ids[2] ], 'Retried in its normal order, never dropped.' );
	}

	public function test_deferred_item_returns_to_front_and_run_ends() {
		$post   = $this->elementor_post_with_builder();
		$others = self::factory()->post->create_many( 2 );
		$this->assertLessThan( $others[0], $post->ID, 'The rendered item is queued first.' );
		$finished = 0;
		add_action(
			'wpasl_run_finished',
			static function () use ( &$finished ) {
				++$finished;
			}
		);

		// One second of budget: the loopback (minimum 2 s) is deferred before any request.
		$result = $this->runner->run( 10, 1.0 );

		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( 3, $result['remaining'] );
		$this->assertFalse( $result['cycle_completed'] );
		$this->assertSame( array(), $this->loopback_requests, 'No request was attempted.' );
		$state = ( new State() )->load();
		$this->assertSame( array( $post->ID, $others[0], $others[1] ), $state['queue'], 'Back to the front.' );
		$this->assertSame( array(), $state['failed'] );
		$this->assertSame( array(), $state['render_failed'] );
		$this->assertArrayNotHasKey( $post->ID, $state['generated'] );
		$this->assertSame( 1, $finished, 'The run ended normally.' );

		// With time to spare the same item is generated from its rendered page.
		$result = $this->runner->run( 10, 20.0 );
		$this->assertSame( 3, $result['processed'] );
		$this->assertTrue( $result['cycle_completed'] );
		$this->assertCount( 1, $this->loopback_requests );
		$this->assertStringContainsString( "\nsource: \"rendered\"\n", $this->storage->read( Runner::document_path( 'post', $post->ID ) ) );
		$this->assertSame( 2, $finished );
	}

	public function test_run_started_and_finished_actions_carry_deadline() {
		self::factory()->post->create();
		$events = array();
		add_action(
			'wpasl_run_started',
			static function ( $deadline ) use ( &$events ) {
				$events[] = array( 'started', $deadline );
			}
		);
		add_action(
			'wpasl_run_finished',
			static function () use ( &$events ) {
				$events[] = array( 'finished', null );
			}
		);

		$before = microtime( true );
		$this->runner->run( null, 20.0 );
		$after = microtime( true );

		$this->assertSame( array( 'started', 'finished' ), array_column( $events, 0 ) );
		$this->assertGreaterThanOrEqual( $before + 20.0, $events[0][1] );
		$this->assertLessThanOrEqual( $after + 20.0, $events[0][1] );
		$this->assertSame( 10, has_action( 'wpasl_run_started', array( Plugin::instance()->get( 'rendered_page' ), 'on_run_started' ) ), 'The loopback listens.' );
	}

	public function test_on_demand_render_failure_during_run_is_kept_in_saved_state() {
		$ids   = self::factory()->post->create_many( 3 );
		$state = new State();
		$other = $ids[2];

		// Another request generates an item on demand and records its failure while the run holds the
		// state in memory (simulated from inside the run through the item generator).
		$generator = $this->items;
		$hook      = static function ( $post ) use ( $state, $other, $generator ) {
			static $done = false;
			if ( $done || $post->ID === $other ) {
				return;
			}
			$done = true;
			$state->mark_generated( $other );
			$state->record_render_failure( $other, 'request_error:http_request_failed' );
		};
		$this->runner->set_item_generator(
			new class( $generator, $hook ) implements \WPASL\Generation\ItemGeneratorInterface {
				private $inner;
				private $hook;
				public function __construct( $inner, $hook ) {
					$this->inner = $inner;
					$this->hook  = $hook;
				}
				public function generate( WP_Post $post ) {
					call_user_func( $this->hook, $post );
					return $this->inner->generate( $post );
				}
			}
		);

		$this->runner->run( 2 );

		$saved = $state->load();
		$this->assertArrayHasKey( $other, $saved['generated'], 'The on-demand generation mark survives the run.' );
		$this->assertSame( array( $other => 1 ), $saved['render_failed'], 'And so does its rendered-page failure.' );
		$this->assertSame( 'request_error:http_request_failed', $saved['last_render_error']['reason'] );
		$this->assertSame( 1, $this->runner->status()['render_failed'] );
	}

	public function test_completed_cycle_prunes_render_failed_of_ineligible_items() {
		$post                  = self::factory()->post->create();
		$draft                 = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$state                 = new State();
		$data                  = $state->load();
		$data['render_failed'] = array(
			$post  => 2,
			$draft => 1,
			999999 => 1,
		);
		$state->save( $data, false );

		$this->runner->run_cycle();

		$saved = $state->load();
		$this->assertSame( array( $post => 2 ), $saved['render_failed'], 'Counters of ineligible items are dropped and not resurrected by the merge.' );
		$this->assertSame( 1, $this->runner->status()['render_failed'] );
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

	public function test_publishing_or_updating_never_generates_synchronously() {
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

	public function test_updating_a_published_post_invalidates_the_stored_document_immediately() {
		$id = self::factory()->post->create( array( 'post_title' => 'Before' ) );
		$this->runner->run_cycle();
		$path         = Runner::document_path( 'post', $id );
		$calls_before = $this->items->calls;
		$this->assertSame( "# Before\n", $this->storage->read( $path ) );

		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'After',
			)
		);
		// The stale document is removed at once (no synchronous regeneration); a lazy fill or the
		// next cycle produces the fresh one.
		$this->assertNull( $this->storage->read( $path ) );
		$this->assertSame( $calls_before, $this->items->calls );
		$this->assertSame( 1, $this->runner->status()['pending'] );

		$this->runner->run_cycle();
		$this->assertSame( "# After\n", $this->storage->read( $path ) );
	}

	public function test_resaving_a_published_post_without_changes_still_invalidates() {
		$id   = self::factory()->post->create( array( 'post_title' => 'Same' ) );
		$path = Runner::document_path( 'post', $id );
		$this->runner->run_cycle();
		$this->assertTrue( $this->storage->exists( $path ) );

		wp_update_post( array( 'ID' => $id ) );

		$this->assertFalse( $this->storage->exists( $path ) );
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
		update_option( Settings::OPTION, array( 'llms_full_enabled' => true, 'llms_type_limit' => 100 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
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

	public function test_prune_removes_stale_tmp_files() {
		$post = self::factory()->post->create();
		$this->runner->run_cycle();
		$stale = $this->storage->path( 'md/post/' . $post . '.md.abcdefgh.tmp' );
		$fresh = $this->storage->path( 'md/post/' . $post . '.md.ijklmnop.tmp' );
		file_put_contents( $stale, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $fresh, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		touch( $stale, time() - 2 * HOUR_IN_SECONDS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch

		$this->runner->prune();

		$this->assertFileDoesNotExist( $stale );
		$this->assertFileExists( $fresh, 'A temporary file from a write in progress is kept.' );
		$this->assertTrue( $this->storage->exists( Runner::document_path( 'post', $post ) ) );
		unlink( $fresh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
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
