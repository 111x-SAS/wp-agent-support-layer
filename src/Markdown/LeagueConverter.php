<?php
/**
 * Converter backed by league/html-to-markdown (prefixed).
 *
 * @package WPASL
 */

namespace WPASL\Markdown;

use WPASL\Vendor\League\HTMLToMarkdown\Converter\TableConverter;
use WPASL\Vendor\League\HTMLToMarkdown\HtmlConverter;

/**
 * Cleans the HTML (scripts, styles, forms, iframes, comments), absolutizes URLs and converts it.
 */
final class LeagueConverter implements ConverterInterface {

	/**
	 * Elements removed together with their content.
	 *
	 * @var string[]
	 */
	const REMOVE = array( 'script', 'style', 'form', 'iframe', 'noscript', 'template', 'object', 'embed', 'canvas', 'svg', 'button', 'input', 'select', 'textarea' );

	/**
	 * Attributes that hold URLs to absolutize, keyed by element.
	 *
	 * @var array<string, string[]>
	 */
	const URL_ATTRIBUTES = array(
		'a'      => array( 'href' ),
		'img'    => array( 'src' ),
		'source' => array( 'src' ),
		'video'  => array( 'src', 'poster' ),
		'audio'  => array( 'src' ),
	);

	/**
	 * Library instance.
	 *
	 * @var HtmlConverter
	 */
	private $converter;

	/**
	 * Base URL used to absolutize relative links.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Constructor.
	 *
	 * @param string|null $base_url Base URL; defaults to the home URL.
	 */
	public function __construct( $base_url = null ) {
		$this->base_url  = null === $base_url ? home_url( '/' ) : $base_url;
		$this->converter = new HtmlConverter(
			array(
				'header_style'            => 'atx',
				'strip_tags'              => true,
				'remove_nodes'            => implode( ' ', self::REMOVE ),
				'hard_break'              => false,
				'use_autolinks'           => false,
				'strip_placeholder_links' => true,
				'preserve_comments'       => false,
			)
		);
		$this->converter->getEnvironment()->addConverter( new TableConverter() );
	}

	/**
	 * Whether the prefixed library is available (it is bundled at build time).
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( HtmlConverter::class );
	}

	/**
	 * Cleans and converts an HTML fragment.
	 *
	 * @param string $html HTML fragment.
	 * @return string Markdown.
	 */
	public function convert( $html ) {
		$html = trim( (string) $html );
		if ( '' === $html ) {
			return '';
		}

		$html     = $this->clean( $html );
		$markdown = $this->converter->convert( $html );
		$markdown = preg_replace( "/\n{3,}/", "\n\n", $markdown );

		return trim( $markdown ) . "\n";
	}

	/**
	 * Removes non-content nodes and comments, and absolutizes URLs.
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	private function clean( $html ) {
		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument( '1.0', 'UTF-8' );
		$document->loadHTML( '<?xml encoding="UTF-8"><div id="wpasl-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new \DOMXPath( $document );

		$expression = '//comment()';
		foreach ( self::REMOVE as $tag ) {
			$expression .= ' | //' . $tag;
		}
		$nodes = iterator_to_array( $xpath->query( $expression ) );
		foreach ( $nodes as $node ) {
			if ( $node->parentNode ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$node->parentNode->removeChild( $node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}

		foreach ( self::URL_ATTRIBUTES as $tag => $attributes ) {
			foreach ( $document->getElementsByTagName( $tag ) as $element ) {
				foreach ( $attributes as $attribute ) {
					if ( $element->hasAttribute( $attribute ) ) {
						$element->setAttribute( $attribute, $this->absolutize( $element->getAttribute( $attribute ) ) );
					}
				}
			}
		}

		$root  = $document->getElementById( 'wpasl-root' );
		$inner = '';
		if ( $root ) {
			foreach ( $root->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$inner .= $document->saveHTML( $child );
			}
		}
		return $inner;
	}

	/**
	 * Sets the URL relative references are resolved against (the document's own URL).
	 *
	 * @param string $base_url Base URL.
	 * @return void
	 */
	public function set_base_url( $base_url ) {
		$this->base_url = '' === (string) $base_url ? home_url( '/' ) : (string) $base_url;
	}

	/**
	 * Makes a URL absolute against the base URL: scheme-relative URLs take the base scheme, "./" and
	 * "../" segments are resolved, fragments are left alone.
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	public function absolutize( $url ) {
		$url = trim( $url );
		if ( '' === $url || preg_match( '#^(?:[a-z][a-z0-9+.-]*:|\#)#i', $url ) ) {
			return $url;
		}

		$base   = wp_parse_url( $this->base_url );
		$scheme = isset( $base['scheme'] ) ? $base['scheme'] : 'https';
		if ( 0 === strpos( $url, '//' ) ) {
			return $scheme . ':' . $url;
		}

		$root = $scheme . '://' . ( isset( $base['host'] ) ? $base['host'] : '' ) . ( isset( $base['port'] ) ? ':' . $base['port'] : '' );
		$path = isset( $base['path'] ) && '' !== $base['path'] ? $base['path'] : '/';

		if ( '/' === $url[0] ) {
			return $root . self::normalize_path( $url );
		}
		if ( '?' === $url[0] ) {
			return $root . untrailingslashit( $path ) . $url;
		}
		// Relative to the base directory (everything up to the last slash of the base path).
		$directory = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );
		return $root . self::normalize_path( $directory . $url );
	}

	/**
	 * Resolves "." and ".." segments of an absolute path (query string and fragment preserved).
	 *
	 * @param string $path Path starting with "/".
	 * @return string
	 */
	private static function normalize_path( $path ) {
		$suffix = '';
		if ( preg_match( '/^([^?#]*)(.*)$/s', $path, $m ) ) {
			$path   = $m[1];
			$suffix = $m[2];
		}
		$parts = explode( '/', $path );
		$last  = count( $parts ) - 1;
		$out   = array();
		foreach ( $parts as $index => $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				if ( '..' === $segment && count( $out ) > 1 ) {
					array_pop( $out );
				}
				if ( $index === $last ) {
					$out[] = '';
				}
				continue;
			}
			$out[] = $segment;
		}
		return implode( '/', $out ) . $suffix;
	}
}
