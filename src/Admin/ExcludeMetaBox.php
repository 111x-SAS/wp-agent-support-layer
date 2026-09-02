<?php
/**
 * Per-post exclusion.
 *
 * @package WPASL
 */

namespace WPASL\Admin;

use WPASL\Settings;

/**
 * "Exclude from agent layer" checkbox, stored as protected post meta and exposed to the REST API for editors.
 */
final class ExcludeMetaBox {

	const META  = '_wpasl_exclude';
	const NONCE = 'wpasl_exclude_nonce';

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
		return (bool) get_post_meta( (int) $post_id, self::META, true );
	}

	/**
	 * Registers the meta for each enabled post type.
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
			// auth_callback only guards writes: hide the value from readers without edit permission.
			add_filter( 'rest_prepare_' . $type, array( $this, 'hide_meta_from_readers' ), 10, 2 );
		}
	}

	/**
	 * Removes the exclusion flag from REST responses for users who cannot edit the post.
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
		if ( is_array( $data ) && isset( $data['meta'] ) && is_array( $data['meta'] ) && array_key_exists( self::META, $data['meta'] ) ) {
			unset( $data['meta'][ self::META ] );
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
	 * Renders the checkbox.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function render( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::META ); ?>" value="1" <?php checked( self::is_excluded( $post->ID ) ); ?> />
			<?php esc_html_e( 'Exclude from the agent layer', 'wp-agent-support-layer' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Hides this item from the Markdown version, llms.txt and the agent manifest.', 'wp-agent-support-layer' ); ?></p>
		<?php
	}

	/**
	 * Saves the checkbox. Never generates any document.
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
	}
}
