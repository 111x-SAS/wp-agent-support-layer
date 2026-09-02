<?php
/**
 * Serves agent-skills.json, the OpenAPI document and the API catalog.
 *
 * @package WPASL
 */

namespace WPASL\Manifest;

use WPASL\Http;
use WPASL\Admin\Page;
use WPASL\Admin\Tabs\ManifestsTab;
use WPASL\Markdown\Delivery;
use WPASL\Settings;
use WPASL\Storage;

/**
 * Root routes for /agent-skills.json and /.well-known/api-catalog, plus the wpasl/v1/openapi REST route.
 */
final class ManifestRouter {

	const SKILLS_PATH  = 'agent-skills.json';
	const CATALOG_PATH = '.well-known/api-catalog';

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
	 * @var ManifestBuilder
	 */
	private $builder;

	/**
	 * Delivery (cache lifetime).
	 *
	 * @var Delivery
	 */
	private $delivery;

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings Settings.
	 * @param Storage         $storage  Storage.
	 * @param ManifestBuilder $builder  Builder.
	 * @param Delivery        $delivery Delivery.
	 */
	public function __construct( Settings $settings, Storage $storage, ManifestBuilder $builder, Delivery $delivery ) {
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
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'invalidate' ) );
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'invalidate' ) );
		add_action( 'wpasl_register_tabs', array( $this, 'register_tab' ) );
	}

	/**
	 * Adds the Manifests tab.
	 *
	 * @param Page $page Settings page.
	 * @return void
	 */
	public function register_tab( Page $page ) {
		$page->add_tab( new ManifestsTab( $this->settings ) );
	}

	/**
	 * Deletes the stored documents so they are rebuilt on the next request or run.
	 *
	 * @return void
	 */
	public function invalidate() {
		$this->settings->flush_cache();
		$this->storage->delete( ManifestBuilder::SKILLS_FILE );
		$this->storage->delete( ManifestBuilder::OPENAPI_FILE );
		$this->storage->delete( ManifestBuilder::CATALOG_FILE );
	}

	/**
	 * Registers GET wpasl/v1/openapi.
	 *
	 * @return void
	 */
	public function register_rest_route() {
		register_rest_route(
			'wpasl/v1',
			'/openapi',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_openapi' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST callback for the OpenAPI document.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_openapi() {
		if ( ! $this->builder->enabled() ) {
			return new \WP_Error( 'wpasl_manifest_disabled', __( 'The agent manifests are disabled.', 'wp-agent-support-layer' ), array( 'status' => 404 ) );
		}
		$json = $this->document( ManifestBuilder::OPENAPI_FILE );
		$data = null === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			$data = $this->builder->openapi();
		}
		$response = new \WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'public, max-age=' . $this->delivery->max_age() );
		return $response;
	}

	/**
	 * Which root document a request path targets.
	 *
	 * @param string $request_uri Request URI.
	 * @return string|null "agent-skills.json", ".well-known/api-catalog" or null.
	 */
	public static function requested_path( $request_uri ) {
		$path      = (string) wp_parse_url( (string) $request_uri, PHP_URL_PATH );
		$base_path = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base_path && 0 === strpos( $path, $base_path ) ) {
			$path = substr( $path, strlen( $base_path ) );
		}
		$path = trim( $path, '/' );
		return in_array( $path, array( self::SKILLS_PATH, self::CATALOG_PATH ), true ) ? $path : null;
	}

	/**
	 * Serves the root documents before WordPress parses the query.
	 *
	 * @param \WP $wp WordPress environment.
	 * @return void
	 */
	public function handle_request( $wp ) {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed by requested_path().
		$path        = self::requested_path( $request_uri );
		if ( null === $path ) {
			return;
		}
		if ( ! $this->builder->enabled() ) {
			$wp->query_vars = array( 'error' => '404' );
			return;
		}
		$this->serve( $path );
	}

	/**
	 * Returns a stored document, generating all of them when missing.
	 *
	 * @param string $file Storage file name.
	 * @return string|null
	 */
	public function document( $file ) {
		$json = $this->storage->read( $file );
		if ( null === $json ) {
			$this->storage->ensure();
			$this->builder->generate( $this->storage );
			$json = $this->storage->read( $file );
		}
		return $json;
	}

	/**
	 * Headers for a root document.
	 *
	 * @param string $path Requested path.
	 * @return array<string, string>
	 */
	public function headers( $path ) {
		return array(
			'Content-Type'                => ( self::CATALOG_PATH === $path ? 'application/linkset+json' : 'application/ld+json' ) . '; charset=utf-8',
			'Cache-Control'               => 'public, max-age=' . $this->delivery->max_age(),
			'Access-Control-Allow-Origin' => '*',
			'X-Content-Type-Options'      => 'nosniff',
		);
	}

	/**
	 * Sends a root document and ends the request.
	 *
	 * @param string $path Requested path.
	 * @return bool
	 */
	public function serve( $path ) {
		$file     = self::CATALOG_PATH === $path ? ManifestBuilder::CATALOG_FILE : ManifestBuilder::SKILLS_FILE;
		$document = $this->document( $file );
		if ( null === $document ) {
			return false;
		}

		/** This action is documented in src/Markdown/Delivery.php */
		do_action( 'wpasl_before_serve', 'manifest', null );

		if ( ! headers_sent() ) {
			status_header( 200 );
		}
		foreach ( $this->headers( $path ) as $name => $value ) {
			Http::send_header( $name, $value );
		}

		echo $document; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON body.

		/** This filter is documented in src/Markdown/Delivery.php */
		if ( apply_filters( 'wpasl_terminate_after_serve', true ) ) {
			exit;
		}
		return true;
	}
}
