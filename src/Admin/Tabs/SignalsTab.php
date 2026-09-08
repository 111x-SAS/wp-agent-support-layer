<?php
/**
 * Signals tab.
 *
 * @package WPASL
 */

namespace WPASL\Admin\Tabs;

use WPASL\Admin\Page;
use WPASL\Admin\Tab;
use WPASL\Diagnostics\PageCache;
use WPASL\Settings;

/**
 * Search, AI input and AI training preferences plus the experimental Content-Usage header.
 */
final class SignalsTab implements Tab {

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
	 * Tab slug.
	 *
	 * @return string
	 */
	public function slug() {
		return 'signals';
	}

	/**
	 * Tab label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Signals', 'wp-agent-support-layer' );
	}

	/**
	 * Renders a settings form.
	 *
	 * @return bool
	 */
	public function has_form() {
		return true;
	}

	/**
	 * Prints the fields.
	 *
	 * @return void
	 */
	public function render() {
		$option     = Settings::OPTION;
		$signals    = array(
			'signal_search'   => array(
				'label'       => __( 'Search', 'wp-agent-support-layer' ),
				'directive'   => 'search',
				'description' => __( 'Building a search index and showing links and short excerpts in results. Does not include AI-generated summaries.', 'wp-agent-support-layer' ),
			),
			'signal_ai_input' => array(
				'label'       => __( 'AI input', 'wp-agent-support-layer' ),
				'directive'   => 'ai-input',
				'description' => __( 'Using the content as input for AI models in real time: answer engines, retrieval-augmented generation, on-demand agents.', 'wp-agent-support-layer' ),
			),
			'signal_ai_train' => array(
				'label'       => __( 'AI training', 'wp-agent-support-layer' ),
				'directive'   => 'ai-train',
				'description' => __( 'Training or fine-tuning AI models. When set to No, the site also sends "noai, noimageai" in X-Robots-Tag and the robots meta tag.', 'wp-agent-support-layer' ),
			),
		);
		$page_cache = PageCache::detect();
		?>
		<?php if ( null !== $page_cache ) : ?>
			<div class="notice notice-warning inline"><p>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: 1: page cache plugin name, 2: URL of the Diagnostics tab. */
					__( '%1$s is active. HTML served from its page cache does not carry the Content-Signal, Content-Usage or X-Robots-Tag headers; see the <a href="%2$s">Diagnostics tab</a> for the web server configuration that restores them.', 'wp-agent-support-layer' ),
					esc_html( $page_cache['name'] ),
					esc_url(
						add_query_arg(
							array(
								'page' => Page::SLUG,
								'tab'  => 'diagnostics',
							),
							admin_url( 'tools.php' )
						)
					)
				),
				array( 'a' => array( 'href' => array() ) )
			);
			?>
			</p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'These preferences are published in robots.txt (Content-Signal directive), in the Content-Signal HTTP header of every public response and as robots directives. They are declarations, not enforcement; use the Crawlers tab to decide which AI crawlers may fetch the site.', 'wp-agent-support-layer' ); ?></p>
		<table class="form-table" role="presentation">
			<?php foreach ( $signals as $key => $signal ) : ?>
				<?php $value = 'yes' === $this->settings->get( $key ) ? 'yes' : 'no'; ?>
				<tr>
					<th scope="row"><?php echo esc_html( $signal['label'] ); ?> <code><?php echo esc_html( $signal['directive'] ); ?></code></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php echo esc_html( $signal['label'] ); ?></legend>
							<label style="margin-right:16px"><input type="radio" name="<?php echo esc_attr( $option . '[' . $key . ']' ); ?>" value="yes" <?php checked( $value, 'yes' ); ?> /> <?php esc_html_e( 'Yes', 'wp-agent-support-layer' ); ?></label>
							<label><input type="radio" name="<?php echo esc_attr( $option . '[' . $key . ']' ); ?>" value="no" <?php checked( $value, 'no' ); ?> /> <?php esc_html_e( 'No', 'wp-agent-support-layer' ); ?></label>
							<p class="description"><?php echo esc_html( $signal['description'] ); ?></p>
						</fieldset>
					</td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Content-Usage header', 'wp-agent-support-layer' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[content_usage_header]" value="1" <?php checked( (bool) $this->settings->get( 'content_usage_header' ) ); ?> />
						<?php esc_html_e( 'Also send the experimental IETF AIPREF "Content-Usage" header (train-ai, search).', 'wp-agent-support-layer' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'The AIPREF specification is still a draft; disable this if its syntax changes.', 'wp-agent-support-layer' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}
}
