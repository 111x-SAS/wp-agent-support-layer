<?php
/**
 * Batch generation runner.
 *
 * @package WPASL
 */

namespace WPASL\Generation;

use WPASL\Content\Eligibility;
use WPASL\Settings;
use WPASL\Storage;

/**
 * Generates documents in bounded batches from WP-Cron, prunes stale files and refreshes site-wide artifacts.
 * It never reacts to post saves; the only content hook it uses removes files when a post stops being public.
 */
final class Runner {

	const DEFAULT_TIME_BUDGET = 20.0;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	private $storage;

	/**
	 * Eligibility.
	 *
	 * @var Eligibility
	 */
	private $eligibility;

	/**
	 * State.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Per-item generator.
	 *
	 * @var ItemGeneratorInterface|null
	 */
	private $item_generator = null;

	/**
	 * Site-wide artifact generators.
	 *
	 * @var ArtifactGeneratorInterface[]
	 */
	private $artifact_generators = array();

	/**
	 * Post types of the cycle being run by run_cycle(), or null for every enabled type.
	 *
	 * @var string[]|null
	 */
	private $cycle_post_types = null;

	/**
	 * Reason of the last write_document() failure: no_generator, empty_document, storage_write or deferred.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Whether the document builder deferred the current item (rendered page not fetched for lack of time).
	 *
	 * @var bool
	 */
	private $deferred = false;

	/**
	 * Rendered-page failures tracked while run() is active, or null outside a run: "counts" by post id,
	 * "last" error and "cleared" ids (successful loopbacks). Folded into the run's state on save.
	 *
	 * @var array{counts:array<int,int>, last:array|null, cleared:array<int,bool>}|null
	 */
	private $run_render = null;

	/**
	 * Generation marks recorded by generate_item() while run() is active, or null outside a run. They are
	 * folded into the run's state instead of rewriting the option once per lazy fill.
	 *
	 * @var array<int,int>|null
	 */
	private $run_marks = null;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings    Settings.
	 * @param Storage     $storage     Storage.
	 * @param Eligibility $eligibility Eligibility.
	 * @param State       $state       State.
	 */
	public function __construct( Settings $settings, Storage $storage, Eligibility $eligibility, State $state ) {
		$this->settings    = $settings;
		$this->storage     = $storage;
		$this->eligibility = $eligibility;
		$this->state       = $state;
	}

	/**
	 * Sets the per-item generator.
	 *
	 * @param ItemGeneratorInterface|null $generator Generator.
	 * @return void
	 */
	public function set_item_generator( $generator ) {
		$this->item_generator = $generator;
	}

	/**
	 * Adds a site-wide artifact generator.
	 *
	 * @param ArtifactGeneratorInterface $generator Generator.
	 * @return void
	 */
	public function add_artifact_generator( ArtifactGeneratorInterface $generator ) {
		$this->artifact_generators[ $generator->id() ] = $generator;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( Scheduler::HOOK, array( $this, 'run' ), 10, 0 );
		// Event args (e.g. "manual") are not run() arguments.
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'on_deleted_post' ), 10, 2 );
		add_action( 'wpasl_content_source_resolved', array( $this, 'on_content_source_resolved' ), 10, 2 );
	}

	/**
	 * Relative storage path of a post's document.
	 *
	 * @param string $post_type Post type.
	 * @param int    $post_id   Post id.
	 * @return string
	 */
	public static function document_path( $post_type, $post_id ) {
		return 'md/' . sanitize_key( $post_type ) . '/' . (int) $post_id . '.md';
	}

	/**
	 * Processes one batch. Called by WP-Cron and WP-CLI.
	 *
	 * @param int|null      $limit      Max items; defaults to the batch size setting.
	 * @param float|null    $budget     Max seconds; defaults to 20 (filterable).
	 * @param string[]|null $post_types Restrict the queue (rebuilt or topped up) to these post types.
	 * @return array{processed:int, remaining:int, cycle_completed:bool}
	 */
	public function run( $limit = null, $budget = null, $post_types = null ) {
		if ( null !== $post_types ) {
			$this->cycle_post_types = $post_types;
		}
		$limit = null === $limit ? (int) $this->settings->get( 'batch_size' ) : (int) $limit;
		if ( null === $budget ) {
			/**
			 * Filters the time budget of one generation run, in seconds.
			 *
			 * @param float $budget Seconds.
			 */
			$budget = (float) apply_filters( 'wpasl_run_time_budget', self::DEFAULT_TIME_BUDGET );
		}

		$this->storage->ensure();
		$state            = $this->state->load();
		$this->run_marks  = array();
		$this->run_render = array(
			'counts'  => array_map( 'intval', (array) $state['render_failed'] ),
			'last'    => null,
			'cleared' => array(),
		);

		if ( empty( $state['queue'] ) ) {
			$state['queue']         = $this->build_queue( $state['generated'], $this->cycle_post_types, $state['failed'] );
			$state['cycle_started'] = time();
		} else {
			// Items published during the cycle go first instead of waiting for the next cycle; items that keep
			// failing go last so they never monopolize a batch.
			$fresh          = $this->never_generated_ids( $state );
			$retry          = array_values( array_intersect( $fresh, $this->exhausted_ids( $state ) ) );
			$fresh          = array_values( array_diff( $fresh, $retry ) );
			$state['queue'] = array_merge( $fresh, $state['queue'], $retry );
		}
		// Items whose rendered page could not be fetched last time are retried right after the fresh ones.
		$state['queue'] = $this->prioritize_render_failed( $state['queue'], $state['render_failed'], $state['generated'] );

		$started   = microtime( true );
		$deadline  = $started + $budget;
		$processed = 0;

		/**
		 * Fires when a generation run starts. The rendered page loopback caps its timeout by the deadline.
		 *
		 * @param float $deadline Microtime after which the run stops processing items.
		 */
		do_action( 'wpasl_run_started', $deadline );

		while ( ! empty( $state['queue'] ) && $processed < $limit ) {
			$post_id = (int) array_shift( $state['queue'] );
			$post    = get_post( $post_id );
			if ( $post && $this->eligibility->is_eligible( $post ) ) {
				if ( $this->write_document( $post ) ) {
					$state['generated'][ $post_id ] = time();
					unset( $state['failed'][ $post_id ] );
				} elseif ( 'deferred' === $this->last_error ) {
					// Not enough time left for its rendered page: back to the front, neither processed nor failed.
					array_unshift( $state['queue'], $post_id );
					break;
				} else {
					$state['failed'][ $post_id ] = ( isset( $state['failed'][ $post_id ] ) ? (int) $state['failed'][ $post_id ] : 0 ) + 1;
					$this->report_failure( $post, $this->last_error, $state['failed'][ $post_id ] );
				}
			}
			++$processed;

			if ( microtime( true ) - $started >= $budget ) {
				break;
			}
		}//end while

		$cycle_completed = empty( $state['queue'] );
		$render_pruned   = array();
		if ( $cycle_completed ) {
			$before                        = $state['generated'];
			$state['generated']            = $this->prune( $before );
			$state['_removed']             = array_keys( array_diff_key( $before, $state['generated'] ) );
			$state['last_cycle_completed'] = time();
			$eligible                      = null;
			if ( ! empty( $state['failed'] ) ) {
				$eligible        = array_fill_keys( $this->eligibility->eligible_ids(), true );
				$state['failed'] = array_intersect_key( $state['failed'], $eligible );
			}
			if ( ! empty( $this->run_render['counts'] ) ) {
				$eligible                   = null === $eligible ? array_fill_keys( $this->eligibility->eligible_ids(), true ) : $eligible;
				$kept                       = array_intersect_key( $this->run_render['counts'], $eligible );
				$render_pruned              = array_keys( array_diff_key( $this->run_render['counts'], $kept ) );
				$this->run_render['counts'] = $kept;
			}
		}
		if ( $cycle_completed || $this->artifacts_are_stale( $state ) ) {
			$state['artifacts_generated']        = $this->regenerate_artifacts();
			$state['last_artifacts_regenerated'] = time();
		}

		// Documents generated on demand during this run (e.g. while building llms-full.txt).
		$state['generated'] = $this->run_marks + $state['generated'];
		$state['failed']    = array_diff_key( (array) $state['failed'], $this->run_marks );
		$state['queue']     = array_values( array_diff( array_map( 'intval', $state['queue'] ), array_keys( $this->run_marks ) ) );
		$this->run_marks    = null;

		// Rendered-page failures seen during this run (scheduled items and lazy fills alike).
		$state['render_failed']   = $this->run_render['counts'];
		$state['_render_cleared'] = array_merge( array_keys( $this->run_render['cleared'] ), $render_pruned );
		if ( null !== $this->run_render['last'] ) {
			$state['last_render_error'] = $this->run_render['last'];
		}
		$this->run_render = null;

		$state['last_run']       = time();
		$state['last_run_count'] = $processed;
		$this->state->save( $state );

		/**
		 * Fires when a generation run finished, after its state was saved.
		 */
		do_action( 'wpasl_run_finished' );

		if ( null !== $post_types ) {
			$this->cycle_post_types = null;
		}

		return array(
			'processed'       => $processed,
			'remaining'       => count( $state['queue'] ),
			'cycle_completed' => $cycle_completed,
		);
	}

	/**
	 * Runs batches until the current cycle completes. Used by WP-CLI.
	 *
	 * @param string[]|null $post_types Restrict to these post types (a fresh queue is built).
	 * @return int Items processed.
	 */
	public function run_cycle( $post_types = null ) {
		$state = $this->state->load();
		if ( null !== $post_types ) {
			$state['queue'] = $this->build_queue( $state['generated'], $post_types, $state['failed'] );
			$this->state->save( $state );
			if ( empty( $state['queue'] ) ) {
				// Nothing of these types to do: never fall back to an unrestricted queue.
				return 0;
			}
		}

		$this->cycle_post_types = $post_types;
		$total                  = 0;
		try {
			do {
				$result = $this->run( PHP_INT_MAX, PHP_INT_MAX );
				$total += $result['processed'];
			} while ( ! $result['cycle_completed'] );
		} finally {
			$this->cycle_post_types = null;
		}

		return $total;
	}

	/**
	 * Generates and stores one post's document on demand (lazy fill). Never triggered by saves.
	 *
	 * @param int|\WP_Post $post Post.
	 * @return string|null The document, or null when not eligible or no generator is available.
	 */
	public function generate_item( $post ) {
		$post = get_post( $post );
		if ( ! $post || ! $this->eligibility->is_eligible( $post ) ) {
			return null;
		}
		$this->storage->ensure();
		if ( ! $this->write_document( $post ) ) {
			return null;
		}
		if ( null !== $this->run_marks ) {
			$this->run_marks[ $post->ID ] = time();
		} else {
			$this->state->mark_generated( $post->ID );
		}
		return $this->storage->read( self::document_path( $post->post_type, $post->ID ) );
	}

	/**
	 * Deletes documents whose post is no longer eligible.
	 *
	 * @param array<int,int>|null $generated Generated map to clean; loaded from state when null.
	 * @return array<int,int> Cleaned generated map.
	 */
	public function prune( $generated = null ) {
		$persist = null === $generated;
		if ( $persist ) {
			$state     = $this->state->load();
			$generated = $state['generated'];
		}

		$eligible = array_fill_keys( $this->eligibility->eligible_ids(), true );

		foreach ( $this->storage->list_files( '' ) as $relative ) {
			if ( '.tmp' === substr( $relative, -4 ) ) {
				// Leftover of an interrupted atomic write (see Storage::write()); safe to delete once it is old.
				$mtime = $this->storage->mtime( $relative );
				if ( null !== $mtime && time() - $mtime > HOUR_IN_SECONDS ) {
					$this->storage->delete( $relative );
				}
				continue;
			}
			if ( ! preg_match( '#^md/([^/]+)/(\d+)\.md$#', $relative, $m ) ) {
				continue;
			}
			$post_id = (int) $m[2];
			$post    = get_post( $post_id );
			if ( ! isset( $eligible[ $post_id ] ) || ! $post || $post->post_type !== $m[1] ) {
				$this->storage->delete( $relative );
				unset( $generated[ $post_id ] );
			}
		}

		foreach ( array_keys( $generated ) as $post_id ) {
			if ( ! isset( $eligible[ $post_id ] ) ) {
				unset( $generated[ $post_id ] );
			}
		}

		if ( $persist ) {
			$state['_removed']  = array_keys( array_diff_key( $state['generated'], $generated ) );
			$state['generated'] = $generated;
			$this->state->save( $state );
		}

		return $generated;
	}

	/**
	 * Regenerates every site-wide artifact.
	 *
	 * @return int Number of generators run.
	 */
	public function regenerate_artifacts() {
		$this->storage->ensure();
		foreach ( $this->artifact_generators as $generator ) {
			$generator->generate( $this->storage );
		}
		return count( $this->artifact_generators );
	}

	/**
	 * Removes every generated file and resets the state.
	 *
	 * @return void
	 */
	public function clear() {
		foreach ( $this->storage->list_files( '' ) as $relative ) {
			$this->storage->delete( $relative );
		}
		$this->state->reset();
	}

	/**
	 * Forces a full regeneration on the next runs.
	 *
	 * @param string[]|null $post_types Only forget the documents of these post types.
	 * @return void
	 */
	public function reset_cycle( $post_types = null ) {
		$state          = $this->state->load();
		$state['queue'] = array();
		if ( null === $post_types ) {
			$state['generated'] = array();
			$state['failed']    = array();
		} else {
			$ids                = array_fill_keys( $this->eligibility->eligible_ids( $post_types ), true );
			$state['generated'] = array_diff_key( $state['generated'], $ids );
			$state['failed']    = array_diff_key( (array) $state['failed'], $ids );
		}
		$this->state->save( $state, false );
	}

	/**
	 * Status summary for the admin page and WP-CLI.
	 *
	 * @return array<string, mixed>
	 */
	public function status() {
		$state    = $this->state->load();
		$eligible = $this->eligibility->count();
		$stored   = count( array_intersect_key( $state['generated'], array_fill_keys( $this->eligibility->eligible_ids(), true ) ) );

		return array(
			'eligible'             => $eligible,
			'generated'            => $stored,
			'pending'              => max( 0, $eligible - $stored ),
			'failed'               => count( (array) $state['failed'] ),
			'render_failed'        => count( (array) $state['render_failed'] ),
			'last_render_error'    => is_array( $state['last_render_error'] ) ? $state['last_render_error'] : null,
			'queued'               => count( $state['queue'] ),
			'last_run'             => (int) $state['last_run'],
			'last_run_count'       => (int) $state['last_run_count'],
			'last_cycle_completed' => (int) $state['last_cycle_completed'],
			'artifacts'            => array_keys( $this->artifact_generators ),
			'has_item_generator'   => null !== $this->item_generator,
		);
	}

	/**
	 * Tracks the outcome of the content source resolution of a document: a rendered page that could not be
	 * fetched is counted as a rendered-page failure (never as a conversion failure: the document built from
	 * the editor content is stored anyway), a fetched one clears the item's counter, and a deferred fetch is
	 * reported to run().
	 *
	 * @param \WP_Post             $post Post.
	 * @param array<string, mixed> $info Resolution (see wpasl_content_source_resolved).
	 * @return void
	 */
	public function on_content_source_resolved( $post, $info ) {
		if ( ! $post instanceof \WP_Post || ! is_array( $info ) ) {
			return;
		}
		if ( ! empty( $info['deferred'] ) ) {
			$this->deferred = true;
			return;
		}
		if ( ! empty( $info['fallback'] ) ) {
			$reason = isset( $info['error'] ) ? (string) $info['error'] : '';
			if ( null !== $this->run_render ) {
				$count                                   = ( isset( $this->run_render['counts'][ $post->ID ] ) ? (int) $this->run_render['counts'][ $post->ID ] : 0 ) + 1;
				$this->run_render['counts'][ $post->ID ] = $count;
				$this->run_render['last']                = State::render_error( $post->ID, $reason );
				unset( $this->run_render['cleared'][ $post->ID ] );
			} else {
				$count = $this->state->record_render_failure( $post->ID, $reason );
			}
			$this->report_render_failure( $post, $reason, $count );
			return;
		}
		if ( isset( $info['source'] ) && 'rendered' === $info['source'] ) {
			if ( null !== $this->run_render ) {
				if ( isset( $this->run_render['counts'][ $post->ID ] ) ) {
					unset( $this->run_render['counts'][ $post->ID ] );
					$this->run_render['cleared'][ $post->ID ] = true;
				}
			} else {
				$this->state->clear_render_failure( $post->ID );
			}
		}
	}

	/**
	 * Removes the document when a post stops being published.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( 'publish' === $old_status && 'publish' !== $new_status && $post instanceof \WP_Post ) {
			$this->remove_document( $post );
		}
	}

	/**
	 * Removes the document when a post is deleted.
	 *
	 * @param int           $post_id Post id.
	 * @param \WP_Post|null $post    Post.
	 * @return void
	 */
	public function on_deleted_post( $post_id, $post = null ) {
		if ( $post instanceof \WP_Post ) {
			$this->remove_document( $post );
		}
	}

	/**
	 * Builds the work queue: eligible ids, least recently generated first; items that failed too many
	 * times go last.
	 *
	 * @param array<int,int> $generated  Generation times by post id.
	 * @param string[]|null  $post_types Restrict to these post types.
	 * @param array<int,int> $failed     Failure counts by post id.
	 * @return int[]
	 */
	private function build_queue( array $generated, $post_types = null, array $failed = array() ) {
		if ( null === $this->item_generator ) {
			return array();
		}
		$ids       = $this->eligibility->eligible_ids( $post_types );
		$max       = $this->max_failures();
		$exhausted = array();
		foreach ( $failed as $post_id => $count ) {
			if ( (int) $count >= $max ) {
				$exhausted[ (int) $post_id ] = true;
			}
		}
		usort(
			$ids,
			static function ( $a, $b ) use ( $generated, $exhausted ) {
				$fa = isset( $exhausted[ $a ] ) ? 1 : 0;
				$fb = isset( $exhausted[ $b ] ) ? 1 : 0;
				if ( $fa !== $fb ) {
					return $fa - $fb;
				}
				$ta = isset( $generated[ $a ] ) ? $generated[ $a ] : 0;
				$tb = isset( $generated[ $b ] ) ? $generated[ $b ] : 0;
				return $ta === $tb ? $a - $b : $ta - $tb;
			}
		);
		return $ids;
	}

	/**
	 * Moves the queued items with a rendered-page failure below the threshold right after the leading
	 * never-generated items; items at the threshold keep their normal place.
	 *
	 * @param int[]          $queue         Queue.
	 * @param array<int,int> $render_failed Rendered-page failure counts by post id.
	 * @param array<int,int> $generated     Generation times by post id.
	 * @return int[]
	 */
	private function prioritize_render_failed( array $queue, array $render_failed, array $generated ) {
		$max   = $this->max_failures();
		$retry = array();
		foreach ( $render_failed as $post_id => $count ) {
			if ( (int) $count > 0 && (int) $count < $max ) {
				$retry[ (int) $post_id ] = true;
			}
		}
		if ( empty( $retry ) ) {
			return $queue;
		}
		$queue = array_map( 'intval', $queue );
		$head  = array();
		foreach ( $queue as $post_id ) {
			if ( isset( $generated[ $post_id ] ) || isset( $retry[ $post_id ] ) ) {
				break;
			}
			$head[] = $post_id;
		}
		$rest  = array_slice( $queue, count( $head ) );
		$moved = array_values(
			array_filter(
				$rest,
				static function ( $post_id ) use ( $retry ) {
					return isset( $retry[ $post_id ] );
				}
			)
		);
		if ( empty( $moved ) ) {
			return $queue;
		}
		return array_merge( $head, $moved, array_values( array_diff( $rest, $moved ) ) );
	}

	/**
	 * Ids whose failure count reached the threshold.
	 *
	 * @param array<string, mixed> $state State.
	 * @return int[]
	 */
	private function exhausted_ids( array $state ) {
		$max = $this->max_failures();
		$ids = array();
		foreach ( (array) $state['failed'] as $post_id => $count ) {
			if ( (int) $count >= $max ) {
				$ids[] = (int) $post_id;
			}
		}
		return $ids;
	}

	/**
	 * Failures after which an item is retried only at the end of the queue.
	 *
	 * @return int
	 */
	private function max_failures() {
		/**
		 * Filters the number of consecutive failures after which an item stops being prioritized.
		 *
		 * @param int $max Failures. Default 3.
		 */
		return max( 1, (int) apply_filters( 'wpasl_max_failures', 3 ) );
	}

	/**
	 * Surfaces a generation failure: action for integrators and a line in the PHP error log.
	 *
	 * @param \WP_Post $post     Post.
	 * @param string   $reason   Reason (no_generator, empty_document, storage_write).
	 * @param int      $attempts Consecutive failures so far.
	 * @return void
	 */
	private function report_failure( \WP_Post $post, $reason, $attempts ) {
		/**
		 * Fires when the document of an item could not be generated or stored.
		 *
		 * @param \WP_Post $post     Post.
		 * @param string   $reason   Reason: no_generator, empty_document or storage_write.
		 * @param int      $attempts Consecutive failures so far.
		 */
		do_action( 'wpasl_generation_failed', $post, $reason, $attempts );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational failure that must reach the server log.
		error_log( sprintf( '[wp-agent-support-layer] Could not generate the Markdown document of %s #%d (%s), attempt %d.', $post->post_type, $post->ID, $reason, $attempts ) );
	}

	/**
	 * Surfaces a rendered-page failure: action for integrators and a line in the PHP error log.
	 *
	 * @param \WP_Post $post     Post.
	 * @param string   $reason   Reason (external_host, request_error:<code>, http_<status>, redirect_external_host,
	 *                           redirect_loop, not_html, empty_body, no_content).
	 * @param int      $attempts Consecutive rendered-page failures so far.
	 * @return void
	 */
	private function report_render_failure( \WP_Post $post, $reason, $attempts ) {
		/**
		 * Fires when the rendered page of an item could not be fetched or had no content region, and its
		 * document was built from the editor content instead.
		 *
		 * @param \WP_Post $post     Post.
		 * @param string   $reason   Reason: external_host, request_error:<code>, http_<status>, redirect_external_host,
		 *                           redirect_loop, not_html, empty_body or no_content.
		 * @param int      $attempts Consecutive rendered-page failures so far.
		 */
		do_action( 'wpasl_render_failed', $post, $reason, $attempts );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational failure that must reach the server log.
		error_log( sprintf( '[wp-agent-support-layer] Could not fetch the rendered page of %s #%d (%s), attempt %d; the editor content was used.', $post->post_type, $post->ID, $reason, $attempts ) );
	}

	/**
	 * Eligible ids that were never generated and are not queued yet.
	 *
	 * @param array<string, mixed> $state State.
	 * @return int[]
	 */
	private function never_generated_ids( array $state ) {
		if ( null === $this->item_generator ) {
			return array();
		}
		$known = array_fill_keys( array_map( 'intval', $state['queue'] ), true ) + array_fill_keys( array_keys( $state['generated'] ), true );
		$fresh = array();
		foreach ( $this->eligibility->eligible_ids( $this->cycle_post_types ) as $post_id ) {
			if ( ! isset( $known[ $post_id ] ) ) {
				$fresh[] = $post_id;
			}
		}
		return $fresh;
	}

	/**
	 * Whether the discovery files are older than the configured interval (or were never generated), so a
	 * long cycle on a large site never leaves them stale for more than one interval.
	 *
	 * @param array<string, mixed> $state State.
	 * @return bool
	 */
	private function artifacts_are_stale( array $state ) {
		$last = max( (int) $state['last_cycle_completed'], (int) $state['last_artifacts_regenerated'] );
		return ( time() - $last ) > $this->interval_seconds();
	}

	/**
	 * Configured regeneration interval in seconds.
	 *
	 * @return int
	 */
	private function interval_seconds() {
		$schedules = wp_get_schedules();
		$schedule  = (string) $this->settings->get( 'schedule' );
		return isset( $schedules[ $schedule ] ) ? (int) $schedules[ $schedule ]['interval'] : DAY_IN_SECONDS;
	}

	/**
	 * Generates and writes one document.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private function write_document( \WP_Post $post ) {
		$this->last_error = '';
		$this->deferred   = false;
		if ( null === $this->item_generator ) {
			$this->last_error = 'no_generator';
			return false;
		}
		$document = $this->item_generator->generate( $post );
		if ( ! is_string( $document ) ) {
			$this->last_error = $this->deferred ? 'deferred' : 'empty_document';
			return false;
		}
		if ( ! $this->storage->write( self::document_path( $post->post_type, $post->ID ), $document ) ) {
			$this->last_error = 'storage_write';
			return false;
		}
		return true;
	}

	/**
	 * Deletes a post's document and forgets it.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	private function remove_document( \WP_Post $post ) {
		$relative = self::document_path( $post->post_type, $post->ID );
		if ( $this->storage->exists( $relative ) ) {
			$this->storage->delete( $relative );
		} elseif ( ! in_array( $post->post_type, $this->settings->enabled_post_types(), true ) ) {
			// Nothing stored and nothing tracked for this type: do not rewrite the state option.
			return;
		}
		$this->state->forget( $post->ID );
	}
}
