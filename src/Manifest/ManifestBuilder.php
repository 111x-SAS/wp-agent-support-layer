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
		// Plain permalinks: rest_url() is the site URL plus "?rest_route=/". A Server Object URL must not carry a
		// query string, so the server is the site URL and every path key carries the "/?rest_route=" prefix.
		$plain = false !== strpos( $rest_url, '?' );

		foreach ( $this->registry->all() as $capability ) {
			$url = isset( $capability['url'] ) ? $capability['url'] : $capability['urlTemplate'];
			if ( 0 !== strpos( $url, $rest_url ) || 'GET' !== $capability['method'] ) {
				continue;
			}
			$route = substr( $url, strlen( $rest_url ) );
			if ( $plain ) {
				$route = (string) strtok( $route, '&' );
			}
			$path = '/' . ltrim( $route, '/' );
			$path = (string) wp_parse_url( $path, PHP_URL_PATH );
			if ( $plain ) {
				$path = '/?rest_route=' . $path;
			}

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
						'200'     => array(
							'description' => __( 'Successful response.', 'wp-agent-support-layer' ),
							'content'     => array(
								$capability['responseType'] => array( 'schema' => $this->schema_for( $path ) ),
							),
						),
						'400'     => self::error_response( __( 'Invalid parameter.', 'wp-agent-support-layer' ) ),
						'404'     => self::error_response( __( 'Not found.', 'wp-agent-support-layer' ) ),
						'default' => self::error_response( __( 'Error response of the WordPress REST API.', 'wp-agent-support-layer' ) ),
					),
				),
			);
		}//end foreach

		$document = array(
			'openapi'    => '3.1.0',
			'info'       => array(
				'title'       => sprintf(
					/* translators: %s: site name. */
					__( '%s public API', 'wp-agent-support-layer' ),
					DocumentBuilder::plain_text( get_bloginfo( 'name' ) )
				),
				'description' => __( 'Read-only endpoints of the WordPress REST API that agents may use without authentication.', 'wp-agent-support-layer' )
					. ' ' . self::versioning_policy( self::namespaces_of( array_keys( $paths ) ) ),
				'version'     => WPASL_VERSION,
				'contact'     => array( 'email' => $this->settings->contact_email() ),
			),
			'servers'    => array( array( 'url' => $plain ? untrailingslashit( home_url() ) : untrailingslashit( $rest_url ) ) ),
			'paths'      => $paths,
			'components' => array(
				'schemas' => array( 'Error' => self::error_schema() ),
			),
		);

		/**
		 * Filters the OpenAPI document.
		 *
		 * @param array<string, mixed> $document Document.
		 */
		return (array) apply_filters( 'wpasl_openapi', $document );
	}

	/**
	 * Versioned REST namespaces ("wp/v2", "wpasl/v1") found in a list of REST paths or URLs, sorted and unique.
	 * Accepts OpenAPI path keys with pretty permalinks ("/wp/v2/posts"), with plain permalinks
	 * ("/?rest_route=/wp/v2/posts") and absolute REST URLs.
	 *
	 * @param string[] $paths Paths or URLs.
	 * @return string[]
	 */
	public static function namespaces_of( array $paths ) {
		$namespaces = array();
		foreach ( $paths as $path ) {
			if ( preg_match( '#/(?:\?rest_route=/)?([a-z0-9_-]+/v\d+)/#i', (string) $path, $m ) ) {
				$namespaces[] = $m[1];
			}
		}
		$namespaces = array_values( array_unique( $namespaces ) );
		sort( $namespaces );
		return $namespaces;
	}

	/**
	 * The API versioning and deprecation policy, shared by the OpenAPI description and auth.md. Written in
	 * English on purpose and not translated: its readers are agents and scanners looking for these terms.
	 * It states only what the site really does: versioned namespaces, release cycles, no Deprecation or
	 * Sunset headers, incompatible changes announced in the changelog and the WordPress release notes.
	 *
	 * @param string[] $namespaces Versioned namespaces that actually appear in the routes.
	 * @return string
	 */
	public static function versioning_policy( array $namespaces ) {
		$namespaces = array_values( array_filter( array_map( 'strval', $namespaces ) ) );
		$list       = empty( $namespaces ) ? 'wp/v2' : implode( ', ', $namespaces );
		return 'This API is the WordPress REST API. Routes are grouped in versioned namespaces (' . $list . '). '
			. 'Changes follow the release cycles of WordPress core and of the WP Agent Support Layer plugin. '
			. 'This site does not send Deprecation or Sunset headers. '
			. 'Backwards-incompatible changes to the wpasl/v1 routes are announced in the plugin changelog; '
			. 'changes to core routes follow the WordPress release notes.';
	}

	/**
	 * Schema of the error body of the WordPress REST API (WP_Error as serialized by WP_REST_Server): the
	 * site does not emit RFC 9457 problem details, so the document does not declare them.
	 *
	 * @return array<string, mixed>
	 */
	public static function error_schema() {
		return array(
			'type'                 => 'object',
			'required'             => array( 'code', 'message' ),
			'properties'           => array(
				'code'    => array(
					'type'        => 'string',
					'description' => __( 'Machine-readable error code, e.g. rest_no_route or rest_post_invalid_id.', 'wp-agent-support-layer' ),
				),
				'message' => array(
					'type'        => 'string',
					'description' => __( 'Human-readable message.', 'wp-agent-support-layer' ),
				),
				'data'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'status' => array(
							'type'        => 'integer',
							'description' => __( 'HTTP status code.', 'wp-agent-support-layer' ),
						),
					),
					'additionalProperties' => true,
				),
			),
			'additionalProperties' => true,
		);
	}

	/**
	 * A Response Object whose JSON body follows the Error schema.
	 *
	 * @param string $description Description.
	 * @return array<string, mixed>
	 */
	private static function error_response( $description ) {
		return array(
			'description' => $description,
			'content'     => array(
				'application/json' => array(
					'schema' => array( '$ref' => '#/components/schemas/Error' ),
				),
			),
		);
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
	 * The RFC 9727 API catalog (linkset): linkset[0] is the catalog entry (anchor = the catalog URL, "item"
	 * = the REST API base), linkset[1] the API entry (anchor = the REST API base, "service-desc" and
	 * "service-doc").
	 *
	 * @return array<string, mixed>
	 */
	public function api_catalog() {
		$docs = array(
			array(
				'href' => home_url( '/llms.txt' ),
				'type' => 'text/markdown',
			),
		);
		if ( AuthMdBuilder::is_published( $this->settings ) ) {
			$docs[] = array(
				'href' => AuthMdBuilder::url(),
				'type' => 'text/markdown',
			);
		}
		$docs[] = array(
			'href' => home_url( '/agent-skills.json' ),
			'type' => 'application/ld+json',
		);

		$api = untrailingslashit( rest_url() );
		// RFC 9727 appendix A.2: the entry anchored at the catalog lists the APIs with "item" (RFC 6573);
		// appendix A.1: the entry anchored at each API carries its description and documentation. The site
		// exposes one API, the WordPress REST API; a capability with another base added by filter does not
		// create another entry (use wpasl_api_catalog for that).
		$document = array(
			'linkset' => array(
				array(
					'anchor' => home_url( '/.well-known/api-catalog' ),
					'item'   => array(
						array( 'href' => $api ),
					),
				),
				array(
					'anchor'       => $api,
					'service-desc' => array(
						array(
							'href' => self::openapi_url(),
							'type' => 'application/openapi+json',
						),
					),
					'service-doc'  => $docs,
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
