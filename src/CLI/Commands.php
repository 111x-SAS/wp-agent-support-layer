<?php
/**
 * WP-CLI commands.
 *
 * @package WPASL
 */

namespace WPASL\CLI;

use WPASL\Generation\Runner;
use WPASL\Generation\Scheduler;

/**
 * Operates the agent layer: wp wpasl generate|status|clear.
 */
final class Commands {

	/**
	 * Runner.
	 *
	 * @var Runner
	 */
	private $runner;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Constructor.
	 *
	 * @param Runner    $runner    Runner.
	 * @param Scheduler $scheduler Scheduler.
	 */
	public function __construct( Runner $runner, Scheduler $scheduler ) {
		$this->runner    = $runner;
		$this->scheduler = $scheduler;
	}

	/**
	 * Generates Markdown documents and discovery files.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Regenerate every eligible item, not only the pending ones.
	 *
	 * [--post-type=<type>]
	 * : Only process this post type.
	 *
	 * [--batch]
	 * : Process a single batch (same as one scheduled run) instead of a full cycle.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpasl generate
	 *     wp wpasl generate --all
	 *     wp wpasl generate --post-type=page
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 * @return void
	 */
	public function generate( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['all'] ) ) {
			$this->runner->reset_cycle();
		}

		if ( ! empty( $assoc_args['batch'] ) ) {
			$result = $this->runner->run();
			\WP_CLI::success( sprintf( 'Processed %d item(s); %d remaining in this cycle.', $result['processed'], $result['remaining'] ) );
			return;
		}

		$types = empty( $assoc_args['post-type'] ) ? null : array( sanitize_key( (string) $assoc_args['post-type'] ) );
		$total = $this->runner->run_cycle( $types );
		\WP_CLI::success( sprintf( 'Processed %d item(s) and regenerated the discovery files.', $total ) );
	}

	/**
	 * Shows generation status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		$status = $this->runner->status();
		$next   = $this->scheduler->next_run();
		$rows   = array(
			array( 'key' => 'eligible', 'value' => $status['eligible'] ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'key' => 'generated', 'value' => $status['generated'] ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'key' => 'pending', 'value' => $status['pending'] ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'key' => 'queued', 'value' => $status['queued'] ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'key' => 'last_run', 'value' => $status['last_run'] ? gmdate( 'c', $status['last_run'] ) : 'never' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'key' => 'last_cycle_completed', 'value' => $status['last_cycle_completed'] ? gmdate( 'c', $status['last_cycle_completed'] ) : 'never' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'key' => 'next_run', 'value' => null === $next ? 'not scheduled' : gmdate( 'c', $next ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'key' => 'schedule', 'value' => (string) $this->scheduler->current_interval() ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'key' => 'artifacts', 'value' => implode( ',', $status['artifacts'] ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		\WP_CLI\Utils\format_items( $format, $rows, array( 'key', 'value' ) );
	}

	/**
	 * Deletes every generated file and resets the generation state.
	 *
	 * @return void
	 */
	public function clear() {
		$this->runner->clear();
		\WP_CLI::success( 'Storage cleared. All items are pending again.' );
	}
}
