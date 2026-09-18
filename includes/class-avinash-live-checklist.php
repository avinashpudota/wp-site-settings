<?php
/** Persistent, site-wide launch checklist. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Avinash_Live_Checklist {
	const DISMISSED_OPTION = 'avinash_live_checklist_dismissed';
	const ACTION = 'avinash_dismiss_live_checklist';
	private $smtp_ready;

	public function __construct( callable $smtp_ready ) {
		$this->smtp_ready = $smtp_ready;
		add_action( 'admin_notices', array( $this, 'render' ), 5 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'dismiss' ) );
	}

	public function should_show(): bool {
		if ( ! current_user_can( 'manage_options' ) || is_network_admin() || is_user_admin() || get_option( self::DISMISSED_OPTION, false ) ) {
			return false;
		}
		$screen = get_current_screen();
		// Includes classic/block post, page and custom-post-type editors.
		return $screen && ! in_array( $screen->base, array( 'post', 'site-editor', 'widgets', 'customize', 'term', 'user-edit', 'theme-editor', 'plugin-editor' ), true );
	}

	public function render(): void {
		if ( ! $this->should_show() ) {
			return;
		}
		global $wpdb;
		// Stop after eleven rows; no full revision count on every admin request.
		$revisions = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' LIMIT 11" );
		$optimized = ! $wpdb->last_error && is_array( $revisions ) && count( $revisions ) <= 10;
		$smtp_ready = call_user_func( $this->smtp_ready );
		?>
		<div class="notice notice-info" id="avinash-live-checklist">
			<p><strong><?php esc_html_e( 'Move to Live Checklist', 'site-settings-by-avinash' ); ?></strong></p>
			<ol>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=avinash-site-settings&tab=smtp' ) ); ?>"><?php esc_html_e( 'SMTP Configuration', 'site-settings-by-avinash' ); ?></a> (<strong><?php echo esc_html( $smtp_ready ? __( 'Active', 'site-settings-by-avinash' ) : __( 'Inactive', 'site-settings-by-avinash' ) ); ?></strong>)</li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=avinash-site-settings&tab=database' ) ); ?>"><?php esc_html_e( 'Clean and Optimize Database', 'site-settings-by-avinash' ); ?></a> (<strong><?php echo esc_html( $optimized ? __( 'Optimized', 'site-settings-by-avinash' ) : __( 'Not Optimized', 'site-settings-by-avinash' ) ); ?></strong>)</li>
			</ol>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<p><button type="submit" class="button-link"><?php esc_html_e( 'Dismiss this notification.', 'site-settings-by-avinash' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	public function dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to dismiss this checklist.', 'site-settings-by-avinash' ), '', array( 'response' => 403 ) );
		}
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Use the checklist dismissal button.', 'site-settings-by-avinash' ), '', array( 'response' => 405 ) );
		}
		check_admin_referer( self::ACTION );
		update_option( self::DISMISSED_OPTION, true, false );
		if ( ! get_option( self::DISMISSED_OPTION, false ) ) {
			wp_die( esc_html__( 'Could not save dismissal. Please try again.', 'site-settings-by-avinash' ) );
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}
}
