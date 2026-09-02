<?php
/**
 * WP-CLI commands.
 *
 * @package WPASL
 */

namespace WPASL\CLI;

use WPASL\Generation\Runner;
use WPASL\Generation\Scheduler;
use WPASL\Settings;

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
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Runner        $runner    Runner.
	 * @param Scheduler     $scheduler Scheduler.
	 * @param Settings|null $settings  Settings; a fresh instance when omitted.
	 */
	public function __construct( Runner $runner, Scheduler $scheduler, ?Settings $settings = null ) {
		$this->runner    = $runner;
		$this->scheduler = $scheduler;
		$this->settings  = null === $settings ? new Settings() : $settings;
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
		if ( ! $this->runner->status()['has_item_generator'] ) {
			\WP_CLI::error( __( 'The bundled HTML-to-Markdown library is missing: install a release build or run "composer run build" in the plugin directory.', 'wp-agent-support-layer' ) );
		}

		$types = null;
		if ( ! empty( $assoc_args['post-type'] ) ) {
			$type = sanitize_key( (string) $assoc_args['post-type'] );
			if ( ! in_array( $type, $this->settings->enabled_post_types(), true ) ) {
				/* translators: %s: post type. */
				\WP_CLI::error( sprintf( __( 'Post type "%s" is not enabled in the plugin settings (Tools > Agent Support Layer > General).', 'wp-agent-support-layer' ), $type ) );
			}
			$types = array( $type );
		}

		if ( ! empty( $assoc_args['all'] ) ) {
			$this->runner->reset_cycle();
		}

		if ( ! empty( $assoc_args['batch'] ) ) {
			$result = $this->runner->run();
			/* translators: 1: items processed, 2: items remaining. */
			\WP_CLI::success( sprintf( __( 'Processed %1$d item(s); %2$d remaining in this cycle.', 'wp-agent-support-layer' ), $result['processed'], $result['remaining'] ) );
			return;
		}

		$total = $this->runner->run_cycle( $types );
		/* translators: %d: items processed. */
		\WP_CLI::success( sprintf( __( 'Processed %d item(s) and regenerated the discovery files.', 'wp-agent-support-layer' ), $total ) );
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
			array( 'key' => 'last_run', 'value' => $status['last_run'] ? gmdate( 'c', $status['last_run'] ) : __( 'never', 'wp-agent-support-layer' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'key' => 'last_cycle_completed', 'value' => $status['last_cycle_completed'] ? gmdate( 'c', $status['last_cycle_completed'] ) : __( 'never', 'wp-agent-support-layer' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'key' => 'next_run', 'value' => null === $next ? __( 'not scheduled', 'wp-agent-support-layer' ) : gmdate( 'c', $next ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
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
		\WP_CLI::success( __( 'Storage cleared. All items are pending again.', 'wp-agent-support-layer' ) );
	}
}
