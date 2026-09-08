<?php
/**
 * Serves /auth.md.
 *
 * @package WPASL
 */

namespace WPASL\Manifest;

use WPASL\Http;
use WPASL\Markdown\Delivery;
use WPASL\Markdown\DocumentBuilder;
use WPASL\Settings;
use WPASL\Storage;

/**
 * Root-level route for auth.md, with physical-file precedence and lazy generation, like llms.txt.
 */
final class AuthMdRouter {

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
	 * @var AuthMdBuilder
	 */
	private $builder;

	/**
	 * Delivery (for cache lifetime).
	 *
	 * @var Delivery
	 */
	private $delivery;

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings Settings.
	 * @param Storage       $storage  Storage.
	 * @param AuthMdBuilder $builder  Builder.
	 * @param Delivery      $delivery Delivery.
	 */
	public function __construct( Settings $settings, Storage $storage, AuthMdBuilder $builder, Delivery $delivery ) {
		$this->settings = $settings;
		$this->storage  = $storage;
		$this->builder  = $builder;
		$this->delivery = $delivery;
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
	}

	/**
	 * Path of a physical auth.md in the site root, filterable for tests.
	 *
	 * @return string
	 */
	public static function physical_path() {
		/**
		 * Filters the path checked for a physical auth.md.
		 *
		 * @param string $path Absolute path.
		 */
		return (string) apply_filters( 'wpasl_physical_auth_md_path', ABSPATH . AuthMdBuilder::FILE );
	}

	/**
	 * Whether a physical auth.md exists.
	 *
	 * @return bool
	 */
	public static function physical_file_exists() {
		return is_file( self::physical_path() );
	}

	/**
	 * Deletes the stored document so it is rebuilt on the next request or run.
	 *
	 * @return void
	 */
	public function invalidate() {
		$this->settings->flush_cache();
		$this->storage->delete( AuthMdBuilder::FILE );
	}

	/**
	 * Whether a request targets exactly /auth.md (relative to the site root). The path is reserved for
	 * this document whether or not it is published: Delivery never resolves it to content.
	 *
	 * @param string $request_uri Request URI.
	 * @return bool
	 */
	public static function requested( $request_uri ) {
		$path      = (string) wp_parse_url( (string) $request_uri, PHP_URL_PATH );
		$base_path = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base_path ) {
			// On a site under /blog/ only /blog/auth.md is the document; /auth.md is outside the site.
			if ( 0 !== strpos( $path, $base_path . '/' ) ) {
				return false;
			}
			$path = substr( $path, strlen( $base_path ) );
		}
		return AuthMdBuilder::FILE === ManifestRouter::exact_relative_path( $path );
	}

	/**
	 * Serves auth.md before WordPress parses the query.
	 *
	 * @param \WP $wp WordPress environment.
	 * @return void
	 */
	public function handle_request( $wp ) {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed by requested().
		if ( ! self::requested( $request_uri ) ) {
			return;
		}
		if ( self::physical_file_exists() ) {
			return;
		}
		if ( ! $this->builder->enabled() ) {
			$wp->query_vars = array( 'error' => '404' );
			return;
		}
		$this->serve();
	}

	/**
	 * Returns the stored document, generating it on demand when missing.
	 *
	 * @return string|null
	 */
	public function document() {
		$document = $this->storage->read( AuthMdBuilder::FILE );
		if ( null === $document ) {
			$this->storage->ensure();
			$this->builder->generate( $this->storage );
			$document = $this->storage->read( AuthMdBuilder::FILE );
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
			'Content-Type'           => Delivery::MIME . '; charset=utf-8',
			'X-Markdown-Tokens'      => (string) DocumentBuilder::estimate_tokens( $document ),
			'Cache-Control'          => 'public, max-age=' . $this->delivery->max_age(),
			'X-Content-Type-Options' => 'nosniff',
		);
	}

	/**
	 * Sends the document and ends the request.
	 *
	 * @return bool
	 */
	public function serve() {
		$document = $this->document();
		if ( null === $document ) {
			return false;
		}

		/** This action is documented in src/Markdown/Delivery.php */
		do_action( 'wpasl_before_serve', 'auth-md', null );

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
}
