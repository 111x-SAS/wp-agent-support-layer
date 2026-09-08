<?php
/**
 * Manifests tab.
 *
 * @package WPASL
 */

namespace WPASL\Admin\Tabs;

use WPASL\Admin\Tab;
use WPASL\Manifest\AuthMdBuilder;
use WPASL\Manifest\AuthMdRouter;
use WPASL\Manifest\ManifestBuilder;
use WPASL\Settings;

/**
 * Enable/disable the manifests and auth.md, set the contact email and the auth.md notes.
 */
final class ManifestsTab implements Tab {

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
		return 'manifests';
	}

	/**
	 * Tab label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Manifests', 'wp-agent-support-layer' );
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
		$links  = array(
			home_url( '/agent-skills.json' )       => __( 'JSON-LD manifest of the actions an agent can perform without authentication.', 'wp-agent-support-layer' ),
			ManifestBuilder::openapi_url()         => __( 'OpenAPI 3.1 description of the public REST endpoints.', 'wp-agent-support-layer' ),
			home_url( '/.well-known/api-catalog' ) => __( 'RFC 9727 API catalog pointing to the OpenAPI document.', 'wp-agent-support-layer' ),
			AuthMdBuilder::url()                   => __( 'Markdown statement of how agents may access this site: no registration, no credentials, public read-only endpoints.', 'wp-agent-support-layer' ),
		);
		?>
		<p><?php esc_html_e( 'The manifests only declare what the site really exposes: search, listing and reading of the enabled content types through the REST API, Markdown delivery and llms.txt. Nothing that requires authentication is ever listed. auth.md states the same in prose for agents that look for it at the site root.', 'wp-agent-support-layer' ); ?></p>
		<?php if ( AuthMdRouter::physical_file_exists() ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'A physical auth.md file exists in the site root and takes precedence over the generated one.', 'wp-agent-support-layer' ); ?></p></div>
		<?php endif; ?>
		<ul>
			<?php foreach ( $links as $url => $description ) : ?>
				<li><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( $url ); ?></code></a> &mdash; <?php echo esc_html( $description ); ?></li>
			<?php endforeach; ?>
		</ul>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Publish manifests', 'wp-agent-support-layer' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[manifest_enabled]" value="1" <?php checked( (bool) $this->settings->get( 'manifest_enabled' ) ); ?> />
						<?php esc_html_e( 'Serve agent-skills.json, the OpenAPI document and the API catalog.', 'wp-agent-support-layer' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'When disabled, these URLs respond 404.', 'wp-agent-support-layer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Publish auth.md', 'wp-agent-support-layer' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[auth_md_enabled]" value="1" <?php checked( (bool) $this->settings->get( 'auth_md_enabled' ) ); ?> />
						<?php esc_html_e( 'Serve /auth.md: the public access statement for agents (audience, no registration, no credentials, public endpoints).', 'wp-agent-support-layer' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Requires the manifests above. When disabled, /auth.md responds 404.', 'wp-agent-support-layer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wpasl-contact-email"><?php esc_html_e( 'Contact email', 'wp-agent-support-layer' ); ?></label></th>
				<td>
					<input type="email" id="wpasl-contact-email" name="<?php echo esc_attr( $option ); ?>[contact_email]" value="<?php echo esc_attr( (string) $this->settings->get( 'contact_email' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Published in the manifests as the technical contact. Defaults to the site admin email.', 'wp-agent-support-layer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wpasl-auth-md-notes"><?php esc_html_e( 'auth.md notes (Markdown)', 'wp-agent-support-layer' ); ?></label></th>
				<td>
					<textarea id="wpasl-auth-md-notes" name="<?php echo esc_attr( $option ); ?>[auth_md_notes]" rows="6" class="large-text code" maxlength="<?php echo esc_attr( (string) Settings::AUTH_MD_NOTES_MAX ); ?>"><?php echo esc_textarea( (string) $this->settings->get( 'auth_md_notes' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Optional. Appended to auth.md as a Notes section, e.g. rate limits or preferred endpoints. Do not paste credentials: the document is public.', 'wp-agent-support-layer' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}
}
