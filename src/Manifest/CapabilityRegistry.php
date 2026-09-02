<?php
/**
 * Capabilities an agent can use without authentication.
 *
 * @package WPASL
 */

namespace WPASL\Manifest;

use WPASL\Settings;

/**
 * Derives the capability list from what the site really exposes: REST read endpoints of enabled
 * post types, search, Markdown delivery, llms.txt and the OpenAPI document. Never declares
 * authenticated endpoints. Developers can add entries with the wpasl_agent_capabilities filter.
 */
final class CapabilityRegistry {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Enabled post types that are exposed in the REST API, keyed by post type => rest base.
	 *
	 * @return array<string, string>
	 */
	public function rest_post_types() {
		$bases = array();
		foreach ( $this->settings->enabled_post_types() as $type ) {
			$object = get_post_type_object( $type );
			if ( $object && ! empty( $object->show_in_rest ) ) {
				$bases[ $type ] = ! empty( $object->rest_base ) ? $object->rest_base : $object->name;
			}
		}
		return $bases;
	}

	/**
	 * All capabilities.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all() {
		$capabilities = array();

		$capabilities[] = $this->capability(
			'search-content',
			__( 'Search content', 'wp-agent-support-layer' ),
			__( 'Full-text search across public content. Returns titles, URLs and types.', 'wp-agent-support-layer' ),
			rest_url( 'wp/v2/search' ),
			array(
				$this->param( 'search', 'query', 'string', true, __( 'Search terms.', 'wp-agent-support-layer' ) ),
				$this->param( 'type', 'query', 'string', false, __( 'Restrict to "post" (content), "term" or "post-format".', 'wp-agent-support-layer' ) ),
				$this->param( 'subtype', 'query', 'string', false, __( 'Post type, e.g. "post" or "page".', 'wp-agent-support-layer' ) ),
				$this->param( 'per_page', 'query', 'integer', false, __( 'Results per page (1-100).', 'wp-agent-support-layer' ) ),
				$this->param( 'page', 'query', 'integer', false, __( 'Page number.', 'wp-agent-support-layer' ) ),
			)
		);

		foreach ( $this->rest_post_types() as $type => $base ) {
			$object = get_post_type_object( $type );
			$label  = $object ? $object->labels->name : $type;
			$single = $object ? $object->labels->singular_name : $type;

			$capabilities[] = $this->capability(
				'list-' . $type,
				/* translators: %s: post type plural label. */
				sprintf( __( 'List %s', 'wp-agent-support-layer' ), $label ),
				/* translators: %s: post type plural label. */
				sprintf( __( 'Lists published %s with title, excerpt, date and link.', 'wp-agent-support-layer' ), $label ),
				rest_url( 'wp/v2/' . $base ),
				array(
					$this->param( 'search', 'query', 'string', false, __( 'Search terms.', 'wp-agent-support-layer' ) ),
					$this->param( 'slug', 'query', 'string', false, __( 'Filter by slug.', 'wp-agent-support-layer' ) ),
					$this->param( 'per_page', 'query', 'integer', false, __( 'Results per page (1-100).', 'wp-agent-support-layer' ) ),
					$this->param( 'page', 'query', 'integer', false, __( 'Page number.', 'wp-agent-support-layer' ) ),
					$this->param( 'orderby', 'query', 'string', false, __( 'Sort field: date, title, modified, relevance.', 'wp-agent-support-layer' ) ),
					$this->param( 'order', 'query', 'string', false, __( '"asc" or "desc".', 'wp-agent-support-layer' ) ),
				)
			);

			$capabilities[] = $this->capability(
				'read-' . $type,
				/* translators: %s: post type singular label. */
				sprintf( __( 'Read a %s', 'wp-agent-support-layer' ), $single ),
				/* translators: %s: post type singular label. */
				sprintf( __( 'Returns one published %s as JSON with rendered content.', 'wp-agent-support-layer' ), $single ),
				rest_url( 'wp/v2/' . $base . '/{id}' ),
				array(
					$this->param( 'id', 'path', 'integer', true, __( 'Item id.', 'wp-agent-support-layer' ) ),
				),
				'application/json',
				true
			);
		}//end foreach

		$pretty         = '' !== (string) get_option( 'permalink_structure' );
		$capabilities[] = $this->capability(
			'read-markdown',
			__( 'Read content as Markdown', 'wp-agent-support-layer' ),
			$pretty
				? __( 'Any public item is available as Markdown by appending ".md" to its URL, or by requesting its URL with "Accept: text/markdown".', 'wp-agent-support-layer' )
				: __( 'Any public item is available as Markdown by adding "?wpasl=md" to its URL, or by requesting its URL with "Accept: text/markdown".', 'wp-agent-support-layer' ),
			// Reserved expansion (RFC 6570 {+var}): a hierarchical path keeps its slashes unencoded.
			$pretty ? home_url( '/{+path}.md' ) : home_url( '/?p={id}&wpasl=md' ),
			array(
				$pretty
					? $this->param( 'path', 'path', 'string', true, __( 'Path of the item, as in its canonical URL.', 'wp-agent-support-layer' ) )
					: $this->param( 'id', 'query', 'integer', true, __( 'Item id.', 'wp-agent-support-layer' ) ),
			),
			'text/markdown',
			true
		);

		$capabilities[] = $this->capability(
			'site-index',
			__( 'Site index (llms.txt)', 'wp-agent-support-layer' ),
			__( 'Curated Markdown index of the site with links to the Markdown version of each item.', 'wp-agent-support-layer' ),
			home_url( '/llms.txt' ),
			array(),
			'text/markdown'
		);

		$capabilities[] = $this->capability(
			'openapi',
			__( 'API description (OpenAPI 3.1)', 'wp-agent-support-layer' ),
			__( 'Machine-readable description of the public REST endpoints listed here.', 'wp-agent-support-layer' ),
			rest_url( 'wpasl/v1/openapi' ),
			array(),
			'application/json'
		);

		/**
		 * Filters the declared capabilities. Only public, unauthenticated actions belong here.
		 *
		 * @param array<int, array<string, mixed>> $capabilities Capabilities.
		 */
		$capabilities = apply_filters( 'wpasl_agent_capabilities', $capabilities );

		return array_values( array_filter( array_map( array( $this, 'normalize' ), (array) $capabilities ) ) );
	}

	/**
	 * Builds a capability entry.
	 *
	 * @param string                           $id           Identifier.
	 * @param string                           $name         Name.
	 * @param string                           $description  Description.
	 * @param string                           $url          URL or URL template.
	 * @param array<int, array<string, mixed>> $parameters   Parameters.
	 * @param string                           $response     Response media type.
	 * @param bool                             $is_template  Whether the URL contains {placeholders}.
	 * @return array<string, mixed>
	 */
	private function capability( $id, $name, $description, $url, array $parameters, $response = 'application/json', $is_template = false ) {
		$entry = array(
			'id'             => $id,
			'name'           => $name,
			'description'    => $description,
			'method'         => 'GET',
			'authentication' => 'none',
			'responseType'   => $response,
			'parameters'     => $parameters,
		);
		if ( $is_template ) {
			$entry['urlTemplate'] = $url;
		} else {
			$entry['url'] = $url;
		}
		return $entry;
	}

	/**
	 * Builds a parameter entry.
	 *
	 * @param string $name        Name.
	 * @param string $in          "query" or "path".
	 * @param string $type        JSON type.
	 * @param bool   $required    Whether required.
	 * @param string $description Description.
	 * @return array<string, mixed>
	 */
	private function param( $name, $in, $type, $required, $description ) {
		return array(
			'name'        => $name,
			'in'          => $in,
			'type'        => $type,
			'required'    => $required,
			'description' => $description,
		);
	}

	/**
	 * Validates and normalizes a (possibly filtered) capability. Drops entries that need auth or lack a URL.
	 *
	 * @param mixed $entry Entry.
	 * @return array<string, mixed>|null
	 */
	public function normalize( $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['id'] ) || ( empty( $entry['url'] ) && empty( $entry['urlTemplate'] ) ) ) {
			return null;
		}
		$auth = isset( $entry['authentication'] ) ? (string) $entry['authentication'] : 'none';
		if ( 'none' !== $auth ) {
			return null;
		}
		$clean = array(
			'id'             => sanitize_key( (string) $entry['id'] ),
			'name'           => isset( $entry['name'] ) ? (string) $entry['name'] : (string) $entry['id'],
			'description'    => isset( $entry['description'] ) ? (string) $entry['description'] : '',
			'method'         => isset( $entry['method'] ) ? strtoupper( (string) $entry['method'] ) : 'GET',
			'authentication' => 'none',
			'responseType'   => isset( $entry['responseType'] ) ? (string) $entry['responseType'] : 'application/json',
			'parameters'     => array(),
		);
		if ( ! empty( $entry['urlTemplate'] ) ) {
			$clean['urlTemplate'] = (string) $entry['urlTemplate'];
		} else {
			$clean['url'] = (string) $entry['url'];
		}
		foreach ( (array) ( isset( $entry['parameters'] ) ? $entry['parameters'] : array() ) as $param ) {
			if ( ! is_array( $param ) || empty( $param['name'] ) ) {
				continue;
			}
			$clean['parameters'][] = array(
				'name'        => (string) $param['name'],
				'in'          => isset( $param['in'] ) && 'path' === $param['in'] ? 'path' : 'query',
				'type'        => isset( $param['type'] ) ? (string) $param['type'] : 'string',
				'required'    => ! empty( $param['required'] ),
				'description' => isset( $param['description'] ) ? (string) $param['description'] : '',
			);
		}
		return $clean;
	}
}
