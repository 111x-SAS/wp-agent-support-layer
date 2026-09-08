<?php
/**
 * The llms.txt tab.
 *
 * @package WPASL
 */

namespace WPASL\Admin\Tabs;

use WPASL\Admin\Tab;
use WPASL\Llms\LlmsTxtBuilder;
use WPASL\Llms\LlmsTxtRouter;
use WPASL\Settings;
use WPASL\Storage;

/**
 * Description, free intro, "when to use this site" guidance, preview and per-type limits, the optional
 * llms-full.txt, and the size of the generated llms.txt.
 */
final class LlmsTab implements Tab {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Storage (size of the generated llms.txt).
	 *
	 * @var Storage
	 */
	private $storage;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param Storage  $storage  Storage.
	 */
	public function __construct( Settings $settings, Storage $storage ) {
		$this->settings = $settings;
		$this->storage  = $storage;
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
		<?php $this->render_type_files(); ?>
		<?php $this->render_size(); ?>
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
				<th scope="row"><label for="wpasl-llms-when-to-use"><?php esc_html_e( 'When to use this site (Markdown)', 'wp-agent-support-layer' ); ?></label></th>
				<td>
					<textarea id="wpasl-llms-when-to-use" name="<?php echo esc_attr( $option ); ?>[llms_when_to_use]" rows="6" maxlength="<?php echo esc_attr( (string) Settings::LLMS_WHEN_TO_USE_MAX ); ?>" class="large-text code"><?php echo esc_textarea( (string) $this->settings->get( 'llms_when_to_use' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Name your best-fit use cases and how an agent should call this site (which documents to read first, which endpoints to use). Shown in llms.txt and auth.md. Leave empty to omit the section.', 'wp-agent-support-layer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wpasl-llms-preview-limit"><?php esc_html_e( 'Items per section in llms.txt', 'wp-agent-support-layer' ); ?></label></th>
				<td>
					<input type="number" id="wpasl-llms-preview-limit" name="<?php echo esc_attr( $option ); ?>[llms_preview_limit]" value="<?php echo esc_attr( (string) (int) $this->settings->get( 'llms_preview_limit' ) ); ?>" min="1" max="<?php echo esc_attr( (string) Settings::LLMS_PREVIEW_LIMIT_MAX ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'Preview shown in llms.txt for each content type; when a type has more items, the section ends with a link to its full list. Pages are listed by menu order, other types by publication date, newest first.', 'wp-agent-support-layer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wpasl-llms-type-limit"><?php esc_html_e( 'Items per content-type file', 'wp-agent-support-layer' ); ?></label></th>
				<td>
					<input type="number" id="wpasl-llms-type-limit" name="<?php echo esc_attr( $option ); ?>[llms_type_limit]" value="<?php echo esc_attr( (string) (int) $this->settings->get( 'llms_type_limit' ) ); ?>" min="1" max="<?php echo esc_attr( (string) Settings::LLMS_TYPE_LIMIT_MAX ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'Maximum number of items listed in each /llms-<type>.txt file (and included in llms-full.txt). Above it the file ends with a note saying how many items were left out.', 'wp-agent-support-layer' ); ?></p>
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
					<input type="number" id="wpasl-llms-full-max" name="<?php echo esc_attr( $option ); ?>[<?php echo esc_attr( Settings::LLMS_FULL_MAX_FIELD_MB ); ?>]" value="<?php echo esc_attr( (string) max( 1, $mb ) ); ?>" min="1" max="100" class="small-text" />
					<p class="description"><?php esc_html_e( 'When the limit is reached the file stops at the last complete item and ends with a note saying it was truncated.', 'wp-agent-support-layer' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Prints the URLs of the per-type files of the enabled post types.
	 *
	 * @return void
	 */
	private function render_type_files() {
		$files = array();
		foreach ( $this->settings->enabled_post_types() as $type ) {
			$file = LlmsTxtBuilder::type_file( $type );
			if ( null !== $file ) {
				$files[] = $file;
			}
		}
		if ( empty( $files ) ) {
			return;
		}
		?>
		<p>
			<?php esc_html_e( 'Full list of each content type, linked from llms.txt:', 'wp-agent-support-layer' ); ?>
			<?php
			$links = array();
			foreach ( $files as $file ) {
				$url     = home_url( '/' . $file );
				$links[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html( $url ) . '</code></a>';
			}
			echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			?>
		</p>
		<?php
	}

	/**
	 * Prints the size of the generated llms.txt and a warning above the recommended maximum.
	 *
	 * @return void
	 */
	private function render_size() {
		$stored = $this->storage->read( LlmsTxtBuilder::FILE );
		if ( null === $stored ) {
			?>
			<p class="description"><?php esc_html_e( 'llms.txt has not been generated yet; it will be built on the next request or run.', 'wp-agent-support-layer' ); ?></p>
			<?php
			return;
		}
		$size = mb_strlen( $stored );
		?>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of characters. */
					__( 'Generated llms.txt size: %s characters.', 'wp-agent-support-layer' ),
					number_format_i18n( $size )
				)
			);
			?>
		</p>
		<?php if ( $size > LlmsTxtBuilder::RECOMMENDED_MAX_CHARS ) : ?>
			<div class="notice notice-warning inline"><p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: size in characters, 2: recommended maximum in characters. */
					__( 'llms.txt is %1$s characters; agents expect at most %2$s. Lower "Items per section in llms.txt" so the file stays a navigation index (the full lists live in the content-type files).', 'wp-agent-support-layer' ),
					number_format_i18n( $size ),
					number_format_i18n( LlmsTxtBuilder::RECOMMENDED_MAX_CHARS )
				)
			);
			?>
			</p></div>
			<?php
		endif;
	}
}
