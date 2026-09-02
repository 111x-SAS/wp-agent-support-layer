<?php
/**
 * Plugin settings.
 *
 * @package WPASL
 */

namespace WPASL;

/**
 * Reads, sanitizes and writes the single plugin option.
 */
final class Settings {

	const OPTION = 'wpasl_settings';

	/**
	 * Setting keys owned by each admin tab. Only the keys of the submitted tab are sanitized.
	 *
	 * @var array<string, string[]>
	 */
	const TAB_KEYS = array(
		'general'   => array( 'post_types', 'schedule', 'batch_size' ),
		'signals'   => array( 'signal_search', 'signal_ai_input', 'signal_ai_train', 'content_usage_header' ),
		'crawlers'  => array( 'crawler_overrides' ),
		'llms'      => array( 'llms_description', 'llms_intro', 'llms_limit', 'llms_full_enabled', 'llms_full_max_bytes' ),
		'manifests' => array( 'manifest_enabled', 'contact_email' ),
	);

	/**
	 * Form field (megabytes) that feeds the llms_full_max_bytes setting.
	 */
	const LLMS_FULL_MAX_FIELD_MB = 'llms_full_max_bytes_mb';

	/**
	 * Allowed cron intervals.
	 *
	 * @var string[]
	 */
	const SCHEDULES = array( 'hourly', 'twicedaily', 'daily', 'weekly' );

	/**
	 * Cached option value.
	 *
	 * @var array<string, mixed>|null
	 */
	private $cache = null;

	/**
	 * Default values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'post_types'           => array( 'post', 'page' ),
			'schedule'             => 'daily',
			'batch_size'           => 50,
			'signal_search'        => 'yes',
			'signal_ai_input'      => 'yes',
			'signal_ai_train'      => 'no',
			'content_usage_header' => true,
			'crawler_overrides'    => array(),
			'llms_description'     => '',
			'llms_intro'           => '',
			'llms_limit'           => 100,
			'llms_full_enabled'    => false,
			'llms_full_max_bytes'  => 5 * MB_IN_BYTES,
			'manifest_enabled'     => true,
			'contact_email'        => '',
		);
	}

	/**
	 * Returns all settings merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all() {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return $this->cache;
	}

	/**
	 * Returns one setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( $key ) {
		$all = $this->all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Persists a full, already-sanitized settings array.
	 *
	 * @param array<string, mixed> $values Values to store.
	 * @return void
	 */
	public function update( array $values ) {
		$this->cache = null;
		update_option( self::OPTION, $values, true );
	}

	/**
	 * Clears the in-memory cache (used after external option writes).
	 *
	 * @return void
	 */
	public function flush_cache() {
		$this->cache = null;
	}

	/**
	 * Enabled post types, restricted to public, currently registered ones.
	 *
	 * @return string[]
	 */
	public function enabled_post_types() {
		$enabled = (array) $this->get( 'post_types' );
		return array_values( array_intersect( $enabled, self::selectable_post_types() ) );
	}

	/**
	 * Post types an administrator may enable.
	 *
	 * @return string[]
	 */
	public static function selectable_post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * Contact email, falling back to the site admin email.
	 *
	 * @return string
	 */
	public function contact_email() {
		$email = (string) $this->get( 'contact_email' );
		return '' === $email ? (string) get_option( 'admin_email' ) : $email;
	}

	/**
	 * Settings API sanitize callback. Merges the submitted tab over the stored values.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ) {
		$current = $this->all();
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$tab  = isset( $input['_tab'] ) ? sanitize_key( $input['_tab'] ) : '';
		$keys = isset( self::TAB_KEYS[ $tab ] ) ? self::TAB_KEYS[ $tab ] : array_keys( self::defaults() );

		$clean = $current;
		foreach ( $keys as $key ) {
			$value = isset( $input[ $key ] ) ? $input[ $key ] : null;
			if ( self::LLMS_FULL_MAX_FIELD_MB === $key . '_mb' && isset( $input[ self::LLMS_FULL_MAX_FIELD_MB ] ) ) {
				// The form submits megabytes; the option stores bytes. Converting only from the form field keeps
				// sanitize() idempotent (WordPress sanitizes twice when the option is created).
				$mb    = absint( $input[ self::LLMS_FULL_MAX_FIELD_MB ] );
				$value = $mb > 0 ? min( 100, $mb ) * MB_IN_BYTES : null;
			}
			$clean[ $key ] = $this->sanitize_key_value( $key, $value );
		}

		$this->cache = null;
		return $clean;
	}

	/**
	 * Sanitizes one setting. A null value means "absent from the form" (unchecked box, empty list).
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	private function sanitize_key_value( $key, $value ) {
		$defaults = self::defaults();

		switch ( $key ) {
			case 'post_types':
				$value = array_map( 'sanitize_key', (array) $value );
				return array_values( array_intersect( $value, self::selectable_post_types() ) );

			case 'schedule':
				$value = sanitize_key( (string) $value );
				return in_array( $value, self::SCHEDULES, true ) ? $value : $defaults['schedule'];

			case 'batch_size':
				$number = absint( $value );
				return $number > 0 ? min( 500, $number ) : $defaults['batch_size'];

			case 'llms_limit':
				$number = absint( $value );
				return $number > 0 ? min( 1000, $number ) : $defaults['llms_limit'];

			case 'llms_full_max_bytes':
				$bytes = absint( $value );
				return $bytes > 0 ? min( 100 * MB_IN_BYTES, $bytes ) : $defaults['llms_full_max_bytes'];

			case 'signal_search':
			case 'signal_ai_input':
			case 'signal_ai_train':
				return 'yes' === $value ? 'yes' : 'no';

			case 'content_usage_header':
			case 'llms_full_enabled':
			case 'manifest_enabled':
				return ! empty( $value );

			case 'crawler_overrides':
				$clean = array();
				foreach ( (array) $value as $agent => $policy ) {
					$agent  = sanitize_text_field( (string) $agent );
					$policy = sanitize_key( (string) $policy );
					if ( '' !== $agent && in_array( $policy, array( 'allow', 'block' ), true ) ) {
						$clean[ $agent ] = $policy;
					}
				}
				return $clean;

			case 'llms_description':
				return sanitize_text_field( (string) $value );

			case 'llms_intro':
				return sanitize_textarea_field( (string) $value );

			case 'contact_email':
				$email = sanitize_email( (string) $value );
				return is_email( $email ) ? $email : '';
		}//end switch

		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : null;
	}
}
