<?php
/**
 * Discovery links in the HTML head and the [wpasl_agent_links] shortcode.
 *
 * @package WPASL
 */

namespace WPASL\Manifest;

use WPASL\Settings;

/**
 * Announces the agent documents from every public HTML page: <link> elements in the head (IANA-registered
 * relations: service-desc, api-catalog, describedby, service-doc) and a shortcode that prints the same
 * links as a list for the theme footer. Nothing is emitted when the manifests are disabled.
 */
final class DiscoveryLinks {

	const SHORTCODE = 'wpasl_agent_links';

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
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Next to the Markdown alternate link of the content (Delivery, priority 5).
		add_action( 'wp_head', array( $this, 'print_links' ), 5 );
		add_action( 'init', array( $this, 'register_shortcode' ) );
	}

	/**
	 * Registers the shortcode.
	 *
	 * @return void
	 */
	public function register_shortcode() {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
	}

	/**
	 * The discovery links: OpenAPI (service-desc), API catalog (api-catalog), llms.txt (describedby) and,
	 * when published, auth.md (service-doc). Empty when the manifests are disabled.
	 *
	 * @return array<int, array{rel:string, type:string, href:string, label:string}>
	 */
	public function links() {
		if ( ! $this->settings->get( 'manifest_enabled' ) ) {
			return array();
		}
		$links = array(
			array(
				'rel'   => 'service-desc',
				'type'  => 'application/openapi+json',
				'href'  => ManifestBuilder::openapi_url(),
				'label' => __( 'OpenAPI description of the public REST API', 'wp-agent-support-layer' ),
			),
			array(
				'rel'   => 'api-catalog',
				'type'  => 'application/linkset+json',
				'href'  => home_url( '/' . ManifestRouter::CATALOG_PATH ),
				'label' => __( 'API catalog (RFC 9727)', 'wp-agent-support-layer' ),
			),
			array(
				'rel'   => 'describedby',
				'type'  => 'text/markdown',
				'href'  => home_url( '/llms.txt' ),
				'label' => __( 'Site index for agents (llms.txt)', 'wp-agent-support-layer' ),
			),
		);
		if ( AuthMdBuilder::is_published( $this->settings ) ) {
			$links[] = array(
				'rel'   => 'service-doc',
				'type'  => 'text/markdown',
				'href'  => AuthMdBuilder::url(),
				'label' => __( 'Agent access documentation (auth.md)', 'wp-agent-support-layer' ),
			);
		}

		/**
		 * Filters the discovery links printed in the HTML head and by the [wpasl_agent_links] shortcode.
		 *
		 * @param array<int, array{rel:string, type:string, href:string, label:string}> $links Links.
		 */
		$links = apply_filters( 'wpasl_discovery_links', $links );

		$clean = array();
		foreach ( (array) $links as $link ) {
			if ( is_array( $link ) && ! empty( $link['rel'] ) && ! empty( $link['href'] ) ) {
				$clean[] = array(
					'rel'   => (string) $link['rel'],
					'type'  => isset( $link['type'] ) ? (string) $link['type'] : '',
					'href'  => (string) $link['href'],
					'label' => isset( $link['label'] ) ? (string) $link['label'] : (string) $link['rel'],
				);
			}
		}
		return $clean;
	}

	/**
	 * Prints the <link> elements on public pages. Never in the admin.
	 *
	 * @return void
	 */
	public function print_links() {
		if ( is_admin() ) {
			return;
		}
		foreach ( $this->links() as $link ) {
			printf(
				'<link rel="%1$s" type="%2$s" href="%3$s" />' . "\n",
				esc_attr( $link['rel'] ),
				esc_attr( $link['type'] ),
				esc_url( $link['href'] )
			);
		}
	}

	/**
	 * Renders [wpasl_agent_links]: an unordered list of the discovery links, auth.md first.
	 *
	 * @return string HTML, or '' when the manifests are disabled.
	 */
	public function shortcode() {
		$links = $this->links();
		if ( empty( $links ) ) {
			return '';
		}
		// auth.md first: it is the document written for agents that land on the page.
		usort(
			$links,
			static function ( $a, $b ) {
				return ( 'service-doc' === $b['rel'] ) - ( 'service-doc' === $a['rel'] );
			}
		);
		$out = '<ul class="wpasl-agent-links">';
		foreach ( $links as $link ) {
			$out .= sprintf(
				'<li><a href="%1$s" rel="%2$s" type="%3$s">%4$s</a></li>',
				esc_url( $link['href'] ),
				esc_attr( $link['rel'] ),
				esc_attr( $link['type'] ),
				esc_html( $link['label'] )
			);
		}
		return $out . '</ul>';
	}
}
