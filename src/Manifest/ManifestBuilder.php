<?php
/**
 * Builds agent-skills.json, the OpenAPI document and the API catalog.
 *
 * @package WPASL
 */

namespace WPASL\Manifest;

use WPASL\Generation\ArtifactGeneratorInterface;
use WPASL\Markdown\DocumentBuilder;
use WPASL\Settings;
use WPASL\Signals\ContentSignals;
use WPASL\Storage;

/**
 * Generates the three discovery documents from the capability registry and the REST server routes.
 */
final class ManifestBuilder implements ArtifactGeneratorInterface {

	const SKILLS_FILE  = 'agent-skills.json';
	const OPENAPI_FILE = 'openapi.json';
	const CATALOG_FILE = 'api-catalog.json';
	const VOCAB        = 'https://github.com/111x-SAS/wp-agent-support-layer/blob/main/docs/agent-skills.md#';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Registry.
	 *
	 * @var CapabilityRegistry
	 */
	private $registry;

	/**
	 * Signals.
	 *
	 * @var ContentSignals
	 */
	private $signals;

	/**
	 * Constructor.
	 *
	 * @param Settings           $settings Settings.
	 * @param CapabilityRegistry $registry Registry.
	 * @param ContentSignals     $signals  Signals.
	 */
	public function __construct( Settings $settings, CapabilityRegistry $registry, ContentSignals $signals ) {
		$this->settings = $settings;
		$this->registry = $registry;
		$this->signals  = $signals;
	}

	/**
	 * Artifact id.
	 *
	 * @return string
	 */
	public function id() {
		return 'manifests';
	}

	/**
	 * Whether the manifests are enabled.
	 *
	 * @return bool
	 */
	public function enabled() {
		return (bool) $this->settings->get( 'manifest_enabled' );
	}

	/**
	 * Writes the three documents, or removes them when disabled.
	 *
	 * @param Storage $storage Storage.
	 * @return void
	 */
	public function generate( Storage $storage ) {
		if ( ! $this->enabled() ) {
			$storage->delete( self::SKILLS_FILE );
			$storage->delete( self::OPENAPI_FILE );
			$storage->delete( self::CATALOG_FILE );
			return;
		}
		$storage->write( self::SKILLS_FILE, self::encode( $this->agent_skills() ) );
		$storage->write( self::OPENAPI_FILE, self::encode( $this->openapi() ) );
		$storage->write( self::CATALOG_FILE, self::encode( $this->api_catalog() ) );
	}

	/**
	 * Pretty JSON.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return string
	 */
	public static function encode( array $data ) {
		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	}

	/**
	 * URL of the OpenAPI document.
	 *
	 * @return string
	 */
	public static function openapi_url() {
		return rest_url( 'wpasl/v1/openapi' );
	}

	/**
	 * The agent-skills.json document (JSON-LD).
	 *
	 * @return array<string, mixed>
	 */
	public function agent_skills() {
		$site_name = DocumentBuilder::plain_text( get_bloginfo( 'name' ) );

		$document = array(
			'@context'        => array(
				'@vocab'          => 'https://schema.org/',
				'wpasl'           => self::VOCAB,
				'manifestVersion' => 'wpasl:manifestVersion',
				'generatedAt'     => array(
					'@id'   => 'wpasl:generatedAt',
					'@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
				),
				'contentSignals'  => 'wpasl:contentSignals',
				'capabilities'    => array(
					'@id'        => 'wpasl:capabilities',
					'@container' => '@list',
				),
				'id'              => 'wpasl:capabilityId',
				'method'          => 'wpasl:httpMethod',
				'authentication'  => 'wpasl:authentication',
				'responseType'    => 'wpasl:responseType',
				'urlTemplate'     => 'wpasl:urlTemplate',
				'parameters'      => array(
					'@id'        => 'wpasl:parameters',
					'@container' => '@list',
				),
				'in'              => 'wpasl:parameterLocation',
				'type'            => 'wpasl:parameterType',
				'required'        => 'wpasl:required',
			),
			'@type'           => 'WebSite',
			'@id'             => home_url( '/' ),
			'name'            => $site_name,
			'url'             => home_url( '/' ),
			'description'     => DocumentBuilder::plain_text( get_bloginfo( 'description' ) ),
			'inLanguage'      => get_bloginfo( 'language' ),
			'publisher'       => array(
				'@type' => 'Organization',
				'name'  => $site_name,
				'url'   => home_url( '/' ),
				'email' => $this->settings->contact_email(),
			),
			'manifestVersion' => '1.0',
			'generatedAt'     => gmdate( 'c' ),
			'contentSignals'  => $this->signals->values(),
			'capabilities'    => $this->registry->all(),
		);

		/**
		 * Filters the agent-skills.json document.
		 *
		 * @param array<string, mixed> $document Document.
		 */
		return (array) apply_filters( 'wpasl_agent_skills', $document );
	}

	/**
	 * The OpenAPI 3.1 document describing the public GET endpoints declared as capabilities.
	 *
	 * @return array<string, mixed>
	 */
	public function openapi() {
		$paths    = array();
		$rest_url = rest_url();

		foreach ( $this->registry->all() as $capability ) {
			$url = isset( $capability['url'] ) ? $capability['url'] : $capability['urlTemplate'];
			if ( 0 !== strpos( $url, $rest_url ) || 'GET' !== $capability['method'] ) {
				continue;
			}
			$path = '/' . ltrim( substr( $url, strlen( $rest_url ) ), '/' );
			$path = (string) wp_parse_url( $path, PHP_URL_PATH );

			$parameters = array();
			foreach ( $capability['parameters'] as $param ) {
				$parameters[] = array(
					'name'        => $param['name'],
					'in'          => $param['in'],
					'required'    => 'path' === $param['in'] ? true : (bool) $param['required'],
					'description' => $param['description'],
					'schema'      => array( 'type' => $param['type'] ),
				);
			}

			$paths[ $path ] = array(
				'get' => array(
					'operationId' => $capability['id'],
					'summary'     => $capability['name'],
					'description' => $capability['description'],
					'parameters'  => $parameters,
					'security'    => array(),
					'responses'   => array(
						'200' => array(
							'description' => __( 'Successful response.', 'wp-agent-support-layer' ),
							'content'     => array(
								$capability['responseType'] => array( 'schema' => $this->schema_for( $path ) ),
							),
						),
						'404' => array( 'description' => __( 'Not found.', 'wp-agent-support-layer' ) ),
					),
				),
			);
		}//end foreach

		$document = array(
			'openapi' => '3.1.0',
			'info'    => array(
				'title'       => sprintf(
					/* translators: %s: site name. */
					__( '%s public API', 'wp-agent-support-layer' ),
					DocumentBuilder::plain_text( get_bloginfo( 'name' ) )
				),
				'description' => __( 'Read-only endpoints of the WordPress REST API that agents may use without authentication.', 'wp-agent-support-layer' ),
				'version'     => WPASL_VERSION,
				'contact'     => array( 'email' => $this->settings->contact_email() ),
			),
			'servers' => array( array( 'url' => untrailingslashit( $rest_url ) ) ),
			'paths'   => $paths,
		);

		/**
		 * Filters the OpenAPI document.
		 *
		 * @param array<string, mixed> $document Document.
		 */
		return (array) apply_filters( 'wpasl_openapi', $document );
	}

	/**
	 * Response schema for a REST path, taken from the REST server when available.
	 *
	 * @param string $path REST path with {placeholders}.
	 * @return array<string, mixed>
	 */
	private function schema_for( $path ) {
		$is_list    = false === strpos( $path, '{' );
		$server     = rest_get_server();
		$path_plain = preg_replace( '/\{[^}]+\}/', 'X', $path );

		foreach ( array_keys( $server->get_routes() ) as $route ) {
			$route_plain = preg_replace( '/\(\?P<\w+>.+?\)/', 'X', $route );
			if ( $route_plain !== $path_plain ) {
				continue;
			}
			$options = $server->get_route_options( $route );
			if ( ! empty( $options['schema'] ) && is_callable( $options['schema'] ) ) {
				$schema = call_user_func( $options['schema'] );
				if ( is_array( $schema ) ) {
					unset( $schema['$schema'] );
					return $is_list ? array(
						'type'  => 'array',
						'items' => $schema,
					) : $schema;
				}
			}
			break;
		}
		return $is_list ? array( 'type' => 'array' ) : array( 'type' => 'object' );
	}

	/**
	 * The RFC 9727 API catalog (linkset).
	 *
	 * @return array<string, mixed>
	 */
	public function api_catalog() {
		$document = array(
			'linkset' => array(
				array(
					'anchor'       => untrailingslashit( rest_url() ),
					'service-desc' => array(
						array(
							'href' => self::openapi_url(),
							'type' => 'application/openapi+json',
						),
					),
					'service-doc'  => array(
						array(
							'href' => home_url( '/llms.txt' ),
							'type' => 'text/markdown',
						),
						array(
							'href' => home_url( '/agent-skills.json' ),
							'type' => 'application/ld+json',
						),
					),
				),
			),
		);

		/**
		 * Filters the API catalog linkset.
		 *
		 * @param array<string, mixed> $document Document.
		 */
		return (array) apply_filters( 'wpasl_api_catalog', $document );
	}
}
