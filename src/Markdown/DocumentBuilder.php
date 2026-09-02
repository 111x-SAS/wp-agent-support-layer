<?php
/**
 * Builds the Markdown document of a post.
 *
 * @package WPASL
 */

namespace WPASL\Markdown;

use WPASL\Generation\ItemGeneratorInterface;

/**
 * YAML front matter, H1 title and the converted rendered content.
 */
final class DocumentBuilder implements ItemGeneratorInterface {

	/**
	 * Converter.
	 *
	 * @var ConverterInterface
	 */
	private $converter;

	/**
	 * Constructor.
	 *
	 * @param ConverterInterface $converter Converter.
	 */
	public function __construct( ConverterInterface $converter ) {
		$this->converter = $converter;
	}

	/**
	 * Builds the document: YAML front matter, H1 title and converted body.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public function generate( \WP_Post $post ) {
		$html = $this->render_html( $post );

		/**
		 * Filters the rendered HTML before it is converted to Markdown.
		 *
		 * @param string   $html Rendered HTML.
		 * @param \WP_Post $post Post.
		 */
		$html = (string) apply_filters( 'wpasl_markdown_html', $html, $post );

		$body     = $this->converter->convert( $html );
		$title    = self::plain_text( $post->post_title );
		$document = $this->front_matter( $post, $title, $body ) . '# ' . $title . "\n\n" . $body;

		/**
		 * Filters the final Markdown document.
		 *
		 * @param string   $document Markdown document.
		 * @param \WP_Post $post     Post.
		 */
		return (string) apply_filters( 'wpasl_markdown_document', $document, $post );
	}

	/**
	 * Rough token estimate used for the X-Markdown-Tokens header.
	 *
	 * @param string $document Markdown document.
	 * @return int
	 */
	public static function estimate_tokens( $document ) {
		return (int) ceil( strlen( (string) $document ) / 4 );
	}

	/**
	 * Renders the post content with blocks and shortcodes processed.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function render_html( \WP_Post $post ) {
		global $wp_query, $more, $page;

		$previous_post  = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$previous_query = $wp_query;
		$previous_more  = $more;
		$previous_page  = $page;

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below.
		setup_postdata( $post );

		// Outside a singular view WordPress renders only the teaser and the first page. The
		// document must always carry the whole content, whichever code path generated it.
		$more = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below.
		$page = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below.

		$content = self::strip_pagination_marks( (string) $post->post_content );
		/** This filter is documented in wp-includes/post-template.php */
		$html = (string) apply_filters( 'the_content', $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		$html = str_replace( ']]>', ']]&gt;', $html );

		wp_reset_postdata();
		$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring.
		$wp_query        = $previous_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring.
		$more            = $previous_more; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring.
		$page            = $previous_page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring.

		return $html;
	}

	/**
	 * Removes the "more" and "nextpage" marks (and their block wrappers) so the whole content is rendered.
	 *
	 * @param string $content Raw post content.
	 * @return string
	 */
	public static function strip_pagination_marks( $content ) {
		$content = (string) preg_replace( '/<!--\s*\/?wp:(?:more|nextpage)\b[^>]*-->/i', '', $content );
		$content = (string) preg_replace( '/<!--more(?:.*?)?-->/i', '', $content );
		return str_ireplace( '<!--nextpage-->', '', $content );
	}

	/**
	 * Builds the YAML front matter block.
	 *
	 * @param \WP_Post $post  Post.
	 * @param string   $title Decoded title.
	 * @param string   $body  Markdown body (used for the fallback description).
	 * @return string
	 */
	private function front_matter( \WP_Post $post, $title, $body ) {
		$author = get_userdata( (int) $post->post_author );

		$fields = array(
			'title'    => $title,
			'url'      => get_permalink( $post ),
			'type'     => $post->post_type,
			'date'     => get_the_date( 'c', $post ),
			'modified' => get_the_modified_date( 'c', $post ),
			'author'   => $author ? $author->display_name : '',
			'lang'     => $this->language( $post ),
		);

		$description = $this->description( $post, $body );
		if ( '' !== $description ) {
			$fields['description'] = $description;
		}

		$categories = $this->term_names( $post, 'category' );
		if ( ! empty( $categories ) ) {
			$fields['categories'] = $categories;
		}

		$tags = $this->term_names( $post, 'post_tag' );
		if ( ! empty( $tags ) ) {
			$fields['tags'] = $tags;
		}

		/**
		 * Filters the front matter fields.
		 *
		 * @param array<string, string|string[]> $fields Fields.
		 * @param \WP_Post                       $post   Post.
		 */
		$fields = apply_filters( 'wpasl_markdown_front_matter', $fields, $post );

		$yaml = "---\n";
		foreach ( $fields as $key => $value ) {
			if ( is_array( $value ) ) {
				$yaml .= $key . ":\n";
				foreach ( $value as $item ) {
					$yaml .= '  - ' . self::yaml_string( (string) $item ) . "\n";
				}
			} else {
				$yaml .= $key . ': ' . self::yaml_string( (string) $value ) . "\n";
			}
		}
		return $yaml . "---\n\n";
	}

	/**
	 * Language tag of the post, defaulting to the site language.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function language( \WP_Post $post ) {
		$lang = get_bloginfo( 'language' );
		/**
		 * Filters the language tag written to the front matter.
		 *
		 * @param string   $lang Language tag (BCP 47).
		 * @param \WP_Post $post Post.
		 */
		return (string) apply_filters( 'wpasl_markdown_language', $lang, $post );
	}

	/**
	 * Short description: manual excerpt, otherwise the first words of the body.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $body Markdown body.
	 * @return string
	 */
	private function description( \WP_Post $post, $body ) {
		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			return self::plain_text( $post->post_excerpt );
		}
		$text = preg_replace( '/[#*_>`\[\]!]+/', '', $body );
		$text = preg_replace( '/\(https?:[^)]*\)/', '', $text );
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		return '' === $text ? '' : wp_trim_words( $text, 30, '…' );
	}

	/**
	 * Term names of a taxonomy when the post type supports it.
	 *
	 * @param \WP_Post $post     Post.
	 * @param string   $taxonomy Taxonomy.
	 * @return string[]
	 */
	private function term_names( \WP_Post $post, $taxonomy ) {
		if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			return array();
		}
		$terms = get_the_terms( $post, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$names = array();
		foreach ( $terms as $term ) {
			if ( 'category' === $taxonomy && 'uncategorized' === $term->slug ) {
				continue;
			}
			$names[] = self::plain_text( $term->name );
		}
		return $names;
	}

	/**
	 * Plain, entity-decoded text without markup or smart-quote texturizing.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function plain_text( $value ) {
		return trim( html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Quotes a string for YAML.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function yaml_string( $value ) {
		$value = str_replace( array( '\\', '"', "\n", "\r", "\t" ), array( '\\\\', '\\"', '\\n', '', ' ' ), $value );
		return '"' . $value . '"';
	}
}
