<?php
/**
 * Builds the Markdown document of a post.
 *
 * @package WPASL
 */

namespace WPASL\Markdown;

use WPASL\Generation\ItemGeneratorInterface;

/**
 * YAML front matter, H1 title and the converted body: the editor content, or the content region of the
 * rendered page when the content source resolves to "rendered" (falling back to the editor content when the
 * page cannot be fetched).
 */
final class DocumentBuilder implements ItemGeneratorInterface {

	/**
	 * Globals set by setup_postdata() besides $post, $more and $page.
	 *
	 * @var string[]
	 */
	const POSTDATA_GLOBALS = array( 'id', 'authordata', 'currentday', 'currentmonth', 'pages', 'numpages', 'multipage' );

	/**
	 * Converter.
	 *
	 * @var ConverterInterface
	 */
	private $converter;

	/**
	 * Content source resolver, or null to always use the editor content.
	 *
	 * @var ContentSource|null
	 */
	private $source;

	/**
	 * Rendered page fetcher.
	 *
	 * @var RenderedPage|null
	 */
	private $fetcher;

	/**
	 * Content region extractor.
	 *
	 * @var ContentExtractor|null
	 */
	private $extractor;

	/**
	 * Constructor.
	 *
	 * @param ConverterInterface    $converter Converter.
	 * @param ContentSource|null    $source    Content source resolver; without it every document uses the editor content.
	 * @param RenderedPage|null     $fetcher   Loopback fetcher of the rendered page.
	 * @param ContentExtractor|null $extractor Content region extractor.
	 */
	public function __construct( ConverterInterface $converter, ?ContentSource $source = null, ?RenderedPage $fetcher = null, ?ContentExtractor $extractor = null ) {
		$this->converter = $converter;
		$this->source    = $source;
		$this->fetcher   = $fetcher;
		$this->extractor = $extractor;
		if ( null !== $source ) {
			$source->set_editor_body_callback( array( $this, 'editor_body' ) );
		}
	}

	/**
	 * Builds the document: YAML front matter, H1 title and converted body.
	 *
	 * @param \WP_Post $post Post.
	 * @return string|null Null when the rendered page was deferred by the run's time budget.
	 */
	public function generate( \WP_Post $post ) {
		$resolution = null === $this->source
			? array(
				'source' => 'editor',
				'reason' => 'default',
			)
			: $this->source->resolve( $post );
		$info       = array(
			'source'   => $resolution['source'],
			'reason'   => $resolution['reason'],
			'fallback' => false,
			'error'    => '',
			'deferred' => false,
		);

		$body = null;
		if ( 'rendered' === $info['source'] ) {
			$rendered = $this->rendered_body( $post );
			if ( 'deferred' === $rendered['error'] ) {
				$info['deferred'] = true;
				$this->announce( $post, $info );
				return null;
			}
			if ( null === $rendered['body'] ) {
				$info['source']   = 'editor';
				$info['fallback'] = true;
				$info['error']    = $rendered['error'];
			} else {
				$body = $rendered['body'];
			}
		}
		if ( null === $body ) {
			// The empty-editor check may have converted the editor content already.
			$body = null === $this->source ? null : $this->source->take_editor_body( $post->ID );
			if ( null === $body ) {
				$body = $this->editor_body( $post );
			}
		}

		$title    = self::plain_text( $post->post_title );
		$document = $this->front_matter( $post, $title, $body, $info['source'] ) . '# ' . $title . "\n\n" . $body;
		$this->announce( $post, $info );

		/**
		 * Filters the final Markdown document.
		 *
		 * @param string   $document Markdown document.
		 * @param \WP_Post $post     Post.
		 */
		return (string) apply_filters( 'wpasl_markdown_document', $document, $post );
	}

	/**
	 * Markdown body of the editor content (blocks and shortcodes processed, whole content).
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public function editor_body( \WP_Post $post ) {
		return $this->convert_html( $this->render_html( $post ), $post );
	}

	/**
	 * Markdown body of the content region of the rendered page, or the reason it could not be produced.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{body:string|null, error:string}
	 */
	private function rendered_body( \WP_Post $post ) {
		if ( null === $this->fetcher || null === $this->extractor ) {
			return array(
				'body'  => null,
				'error' => 'no_fetcher',
			);
		}
		$fetch = $this->fetcher->fetch( $post );
		if ( ! $fetch['ok'] ) {
			return array(
				'body'  => null,
				'error' => (string) $fetch['error'],
			);
		}
		$extracted = $this->extractor->extract( $fetch['body'], $post );
		if ( ! $extracted['ok'] ) {
			return array(
				'body'  => null,
				'error' => (string) $extracted['error'],
			);
		}
		return array(
			'body'  => $this->convert_html( $extracted['html'], $post ),
			'error' => '',
		);
	}

	/**
	 * Filters and converts an HTML fragment (editor content or extracted region) to Markdown.
	 *
	 * @param string   $html HTML fragment.
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function convert_html( $html, \WP_Post $post ) {
		/**
		 * Filters the HTML before it is converted to Markdown: the rendered editor content, or the content
		 * region extracted from the rendered page.
		 *
		 * @param string   $html HTML fragment.
		 * @param \WP_Post $post Post.
		 */
		$html = (string) apply_filters( 'wpasl_markdown_html', $html, $post );

		$this->converter->set_base_url( (string) get_permalink( $post ) );
		return $this->converter->convert( $html );
	}

	/**
	 * Announces the resolution of a document.
	 *
	 * @param \WP_Post             $post Post.
	 * @param array<string, mixed> $info Resolution.
	 * @return void
	 */
	private function announce( \WP_Post $post, array $info ) {
		/**
		 * Fires once the content source of a post was resolved and its document built, or its rendered page
		 * deferred to the next run.
		 *
		 * @param \WP_Post $post Post.
		 * @param array    $info {
		 *     Resolution.
		 *
		 *     @type string $source   Source of the body actually used: "editor" or "rendered".
		 *     @type string $reason   Reason of the resolution (post_override, post_type_setting, page_template,
		 *                            builder:<id>, template_file:<file>, empty_editor, default, or a filter's own).
		 *     @type bool   $fallback Whether the rendered page could not be used and the editor content was.
		 *     @type string $error    Reason of the fallback: external_host, request_error:<code>, http_<status>,
		 *                            redirect_external_host, redirect_loop, not_html, empty_body, no_content.
		 *     @type bool   $deferred Whether the fetch was deferred by the run's time budget (no document built).
		 * }
		 */
		do_action( 'wpasl_content_source_resolved', $post, $info );
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
		// setup_postdata() also sets these; wp_reset_postdata() only restores them inside a singular view.
		$previous_globals = array();
		foreach ( self::POSTDATA_GLOBALS as $name ) {
			$previous_globals[ $name ] = isset( $GLOBALS[ $name ] ) ? $GLOBALS[ $name ] : null;
		}

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
		foreach ( $previous_globals as $name => $value ) {
			$GLOBALS[ $name ] = $value; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restoring core globals.
		}

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
	 * @param \WP_Post $post   Post.
	 * @param string   $title  Decoded title.
	 * @param string   $body   Markdown body (used for the fallback description).
	 * @param string   $source Source of the body: "editor" or "rendered".
	 * @return string
	 */
	private function front_matter( \WP_Post $post, $title, $body, $source = 'editor' ) {
		$author = get_userdata( (int) $post->post_author );

		$fields = array(
			'title'    => $title,
			'url'      => get_permalink( $post ),
			'type'     => $post->post_type,
			'date'     => get_the_date( 'c', $post ),
			'modified' => get_the_modified_date( 'c', $post ),
			'author'   => $author ? $author->display_name : '',
			'lang'     => $this->language( $post ),
			'source'   => $source,
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
