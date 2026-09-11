<?php
/**
 * Content source resolution.
 *
 * @package WPASL
 */

namespace WPASL\Markdown;

use WPASL\Admin\ExcludeMetaBox;
use WPASL\Settings;

/**
 * Decides whether the Markdown body of a post comes from the editor content or from the rendered page,
 * evaluating in order: the per-post override, the per-type setting and, in "auto", the WordPress
 * conditionals (assigned template, page builder meta, theme template file that does not print the editor
 * content, and, only when a filter enables the rule, an editor content that converts to (almost) nothing).
 */
final class ContentSource {

	// phpcs:disable WordPress.WP.CapitalPDangit.MisspelledInComment -- 'wordpress' is a literal meta value of Bricks.
	/**
	 * Page builders that keep the visible content outside post_content, keyed by id:
	 * array( meta key or list of alternative keys, expected value or null for "not empty", extra meta key that
	 * must be non-empty or null ).
	 *
	 * Verified against each builder (2026-09-08):
	 * - elementor: Elementor 4.2.4 (downloads.wordpress.org), core/base/document.php:
	 *   BUILT_WITH_ELEMENTOR_META_KEY = '_elementor_edit_mode' written as 'builder' by
	 *   set_is_built_with_elementor(); ELEMENTOR_DATA_META_KEY = '_elementor_data' holds the elements;
	 *   includes/frontend.php get_builder_content() renders only when is_built_with_elementor().
	 * - divi: includes/builder/functions.php et_pb_is_pagebuilder_used():
	 *   'on' === get_post_meta( $id, '_et_pb_use_builder', true ) (public mirror github.com/amon-ra/divi;
	 *   divibooster.com/detecting-if-divi-builder-is-running/).
	 * - beaver-builder: Beaver Builder Lite 2.10.3.2 (downloads.wordpress.org),
	 *   classes/class-fl-builder-model.php is_builder_enabled() returns get_post_meta( '_fl_builder_enabled' );
	 *   enable() stores true, disable() stores false (empty), so "not empty" is the predicate.
	 * - bricks: BRICKS_DB_EDITOR_MODE = '_bricks_editor_mode' with values 'bricks' / 'wordpress' ("Render with
	 *   WordPress" keeps the Bricks data but renders the editor content, and an empty canvas resets the mode to
	 *   'wordpress'; forum.bricksbuilder.io/t/39853); the page structure lives in '_bricks_page_content_2'
	 *   (respira.press/docs/builders/bricks, rudrastyh.com/support/bricks-builder). Both are required.
	 * - oxygen: 'ct_builder_shortcodes' (classic) or 'ct_builder_json' (4.0+) hold the layout
	 *   (respira.press/docs/builders/oxygen, wpdevdesign.com/shortcode-for-displaying-oxygen-templates-and-reusable-parts/).
	 * - breakdance: the JSON tree is stored in '_breakdance_data' and post_content only carries a block
	 *   launcher comment (respira.press/docs/builders/breakdance, queryra.com/docs/breakdance-integration).
	 * WPBakery is left out on purpose: it stores shortcodes in post_content and the_content renders them.
	 *
	 * @var array<string, array{0:string|string[], 1:string|null, 2:string|null}>
	 */
	const BUILDER_META = array(
		'elementor'      => array( '_elementor_edit_mode', 'builder', '_elementor_data' ),
		'divi'           => array( '_et_pb_use_builder', 'on', null ),
		'beaver-builder' => array( '_fl_builder_enabled', null, null ),
		'bricks'         => array( '_bricks_editor_mode', 'bricks', '_bricks_page_content_2' ),
		'oxygen'         => array( array( 'ct_builder_shortcodes', 'ct_builder_json' ), null, null ),
		'breakdance'     => array( '_breakdance_data', null, null ),
	);
	// phpcs:enable WordPress.WP.CapitalPDangit.MisspelledInComment

	/**
	 * Bytes read from a template file at most.
	 */
	const TEMPLATE_MAX_BYTES = 262144;

	/**
	 * Template parts followed from one template at most.
	 */
	const MAX_TEMPLATE_PARTS = 10;

	/**
	 * Default minimum length (characters) of the converted editor body below which the page is rendered. 0
	 * disables the rule: a post without an assigned template, a builder or a fixed theme template keeps the
	 * editor content whatever its length. Raise it with the wpasl_editor_min_chars filter.
	 */
	const DEFAULT_MIN_CHARS = 0;

	/**
	 * Verdicts of template_file_verdict(): the template prints the editor content, does not, or cannot be told.
	 */
	const PRINTS_EDITOR = 'editor';
	const FIXED_CONTENT = 'rendered';
	const INCONCLUSIVE  = 'inconclusive';

	/**
	 * Template verdicts by "path:mtime", per process.
	 *
	 * @var array<string, string>
	 */
	private static $template_cache = array();

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Converts the editor content of a post to a Markdown body (injected by the document builder).
	 *
	 * @var callable|null
	 */
	private $editor_body = null;

	/**
	 * Editor bodies computed by the empty-editor check, by post id, for the document builder to reuse.
	 *
	 * @var array<int, string>
	 */
	private $editor_bodies = array();

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings    Settings.
	 * @param callable|null $editor_body Callable( WP_Post ): string returning the Markdown body of the editor
	 *                                   content; the plain post content is measured when omitted.
	 */
	public function __construct( Settings $settings, $editor_body = null ) {
		$this->settings = $settings;
		if ( is_callable( $editor_body ) ) {
			$this->editor_body = $editor_body;
		}
	}

	/**
	 * Sets the editor conversion callable.
	 *
	 * @param callable $editor_body Callable( WP_Post ): string.
	 * @return void
	 */
	public function set_editor_body_callback( callable $editor_body ) {
		$this->editor_body = $editor_body;
	}

	/**
	 * Resolves the content source of a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{source:string, reason:string} "editor" or "rendered" with the reason: post_override,
	 *                                             post_type_setting, page_template, builder:<id>,
	 *                                             template_file:<file>, empty_editor or default.
	 */
	public function resolve( \WP_Post $post ) {
		unset( $this->editor_bodies[ $post->ID ] );
		$result = $this->evaluate( $post );

		/**
		 * Filters the resolved content source of a post.
		 *
		 * @param array{source:string, reason:string} $result Source ("editor" or "rendered") and reason.
		 * @param \WP_Post                            $post   Post.
		 */
		$result = apply_filters( 'wpasl_content_source', $result, $post );

		$source = isset( $result['source'] ) && 'rendered' === $result['source'] ? 'rendered' : 'editor';
		return array(
			'source' => $source,
			'reason' => isset( $result['reason'] ) ? (string) $result['reason'] : 'default',
		);
	}

	/**
	 * Markdown body of the editor content computed by the last resolution of a post, if the empty-editor
	 * check ran; consumed once.
	 *
	 * @param int $post_id Post id.
	 * @return string|null
	 */
	public function take_editor_body( $post_id ) {
		if ( ! isset( $this->editor_bodies[ (int) $post_id ] ) ) {
			return null;
		}
		$body = $this->editor_bodies[ (int) $post_id ];
		unset( $this->editor_bodies[ (int) $post_id ] );
		return $body;
	}

	/**
	 * Forgets the template verdicts (tests).
	 *
	 * @return void
	 */
	public static function flush_template_cache() {
		self::$template_cache = array();
	}

	/**
	 * Evaluates the conditionals in order.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{source:string, reason:string}
	 */
	private function evaluate( \WP_Post $post ) {
		$override = ExcludeMetaBox::source_override( $post->ID );
		if ( '' !== $override ) {
			return self::result( $override, 'post_override' );
		}

		$setting = $this->settings->content_source( $post->post_type );
		if ( 'auto' !== $setting ) {
			return self::result( $setting, 'post_type_setting' );
		}

		if ( '' !== (string) get_page_template_slug( $post ) ) {
			return self::result( 'rendered', 'page_template' );
		}

		$builder = self::builder_of( $post );
		if ( '' !== $builder ) {
			return self::result( 'rendered', 'builder:' . $builder );
		}

		$template = $this->fixed_template( $post );
		if ( '' !== $template ) {
			return self::result( 'rendered', 'template_file:' . $template );
		}

		if ( $this->editor_is_empty( $post ) ) {
			return self::result( 'rendered', 'empty_editor' );
		}

		return self::result( 'editor', 'default' );
	}

	/**
	 * Builds a result.
	 *
	 * @param string $source Source.
	 * @param string $reason Reason.
	 * @return array{source:string, reason:string}
	 */
	private static function result( $source, $reason ) {
		return array(
			'source' => $source,
			'reason' => $reason,
		);
	}

	/**
	 * Id of the page builder that built the post, or '' when none of the known meta keys apply.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function builder_of( \WP_Post $post ) {
		/**
		 * Filters the page builders detected through post meta.
		 *
		 * Each entry is keyed by the builder id (used in the "builder:<id>" reason) and holds: the meta key,
		 * or a list of alternative meta keys; the expected value, or null when any non-empty value counts;
		 * and an extra meta key that must be non-empty as well, or null.
		 *
		 * @param array<string, array{0:string|string[], 1:string|null, 2:string|null}> $builders Builders.
		 * @param \WP_Post                                                               $post     Post.
		 */
		$builders = apply_filters( 'wpasl_builder_meta_keys', self::BUILDER_META, $post );

		foreach ( (array) $builders as $id => $spec ) {
			$spec = array_values( (array) $spec );
			if ( empty( $spec[0] ) ) {
				continue;
			}
			$keys     = (array) $spec[0];
			$expected = isset( $spec[1] ) ? $spec[1] : null;
			$extra    = isset( $spec[2] ) ? (string) $spec[2] : '';

			$matched = false;
			foreach ( $keys as $key ) {
				$value = get_post_meta( $post->ID, (string) $key, true );
				if ( null === $expected ? self::meta_not_empty( $value ) : (string) $value === (string) $expected ) {
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) {
				continue;
			}
			if ( '' !== $extra && ! self::meta_not_empty( get_post_meta( $post->ID, $extra, true ) ) ) {
				continue;
			}
			return (string) $id;
		}//end foreach
		return '';
	}

	/**
	 * Whether a meta value counts as present: anything but '', '0', an empty array or an empty JSON list.
	 *
	 * @param mixed $value Meta value.
	 * @return bool
	 */
	private static function meta_not_empty( $value ) {
		if ( is_array( $value ) ) {
			return ! empty( $value );
		}
		$value = trim( (string) $value );
		return '' !== $value && '0' !== $value && '[]' !== $value && '{}' !== $value && 'null' !== $value;
	}

	/**
	 * Template candidates WordPress consults for the singular view of a post, without the generic
	 * single.php / page.php (they print the editor content in any sane theme and say nothing about the type).
	 *
	 * @param \WP_Post $post Post.
	 * @return string[] File names relative to the theme, ordered by priority.
	 */
	public function template_candidates( \WP_Post $post ) {
		$candidates = array();
		$assigned   = (string) get_page_template_slug( $post );
		if ( '' !== $assigned ) {
			$candidates[] = $assigned;
		}
		$slug    = (string) $post->post_name;
		$decoded = urldecode( $slug );
		if ( 'page' === $post->post_type ) {
			if ( '' !== $slug ) {
				if ( $decoded !== $slug ) {
					$candidates[] = "page-{$decoded}.php";
				}
				$candidates[] = "page-{$slug}.php";
			}
			$candidates[] = "page-{$post->ID}.php";
		} else {
			if ( '' !== $slug ) {
				if ( $decoded !== $slug ) {
					$candidates[] = "single-{$post->post_type}-{$decoded}.php";
				}
				$candidates[] = "single-{$post->post_type}-{$slug}.php";
			}
			$candidates[] = "single-{$post->post_type}.php";
		}

		/**
		 * Filters the theme templates inspected to decide whether a post is rendered with fixed content.
		 *
		 * @param string[] $candidates Template file names relative to the theme, ordered by priority.
		 * @param \WP_Post $post       Post.
		 */
		$candidates = apply_filters( 'wpasl_template_candidates', $candidates, $post );
		return array_values( array_filter( array_map( 'strval', (array) $candidates ) ) );
	}

	/**
	 * Name of the specific theme template of the post whose code does not print the editor content, or ''
	 * when there is none or the template is inconclusive.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function fixed_template( \WP_Post $post ) {
		$candidates = $this->template_candidates( $post );
		if ( empty( $candidates ) ) {
			return '';
		}

		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return self::fixed_block_template( $post, $candidates );
		}

		$path = locate_template( $candidates, false, false );
		/**
		 * Filters the path of the theme template inspected for a post.
		 *
		 * @param string   $path       Absolute path found by locate_template(), or '' when none of the candidates exists.
		 * @param string[] $candidates Template candidates.
		 * @param \WP_Post $post       Post.
		 */
		$path = (string) apply_filters( 'wpasl_template_file', $path, $candidates, $post );
		if ( '' === $path ) {
			return '';
		}
		return self::FIXED_CONTENT === self::template_file_verdict( $path, 0 ) ? basename( $path ) : '';
	}

	/**
	 * Block theme branch: resolves the same hierarchy (without ".php") to a block template and inspects its
	 * content; a "wp:post-content" block prints the editor content and a "wp:template-part" block is
	 * inconclusive.
	 *
	 * @param \WP_Post $post       Post.
	 * @param string[] $candidates Template candidates.
	 * @return string Template slug when it renders fixed content, '' otherwise.
	 */
	private static function fixed_block_template( \WP_Post $post, array $candidates ) {
		if ( ! function_exists( 'resolve_block_template' ) ) {
			return '';
		}
		$hierarchy = array();
		foreach ( $candidates as $candidate ) {
			$hierarchy[] = preg_replace( '/\.php$/', '', $candidate );
		}
		$template = resolve_block_template( 'page' === $post->post_type ? 'page' : 'single', $hierarchy, '' );
		if ( ! $template instanceof \WP_Block_Template || ! in_array( $template->slug, $hierarchy, true ) ) {
			return '';
		}
		return self::FIXED_CONTENT === self::block_content_verdict( (string) $template->content ) ? (string) $template->slug : '';
	}

	/**
	 * Verdict for block template markup.
	 *
	 * @param string $content Block markup.
	 * @return string
	 */
	private static function block_content_verdict( $content ) {
		if ( false !== strpos( $content, 'wp:post-content' ) ) {
			return self::PRINTS_EDITOR;
		}
		if ( false !== strpos( $content, 'wp:template-part' ) ) {
			return self::INCONCLUSIVE;
		}
		return self::FIXED_CONTENT;
	}

	/**
	 * Verdict for a PHP template file: prints the editor content ("the_content" or "wp:post-content" in the
	 * code), is inconclusive (dynamic template parts or includes, unreadable file), or renders fixed content.
	 * Literal get_template_part() calls are followed one level. Cached per process by path and mtime.
	 *
	 * @param string $path  Absolute path.
	 * @param int    $depth Nesting level (0 for the template itself).
	 * @return string
	 */
	private static function template_file_verdict( $path, $depth ) {
		$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable files are inconclusive.
		if ( false === $mtime ) {
			return self::INCONCLUSIVE;
		}
		$key = $path . ':' . $mtime;
		if ( isset( self::$template_cache[ $key ] ) ) {
			return self::$template_cache[ $key ];
		}
		$verdict = self::analyze_template( $path, $depth );

		self::$template_cache[ $key ] = $verdict;
		return $verdict;
	}

	/**
	 * Reads and analyzes a template file (see template_file_verdict()).
	 *
	 * @param string $path  Absolute path.
	 * @param int    $depth Nesting level.
	 * @return string
	 */
	private static function analyze_template( $path, $depth ) {
		$code = @file_get_contents( $path, false, null, 0, self::TEMPLATE_MAX_BYTES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local theme file, bounded read.
		if ( false === $code ) {
			return self::INCONCLUSIVE;
		}
		if ( false !== strpos( $code, 'the_content' ) || false !== strpos( $code, 'wp:post-content' ) ) {
			return self::PRINTS_EDITOR;
		}
		if ( false !== strpos( $code, 'locate_template(' ) || false !== stripos( $code, 'locate_template (' ) ) {
			return self::INCONCLUSIVE;
		}

		// include / require with anything but a plain string literal. The keyword must not be immediately
		// preceded by a quote, or an array key like 'include' => array(...) (get_posts(), WP_Query...) would
		// be mistaken for a dynamic include; a real include/require is never preceded by a quote this way,
		// so this alone rules out the array-key case without missing a quote-adjacent argument such as
		// include'./file.php'; (valid PHP with no space).
		if ( preg_match_all( '/(?<!["\'])\b(?:include|require)(?:_once)?\s*\(?\s*([^;]+);/i', $code, $includes ) ) {
			foreach ( $includes[1] as $argument ) {
				$argument = trim( rtrim( trim( $argument ), ')' ) );
				if ( ! preg_match( '/^(["\'])([^"\']+)\1$/', $argument, $m ) ) {
					return self::INCONCLUSIVE;
				}
				$verdict = self::follow_part( array( $m[2] ), dirname( $path ), $depth );
				if ( self::FIXED_CONTENT !== $verdict ) {
					return $verdict;
				}
			}
		}

		if ( preg_match_all( '/\bget_template_part\s*\(([^;]*?)\)\s*;/i', $code, $parts ) ) {
			if ( count( $parts[1] ) > self::MAX_TEMPLATE_PARTS ) {
				return self::INCONCLUSIVE;
			}
			foreach ( $parts[1] as $arguments ) {
				$literals = self::literal_arguments( $arguments );
				if ( null === $literals ) {
					return self::INCONCLUSIVE;
				}
				$names = array();
				if ( isset( $literals[1] ) && '' !== $literals[1] ) {
					$names[] = $literals[0] . '-' . $literals[1] . '.php';
				}
				$names[] = $literals[0] . '.php';
				$verdict = self::follow_part( $names, null, $depth );
				if ( self::FIXED_CONTENT !== $verdict ) {
					return $verdict;
				}
			}
		}

		return self::FIXED_CONTENT;
	}

	/**
	 * Follows a template part one level down.
	 *
	 * @param string[]    $names     File names to try (theme-relative, or relative to $directory).
	 * @param string|null $directory Directory of the including file for plain includes, null for theme parts.
	 * @param int         $depth     Nesting level of the including file.
	 * @return string Verdict.
	 */
	private static function follow_part( array $names, $directory, $depth ) {
		if ( $depth >= 1 ) {
			return self::INCONCLUSIVE;
		}
		$located = '';
		if ( null === $directory ) {
			$located = locate_template( $names, false, false );
		} else {
			foreach ( $names as $name ) {
				$candidate = $directory . '/' . ltrim( $name, '/' );
				if ( file_exists( $candidate ) ) {
					$located = $candidate;
					break;
				}
			}
		}
		if ( '' === $located ) {
			// get_template_part() with a missing file prints nothing.
			return null === $directory ? self::FIXED_CONTENT : self::INCONCLUSIVE;
		}
		return self::template_file_verdict( $located, $depth + 1 );
	}

	/**
	 * String literals of an argument list, or null when any argument is not a plain literal.
	 *
	 * @param string $arguments Text between the parentheses.
	 * @return string[]|null
	 */
	private static function literal_arguments( $arguments ) {
		$arguments = trim( $arguments );
		if ( '' === $arguments ) {
			return null;
		}
		$literals = array();
		foreach ( explode( ',', $arguments ) as $argument ) {
			$argument = trim( $argument );
			if ( '' === $argument ) {
				continue;
			}
			if ( ! preg_match( '/^(["\'])([^"\']*)\1$/', $argument, $m ) ) {
				return null;
			}
			$literals[] = $m[2];
		}
		return empty( $literals ) ? null : $literals;
	}

	/**
	 * Whether the editor content converts to an empty or very short Markdown body.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private function editor_is_empty( \WP_Post $post ) {
		/**
		 * Filters the minimum length, in characters, of the converted editor body; below it the post is
		 * rendered from its page when no other conditional applied.
		 *
		 * @param int      $min_chars Characters. Default 0: the rule is off.
		 * @param \WP_Post $post      Post.
		 */
		$min = max( 0, (int) apply_filters( 'wpasl_editor_min_chars', self::DEFAULT_MIN_CHARS, $post ) );
		if ( 0 === $min ) {
			return false;
		}

		if ( null === $this->editor_body ) {
			$text = DocumentBuilder::plain_text( $post->post_content );
		} else {
			$body = (string) call_user_func( $this->editor_body, $post );

			$this->editor_bodies[ $post->ID ] = $body;
			$text                             = $body;
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return mb_strlen( $text ) < $min;
	}
}
