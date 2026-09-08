<?php
/**
 * Builds llms.txt and llms-full.txt.
 *
 * @package WPASL
 */

namespace WPASL\Llms;

use WPASL\Content\Eligibility;
use WPASL\Content\SitemapLocator;
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
	 * Post type names that cannot have a per-type file: "llms-full.txt" is the concatenated document.
	 *
	 * @var string[]
	 */
	const RESERVED_TYPES = array( 'full' );

	/**
	 * Size (characters) above which agents and scanners consider llms.txt too large to read in one go.
	 */
	const RECOMMENDED_MAX_CHARS = 30000;

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
	 * Writes llms.txt, one llms-<post_type>.txt per enabled post type and, when enabled, llms-full.txt;
	 * removes the per-type files of post types no longer enabled and a stale llms-full.txt.
	 *
	 * @param Storage $storage Storage.
	 * @return void
	 */
	public function generate( Storage $storage ) {
		$sections = $this->sections();
		$this->generate_file( $storage, self::FILE, $sections );
		$files = $this->type_files();
		foreach ( $files as $file ) {
			$this->generate_file( $storage, $file, $sections );
		}
		foreach ( self::stored_type_files( $storage ) as $file ) {
			if ( ! in_array( $file, $files, true ) ) {
				$storage->delete( $file );
			}
		}
		$this->generate_file( $storage, self::FULL_FILE, $sections );
	}

	/**
	 * Writes one file: llms.txt, a per-type llms-<post_type>.txt or llms-full.txt. llms-full.txt converts
	 * every missing item document, so callers on the request path only ask for the other two kinds.
	 *
	 * @param Storage                   $storage  Storage.
	 * @param string                    $file     self::FILE, self::FULL_FILE or a name returned by type_file().
	 * @param array<string, int[]>|null $sections Sections; computed when null.
	 * @return bool Whether the file exists afterwards.
	 */
	public function generate_file( Storage $storage, $file, $sections = null ) {
		if ( self::FULL_FILE === $file && ! $this->full_enabled() ) {
			$storage->delete( self::FULL_FILE );
			return false;
		}
		if ( self::FILE !== $file && self::FULL_FILE !== $file ) {
			$type = self::type_of_file( $file );
			if ( null === $type || ! isset( $this->type_files()[ $type ] ) ) {
				$storage->delete( $file );
				return false;
			}
			$sections = null === $sections ? $this->sections() : $sections;
			$ids      = isset( $sections[ $type ] ) ? (array) $sections[ $type ] : array();
			return $storage->write( $file, $this->build_type( $type, $ids ) );
		}
		$sections = null === $sections ? $this->sections() : $sections;
		if ( self::FULL_FILE === $file ) {
			return $storage->write( self::FULL_FILE, $this->build_full( $sections ) );
		}
		return $storage->write( self::FILE, $this->build( $sections ) );
	}

	/**
	 * Name of the per-type file of a post type ("llms-post.txt"), or null for a reserved name.
	 *
	 * @param string $post_type Post type.
	 * @return string|null
	 */
	public static function type_file( $post_type ) {
		$post_type = (string) $post_type;
		if ( in_array( $post_type, self::RESERVED_TYPES, true ) || ! preg_match( '/^[a-z0-9_-]{1,20}$/', $post_type ) ) {
			return null;
		}
		return 'llms-' . $post_type . '.txt';
	}

	/**
	 * Post type of a per-type file name ("llms-post.txt" => "post"), or null for any other name, including
	 * llms.txt and llms-full.txt.
	 *
	 * @param string $file File name.
	 * @return string|null
	 */
	public static function type_of_file( $file ) {
		if ( ! preg_match( '/^llms-([a-z0-9_-]{1,20})\\.txt$/', (string) $file, $m ) || in_array( $m[1], self::RESERVED_TYPES, true ) ) {
			return null;
		}
		return $m[1];
	}

	/**
	 * Per-type files of the enabled post types, pages first (the order of sections()).
	 *
	 * @return array<string, string> Post type => file name.
	 */
	public function type_files() {
		$files = array();
		foreach ( $this->ordered_types() as $type ) {
			$file = self::type_file( $type );
			if ( null !== $file ) {
				$files[ $type ] = $file;
			}
		}
		return $files;
	}

	/**
	 * Per-type files present in the storage root (llms-full.txt excluded).
	 *
	 * @param Storage $storage Storage.
	 * @return string[] File names.
	 */
	public static function stored_type_files( Storage $storage ) {
		$found = array();
		foreach ( (array) glob( $storage->path( 'llms-*.txt' ) ) as $path ) {
			$file = basename( (string) $path );
			if ( null !== self::type_of_file( $file ) ) {
				$found[] = $file;
			}
		}
		return $found;
	}

	/**
	 * Enabled post types with pages first.
	 *
	 * @return string[]
	 */
	private function ordered_types() {
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
		return $types;
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
	 * Enabled post types with pages first, each with its ordered list of eligible ids up to the per-type
	 * limit: the full lists that the per-type files and llms-full.txt use.
	 *
	 * @return array<string, int[]> Post type => ids.
	 */
	public function sections() {
		$limit    = max( 1, (int) $this->settings->get( 'llms_type_limit' ) );
		$sections = array();
		foreach ( $this->ordered_types() as $type ) {
			$args = array( 'posts_per_page' => $limit );
			// Only pages follow the menu order; every other post type (hierarchical or not) is listed by date.
			if ( 'page' === $type ) {
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
		 * Filters the llms.txt sections (post type => ids): the full lists, before the preview cut.
		 *
		 * @param array<string, int[]> $sections Sections.
		 */
		return (array) apply_filters( 'wpasl_llms_sections', $sections );
	}

	/**
	 * The preview shown in llms.txt: the first items of each section, up to the preview limit, in the same
	 * order as the full list.
	 *
	 * @param array<string, int[]> $sections Full sections.
	 * @return array<string, int[]> Post type => ids.
	 */
	public function preview_sections( array $sections ) {
		$limit   = max( 1, (int) $this->settings->get( 'llms_preview_limit' ) );
		$preview = array();
		foreach ( $sections as $type => $ids ) {
			$preview[ $type ] = array_slice( (array) $ids, 0, $limit );
		}

		/**
		 * Filters the llms.txt preview sections (post type => ids shown in llms.txt).
		 *
		 * @param array<string, int[]> $preview  Preview sections.
		 * @param array<string, int[]> $sections Full sections.
		 */
		return (array) apply_filters( 'wpasl_llms_preview_sections', $preview, $sections );
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

		// Fixed English heading, like "## Optional": the section is addressed to agents.
		$when = trim( (string) $this->settings->get( 'llms_when_to_use' ) );
		if ( '' !== $when ) {
			$out .= "## When to use this site\n\n" . $when . "\n\n";
		}

		foreach ( $this->preview_sections( $sections ) as $type => $ids ) {
			if ( empty( $ids ) ) {
				continue;
			}
			$label = self::type_label( $type );
			$out  .= '## ' . $label . "\n\n";
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( ! $post ) {
					continue;
				}
				$out .= $this->item_line( $post );
			}
			$total = isset( $sections[ $type ] ) ? count( (array) $sections[ $type ] ) : 0;
			$file  = self::type_file( $type );
			if ( null !== $file && $total > count( $ids ) ) {
				// A standard llms.txt link line, so parsers treat the full list as one more item.
				$out .= '- [Full list of ' . $label . ' (' . $total . ' items)](' . home_url( '/' . $file ) . ")\n";
			}
			$out .= "\n";
		}//end foreach

		$optional = $this->optional_links();
		if ( ! empty( $optional ) ) {
			$out .= "## Optional\n\n";
			foreach ( $optional as $label => $link ) {
				$out .= '- [' . $label . '](' . $link[0] . '): ' . $link[1] . "\n";
			}
		}

		/**
		 * Filters the generated llms.txt.
		 *
		 * @param string $out Document.
		 */
		return (string) apply_filters( 'wpasl_llms_txt', $out );
	}

	/**
	 * Builds llms-<post_type>.txt: every eligible item of one post type up to the per-type limit, in the
	 * llms.txt line format. English on purpose (addressed to agents), like the fixed llms.txt headings.
	 *
	 * @param string $type Post type.
	 * @param int[]  $ids  Ordered ids (the full section).
	 * @return string
	 */
	public function build_type( $type, array $ids ) {
		$site  = DocumentBuilder::plain_text( get_bloginfo( 'name' ) );
		$label = self::type_label( $type );
		$ids   = array_values( $ids );

		$out  = '# ' . $site . ' — ' . $label . "\n\n";
		$out .= '> All public ' . $label . ' of ' . $site . ' (' . count( $ids ) . ' items). Index: ' . home_url( '/' . self::FILE ) . "\n\n";
		foreach ( array_chunk( $ids, Eligibility::PRIME_CHUNK ) as $chunk ) {
			_prime_post_caches( $chunk, false, false );
			foreach ( $chunk as $id ) {
				$post = get_post( $id );
				if ( $post ) {
					$out .= $this->item_line( $post );
				}
			}
		}
		$total = $this->eligibility->count( array( $type ) );
		if ( $total > count( $ids ) ) {
			$out .= "\n> Truncated: listing " . count( $ids ) . ' of ' . $total . " items.\n";
		}
		return $out;
	}

	/**
	 * Plural label of a post type, as plain text.
	 *
	 * @param string $type Post type.
	 * @return string
	 */
	private static function type_label( $type ) {
		$object = get_post_type_object( $type );
		return $object ? DocumentBuilder::plain_text( $object->labels->name ) : ucfirst( (string) $type );
	}

	/**
	 * Builds llms-full.txt: the Markdown documents of the items of the full lists (the per-type files, not
	 * only the llms.txt preview), concatenated, within the size limit.
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
		$links   = array();
		$sitemap = SitemapLocator::url();
		if ( null !== $sitemap ) {
			// Core index (following the permalink structure) or the one served by an SEO plugin.
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
