<?php
/**
 * Serves /llms.txt and /llms-full.txt.
 *
 * @package WPASL
 */

namespace WPASL\Llms;

use WPASL\Generation\Scheduler;
use WPASL\Http;
use WPASL\Admin\Page;
use WPASL\Admin\Tabs\LlmsTab;
use WPASL\Markdown\Delivery;
use WPASL\Settings;
use WPASL\Storage;

/**
 * Root-level routes for the llms.txt files, with physical-file precedence and lazy generation.
 */
final class LlmsTxtRouter {

	/**
	 * Seconds suggested to clients while llms-full.txt is being built.
	 */
	const RETRY_AFTER = 120;

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
	 * Builder.
	 *
	 * @var LlmsTxtBuilder
	 */
	private $builder;

	/**
	 * Delivery (for cache lifetime).
	 *
	 * @var Delivery
	 */
	private $delivery;

	/**
	 * Scheduler (background build of llms-full.txt).
	 *
	 * @var Scheduler|null
	 */
	private $scheduler;

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings  Settings.
	 * @param Storage        $storage   Storage.
	 * @param LlmsTxtBuilder $builder   Builder.
	 * @param Delivery       $delivery  Delivery.
	 * @param Scheduler|null $scheduler Scheduler.
	 */
	public function __construct( Settings $settings, Storage $storage, LlmsTxtBuilder $builder, Delivery $delivery, ?Scheduler $scheduler = null ) {
		$this->settings  = $settings;
		$this->storage   = $storage;
		$this->builder   = $builder;
		$this->delivery  = $delivery;
		$this->scheduler = $scheduler;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'parse_request', array( $this, 'handle_request' ), 1 );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'invalidate' ) );
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'invalidate' ) );
		add_action( 'wpasl_register_tabs', array( $this, 'register_tab' ) );
		add_action( Scheduler::LLMS_FULL_HOOK, array( $this, 'build_full_file' ) );
	}

	/**
	 * Builds llms-full.txt in the background (one-off cron event).
	 *
	 * @return void
	 */
	public function build_full_file() {
		$this->storage->ensure();
		$this->builder->generate_file( $this->storage, LlmsTxtBuilder::FULL_FILE );
	}

	/**
	 * Adds the llms.txt tab.
	 *
	 * @param Page $page Settings page.
	 * @return void
	 */
	public function register_tab( Page $page ) {
		$page->add_tab( new LlmsTab( $this->settings ) );
	}

	/**
	 * Path of a physical llms.txt in the site root, filterable for tests.
	 *
	 * @return string
	 */
	public static function physical_path() {
		/**
		 * Filters the path checked for a physical llms.txt.
		 *
		 * @param string $path Absolute path.
		 */
		return (string) apply_filters( 'wpasl_physical_llms_path', ABSPATH . LlmsTxtBuilder::FILE );
	}

	/**
	 * Whether a physical llms.txt exists.
	 *
	 * @return bool
	 */
	public static function physical_file_exists() {
		return is_file( self::physical_path() );
	}

	/**
	 * Deletes the stored files so they are rebuilt on the next request or run.
	 *
	 * @return void
	 */
	public function invalidate() {
		$this->settings->flush_cache();
		$this->storage->delete( LlmsTxtBuilder::FILE );
		$this->storage->delete( LlmsTxtBuilder::FULL_FILE );
	}

	/**
	 * Which file a request path targets: "llms.txt", "llms-full.txt" or null.
	 *
	 * @param string $request_uri Request URI.
	 * @return string|null
	 */
	public static function requested_file( $request_uri ) {
		$path      = (string) wp_parse_url( (string) $request_uri, PHP_URL_PATH );
		$base_path = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base_path && 0 === strpos( $path, $base_path ) ) {
			$path = substr( $path, strlen( $base_path ) );
		}
		$path = trim( $path, '/' );
		return in_array( $path, array( LlmsTxtBuilder::FILE, LlmsTxtBuilder::FULL_FILE ), true ) ? $path : null;
	}

	/**
	 * Serves the requested file before WordPress parses the query.
	 *
	 * @param \WP $wp WordPress environment.
	 * @return void
	 */
	public function handle_request( $wp ) {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed by requested_file().
		$file        = self::requested_file( $request_uri );
		if ( null === $file ) {
			return;
		}
		if ( LlmsTxtBuilder::FILE === $file && self::physical_file_exists() ) {
			return;
		}
		if ( LlmsTxtBuilder::FULL_FILE === $file && ! $this->builder->full_enabled() ) {
			$wp->query_vars = array( 'error' => '404' );
			return;
		}
		$this->serve( $file );
	}

	/**
	 * Returns a file's contents. A missing llms.txt is generated on the spot; a missing llms-full.txt is
	 * not (it converts every item document), so null is returned and the caller answers 503.
	 *
	 * @param string $file "llms.txt" or "llms-full.txt".
	 * @return string|null
	 */
	public function document( $file ) {
		$document = $this->storage->read( $file );
		if ( null === $document && LlmsTxtBuilder::FILE === $file ) {
			$this->storage->ensure();
			$this->builder->generate_file( $this->storage, $file );
			$document = $this->storage->read( $file );
		}
		return $document;
	}

	/**
	 * Response headers.
	 *
	 * @param string $document Document.
	 * @return array<string, string>
	 */
	public function headers( $document ) {
		return array(
			'Content-Type'           => 'text/markdown; charset=utf-8',
			'X-Markdown-Tokens'      => (string) (int) ceil( strlen( $document ) / 4 ),
			'Cache-Control'          => 'public, max-age=' . $this->delivery->max_age(),
			'X-Content-Type-Options' => 'nosniff',
		);
	}

	/**
	 * Sends the file and ends the request.
	 *
	 * @param string $file File name.
	 * @return bool
	 */
	public function serve( $file ) {
		$document = $this->document( $file );
		if ( null === $document ) {
			if ( LlmsTxtBuilder::FULL_FILE === $file ) {
				$this->serve_unavailable();
			}
			return false;
		}

		/** This action is documented in src/Markdown/Delivery.php */
		do_action( 'wpasl_before_serve', 'llms-txt', null );

		if ( ! headers_sent() ) {
			status_header( 200 );
		}
		foreach ( $this->headers( $document ) as $name => $value ) {
			Http::send_header( $name, $value );
		}

		echo $document; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text/markdown body.

		/** This filter is documented in src/Markdown/Delivery.php */
		if ( apply_filters( 'wpasl_terminate_after_serve', true ) ) {
			exit;
		}
		return true;
	}

	/**
	 * Answers 503 with Retry-After for a missing llms-full.txt and schedules its background build.
	 *
	 * @return void
	 */
	private function serve_unavailable() {
		/** This action is documented in src/Markdown/Delivery.php */
		do_action( 'wpasl_before_serve', 'llms-txt', null );

		status_header( 503 );
		// Checks headers_sent() itself; the filter still records the code.
		Http::send_header( 'Content-Type', 'text/plain; charset=utf-8' );
		Http::send_header( 'Retry-After', (string) self::RETRY_AFTER );
		Http::send_header( 'Cache-Control', 'no-store' );
		Http::send_header( 'X-Content-Type-Options', 'nosniff' );

		echo esc_html__( 'llms-full.txt is being generated in the background. Retry in a few minutes.', 'wp-agent-support-layer' ), "\n";

		if ( $this->scheduler ) {
			$this->scheduler->schedule_llms_full();
		}

		/** This filter is documented in src/Markdown/Delivery.php */
		if ( apply_filters( 'wpasl_terminate_after_serve', true ) ) {
			exit;
		}
	}
}
