<?php
/**
 * Diagnostics tab.
 *
 * @package WPASL
 */

namespace WPASL\Admin\Tabs;

use WPASL\Admin\Page;
use WPASL\Admin\Tab;
use WPASL\Diagnostics\CrawlerProbe;
use WPASL\Diagnostics\DiagnosticsController;
use WPASL\Diagnostics\Report;
use WPASL\Robots\Catalog;

/**
 * Runs the crawler simulation, shows the report, the infrastructure checklist and equivalent curl commands.
 */
final class DiagnosticsTab implements Tab {

	/**
	 * Probe.
	 *
	 * @var CrawlerProbe
	 */
	private $probe;

	/**
	 * Page.
	 *
	 * @var Page
	 */
	private $page;

	/**
	 * Constructor.
	 *
	 * @param CrawlerProbe $probe Probe.
	 * @param Page         $page  Page.
	 */
	public function __construct( CrawlerProbe $probe, Page $page ) {
		$this->probe = $probe;
		$this->page  = $page;
	}

	/**
	 * Tab slug.
	 *
	 * @return string
	 */
	public function slug() {
		return 'diagnostics';
	}

	/**
	 * Tab label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Diagnostics', 'wp-agent-support-layer' );
	}

	/**
	 * Not a settings form.
	 *
	 * @return bool
	 */
	public function has_form() {
		return false;
	}

	/**
	 * Prints the tab.
	 *
	 * @return void
	 */
	public function render() {
		$report = Report::load();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice flag set by our redirect.
		$notice = isset( $_GET['wpasl_notice'] ) ? sanitize_key( wp_unslash( $_GET['wpasl_notice'] ) ) : '';
		?>
		<p><?php esc_html_e( 'Simulates how each AI crawler sees this site by requesting the home page, a sample item (as HTML and as Markdown) and the discovery files from this server with the crawler user-agents. Read-only; no external service is contacted.', 'wp-agent-support-layer' ); ?></p>
		<?php if ( 'diagnostics' === $notice ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Diagnostics completed.', 'wp-agent-support-layer' ); ?></p></div>
		<?php endif; ?>
		<?php
		$pending = DiagnosticsController::pending_run();
		if ( $pending ) :
			?>
			<div class="notice notice-warning inline"><p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: crawlers done, 2: crawlers pending. */
					__( 'A simulation is in progress: %1$d crawler(s) done, %2$d pending. Run it again to continue where it stopped.', 'wp-agent-support-layer' ),
					count( (array) $pending['crawlers'] ),
					count( (array) $pending['pending'] )
				)
			);
			?>
			</p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( DiagnosticsController::ACTION ); ?>" />
			<?php wp_nonce_field( DiagnosticsController::ACTION, DiagnosticsController::NONCE ); ?>
			<?php submit_button( __( 'Run crawler simulation', 'wp-agent-support-layer' ), 'primary', 'submit', false ); ?>
			<span class="description" style="margin-left:8px"><?php esc_html_e( 'Runs in short batches (the page reloads by itself); results are kept for one hour.', 'wp-agent-support-layer' ); ?></span>
		</form>

		<?php if ( $report ) : ?>
			<?php $this->render_report( $report ); ?>
		<?php endif; ?>

		<?php $this->render_checklist(); ?>
		<?php $this->render_curl(); ?>
		<?php
	}

	/**
	 * Prints a stored report.
	 *
	 * @param array<string, mixed> $report Report.
	 * @return void
	 */
	private function render_report( array $report ) {
		$infra = (array) $report['infrastructure'];
		?>
		<h2><?php esc_html_e( 'Last report', 'wp-agent-support-layer' ); ?></h2>
		<p class="description"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $report['generated_at'] ) ); ?>
		<?php
		if ( ! empty( $report['sample_post'] ) ) :
			?>
			· <?php esc_html_e( 'Sample item:', 'wp-agent-support-layer' ); ?> <a href="<?php echo esc_url( get_permalink( (int) $report['sample_post'] ) ); ?>"><?php echo esc_html( get_the_title( (int) $report['sample_post'] ) ); ?></a><?php endif; ?></p>

		<h3><?php esc_html_e( 'Infrastructure', 'wp-agent-support-layer' ); ?></h3>
		<ul>
			<li><?php echo '' === $infra['cdn'] ? esc_html__( 'No CDN or proxy detected.', 'wp-agent-support-layer' ) : esc_html( sprintf( /* translators: %s: CDN name. */ __( '%s detected in front of the site.', 'wp-agent-support-layer' ), $infra['cdn'] ) ); ?></li>
			<?php
			if ( '' !== $infra['server'] ) :
				?>
				<li><?php echo esc_html( sprintf( /* translators: %s: Server header. */ __( 'Server header: %s', 'wp-agent-support-layer' ), $infra['server'] ) ); ?></li><?php endif; ?>
			<?php
			if ( ! empty( $infra['edge_markdown'] ) ) :
				?>
				<li><strong><?php esc_html_e( 'Cloudflare "Markdown for Agents" seems to convert pages at the edge. Keep only one converter (this plugin or Cloudflare) to avoid double processing.', 'wp-agent-support-layer' ); ?></strong></li><?php endif; ?>
		</ul>

		<h3><?php esc_html_e( 'Site checks', 'wp-agent-support-layer' ); ?></h3>
		<table class="widefat striped" style="max-width:900px">
			<tbody>
				<?php foreach ( (array) $report['site'] as $check ) : ?>
					<tr><td style="width:90px"><?php $this->badge( $check['status'] ); ?></td><td><?php echo esc_html( $check['message'] ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Per crawler', 'wp-agent-support-layer' ); ?></h3>
		<table class="widefat striped" style="max-width:1100px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Crawler', 'wp-agent-support-layer' ); ?></th>
					<th><?php esc_html_e( 'robots.txt', 'wp-agent-support-layer' ); ?></th>
					<th><?php esc_html_e( 'Checks', 'wp-agent-support-layer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( (array) $report['crawlers'] as $agent => $data ) : ?>
					<tr>
						<td><code><?php echo esc_html( $agent ); ?></code></td>
						<td><?php echo esc_html( 'block' === $data['policy'] ? __( 'Block', 'wp-agent-support-layer' ) : __( 'Allow', 'wp-agent-support-layer' ) ); ?></td>
						<td>
							<?php foreach ( (array) $data['checks'] as $check ) : ?>
								<div><?php $this->badge( $check['status'] ); ?> <?php echo esc_html( $check['message'] ); ?></div>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Prints a status badge.
	 *
	 * @param string $status ok, warning or error.
	 * @return void
	 */
	private function badge( $status ) {
		$labels = array(
			Report::OK      => __( 'OK', 'wp-agent-support-layer' ),
			Report::WARNING => __( 'Warning', 'wp-agent-support-layer' ),
			Report::ERROR   => __( 'Error', 'wp-agent-support-layer' ),
		);
		$colors = array(
			Report::OK      => '#00a32a',
			Report::WARNING => '#dba617',
			Report::ERROR   => '#d63638',
		);
		$status = isset( $labels[ $status ] ) ? $status : Report::WARNING;
		printf( '<span class="wpasl-badge" style="display:inline-block;min-width:64px;text-align:center;padding:1px 6px;border-radius:3px;color:#fff;background:%1$s">%2$s</span>', esc_attr( $colors[ $status ] ), esc_html( $labels[ $status ] ) );
	}

	/**
	 * Static WAF / rate limiting / IP filtering checklist.
	 *
	 * @return void
	 */
	private function render_checklist() {
		$items = array(
			__( 'List which AI crawlers you want to allow (see the Crawlers tab) and check that your WAF, bot-fight or "AI scrapers" rules do not block them by user-agent or by ASN.', 'wp-agent-support-layer' ),
			__( 'Verify crawlers by IP range or reverse DNS as published by each vendor, rather than trusting the user-agent string alone.', 'wp-agent-support-layer' ),
			__( 'Crawlers that ignore robots.txt (Bytespider has been reported to) can only be stopped at the firewall or CDN; block them there if you do not want them.', 'wp-agent-support-layer' ),
			__( 'Keep rate limiting enabled; raise thresholds for verified crawlers instead of disabling limits site-wide.', 'wp-agent-support-layer' ),
			__( 'Ensure the CDN or page cache honours "Vary: Accept" for HTML URLs, or rely on the .md URLs which are cached independently.', 'wp-agent-support-layer' ),
			__( 'Do not cache or transform robots.txt, llms.txt, agent-skills.json and /.well-known/api-catalog beyond their Cache-Control lifetime.', 'wp-agent-support-layer' ),
			__( 'If Cloudflare "Markdown for Agents" is enabled, decide whether Cloudflare or this plugin converts pages, not both.', 'wp-agent-support-layer' ),
			__( 'Confirm that the uploads/wp-agent-support-layer directory is not directly accessible (Apache .htaccess or an nginx deny rule).', 'wp-agent-support-layer' ),
			__( 'After changing security rules, run this simulation again and compare the per-crawler results.', 'wp-agent-support-layer' ),
		);
		?>
		<h2><?php esc_html_e( 'Infrastructure checklist (WAF, rate limiting, IP filtering)', 'wp-agent-support-layer' ); ?></h2>
		<p class="description"><?php esc_html_e( 'This plugin cannot change your CDN or firewall. Review these points with your provider.', 'wp-agent-support-layer' ); ?></p>
		<ol>
			<?php foreach ( $items as $item ) : ?>
				<li><?php echo esc_html( $item ); ?></li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * Equivalent curl commands to repeat the checks from outside the server.
	 *
	 * @return void
	 */
	private function render_curl() {
		$post = $this->probe->sample_post();
		$url  = $post ? get_permalink( $post ) : home_url( '/' );
		?>
		<h2><?php esc_html_e( 'Repeat the checks from outside', 'wp-agent-support-layer' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Run these from your computer to see exactly what each crawler receives through your CDN and firewall.', 'wp-agent-support-layer' ); ?></p>
		<textarea readonly class="large-text code" rows="14">
		<?php
		foreach ( Catalog::all() as $agent => $crawler ) {
			echo esc_textarea( sprintf( "# %s (%s)\n", $agent, $crawler['vendor'] ) );
			echo esc_textarea( sprintf( "curl -sI -A 'Mozilla/5.0 (compatible; %s/1.0)' -H 'Accept: text/html' '%s'\n", $agent, $url ) );
			echo esc_textarea( sprintf( "curl -sI -A 'Mozilla/5.0 (compatible; %s/1.0)' -H 'Accept: text/markdown' '%s'\n\n", $agent, $url ) );
		}
		echo esc_textarea( sprintf( "# Discovery files\ncurl -s '%s'\ncurl -s '%s'\ncurl -s '%s'\ncurl -s '%s'\n", home_url( '/robots.txt' ), home_url( '/llms.txt' ), home_url( '/agent-skills.json' ), home_url( '/.well-known/api-catalog' ) ) );
		?>
		</textarea>
		<?php
	}
}
