<?php
/**
 * Per-post exclusion.
 *
 * @package WPASL
 */

namespace WPASL\Admin;

use WPASL\Settings;

/**
 * "Exclude from agent layer" checkbox and "Markdown content source" override, stored as protected post meta
 * and exposed to the REST API for editors.
 */
final class ExcludeMetaBox {

	const META  = '_wpasl_exclude';
	const NONCE = 'wpasl_exclude_nonce';

	/**
	 * Per-post content source override: "editor", "rendered" or '' (follow the settings).
	 */
	const SOURCE_META = '_wpasl_content_source';

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
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_meta' ), 20 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'on_meta_change' ), 10, 3 );
		}
	}

	/**
	 * Clears the cached exclusion list when the flag changes.
	 *
	 * @param int|int[] $meta_id  Meta id(s).
	 * @param int       $post_id  Post id.
	 * @param string    $meta_key Meta key.
	 * @return void
	 */
	public function on_meta_change( $meta_id, $post_id, $meta_key ) {
		if ( self::META === $meta_key ) {
			\WPASL\Content\Eligibility::flush_excluded_cache();
		}
	}

	/**
	 * Whether a post is excluded.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function is_excluded( $post_id ) {
		// Any stored value other than '' and '0' excludes (third parties may write "yes"); Eligibility::excluded_ids()
		// applies the same predicate in SQL.
		$value = (string) get_post_meta( (int) $post_id, self::META, true );
		return '' !== $value && '0' !== $value;
	}

	/**
	 * Content source override of a post: "editor", "rendered" or '' when the post follows the settings.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public static function source_override( $post_id ) {
		return self::sanitize_source( get_post_meta( (int) $post_id, self::SOURCE_META, true ) );
	}

	/**
	 * Sanitizes a content source override: "editor", "rendered" or '' for anything else.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_source( $value ) {
		$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
		return in_array( $value, Settings::CONTENT_SOURCES, true ) ? $value : '';
	}

	/**
	 * Registers the meta keys for each enabled post type.
	 *
	 * @return void
	 */
	public function register_meta() {
		foreach ( $this->settings->enabled_post_types() as $type ) {
			register_post_meta(
				$type,
				self::META,
				array(
					'type'              => 'boolean',
					'description'       => __( 'Exclude from the agent layer (Markdown, llms.txt, manifests).', 'wp-agent-support-layer' ),
					'single'            => true,
					'default'           => false,
					'show_in_rest'      => true,
					'sanitize_callback' => 'rest_sanitize_boolean',
					'auth_callback'     => array( $this, 'can_edit' ),
				)
			);
			register_post_meta(
				$type,
				self::SOURCE_META,
				array(
					'type'              => 'string',
					'description'       => __( 'Content source of the Markdown version: "editor", "rendered" or empty to follow the settings.', 'wp-agent-support-layer' ),
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => array( __CLASS__, 'sanitize_source' ),
					'auth_callback'     => array( $this, 'can_edit' ),
				)
			);
			// auth_callback only guards writes: hide the values from readers without edit permission.
			add_filter( 'rest_prepare_' . $type, array( $this, 'hide_meta_from_readers' ), 10, 2 );
		}//end foreach
	}

	/**
	 * Removes the exclusion flag and the content source override from REST responses for users who cannot
	 * edit the post.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_Post          $post     Post.
	 * @return \WP_REST_Response
	 */
	public function hide_meta_from_readers( $response, $post ) {
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post || current_user_can( 'edit_post', $post->ID ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['meta'] ) || ! is_array( $data['meta'] ) ) {
			return $response;
		}
		$changed = false;
		foreach ( array( self::META, self::SOURCE_META ) as $key ) {
			if ( array_key_exists( $key, $data['meta'] ) ) {
				unset( $data['meta'][ $key ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * REST auth callback.
	 *
	 * @param bool   $allowed  Unused.
	 * @param string $meta_key Unused.
	 * @param int    $post_id  Post id.
	 * @return bool
	 */
	public function can_edit( $allowed, $meta_key, $post_id ) {
		return current_user_can( 'edit_post', (int) $post_id );
	}

	/**
	 * Adds the meta box on enabled post types.
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	public function add_meta_box( $post_type ) {
		if ( ! in_array( $post_type, $this->settings->enabled_post_types(), true ) ) {
			return;
		}
		add_meta_box(
			'wpasl-exclude',
			__( 'Agent Support Layer', 'wp-agent-support-layer' ),
			array( $this, 'render' ),
			$post_type,
			'side',
			'default'
		);
	}

	/**
	 * Renders the checkbox and the content source select.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function render( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );
		$source  = self::source_override( $post->ID );
		$options = array(
			''         => __( 'Follow the settings', 'wp-agent-support-layer' ),
			'editor'   => __( 'Editor content', 'wp-agent-support-layer' ),
			'rendered' => __( 'Rendered page', 'wp-agent-support-layer' ),
		);
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::META ); ?>" value="1" <?php checked( self::is_excluded( $post->ID ) ); ?> />
			<?php esc_html_e( 'Exclude from the agent layer', 'wp-agent-support-layer' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Hides this item from the Markdown version, llms.txt and the agent manifest.', 'wp-agent-support-layer' ); ?></p>
		<p style="margin-top:12px;">
			<label for="wpasl-content-source"><strong><?php esc_html_e( 'Markdown content source', 'wp-agent-support-layer' ); ?></strong></label><br />
			<select id="wpasl-content-source" name="<?php echo esc_attr( self::SOURCE_META ); ?>" style="max-width:100%;">
				<?php foreach ( $options as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $source, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="description"><?php esc_html_e( 'Where the Markdown body comes from: the editor content, or the page as rendered by the theme and page builders. "Follow the settings" applies the content source configured for this content type.', 'wp-agent-support-layer' ); ?></p>
		<?php
	}

	/**
	 * Saves the checkbox and the content source select. Never generates any document.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, $this->settings->enabled_post_types(), true ) ) {
			return;
		}

		if ( ! empty( $_POST[ self::META ] ) ) {
			update_post_meta( $post_id, self::META, true );
		} else {
			delete_post_meta( $post_id, self::META );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_source() reduces the value to editor, rendered or ''.
		$source = isset( $_POST[ self::SOURCE_META ] ) ? self::sanitize_source( wp_unslash( $_POST[ self::SOURCE_META ] ) ) : '';
		if ( '' !== $source ) {
			update_post_meta( $post_id, self::SOURCE_META, $source );
		} else {
			// The default ("follow the settings") is never stored.
			delete_post_meta( $post_id, self::SOURCE_META );
		}
	}
}
