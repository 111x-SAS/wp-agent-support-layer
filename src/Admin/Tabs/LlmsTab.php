<?php
/**
 * The llms.txt tab.
 *
 * @package WPASL
 */

namespace WPASL\Admin\Tabs;

use WPASL\Admin\Tab;
use WPASL\Llms\LlmsTxtRouter;
use WPASL\Settings;

/**
 * Description, free intro, per-section limit and the optional llms-full.txt.
 */
final class LlmsTab implements Tab {

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
		return 'llms';
	}

	/**
	 * Tab label.
	 *
	 * @return string
	 */
	public function label() {
		return 'llms.txt';
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
		$option = Settings::OPTION;
		$mb     = (int) round( (int) $this->settings->get( 'llms_full_max_bytes' ) / MB_IN_BYTES );
		?>
		<p>
			<?php
			printf(
				/* translators: %s: URL of llms.txt. */
				esc_html__( 'Published at %s: the site name, a description, an optional introduction and one section per content type linking to the Markdown version of each item.', 'wp-agent-support-layer' ),
				'<a href="' . esc_url( home_url( '/llms.txt' ) ) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html( home_url( '/llms.txt' ) ) . '</code></a>'
			);
			?>
		</p>
		<?php if ( LlmsTxtRouter::physical_file_exists() ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'A physical llms.txt file exists in the site root and takes precedence over the generated one.', 'wp-agent-support-layer' ); ?></p></div>
		<?php endif; ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wpasl-llms-description"><?php esc_html_e( 'Site description', 'wp-agent-support-layer' ); ?></label></th>
				<td>
					<input type="text" id="wpasl-llms-description" name="<?php echo esc_attr( $option ); ?>[llms_description]" value="<?php echo esc_attr( (string) $this->settings->get( 'llms_description' ) ); ?>" class="large-text" placeholder="<?php echo esc_attr( get_bloginfo( 'description' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'One or two sentences shown as the summary block. Defaults to the site tagline.', 'wp-agent-support-layer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wpasl-llms-intro"><?php esc_html_e( 'Introduction (Markdown)', 'wp-agent-support-layer' ); ?></label></th>
				<td>
					<textarea id="wpasl-llms-intro" name="<?php echo esc_attr( $option ); ?>[llms_intro]" rows="6" class="large-text code"><?php echo esc_textarea( (string) $this->settings->get( 'llms_intro' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Optional free text placed before the content sections: what the site is about, key products, how to get in touch.', 'wp-agent-support-layer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wpasl-llms-limit"><?php esc_html_e( 'Items per section', 'wp-agent-support-layer' ); ?></label></th>
				<td>
					<input type="number" id="wpasl-llms-limit" name="<?php echo esc_attr( $option ); ?>[llms_limit]" value="<?php echo esc_attr( (string) (int) $this->settings->get( 'llms_limit' ) ); ?>" min="1" max="1000" class="small-text" />
					<p class="description"><?php esc_html_e( 'Pages are listed by menu order, other types by publication date, newest first.', 'wp-agent-support-layer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'llms-full.txt', 'wp-agent-support-layer' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[llms_full_enabled]" value="1" <?php checked( (bool) $this->settings->get( 'llms_full_enabled' ) ); ?> />
						<?php esc_html_e( 'Also publish /llms-full.txt with the full Markdown of every indexed item concatenated.', 'wp-agent-support-layer' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Useful for agents that want the whole site in one request. Disabled by default because the file can get large.', 'wp-agent-support-layer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wpasl-llms-full-max"><?php esc_html_e( 'llms-full.txt size limit (MB)', 'wp-agent-support-layer' ); ?></label></th>
				<td>
					<input type="number" id="wpasl-llms-full-max" name="<?php echo esc_attr( $option ); ?>[llms_full_max_bytes]" value="<?php echo esc_attr( (string) max( 1, $mb ) ); ?>" min="1" max="100" class="small-text" />
					<p class="description"><?php esc_html_e( 'When the limit is reached the file stops at the last complete item and ends with a note saying it was truncated.', 'wp-agent-support-layer' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}
}
