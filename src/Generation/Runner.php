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
		add_action( Scheduler::HOOK, array( $this, 'run' ) );
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'on_deleted_post' ), 10, 2 );
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
	 * @param int|null   $limit  Max items; defaults to the batch size setting.
	 * @param float|null $budget Max seconds; defaults to 20 (filterable).
	 * @return array{processed:int, remaining:int, cycle_completed:bool}
	 */
	public function run( $limit = null, $budget = null ) {
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
		$state = $this->state->load();

		if ( empty( $state['queue'] ) ) {
			$state['queue']         = $this->build_queue( $state['generated'] );
			$state['cycle_started'] = time();
		}

		$started   = microtime( true );
		$processed = 0;

		while ( ! empty( $state['queue'] ) && $processed < $limit ) {
			$post_id = (int) array_shift( $state['queue'] );
			$post    = get_post( $post_id );
			if ( $post && $this->eligibility->is_eligible( $post ) && $this->write_document( $post ) ) {
				$state['generated'][ $post_id ] = time();
			}
			++$processed;

			if ( microtime( true ) - $started >= $budget ) {
				break;
			}
		}

		$cycle_completed = empty( $state['queue'] );
		if ( $cycle_completed ) {
			$state['generated']            = $this->prune( $state['generated'] );
			$state['last_cycle_completed'] = time();
			$state['artifacts_generated']  = $this->regenerate_artifacts();
		}

		$state['last_run']       = time();
		$state['last_run_count'] = $processed;
		$this->state->save( $state );

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
			$state['queue'] = $this->build_queue( $state['generated'], $post_types );
			$this->state->save( $state );
		}

		$total = 0;
		do {
			$result = $this->run( PHP_INT_MAX, PHP_INT_MAX );
			$total += $result['processed'];
		} while ( ! $result['cycle_completed'] );

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
		$this->state->mark_generated( $post->ID );
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

		foreach ( $this->storage->list_files( 'md' ) as $relative ) {
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
	 * @return void
	 */
	public function reset_cycle() {
		$state              = $this->state->load();
		$state['queue']     = array();
		$state['generated'] = array();
		$this->state->save( $state );
	}

	/**
	 * Status summary for the admin page and WP-CLI.
	 *
	 * @return array<string, mixed>
	 */
	public function status() {
		$state    = $this->state->load();
		$eligible = $this->eligibility->eligible_ids();
		$stored   = 0;
		foreach ( $eligible as $post_id ) {
			if ( isset( $state['generated'][ $post_id ] ) ) {
				++$stored;
			}
		}

		return array(
			'eligible'             => count( $eligible ),
			'generated'            => $stored,
			'pending'              => count( $eligible ) - $stored,
			'queued'               => count( $state['queue'] ),
			'last_run'             => (int) $state['last_run'],
			'last_run_count'       => (int) $state['last_run_count'],
			'last_cycle_completed' => (int) $state['last_cycle_completed'],
			'artifacts'            => array_keys( $this->artifact_generators ),
			'has_item_generator'   => null !== $this->item_generator,
		);
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
	 * Builds the work queue: eligible ids, least recently generated first.
	 *
	 * @param array<int,int> $generated  Generation times by post id.
	 * @param string[]|null  $post_types Restrict to these post types.
	 * @return int[]
	 */
	private function build_queue( array $generated, $post_types = null ) {
		if ( null === $this->item_generator ) {
			return array();
		}
		$ids = $this->eligibility->eligible_ids( $post_types );
		usort(
			$ids,
			static function ( $a, $b ) use ( $generated ) {
				$ta = isset( $generated[ $a ] ) ? $generated[ $a ] : 0;
				$tb = isset( $generated[ $b ] ) ? $generated[ $b ] : 0;
				return $ta === $tb ? $a - $b : $ta - $tb;
			}
		);
		return $ids;
	}

	/**
	 * Generates and writes one document.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private function write_document( \WP_Post $post ) {
		if ( null === $this->item_generator ) {
			return false;
		}
		$document = $this->item_generator->generate( $post );
		if ( ! is_string( $document ) ) {
			return false;
		}
		return $this->storage->write( self::document_path( $post->post_type, $post->ID ), $document );
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
		}
		$this->state->forget( $post->ID );
	}
}
