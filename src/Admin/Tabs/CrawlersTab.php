<?php
/**
 * Crawlers tab.
 *
 * @package WPASL
 */

namespace WPASL\Admin\Tabs;

use WPASL\Admin\Tab;
use WPASL\Robots\Catalog;
use WPASL\Robots\Policy;
use WPASL\Robots\RobotsTxt;
use WPASL\Settings;

/**
 * Per-crawler allow / block policy, grouped by purpose, with a preview of the generated robots.txt.
 */
final class CrawlersTab implements Tab {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Robots.txt service.
	 *
	 * @var RobotsTxt
	 */
	private $robots;

	/**
	 * Constructor.
	 *
	 * @param Settings  $settings Settings.
	 * @param RobotsTxt $robots   Robots.txt service.
	 */
	public function __construct( Settings $settings, RobotsTxt $robots ) {
		$this->settings = $settings;
		$this->robots   = $robots;
	}

	/**
	 * Tab slug.
	 *
	 * @return string
	 */
	public function slug() {
		return 'crawlers';
	}

	/**
	 * Tab label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Crawlers', 'wp-agent-support-layer' );
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
		$option       = Settings::OPTION;
		$policy       = $this->robots->policy();
		$descriptions = Catalog::group_descriptions();
		?>
		<p><?php esc_html_e( 'Each AI crawler gets its own group in robots.txt. "Default" follows the Content Signals; choose Allow or Block to override a single crawler.', 'wp-agent-support-layer' ); ?></p>
		<p class="description"><?php esc_html_e( 'Microsoft Copilot uses Bingbot and Google Gemini uses Google-Extended; they cannot be separated from regular search indexing beyond what those tokens allow.', 'wp-agent-support-layer' ); ?></p>
		<?php if ( RobotsTxt::physical_file_exists() ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'A physical robots.txt file exists in the site root. WordPress does not serve the generated rules; copy the text below into your file.', 'wp-agent-support-layer' ); ?></p></div>
		<?php endif; ?>
		<?php foreach ( Catalog::groups() as $group => $label ) : ?>
			<?php $default = $policy->default_for_group( $group ); ?>
			<h2><?php echo esc_html( $label ); ?></h2>
			<p class="description"><?php echo esc_html( $descriptions[ $group ] ); ?> <?php echo esc_html( Policy::BLOCK === $default ? __( 'Current default: Block.', 'wp-agent-support-layer' ) : __( 'Current default: Allow.', 'wp-agent-support-layer' ) ); ?></p>
			<table class="widefat striped" style="max-width:760px;margin-bottom:16px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User-agent', 'wp-agent-support-layer' ); ?></th>
						<th><?php esc_html_e( 'Vendor', 'wp-agent-support-layer' ); ?></th>
						<th><?php esc_html_e( 'Policy', 'wp-agent-support-layer' ); ?></th>
						<th><?php esc_html_e( 'Effective', 'wp-agent-support-layer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( Catalog::by_group( $group ) as $agent => $crawler ) : ?>
						<?php
						$override = $policy->override_for( $agent );
						$current  = null === $override ? 'default' : $override;
						$name     = $option . '[crawler_overrides][' . $agent . ']';
						?>
						<tr>
							<td>
								<code><?php echo esc_html( $agent ); ?></code>
								<?php if ( '' !== $crawler['docs'] ) : ?>
									<a href="<?php echo esc_url( $crawler['docs'] ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'Documentation', 'wp-agent-support-layer' ); ?>">&#9432;</a>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $crawler['vendor'] ); ?></td>
							<td>
								<label style="margin-right:10px"><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="default" <?php checked( $current, 'default' ); ?> /> <?php esc_html_e( 'Default', 'wp-agent-support-layer' ); ?></label>
								<label style="margin-right:10px"><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="allow" <?php checked( $current, 'allow' ); ?> /> <?php esc_html_e( 'Allow', 'wp-agent-support-layer' ); ?></label>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="block" <?php checked( $current, 'block' ); ?> /> <?php esc_html_e( 'Block', 'wp-agent-support-layer' ); ?></label>
							</td>
							<td><?php echo esc_html( Policy::BLOCK === $policy->for_agent( $agent ) ? __( 'Block', 'wp-agent-support-layer' ) : __( 'Allow', 'wp-agent-support-layer' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>
		<h2><?php esc_html_e( 'Generated robots.txt', 'wp-agent-support-layer' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Read-only preview of what WordPress serves at /robots.txt with the saved settings.', 'wp-agent-support-layer' ); ?></p>
		<textarea readonly class="large-text code" rows="18"><?php echo esc_textarea( $this->robots->generated_output() ); ?></textarea>
		<?php
	}
}
