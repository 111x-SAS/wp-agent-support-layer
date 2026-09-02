<?php
/**
 * Settings page under Tools.
 *
 * @package WPASL
 */

namespace WPASL\Admin;

use WPASL\Admin\Tabs\GeneralTab;
use WPASL\Settings;

/**
 * Registers the Tools submenu, the Settings API option and renders the tabbed page.
 */
final class Page {

	const SLUG       = 'wp-agent-support-layer';
	const CAPABILITY = 'manage_options';
	const GROUP      = 'wpasl';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Registered tabs keyed by slug.
	 *
	 * @var array<string, Tab>
	 */
	private $tabs = array();

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
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPASL_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds a tab. Tabs are rendered in registration order.
	 *
	 * @param Tab $tab Tab.
	 * @return void
	 */
	public function add_tab( Tab $tab ) {
		$this->tabs[ $tab->slug() ] = $tab;
	}

	/**
	 * Returns the registered tabs, collecting them lazily from the plugin's modules.
	 *
	 * @return array<string, Tab>
	 */
	public function tabs() {
		if ( empty( $this->tabs ) ) {
			$this->add_tab( new GeneralTab( $this->settings ) );

			/**
			 * Lets modules register their settings tabs.
			 *
			 * @param Page $page The settings page.
			 */
			do_action( 'wpasl_register_tabs', $this );
		}
		return $this->tabs;
	}

	/**
	 * Registers the Tools submenu.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_management_page(
			__( 'Agent Support Layer', 'wp-agent-support-layer' ),
			__( 'Agent Support Layer', 'wp-agent-support-layer' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Registers the option with the Settings API.
	 *
	 * @return void
	 */
	public function register_setting() {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$url = esc_url( $this->url() );
		array_unshift( $links, '<a href="' . $url . '">' . esc_html__( 'Settings', 'wp-agent-support-layer' ) . '</a>' );
		return $links;
	}

	/**
	 * URL of the page, optionally for a tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public function url( $tab = '' ) {
		$args = array( 'page' => self::SLUG );
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( $args, admin_url( 'tools.php' ) );
	}

	/**
	 * Currently selected tab slug.
	 *
	 * @return string
	 */
	public function current_tab() {
		$tabs = $this->tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( isset( $tabs[ $requested ] ) ) {
			return $requested;
		}
		$slugs = array_keys( $tabs );
		return $slugs[0];
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-agent-support-layer' ), 403 );
		}

		$tabs    = $this->tabs();
		$current = $this->current_tab();
		$tab     = $tabs[ $current ];
		?>
		<div class="wrap wpasl-wrap">
			<h1><?php esc_html_e( 'Agent Support Layer', 'wp-agent-support-layer' ); ?></h1>
			<?php settings_errors( self::GROUP ); ?>
			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Secondary menu', 'wp-agent-support-layer' ); ?>">
				<?php foreach ( $tabs as $slug => $item ) : ?>
					<a href="<?php echo esc_url( $this->url( $slug ) ); ?>" class="nav-tab<?php echo $slug === $current ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $item->label() ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php if ( $tab->has_form() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
					<?php settings_fields( self::GROUP ); ?>
					<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[_tab]" value="<?php echo esc_attr( $current ); ?>" />
					<?php $tab->render(); ?>
					<?php submit_button(); ?>
				</form>
			<?php else : ?>
				<?php $tab->render(); ?>
			<?php endif; ?>
			<?php
			/**
			 * Fires after the tab content, outside the Settings API form.
			 *
			 * Use this hook to print blocks that carry their own <form> (status panels,
			 * manual actions). Anything printed here is a top-level element of the page.
			 *
			 * @since 1.0.3
			 *
			 * @param string $current Slug of the rendered tab.
			 * @param Page   $page    The settings page.
			 */
			do_action( 'wpasl_page_after_form', $current, $this );
			?>
		</div>
		<?php
	}
}
