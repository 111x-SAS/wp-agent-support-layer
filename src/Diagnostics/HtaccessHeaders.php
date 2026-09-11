<?php
/**
 * Auto-apply of the .htaccess headers block.
 *
 * @package WPASL
 */

namespace WPASL\Diagnostics;

use WPASL\Admin\Page;
use WPASL\Storage;

// Backup and restoration read and write the site's real .htaccess file directly, outside the plugin's own
// storage API (Storage::write()/read() are scoped to the plugin's uploads directory).
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.unlink_unlink

/**
 * Detects whether the environment supports the .htaccess auto-apply, and runs the explicit admin action
 * that backs up, writes, verifies and (on failure) restores the .htaccess headers block.
 */
final class HtaccessHeaders {

	/**
	 * Admin-post action.
	 */
	const ACTION = 'wpasl_apply_htaccess';

	/**
	 * Nonce name of the action.
	 */
	const NONCE = 'wpasl_apply_htaccess_nonce';

	/**
	 * Marker name for insert_with_markers()/extract_from_markers().
	 */
	const MARKER = 'WP Agent Support Layer';

	/**
	 * Relative path, inside the plugin's storage, of the .htaccess backup.
	 */
	const BACKUP_FILE = 'system/htaccess.backup';

	/**
	 * Option (no autoload) holding the backup metadata.
	 */
	const BACKUP_OPTION = 'wpasl_htaccess_backup';

	/**
	 * Transient prefix (suffixed with the user id) holding the result of the last apply() shown after the
	 * redirect.
	 */
	const RESULT_TRANSIENT = 'wpasl_htaccess_result_';

	/**
	 * Page cache detection and snippets.
	 *
	 * @var PageCache
	 */
	private $page_cache;

	/**
	 * Probe (reused for the verification request).
	 *
	 * @var CrawlerProbe
	 */
	private $probe;

	/**
	 * Storage (the backup file).
	 *
	 * @var Storage
	 */
	private $storage;

	/**
	 * Page.
	 *
	 * @var Page
	 */
	private $page;

	/**
	 * Constructor.
	 *
	 * @param PageCache    $page_cache Page cache detection and snippets.
	 * @param CrawlerProbe $probe      Probe.
	 * @param Storage      $storage    Storage.
	 * @param Page         $page       Page.
	 */
	public function __construct( PageCache $page_cache, CrawlerProbe $probe, Storage $storage, Page $page ) {
		$this->page_cache = $page_cache;
		$this->probe      = $probe;
		$this->storage    = $storage;
		$this->page       = $page;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Whether the current environment is plausibly compatible with the .htaccess auto-apply.
	 *
	 * Apache is detected through apache_get_modules() when PHP runs as an Apache module, or through
	 * SERVER_SOFTWARE otherwise (PHP-FPM, CGI). LiteSpeed is detected through SERVER_SOFTWARE and considered
	 * compatible unless LSWS_EDITION identifies OpenLiteSpeed. Any other server is not compatible.
	 *
	 * @return array{compatible:bool, server:string, version:string, mod_headers:bool|null, reason:string}
	 */
	public function environment() {
		if ( function_exists( 'apache_get_modules' ) ) {
			$modules = (array) apache_get_modules();
			if ( ! in_array( 'mod_headers', $modules, true ) ) {
				$result = array(
					'compatible'  => false,
					'server'      => 'Apache',
					'version'     => '',
					'mod_headers' => false,
					'reason'      => 'mod_headers is not loaded',
				);
			} else {
				$version_string = function_exists( 'apache_get_version' ) ? (string) apache_get_version() : '';
				$version        = '';
				if ( preg_match( '#Apache/(\d+\.\d+\.\d+)#', $version_string, $m ) ) {
					$version = $m[1];
				}
				$compatible = '' === $version || version_compare( $version, '2.4.7', '>=' );
				$result     = array(
					'compatible'  => $compatible,
					'server'      => '' !== $version ? 'Apache ' . $version : 'Apache',
					'version'     => $version,
					'mod_headers' => true,
					'reason'      => $compatible ? '' : 'Apache version is below 2.4.7',
				);
			}//end if
		} else {
			$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only server identification, never output.
			if ( preg_match( '#^Apache(?:/(\d+\.\d+(?:\.\d+)?))?#i', $software, $m ) ) {
				$version    = isset( $m[1] ) ? $m[1] : '';
				$compatible = '' === $version || version_compare( $version, '2.4.7', '>=' );
				$result     = array(
					'compatible'  => $compatible,
					'server'      => '' !== $version ? 'Apache ' . $version : 'Apache',
					'version'     => $version,
					'mod_headers' => null,
					'reason'      => $compatible ? '' : 'Apache version is below 2.4.7',
				);
			} elseif ( preg_match( '#LiteSpeed#i', $software ) ) {
				$edition = isset( $_SERVER['LSWS_EDITION'] ) ? (string) $_SERVER['LSWS_EDITION'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only server identification, never output.
				$is_ols  = '' !== $edition && false !== stripos( $edition, 'openlitespeed' );
				$result  = array(
					'compatible'  => ! $is_ols,
					'server'      => 'LiteSpeed',
					'version'     => '',
					'mod_headers' => null,
					'reason'      => $is_ols ? 'OpenLiteSpeed is not supported' : '',
				);
			} else {
				$result = array(
					'compatible'  => false,
					'server'      => '' !== $software ? $software : 'unknown',
					'version'     => '',
					'mod_headers' => null,
					'reason'      => 'server not recognized as Apache or LiteSpeed Enterprise',
				);
			}//end if
		}//end if

		/**
		 * Filters the .htaccess auto-apply environment compatibility result.
		 *
		 * @param array{compatible:bool, server:string, version:string, mod_headers:bool|null, reason:string} $result Result.
		 */
		return apply_filters( 'wpasl_htaccess_environment', $result );
	}

	/**
	 * Absolute path of the .htaccess file the auto-apply feature reads and writes.
	 *
	 * @return string
	 */
	public function file() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		$file = trailingslashit( get_home_path() ) . '.htaccess';

		/**
		 * Filters the path to the .htaccess file the auto-apply feature reads and writes.
		 *
		 * @param string $file Absolute path.
		 */
		return (string) apply_filters( 'wpasl_htaccess_file', $file );
	}

	/**
	 * Whether the .htaccess file can be written: writable if it exists, or its directory writable if not.
	 *
	 * @return bool
	 */
	public function writable() {
		$file = $this->file();
		return file_exists( $file ) ? wp_is_writable( $file ) : wp_is_writable( dirname( $file ) );
	}

	/**
	 * Whether the tab should show the capability check and the apply button: single site, Cache Enabler
	 * active, compatible environment and a writable .htaccess.
	 *
	 * @return bool
	 */
	public function available() {
		return ! is_multisite() && null !== PageCache::detect() && $this->environment()['compatible'] && $this->writable();
	}

	/**
	 * Status of the block already on disk: not_applied, current (same content the plugin would write now,
	 * ignoring comment lines and leading/trailing whitespace) or stale (present with different content).
	 *
	 * @return string not_applied, current or stale.
	 */
	public function status() {
		$file  = $this->file();
		$lines = extract_from_markers( $file, self::MARKER );
		if ( empty( $lines ) ) {
			return 'not_applied';
		}
		$strip = static function ( array $lines ) {
			$stripped = array();
			foreach ( $lines as $line ) {
				$trimmed = trim( $line );
				if ( '' !== $trimmed && '#' !== substr( $trimmed, 0, 1 ) ) {
					$stripped[] = $trimmed;
				}
			}
			return $stripped;
		};
		return $strip( $lines ) === $strip( $this->page_cache->htaccess_lines() ) ? 'current' : 'stale';
	}

	/**
	 * Saves a backup of the current .htaccess (or records that it did not exist) in the plugin's storage.
	 * Only the most recent backup is kept.
	 *
	 * @return bool
	 */
	public function backup() {
		$file     = $this->file();
		$existed  = file_exists( $file );
		$contents = $existed ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Backup of the site's own .htaccess before writing it.
		if ( ! $this->storage->write( self::BACKUP_FILE, $contents ) ) {
			return false;
		}
		update_option(
			self::BACKUP_OPTION,
			array(
				'time'    => time(),
				'existed' => $existed,
				'bytes'   => strlen( $contents ),
				'file'    => $file,
			),
			false
		);
		return true;
	}

	/**
	 * Absolute path of the backup file, for the error notice when restore() fails.
	 *
	 * @return string
	 */
	public function backup_path() {
		return $this->storage->path( self::BACKUP_FILE );
	}

	/**
	 * Restores .htaccess from the backup: the original content byte for byte, or removes the file when it
	 * did not exist before the backup.
	 *
	 * @return bool
	 */
	public function restore() {
		$meta = get_option( self::BACKUP_OPTION );
		if ( ! is_array( $meta ) ) {
			return false;
		}
		$file = isset( $meta['file'] ) ? (string) $meta['file'] : $this->file();
		if ( ! empty( $meta['existed'] ) ) {
			$backup = $this->storage->read( self::BACKUP_FILE );
			if ( null === $backup ) {
				return false;
			}
			return false !== file_put_contents( $file, $backup, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Restoring the site's own .htaccess on the same path (not a rename, to keep inode/permissions).
		}
		return ! file_exists( $file ) || unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing the .htaccess this action created, on verification failure.
	}

	/**
	 * Verifies the block is actually served by the web server: a real request to a sample page must answer
	 * with the X-WPASL-Headers marker (never sent from PHP) and Content-Signal.
	 *
	 * @return array{ok:bool, reason:string}
	 */
	public function verify() {
		$post = $this->probe->sample_post();
		$url  = $post ? get_permalink( $post ) : home_url( '/' );
		$url  = add_query_arg( 'wpasl_verify', wp_generate_password( 8, false ), $url );

		$user_agent = 'WP-Agent-Support-Layer-Diagnostics/' . WPASL_VERSION;
		$result     = $this->probe->fetch( $url, $user_agent, 'text/html' );

		if ( '' !== $result['error'] ) {
			return array(
				'ok'     => false,
				'reason' => 'request_error:' . $result['error'],
			);
		}
		if ( $result['status'] >= 300 && $result['status'] < 400 ) {
			return array(
				'ok'     => false,
				'reason' => 'redirect_' . $result['status'],
			);
		}
		if ( 200 !== $result['status'] ) {
			return array(
				'ok'     => false,
				'reason' => 'http_' . $result['status'],
			);
		}
		$marker = isset( $result['headers']['x-wpasl-headers'] ) ? $result['headers']['x-wpasl-headers'] : '';
		if ( false === strpos( $marker, PageCache::MARKER_VALUE ) ) {
			return array(
				'ok'     => false,
				'reason' => 'header_missing:' . PageCache::MARKER_HEADER,
			);
		}
		if ( empty( $result['headers']['content-signal'] ) ) {
			return array(
				'ok'     => false,
				'reason' => 'header_missing:Content-Signal',
			);
		}
		return array(
			'ok'     => true,
			'reason' => '',
		);
	}

	/**
	 * Runs the whole apply sequence: environment + writability, backup, write between markers, verify, and
	 * restore on any failure. Never redirects or prints; handle() and the tests use this directly.
	 *
	 * @return array{ok:bool, step:string, reason:string, restored:bool|null}
	 */
	public function apply() {
		if ( is_multisite() ) {
			return array(
				'ok'       => false,
				'step'     => 'environment',
				'reason'   => 'multisite',
				'restored' => null,
			);
		}

		$environment = $this->environment();
		if ( ! $environment['compatible'] ) {
			return array(
				'ok'       => false,
				'step'     => 'environment',
				'reason'   => $environment['reason'],
				'restored' => null,
			);
		}
		if ( ! $this->writable() ) {
			return array(
				'ok'       => false,
				'step'     => 'environment',
				'reason'   => '.htaccess is not writable',
				'restored' => null,
			);
		}

		if ( ! $this->backup() ) {
			return array(
				'ok'       => false,
				'step'     => 'backup',
				'reason'   => 'the backup could not be saved',
				'restored' => null,
			);
		}

		$written = insert_with_markers( $this->file(), self::MARKER, $this->page_cache->htaccess_lines() );
		if ( ! $written ) {
			return array(
				'ok'       => false,
				'step'     => 'write',
				'reason'   => '.htaccess could not be written',
				'restored' => null,
			);
		}

		$verify = $this->verify();
		if ( ! $verify['ok'] ) {
			$restored = $this->restore();
			return array(
				'ok'       => false,
				'step'     => 'verify',
				'reason'   => $verify['reason'],
				'restored' => $restored,
			);
		}

		return array(
			'ok'       => true,
			'step'     => '',
			'reason'   => '',
			'restored' => null,
		);
	}

	/**
	 * Handles the admin-post action: capability, nonce, apply(), stores the result for the redirect target.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! current_user_can( Page::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-agent-support-layer' ), 403 );
		}
		check_admin_referer( self::ACTION, self::NONCE );

		$result = $this->apply();
		set_transient( self::RESULT_TRANSIENT . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'wpasl_notice', 'htaccess', $this->page->url( 'diagnostics' ) ) );
		exit;
	}

	/**
	 * Reads and clears the transient result of the last apply() for the current user.
	 *
	 * @return array{ok:bool, step:string, reason:string, restored:bool|null}|null
	 */
	public function last_result() {
		$key    = self::RESULT_TRANSIENT . get_current_user_id();
		$result = get_transient( $key );
		delete_transient( $key );
		return is_array( $result ) ? $result : null;
	}
}
