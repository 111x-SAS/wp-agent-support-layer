<?php
/**
 * Generation status and manual actions on the General tab.
 *
 * @package WPASL
 */

namespace WPASL\Admin;

use WPASL\Generation\Runner;
use WPASL\Generation\Scheduler;

/**
 * Shows last/next run, counters, a WP-Cron warning and the "Regenerate now" actions.
 */
final class GenerationStatus {

	const ACTION = 'wpasl_regenerate';
	const NONCE  = 'wpasl_regenerate_nonce';

	/**
	 * Runner.
	 *
	 * @var Runner
	 */
	private $runner;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Page.
	 *
	 * @var Page
	 */
	private $page;

	/**
	 * Constructor.
	 *
	 * @param Runner    $runner    Runner.
	 * @param Scheduler $scheduler Scheduler.
	 * @param Page      $page      Page.
	 */
	public function __construct( Runner $runner, Scheduler $scheduler, Page $page ) {
		$this->runner    = $runner;
		$this->scheduler = $scheduler;
		$this->page      = $page;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wpasl_page_after_form', array( $this, 'render_after_form' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Prints the status block after the settings form of the General tab.
	 *
	 * The block carries its own <form action="admin-post.php">, so it must never be
	 * printed inside the Settings API form (nested forms are dropped by browsers and
	 * the settings form would be submitted with action=wpasl_regenerate).
	 *
	 * @param string $current Slug of the rendered tab.
	 * @return void
	 */
	public function render_after_form( $current ) {
		if ( 'general' !== $current ) {
			return;
		}
		$this->render();
	}

	/**
	 * Whether WP-Cron is disabled for this site.
	 *
	 * @return bool
	 */
	public static function cron_disabled() {
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		/**
		 * Filters whether WP-Cron is considered disabled (used to show the warning).
		 *
		 * @param bool $disabled Whether WP-Cron is disabled.
		 */
		return (bool) apply_filters( 'wpasl_cron_disabled', $disabled );
	}

	/**
	 * Handles the "Regenerate now" form. Schedules work; never generates inline.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! current_user_can( Page::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-agent-support-layer' ), 403 );
		}
		check_admin_referer( self::ACTION, self::NONCE );

		$full = ! empty( $_POST['full'] );
		if ( $full ) {
			$this->runner->reset_cycle();
		}
		$this->scheduler->run_soon();

		wp_safe_redirect( add_query_arg( 'wpasl_notice', $full ? 'full' : 'scheduled', $this->page->url( 'general' ) ) );
		exit;
	}

	/**
	 * Renders the status block and actions.
	 *
	 * @return void
	 */
	public function render() {
		$status = $this->runner->status();
		$next   = $this->scheduler->next_run();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice flag set by our redirect.
		$notice = isset( $_GET['wpasl_notice'] ) ? sanitize_key( wp_unslash( $_GET['wpasl_notice'] ) ) : '';
		?>
		<h2><?php esc_html_e( 'Generation status', 'wp-agent-support-layer' ); ?></h2>
		<?php if ( 'scheduled' === $notice ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'A generation run has been scheduled and will start in the background.', 'wp-agent-support-layer' ); ?></p></div>
		<?php elseif ( 'full' === $notice ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'A full regeneration has been scheduled and will run in the background over one or more batches.', 'wp-agent-support-layer' ); ?></p></div>
		<?php endif; ?>
		<?php if ( self::cron_disabled() ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'WP-Cron is disabled on this site (DISABLE_WP_CRON). Generation depends on a system cron calling wp-cron.php or on the "wp wpasl generate" WP-CLI command.', 'wp-agent-support-layer' ); ?></p></div>
		<?php endif; ?>
		<?php if ( $status['render_failed'] > 0 ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of items. */
						_n( 'The rendered page of %d item could not be fetched from this server; it is served with the editor content until the loopback works.', 'The rendered page of %d items could not be fetched from this server; they are served with the editor content until the loopback works.', (int) $status['render_failed'], 'wp-agent-support-layer' ),
						(int) $status['render_failed']
					)
				);
				?>
				<a href="<?php echo esc_url( $this->page->url( 'diagnostics' ) ); ?>"><?php esc_html_e( 'See the Diagnostics tab.', 'wp-agent-support-layer' ); ?></a>
			</p></div>
		<?php endif; ?>
		<table class="widefat striped" style="max-width:640px">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'Eligible items', 'wp-agent-support-layer' ); ?></th><td><?php echo esc_html( (string) $status['eligible'] ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'With a generated document', 'wp-agent-support-layer' ); ?></th><td><?php echo esc_html( (string) $status['generated'] ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Pending', 'wp-agent-support-layer' ); ?></th><td><?php echo esc_html( (string) $status['pending'] ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Failed items', 'wp-agent-support-layer' ); ?></th><td><?php echo esc_html( (string) $status['failed'] ); ?><?php echo $status['failed'] > 0 ? ' <span class="description">' . esc_html__( '(see the PHP error log; they are retried after the rest of the queue)', 'wp-agent-support-layer' ) . '</span>' : ''; ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Items with a rendered-page failure', 'wp-agent-support-layer' ); ?></th><td><?php echo esc_html( (string) $status['render_failed'] ); ?><?php echo $status['render_failed'] > 0 ? ' <span class="description">' . esc_html__( '(served with the editor content; retried first on the next run)', 'wp-agent-support-layer' ) . '</span>' : ''; ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Last rendered-page failure', 'wp-agent-support-layer' ); ?></th><td><?php echo esc_html( self::format_render_error( $status['last_render_error'] ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Last run', 'wp-agent-support-layer' ); ?></th><td><?php echo esc_html( $this->format_time( $status['last_run'] ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Last completed cycle', 'wp-agent-support-layer' ); ?></th><td><?php echo esc_html( $this->format_time( $status['last_cycle_completed'] ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Next scheduled run', 'wp-agent-support-layer' ); ?></th><td><?php echo esc_html( null === $next ? __( 'Not scheduled', 'wp-agent-support-layer' ) : $this->format_time( $next ) ); ?></td></tr>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<?php wp_nonce_field( self::ACTION, self::NONCE ); ?>
			<?php submit_button( __( 'Regenerate now', 'wp-agent-support-layer' ), 'secondary', 'submit', false ); ?>
			<button type="submit" name="full" value="1" class="button"><?php esc_html_e( 'Regenerate everything', 'wp-agent-support-layer' ); ?></button>
			<p class="description"><?php esc_html_e( 'Both actions run in the background. "Regenerate now" processes the next batch; "Regenerate everything" starts a new cycle over all eligible items.', 'wp-agent-support-layer' ); ?></p>
		</form>
		<?php
	}

	/**
	 * Text of the last rendered-page failure: reason, item id and date, or "None".
	 *
	 * @param array|null $error Last error (post_id, reason, time), or null.
	 * @return string
	 */
	public static function format_render_error( $error ) {
		if ( ! is_array( $error ) || empty( $error['reason'] ) ) {
			return __( 'None', 'wp-agent-support-layer' );
		}
		return sprintf(
			/* translators: 1: failure reason, 2: item id, 3: date. */
			__( '%1$s (#%2$d, %3$s)', 'wp-agent-support-layer' ),
			(string) $error['reason'],
			(int) $error['post_id'],
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $error['time'] )
		);
	}

	/**
	 * Formats a timestamp in the site's timezone.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private function format_time( $timestamp ) {
		if ( $timestamp <= 0 ) {
			return __( 'Never', 'wp-agent-support-layer' );
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $timestamp );
	}
}
