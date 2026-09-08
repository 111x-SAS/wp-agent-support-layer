<?php
/**
 * Content region extraction from a rendered page.
 *
 * @package WPASL
 */

namespace WPASL\Markdown;

use WPASL\Settings;

/**
 * Parses the HTML of a rendered page, picks the main content region (configured CSS selector, then an
 * automatic list, then body), removes the non-content elements and the duplicated H1, and returns the
 * fragment that goes through the usual HTML-to-Markdown path.
 */
final class ContentExtractor {

	/**
	 * Selectors tried in order to find the content region when none is configured.
	 *
	 * @var string[]
	 */
	const CONTENT_SELECTORS = array( 'main', '[role="main"]', 'article', '#content', '#primary', '.site-content', '.elementor[data-elementor-type]' );

	/**
	 * Elements removed from the region before it is measured and converted.
	 *
	 * @var string[]
	 */
	const REMOVE_SELECTORS = array( 'script', 'style', 'noscript', 'template', 'nav', 'header', 'footer', 'aside', 'form', 'iframe', 'svg', 'button', 'input', 'select', 'textarea', '[hidden]', '[aria-hidden="true"]', '.screen-reader-text' );

	/**
	 * Header, footer and popup containers of the page builders, removed as well.
	 *
	 * @var string[]
	 */
	const BUILDER_REMOVE_SELECTORS = array( '.elementor-location-header', '.elementor-location-footer', '[data-elementor-type="header"]', '[data-elementor-type="footer"]', '[data-elementor-type="popup"]', '.et-l--header', '.et-l--footer' );

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
	 * Extracts the content region of a rendered page as an HTML fragment.
	 *
	 * @param string   $html Full HTML of the rendered page.
	 * @param \WP_Post $post Post the page belongs to.
	 * @return array{ok:bool, html:string, selector:string, error:string} "selector" is the selector that
	 *                                                                     matched ('' for body); "error" is
	 *                                                                     "no_content" when no text remains.
	 */
	public function extract( $html, \WP_Post $post ) {
		$result = array(
			'ok'       => false,
			'html'     => '',
			'selector' => '',
			'error'    => '',
		);

		$document = self::load( $html );
		if ( null === $document ) {
			$result['error'] = 'no_content';
			return $result;
		}

		self::remove_nodes( $document, self::remove_selectors() );
		$region = self::region( $document, $this->selector_for( $post ), self::content_selectors() );
		if ( null === $region['node'] ) {
			$result['error'] = 'no_content';
			return $result;
		}
		$result['selector'] = $region['selector'];

		self::remove_duplicate_h1( $document, $region['node'], DocumentBuilder::plain_text( $post->post_title ) );

		if ( ! self::has_text( $region['node'] ) ) {
			$result['error'] = 'no_content';
			return $result;
		}

		$fragment = '';
		foreach ( $region['node']->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$fragment .= $document->saveHTML( $child );
		}
		$result['ok']   = true;
		$result['html'] = $fragment;
		return $result;
	}

	/**
	 * Selector of the content region a page would use, for the diagnostics: the configured selector when
	 * it matches, otherwise the first selector of the automatic list with a match, or '' when the whole
	 * body would be used (or the HTML cannot be parsed).
	 *
	 * @param string $html     Full HTML of the rendered page.
	 * @param string $selector Configured CSS selector, if any.
	 * @return string
	 */
	public static function find_region( $html, $selector = '' ) {
		$document = self::load( $html );
		if ( null === $document ) {
			return '';
		}
		self::remove_nodes( $document, self::remove_selectors() );
		$region = self::region( $document, (string) $selector, self::content_selectors() );
		return null === $region['node'] ? '' : $region['selector'];
	}

	/**
	 * Configured content selector for a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return string CSS selector, or '' for automatic detection.
	 */
	public function selector_for( \WP_Post $post ) {
		$selector = trim( (string) $this->settings->get( 'content_selector' ) );
		/**
		 * Filters the CSS selector of the content region of a rendered page.
		 *
		 * Same subset as the "Content selector (CSS)" setting: tags, #id, .class, [attr], [attr="value"],
		 * their combinations on one element, the descendant and child combinators and comma lists. An
		 * empty selector means automatic detection (wpasl_rendered_content_selectors).
		 *
		 * @param string   $selector CSS selector, '' for automatic detection.
		 * @param \WP_Post $post     Post.
		 */
		return trim( (string) apply_filters( 'wpasl_content_selector', $selector, $post ) );
	}

	/**
	 * Selectors of the automatic content region detection, in order.
	 *
	 * @return string[]
	 */
	private static function content_selectors() {
		/**
		 * Filters the selectors tried, in order, to find the content region of a rendered page when no
		 * selector is configured. For each selector the match with the most text wins; without any match
		 * the whole body is used.
		 *
		 * @param string[] $selectors CSS selectors (same subset as the content selector setting).
		 */
		$selectors = apply_filters( 'wpasl_rendered_content_selectors', self::CONTENT_SELECTORS );
		return array_values( array_filter( array_map( 'strval', (array) $selectors ) ) );
	}

	/**
	 * Selectors of the elements removed from the content region.
	 *
	 * @return string[]
	 */
	private static function remove_selectors() {
		/**
		 * Filters the selectors of the elements removed from the content region of a rendered page before
		 * it is converted: scripts, styles, navigation, header, footer, aside, forms, hidden elements and
		 * the header/footer containers of the page builders (Elementor, Divi).
		 *
		 * @param string[] $selectors CSS selectors (same subset as the content selector setting).
		 */
		$selectors = apply_filters( 'wpasl_rendered_remove_selectors', array_merge( self::REMOVE_SELECTORS, self::BUILDER_REMOVE_SELECTORS ) );
		return array_values( array_filter( array_map( 'strval', (array) $selectors ) ) );
	}

	/**
	 * Translates a CSS selector of the supported subset into an XPath expression.
	 *
	 * Grammar: list := compound ( ',' compound )*; compound := simple ( (' ' | '>') simple )*;
	 * simple := tag? ( '#id' | '.class' | '[attr]' | '[attr="value"]' | "[attr='value']" )*.
	 * Any other syntax (pseudo-classes, pseudo-elements, sibling combinators, substring attribute
	 * operators) is rejected.
	 *
	 * @param string $selector CSS selector.
	 * @return string|null XPath expression, or null when the selector is outside the subset.
	 */
	public static function to_xpath( $selector ) {
		$selector = trim( (string) $selector );
		if ( '' === $selector ) {
			return null;
		}
		$paths = array();
		foreach ( self::split_outside_brackets( $selector, ',' ) as $compound ) {
			$path = self::compound_to_xpath( trim( $compound ) );
			if ( null === $path ) {
				return null;
			}
			$paths[] = $path;
		}
		return implode( ' | ', $paths );
	}

	/**
	 * Canonical form of a selector of the supported subset: trimmed, whitespace collapsed, one space
	 * around the child combinator and after each comma. Sanitizing a canonical selector is a no-op.
	 *
	 * @param string $selector CSS selector.
	 * @return string|null Canonical selector, or null when the selector is outside the subset.
	 */
	public static function normalize_selector( $selector ) {
		$selector = trim( (string) $selector );
		if ( '' === $selector ) {
			return '';
		}
		if ( null === self::to_xpath( $selector ) ) {
			return null;
		}
		$compounds = array();
		foreach ( self::split_outside_brackets( $selector, ',' ) as $compound ) {
			$parts = array();
			foreach ( self::tokenize_compound( trim( $compound ) ) as $token ) {
				$parts[] = '>' === $token ? '>' : $token;
			}
			$compounds[] = implode( ' ', $parts );
		}
		return implode( ', ', $compounds );
	}

	/**
	 * Splits a selector at a separator character that is outside square brackets and quotes.
	 *
	 * @param string $selector  Selector.
	 * @param string $separator One character.
	 * @return string[]
	 */
	private static function split_outside_brackets( $selector, $separator ) {
		$parts   = array();
		$current = '';
		$depth   = 0;
		$quote   = '';
		$length  = strlen( $selector );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $selector[ $i ];
			if ( '' !== $quote ) {
				if ( $char === $quote ) {
					$quote = '';
				}
			} elseif ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( '[' === $char ) {
				++$depth;
			} elseif ( ']' === $char ) {
				--$depth;
			} elseif ( $char === $separator && 0 === $depth ) {
				$parts[] = $current;
				$current = '';
				continue;
			}
			$current .= $char;
		}
		$parts[] = $current;
		return $parts;
	}

	/**
	 * Tokens of a compound selector: simple selectors and the ">" child combinator; whitespace between two
	 * simple selectors is the descendant combinator and produces no token.
	 *
	 * @param string $compound Compound selector.
	 * @return string[]
	 */
	private static function tokenize_compound( $compound ) {
		$tokens  = array();
		$current = '';
		$depth   = 0;
		$quote   = '';
		$length  = strlen( $compound );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $compound[ $i ];
			if ( '' !== $quote ) {
				if ( $char === $quote ) {
					$quote = '';
				}
				$current .= $char;
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( '[' === $char ) {
				++$depth;
			} elseif ( ']' === $char ) {
				--$depth;
			} elseif ( 0 === $depth && ( '>' === $char || ctype_space( $char ) ) ) {
				if ( '' !== $current ) {
					$tokens[] = $current;
					$current  = '';
				}
				if ( '>' === $char ) {
					$tokens[] = '>';
				}
				continue;
			}
			$current .= $char;
		}//end for
		if ( '' !== $current ) {
			$tokens[] = $current;
		}
		return $tokens;
	}

	/**
	 * XPath of one compound selector.
	 *
	 * @param string $compound Compound selector.
	 * @return string|null
	 */
	private static function compound_to_xpath( $compound ) {
		if ( '' === $compound ) {
			return null;
		}
		$xpath   = '';
		$axis    = '//';
		$pending = true;
		// Whether a simple selector is expected next (start, or right after a combinator).
		foreach ( self::tokenize_compound( $compound ) as $token ) {
			if ( '>' === $token ) {
				if ( $pending ) {
					return null;
				}
				$axis    = '/';
				$pending = true;
				continue;
			}
			$step = self::simple_to_xpath( $token );
			if ( null === $step ) {
				return null;
			}
			$xpath  .= $axis . $step;
			$axis    = '//';
			$pending = false;
		}
		return $pending ? null : $xpath;
	}

	/**
	 * XPath step of one simple selector (tag plus id, class and attribute conditions).
	 *
	 * @param string $simple Simple selector.
	 * @return string|null
	 */
	private static function simple_to_xpath( $simple ) {
		if ( ! preg_match( '/^([a-zA-Z][a-zA-Z0-9-]*|\*)?((?:#[a-zA-Z0-9_-]+|\.[a-zA-Z0-9_-]+|\[[^\]]*\])*)$/', $simple, $m ) ) {
			return null;
		}
		$tag  = isset( $m[1] ) && '' !== $m[1] ? strtolower( $m[1] ) : '*';
		$rest = isset( $m[2] ) ? $m[2] : '';
		if ( '' === $rest && '*' === $tag && '*' !== $simple ) {
			return null;
		}
		$step = $tag;
		if ( '' === $rest ) {
			return $step;
		}
		preg_match_all( '/#[a-zA-Z0-9_-]+|\.[a-zA-Z0-9_-]+|\[[^\]]*\]/', $rest, $conditions );
		foreach ( $conditions[0] as $condition ) {
			if ( '#' === $condition[0] ) {
				$step .= '[@id=' . self::xpath_literal( substr( $condition, 1 ) ) . ']';
			} elseif ( '.' === $condition[0] ) {
				$step .= "[contains(concat(' ', normalize-space(@class), ' '), " . self::xpath_literal( ' ' . substr( $condition, 1 ) . ' ' ) . ')]';
			} elseif ( preg_match( '/^\[\s*([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*\]$/', $condition, $a ) ) {
				$step .= '[@' . $a[1] . ']';
			} elseif ( preg_match( '/^\[\s*([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')\s*\]$/', $condition, $a ) ) {
				$value = isset( $a[3] ) ? $a[3] : $a[2];
				$step .= '[@' . $a[1] . '=' . self::xpath_literal( $value ) . ']';
			} else {
				return null;
			}
		}
		return $step;
	}

	/**
	 * XPath string literal, using concat() when the value contains both kinds of quotes.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function xpath_literal( $value ) {
		if ( false === strpos( $value, "'" ) ) {
			return "'" . $value . "'";
		}
		if ( false === strpos( $value, '"' ) ) {
			return '"' . $value . '"';
		}
		$parts = array();
		foreach ( explode( "'", $value ) as $index => $part ) {
			if ( $index > 0 ) {
				$parts[] = '"\'"';
			}
			if ( '' !== $part ) {
				$parts[] = "'" . $part . "'";
			}
		}
		return 'concat(' . implode( ', ', $parts ) . ')';
	}

	/**
	 * Parses a full HTML document with libxml errors silenced (same approach as LeagueConverter::clean()).
	 *
	 * @param string $html HTML.
	 * @return \DOMDocument|null Null when there is nothing to parse.
	 */
	private static function load( $html ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument( '1.0', 'UTF-8' );
		$loaded   = $document->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || ! $document->getElementsByTagName( 'body' )->length ) {
			return null;
		}
		return $document;
	}

	/**
	 * Removes every node matching the selectors.
	 *
	 * @param \DOMDocument $document  Document.
	 * @param string[]     $selectors CSS selectors; the ones outside the subset are ignored.
	 * @return void
	 */
	private static function remove_nodes( \DOMDocument $document, array $selectors ) {
		$expressions = array();
		foreach ( $selectors as $selector ) {
			$xpath = self::to_xpath( $selector );
			if ( null !== $xpath ) {
				$expressions[] = $xpath;
			}
		}
		if ( empty( $expressions ) ) {
			return;
		}
		$query = new \DOMXPath( $document );
		$nodes = $query->query( implode( ' | ', $expressions ) );
		if ( ! $nodes ) {
			return;
		}
		foreach ( iterator_to_array( $nodes ) as $node ) {
			if ( $node->parentNode ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$node->parentNode->removeChild( $node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}
	}

	/**
	 * Finds the content region: the configured selector when it matches, then the automatic list, then body.
	 * For each selector, among the matches that contain text, the one with the most text wins.
	 *
	 * @param \DOMDocument $document   Cleaned document.
	 * @param string       $configured Configured selector, or ''.
	 * @param string[]     $automatic  Automatic selectors.
	 * @return array{node:\DOMNode|null, selector:string}
	 */
	private static function region( \DOMDocument $document, $configured, array $automatic ) {
		$query      = new \DOMXPath( $document );
		$candidates = '' !== $configured ? array_merge( array( $configured ), $automatic ) : $automatic;
		foreach ( $candidates as $selector ) {
			$xpath = self::to_xpath( $selector );
			if ( null === $xpath ) {
				continue;
			}
			$nodes = $query->query( $xpath );
			if ( ! $nodes || ! $nodes->length ) {
				continue;
			}
			$best   = null;
			$length = 0;
			foreach ( $nodes as $node ) {
				$text = self::text_length( $node );
				if ( $text > $length ) {
					$best   = $node;
					$length = $text;
				}
			}
			if ( null !== $best ) {
				return array(
					'node'     => $best,
					'selector' => $selector,
				);
			}
		}//end foreach
		$body = $document->getElementsByTagName( 'body' )->item( 0 );
		return array(
			'node'     => $body,
			'selector' => '',
		);
	}

	/**
	 * Removes the first level-1 heading of the region whose text matches the post title.
	 *
	 * @param \DOMDocument $document Document.
	 * @param \DOMNode     $region   Region node.
	 * @param string       $title    Decoded post title.
	 * @return void
	 */
	private static function remove_duplicate_h1( \DOMDocument $document, \DOMNode $region, $title ) {
		$expected = self::normalize_text( $title );
		if ( '' === $expected ) {
			return;
		}
		$query = new \DOMXPath( $document );
		$nodes = $query->query( './/h1', $region );
		if ( ! $nodes ) {
			return;
		}
		foreach ( $nodes as $node ) {
			if ( self::normalize_text( $node->textContent ) === $expected ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$node->parentNode->removeChild( $node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				return;
			}
		}
	}

	/**
	 * Text of a node for comparisons: entity-decoded, whitespace collapsed, lowercase.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function normalize_text( $text ) {
		$text = str_replace( "\xC2\xA0", ' ', DocumentBuilder::plain_text( $text ) );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return mb_strtolower( trim( (string) $text ) );
	}

	/**
	 * Length of the visible text of a node (0 for whitespace only).
	 *
	 * @param \DOMNode $node Node.
	 * @return int
	 */
	private static function text_length( \DOMNode $node ) {
		return strlen( self::normalize_text( $node->textContent ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Whether a node still has visible text.
	 *
	 * @param \DOMNode $node Node.
	 * @return bool
	 */
	private static function has_text( \DOMNode $node ) {
		return self::text_length( $node ) > 0;
	}
}
