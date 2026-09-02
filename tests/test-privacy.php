<?php
/**
 * Privacy tests: no external requests.
 *
 * @package WPASL
 */

/**
 * Only the diagnostics probe uses the HTTP API, and it refuses hosts other than the site's own.
 */
class Test_Privacy extends WP_UnitTestCase {

	public function test_only_the_crawler_probe_calls_the_http_api() {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPASL_DIR . 'src' ) );
		$users = array();
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$code = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( preg_match( '/wp_remote_|wp_safe_remote_|curl_init|file_get_contents\s*\(\s*[\'"]https?:|fsockopen|WP_Http\b/', $code ) ) {
				$users[] = basename( $file->getPathname() );
			}
		}
		$this->assertSame( array( 'CrawlerProbe.php' ), $users );
	}

	public function test_no_telemetry_or_external_hosts_in_runtime_code() {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPASL_DIR . 'src' ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$code = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			preg_match_all( '#https?://[a-z0-9.-]+#i', $code, $m );
			foreach ( array_unique( $m[0] ) as $url ) {
				$host = wp_parse_url( $url, PHP_URL_HOST );
				$this->assertMatchesRegularExpression(
					'/^(github\.com|contentsignals\.org|www\.w3\.org|schema\.org|platform\.openai\.com|support\.anthropic\.com|developers\.google\.com|support\.apple\.com|commoncrawl\.org|developers\.facebook\.com|developer\.amazon\.com|docs\.diffbot\.com|webz\.io|aspiegel\.com|docs\.perplexity\.ai|docs\.cohere\.com|duckduckgo\.com|about\.you\.com|docs\.mistral\.ai|example\.invalid)$/',
					$host,
					'Only documentation links and vocabularies may appear in ' . basename( $file->getPathname() ) . ': ' . $url
				);
			}
		}
	}
}
