<?php
/**
 * General tab.
 *
 * @package WPASL
 */

namespace WPASL\Admin\Tabs;

use WPASL\Admin\Tab;
use WPASL\Settings;

/**
 * Post types, schedule and batch size.
 */
final class GeneralTab implements Tab {

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
	 * {@inheritDoc}
	 */
	public function slug() {
		return 'general';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'General', 'wp-agent-support-layer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function has_form() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function render() {
		$option    = Settings::OPTION;
		$enabled   = $this->settings->enabled_post_types();
		$schedule  = (string) $this->settings->get( 'schedule' );
		$batch     = (int) $this->settings->get( 'batch_size' );
		$intervals = array(
			'hourly'     => __( 'Hourly', 'wp-agent-support-layer' ),
			'twicedaily' => __( 'Twice daily', 'wp-agent-support-layer' ),
			'daily'      => __( 'Daily', 'wp-agent-support-layer' ),
			'weekly'     => __( 'Weekly', 'wp-agent-support-layer' ),
		);
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Content types', 'wp-agent-support-layer' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Content types exposed to agents', 'wp-agent-support-layer' ); ?></legend>
						<?php foreach ( Settings::selectable_post_types() as $type ) : ?>
							<?php $object = get_post_type_object( $type ); ?>
							<label style="display:block;margin-bottom:4px;">
								<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[post_types][]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $enabled, true ) ); ?> />
								<?php echo esc_html( $object ? $object->labels->name : $type ); ?> <code><?php echo esc_html( $type ); ?></code>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Published items of these types get a Markdown version, appear in llms.txt and are declared in the agent manifest.', 'wp-agent-support-layer' ); ?></p>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wpasl-schedule"><?php esc_html_e( 'Regeneration interval', 'wp-agent-support-layer' ); ?></label></th>
				<td>
					<select id="wpasl-schedule" name="<?php echo esc_attr( $option ); ?>[schedule]">
						<?php foreach ( $intervals as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $schedule, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Markdown documents and discovery files are generated on this schedule, never when a post is saved.', 'wp-agent-support-layer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wpasl-batch-size"><?php esc_html_e( 'Items per run', 'wp-agent-support-layer' ); ?></label></th>
				<td>
					<input type="number" id="wpasl-batch-size" name="<?php echo esc_attr( $option ); ?>[batch_size]" value="<?php echo esc_attr( (string) $batch ); ?>" min="1" max="500" class="small-text" />
					<p class="description"><?php esc_html_e( 'Maximum number of items converted in one scheduled run. Large sites are processed over several runs.', 'wp-agent-support-layer' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
		/**
		 * Prints additional content at the end of the General tab (generation status, actions).
		 */
		do_action( 'wpasl_general_tab_after' );
	}
}
