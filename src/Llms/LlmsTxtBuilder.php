<?php
/**
 * Builds llms.txt and llms-full.txt.
 *
 * @package WPASL
 */

namespace WPASL\Llms;

use WPASL\Content\Eligibility;
use WPASL\Generation\ArtifactGeneratorInterface;
use WPASL\Generation\Runner;
use WPASL\Markdown\Delivery;
use WPASL\Markdown\DocumentBuilder;
use WPASL\Settings;
use WPASL\Storage;

/**
 * Curated Markdown index of the site following llmstxt.org, plus the optional concatenated llms-full.txt.
 */
final class LlmsTxtBuilder implements ArtifactGeneratorInterface {

	const FILE      = 'llms.txt';
	const FULL_FILE = 'llms-full.txt';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Eligibility.
	 *
	 * @var Eligibility
	 */
	private $eligibility;

	/**
	 * Delivery (for Markdown URLs and documents).
	 *
	 * @var Delivery
	 */
	private $delivery;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings    Settings.
	 * @param Eligibility $eligibility Eligibility.
	 * @param Delivery    $delivery    Delivery.
	 */
	public function __construct( Settings $settings, Eligibility $eligibility, Delivery $delivery ) {
		$this->settings    = $settings;
		$this->eligibility = $eligibility;
		$this->delivery    = $delivery;
	}

	/**
	 * Artifact id.
	 *
	 * @return string
	 */
	public function id() {
		return 'llms-txt';
	}

	/**
	 * Writes llms.txt and, when enabled, llms-full.txt; removes a stale llms-full.txt otherwise.
	 *
	 * @param Storage $storage Storage.
	 * @return void
	 */
	public function generate( Storage $storage ) {
		$sections = $this->sections();
		$storage->write( self::FILE, $this->build( $sections ) );
		if ( $this->full_enabled() ) {
			$storage->write( self::FULL_FILE, $this->build_full( $sections ) );
		} else {
			$storage->delete( self::FULL_FILE );
		}
	}

	/**
	 * Whether llms-full.txt is enabled.
	 *
	 * @return bool
	 */
	public function full_enabled() {
		return (bool) $this->settings->get( 'llms_full_enabled' );
	}

	/**
	 * Enabled post types with pages first, each with its ordered, limited list of eligible ids.
	 *
	 * @return array<string, int[]> Post type => ids.
	 */
	public function sections() {
		$types = $this->settings->enabled_post_types();
		usort(
			$types,
			static function ( $a, $b ) {
				if ( 'page' === $a ) {
					return -1;
				}
				if ( 'page' === $b ) {
					return 1;
				}
				return 0;
			}
		);

		$limit    = max( 1, (int) $this->settings->get( 'llms_limit' ) );
		$sections = array();
		foreach ( $types as $type ) {
			$args = array( 'posts_per_page' => $limit );
			if ( is_post_type_hierarchical( $type ) ) {
				$args['orderby'] = array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				);
			} else {
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
			}
			$sections[ $type ] = $this->eligibility->query( array( $type ), $args );
		}

		/**
		 * Filters the llms.txt sections (post type => ids).
		 *
		 * @param array<string, int[]> $sections Sections.
		 */
		return (array) apply_filters( 'wpasl_llms_sections', $sections );
	}

	/**
	 * Builds llms.txt.
	 *
	 * @param array<string, int[]>|null $sections Sections; computed when null.
	 * @return string
	 */
	public function build( $sections = null ) {
		$sections = null === $sections ? $this->sections() : $sections;

		$description = DocumentBuilder::plain_text( (string) $this->settings->get( 'llms_description' ) );
		if ( '' === $description ) {
			$description = DocumentBuilder::plain_text( get_bloginfo( 'description' ) );
		}
		if ( '' === $description ) {
			/* translators: %s: site name. */
			$description = sprintf( __( 'Content index of %s.', 'wp-agent-support-layer' ), DocumentBuilder::plain_text( get_bloginfo( 'name' ) ) );
		}

		$out  = '# ' . DocumentBuilder::plain_text( get_bloginfo( 'name' ) ) . "\n\n";
		$out .= '> ' . $description . "\n\n";

		$intro = trim( (string) $this->settings->get( 'llms_intro' ) );
		if ( '' !== $intro ) {
			$out .= $intro . "\n\n";
		}

		foreach ( $sections as $type => $ids ) {
			if ( empty( $ids ) ) {
				continue;
			}
			$object = get_post_type_object( $type );
			$out   .= '## ' . ( $object ? DocumentBuilder::plain_text( $object->labels->name ) : ucfirst( $type ) ) . "\n\n";
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( ! $post ) {
					continue;
				}
				$out .= $this->item_line( $post );
			}
			$out .= "\n";
		}

		$out .= "## Optional\n\n";
		foreach ( $this->optional_links() as $label => $link ) {
			$out .= '- [' . $label . '](' . $link[0] . '): ' . $link[1] . "\n";
		}

		/**
		 * Filters the generated llms.txt.
		 *
		 * @param string $out Document.
		 */
		return (string) apply_filters( 'wpasl_llms_txt', $out );
	}

	/**
	 * Builds llms-full.txt: the Markdown documents of the indexed items, concatenated, within the size limit.
	 *
	 * @param array<string, int[]>|null $sections Sections; computed when null.
	 * @return string
	 */
	public function build_full( $sections = null ) {
		$sections  = null === $sections ? $this->sections() : $sections;
		$max_bytes = max( 1, (int) $this->settings->get( 'llms_full_max_bytes' ) );
		$separator = "\n\n---\n\n";
		$header    = '# ' . DocumentBuilder::plain_text( get_bloginfo( 'name' ) ) . ' (full)' . "\n\n> " . sprintf(
			/* translators: %s: URL of llms.txt. */
			__( 'Concatenated Markdown of the content indexed in %s.', 'wp-agent-support-layer' ),
			home_url( '/llms.txt' )
		) . "\n";
		$note = "\n\n---\n\n> " . sprintf(
			/* translators: 1: size limit, 2: URL of llms.txt. */
			__( 'Truncated: this file reached its %1$s limit. The complete index is in %2$s.', 'wp-agent-support-layer' ),
			size_format( $max_bytes ),
			home_url( '/llms.txt' )
		) . "\n";

		$out       = $header;
		$truncated = false;
		foreach ( $sections as $ids ) {
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( ! $post ) {
					continue;
				}
				$document = $this->delivery->document( $post );
				if ( null === $document ) {
					continue;
				}
				$piece = $separator . rtrim( $document, "\n" ) . "\n";
				if ( strlen( $out ) + strlen( $piece ) + strlen( $note ) > $max_bytes ) {
					$truncated = true;
					break 2;
				}
				$out .= $piece;
			}
		}

		return $truncated ? $out . $note : $out;
	}

	/**
	 * One "- [Title](url): description" line.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function item_line( \WP_Post $post ) {
		$title = str_replace( array( '[', ']' ), array( '\\[', '\\]' ), DocumentBuilder::plain_text( $post->post_title ) );
		$url   = $this->delivery->markdown_url( $post );
		if ( '' === $url ) {
			$url = get_permalink( $post );
		}
		$description = $this->description( $post );
		return '- [' . $title . '](' . $url . ')' . ( '' === $description ? '' : ': ' . $description ) . "\n";
	}

	/**
	 * Short description of a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function description( \WP_Post $post ) {
		$text = DocumentBuilder::plain_text( $post->post_excerpt );
		if ( '' === $text ) {
			$text = DocumentBuilder::plain_text( strip_shortcodes( excerpt_remove_blocks( $post->post_content ) ) );
		}
		$text = preg_replace( '/\s+/', ' ', $text );
		return '' === $text ? '' : wp_trim_words( $text, 25, '…' );
	}

	/**
	 * Links of the Optional section.
	 *
	 * @return array<string, array{0:string,1:string}> Label => [url, description].
	 */
	private function optional_links() {
		$links = array();
		if ( function_exists( 'wp_sitemaps_get_server' ) && wp_sitemaps_get_server()->sitemaps_enabled() ) {
			// The index URL depends on the permalink structure ("?sitemap=index" with plain permalinks).
			$sitemap = function_exists( 'get_sitemap_url' ) ? (string) get_sitemap_url( 'index' ) : home_url( '/wp-sitemap.xml' );
			$links[ __( 'Sitemap', 'wp-agent-support-layer' ) ] = array( $sitemap, __( 'XML sitemap of the whole site.', 'wp-agent-support-layer' ) );
		}
		if ( $this->settings->get( 'manifest_enabled' ) ) {
			$links[ __( 'Agent skills', 'wp-agent-support-layer' ) ] = array( home_url( '/agent-skills.json' ), __( 'Capabilities an agent can use on this site (JSON-LD).', 'wp-agent-support-layer' ) );
			$links[ __( 'OpenAPI', 'wp-agent-support-layer' ) ]      = array( rest_url( 'wpasl/v1/openapi' ), __( 'OpenAPI 3.1 description of the public REST API.', 'wp-agent-support-layer' ) );
		}
		/**
		 * Filters the Optional links of llms.txt.
		 *
		 * @param array<string, array{0:string,1:string}> $links Label => [url, description].
		 */
		return (array) apply_filters( 'wpasl_llms_optional_links', $links );
	}
}
