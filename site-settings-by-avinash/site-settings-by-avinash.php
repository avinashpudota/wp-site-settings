<?php
/**
 * Plugin Name: Site Settings
 * Description: Lightweight personal utility plugin for SMTP, scripts, custom functions, and database maintenance.
 * Version: 1.0.0
 * Author: Avinash
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: site-settings-by-avinash
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Avinash_Site_Settings {
	private const VERSION       = '1.0.0';
	private const OPTION_NAME   = 'avinash_site_settings_options';
	private const NOTICE_TRANSIENT = 'avinash_site_settings_notice';
	private const PAGE_SLUG     = 'avinash-site-settings';
	private const NONCE_ACTION  = 'avinash_site_settings_action';
	private const NONCE_NAME    = 'avinash_site_settings_nonce';

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'phpmailer_init', array( $this, 'configure_phpmailer' ) );
		add_action( 'wp_head', array( $this, 'print_header_scripts' ), 99 );
		add_action( 'wp_footer', array( $this, 'print_footer_scripts' ), 99 );
		add_action( 'plugins_loaded', array( $this, 'load_custom_functions' ), 20 );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_action_links' ) );
	}

	public function add_plugin_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) ) ),
			esc_html__( 'Settings', 'site-settings-by-avinash' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'Site Settings', 'site-settings-by-avinash' ),
			__( 'Site Settings', 'site-settings-by-avinash' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' ),
			'dashicons-admin-generic',
			59
		);
	}

	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'avinash-site-settings-admin',
			plugins_url( 'assets/admin.css', __FILE__ ),
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'avinash-site-settings-admin',
			plugins_url( 'assets/admin.js', __FILE__ ),
			array(),
			self::VERSION,
			true
		);
	}

	public function handle_admin_actions(): void {
		if ( ! is_admin() || empty( $_POST['avinash_site_settings_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage site settings.', 'site-settings-by-avinash' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$action = sanitize_key( wp_unslash( $_POST['avinash_site_settings_action'] ) );
		$tab    = isset( $_POST['avinash_site_settings_tab'] ) ? sanitize_key( wp_unslash( $_POST['avinash_site_settings_tab'] ) ) : 'smtp';

		switch ( $action ) {
			case 'save':
				$this->save_settings( $tab );
				$this->set_notice( __( 'Settings saved.', 'site-settings-by-avinash' ), 'success' );
				break;
			case 'reset':
				delete_option( self::OPTION_NAME );
				$this->set_notice( __( 'Defaults restored. Enter your SMTP password to activate sending.', 'site-settings-by-avinash' ), 'success' );
				$tab = 'smtp';
				break;
			case 'test_email':
				$this->send_test_email();
				$tab = 'smtp';
				break;
			case 'optimize_revisions':
				$this->delete_post_revisions();
				$tab = 'database';
				break;
			case 'delete_unapproved_comments':
				$this->delete_unapproved_comments();
				$tab = 'database';
				break;
			case 'delete_expired_transients':
				$this->delete_expired_transients();
				$tab = 'database';
				break;
			case 'optimize_table':
				$this->optimize_database_table();
				$tab = 'database';
				break;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'tab'  => $this->normalize_tab( $tab ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function save_settings( string $tab ): void {
		$current = $this->get_options();
		$posted  = isset( $_POST['avinash_site_settings'] ) && is_array( $_POST['avinash_site_settings'] )
			? wp_unslash( $_POST['avinash_site_settings'] )
			: array();

		$options = $current;

		if ( 'smtp' === $tab ) {
			$options['smtp_enabled']    = ! empty( $posted['smtp_enabled'] );
			$options['smtp_host']       = sanitize_text_field( $posted['smtp_host'] ?? $current['smtp_host'] );
			$options['smtp_port']       = max( 1, absint( $posted['smtp_port'] ?? $current['smtp_port'] ) );
			$options['smtp_encryption'] = $this->normalize_encryption( $posted['smtp_encryption'] ?? $current['smtp_encryption'] );
			$options['smtp_auth']       = true;
			$options['smtp_username']   = sanitize_text_field( $posted['smtp_username'] ?? $current['smtp_username'] );
			$options['smtp_password']   = '' !== (string) ( $posted['smtp_password'] ?? '' )
				? (string) $posted['smtp_password']
				: (string) $current['smtp_password'];
			$options['smtp_from_email'] = sanitize_email( $posted['smtp_from_email'] ?? $current['smtp_from_email'] );
			$options['smtp_from_name']  = sanitize_text_field( $posted['smtp_from_name'] ?? $current['smtp_from_name'] );
			$options['smtp_force_from'] = true;
		}

		if ( 'scripts' === $tab ) {
			$options['header_scripts'] = (string) ( $posted['header_scripts'] ?? '' );
			$options['footer_scripts'] = (string) ( $posted['footer_scripts'] ?? '' );
		}

		if ( 'functions' === $tab ) {
			$options['custom_functions'] = $this->sanitize_custom_functions( $posted['custom_functions'] ?? array() );
		}

		update_option( self::OPTION_NAME, $options, false );
	}

	private function send_test_email(): void {
		$options = $this->get_options();
		$to = isset( $_POST['avinash_test_email'] ) ? sanitize_email( wp_unslash( $_POST['avinash_test_email'] ) ) : '';

		if ( ! is_email( $to ) ) {
			$this->set_notice( __( 'Enter a valid test email address.', 'site-settings-by-avinash' ), 'error' );
			return;
		}

		if ( empty( $options['smtp_password'] ) ) {
			$this->set_notice( __( 'Save your SMTP password before sending a test email.', 'site-settings-by-avinash' ), 'error' );
			return;
		}

		$mail_error = null;
		$error_hook = static function ( $wp_error ) use ( &$mail_error ): void {
			$mail_error = $wp_error instanceof WP_Error ? $wp_error->get_error_message() : '';
		};

		add_action( 'wp_mail_failed', $error_hook );

		$sent = wp_mail(
			$to,
			__( 'Site Settings SMTP Test', 'site-settings-by-avinash' ),
			sprintf(
				/* translators: %s: Site name. */
				__( "This test email was sent from %s using Site Settings by Avinash.\n\nIf it reached you, SMTP is configured correctly.", 'site-settings-by-avinash' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			)
		);

		remove_action( 'wp_mail_failed', $error_hook );

		if ( $sent ) {
			$this->set_notice( __( 'Test email sent successfully.', 'site-settings-by-avinash' ), 'success' );
			return;
		}

		$message = $mail_error ? $mail_error : __( 'WordPress could not send the test email. Check the SMTP details and password.', 'site-settings-by-avinash' );
		$this->set_notice( $message, 'error' );
	}

	private function delete_post_revisions(): void {
		$deleted = 0;

		do {
			$batch_deleted = 0;
			$revision_ids = get_posts(
				array(
					'post_type'      => 'revision',
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => 200,
					'no_found_rows'  => true,
				)
			);

			foreach ( $revision_ids as $revision_id ) {
				if ( wp_delete_post_revision( (int) $revision_id ) ) {
					++$deleted;
					++$batch_deleted;
				}
			}
		} while ( 200 === count( $revision_ids ) && $batch_deleted > 0 );

		$this->set_notice(
			sprintf(
				/* translators: %d: Number of deleted revisions. */
				_n( '%d post revision deleted.', '%d post revisions deleted.', $deleted, 'site-settings-by-avinash' ),
				$deleted
			),
			'success'
		);
	}

	private function delete_unapproved_comments(): void {
		$deleted = 0;

		do {
			$batch_deleted = 0;
			$comment_ids = get_comments(
				array(
					'status' => 'hold',
					'fields' => 'ids',
					'number' => 200,
				)
			);

			foreach ( $comment_ids as $comment_id ) {
				if ( wp_delete_comment( (int) $comment_id, true ) ) {
					++$deleted;
					++$batch_deleted;
				}
			}
		} while ( 200 === count( $comment_ids ) && $batch_deleted > 0 );

		$this->set_notice(
			sprintf(
				/* translators: %d: Number of deleted comments. */
				_n( '%d unapproved comment deleted.', '%d unapproved comments deleted.', $deleted, 'site-settings-by-avinash' ),
				$deleted
			),
			'success'
		);
	}

	private function delete_expired_transients(): void {
		$expired = $this->get_expired_transient_timeout_names();

		foreach ( $expired as $timeout_name ) {
			$transient_name = str_replace(
				array( '_transient_timeout_', '_site_transient_timeout_' ),
				array( '_transient_', '_site_transient_' ),
				$timeout_name
			);

			delete_option( $transient_name );
			delete_option( $timeout_name );
		}

		$this->set_notice(
			sprintf(
				/* translators: %d: Number of expired transients. */
				_n( '%d expired transient removed.', '%d expired transients removed.', count( $expired ), 'site-settings-by-avinash' ),
				count( $expired )
			),
			'success'
		);
	}

	private function optimize_database_table(): void {
		global $wpdb;

		$table_name = isset( $_POST['avinash_table_name'] ) ? sanitize_text_field( wp_unslash( $_POST['avinash_table_name'] ) ) : '';

		if ( '' === $table_name || ! $this->database_table_exists( $table_name ) ) {
			$this->set_notice( __( 'Select a valid database table to optimize.', 'site-settings-by-avinash' ), 'error' );
			return;
		}

		$escaped_table = '`' . str_replace( '`', '``', $table_name ) . '`';
		$result        = $wpdb->query( "OPTIMIZE TABLE {$escaped_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( false === $result ) {
			$this->set_notice( __( 'Table optimization failed. Check database permissions.', 'site-settings-by-avinash' ), 'error' );
			return;
		}

		$this->set_notice(
			sprintf(
				/* translators: %s: Database table name. */
				__( '%s optimized successfully.', 'site-settings-by-avinash' ),
				$table_name
			),
			'success'
		);
	}

	public function configure_phpmailer( $phpmailer ): void {
		$options = $this->get_options();

		if ( empty( $options['smtp_enabled'] ) ) {
			return;
		}

		if ( empty( $options['smtp_password'] ) || ! is_email( $options['smtp_username'] ) ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host       = $options['smtp_host'];
		$phpmailer->Port       = (int) $options['smtp_port'];
		$phpmailer->SMTPAuth   = true;
		$phpmailer->Username   = $options['smtp_username'];
		$phpmailer->Password   = $options['smtp_password'];
		$phpmailer->SMTPSecure = 'none' === $options['smtp_encryption'] ? '' : $options['smtp_encryption'];

		if ( ! empty( $options['smtp_force_from'] ) && is_email( $options['smtp_from_email'] ) ) {
			$phpmailer->setFrom( $options['smtp_from_email'], $options['smtp_from_name'], false );
		}
	}

	public function print_header_scripts(): void {
		$options = $this->get_options();

		if ( '' !== trim( $options['header_scripts'] ) ) {
			echo "\n<!-- Site Settings header scripts -->\n";
			echo $options['header_scripts']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo "\n<!-- /Site Settings header scripts -->\n";
		}
	}

	public function print_footer_scripts(): void {
		$options = $this->get_options();

		if ( '' !== trim( $options['footer_scripts'] ) ) {
			echo "\n<!-- Site Settings footer scripts -->\n";
			echo $options['footer_scripts']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo "\n<!-- /Site Settings footer scripts -->\n";
		}
	}

	public function load_custom_functions(): void {
		$options = $this->get_options();
		$snippets = $options['custom_functions'];
		$errors   = array();

		foreach ( $snippets as $snippet ) {
			if ( empty( $snippet['enabled'] ) || '' === trim( $snippet['code'] ) ) {
				continue;
			}

			try {
				eval( $this->normalize_php_code( $snippet['code'] ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
			} catch ( Throwable $throwable ) {
				$errors[] = sprintf(
					/* translators: 1: Snippet title, 2: PHP error message. */
					__( '%1$s: %2$s', 'site-settings-by-avinash' ),
					$snippet['title'],
					$throwable->getMessage()
				);
			}
		}

		if ( empty( $errors ) ) {
			delete_transient( 'avinash_site_settings_php_error' );
			return;
		}

		set_transient( 'avinash_site_settings_php_error', implode( ' | ', $errors ), HOUR_IN_SECONDS );
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options        = $this->get_options();
		$active_tab     = $this->normalize_tab( isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'smtp' );
		$notice         = get_transient( self::NOTICE_TRANSIENT );
		$php_error      = get_transient( 'avinash_site_settings_php_error' );
		$revision_count = $this->get_revision_count();
		$comment_count  = 'database' === $active_tab ? $this->get_unapproved_comment_count() : 0;
		$transients     = 'database' === $active_tab ? $this->get_transient_counts() : array( 'expired' => 0, 'total' => 0 );
		$db_size        = $this->get_database_size();
		$db_tables      = 'database' === $active_tab ? $this->get_database_tables() : array();

		if ( false !== $notice ) {
			delete_transient( self::NOTICE_TRANSIENT );
		}

		$tabs = array(
			'smtp'      => array( 'label' => __( 'SMTP Config', 'site-settings-by-avinash' ), 'icon' => 'dashicons-email-alt2' ),
			'scripts'   => array( 'label' => __( 'Header & Footer', 'site-settings-by-avinash' ), 'icon' => 'dashicons-editor-code' ),
			'functions' => array( 'label' => __( 'Custom Functions', 'site-settings-by-avinash' ), 'icon' => 'dashicons-editor-kitchensink' ),
			'database'  => array( 'label' => __( 'DB Optimization', 'site-settings-by-avinash' ), 'icon' => 'dashicons-database' ),
		);
		?>
		<div class="avinash-settings-shell">
			<header class="avinash-topbar">
				<div>
					<h1><?php esc_html_e( 'Site Settings', 'site-settings-by-avinash' ); ?></h1>
					<p><?php esc_html_e( 'By Avinash', 'site-settings-by-avinash' ); ?></p>
				</div>
				<div class="avinash-topbar__actions">
					<form method="post">
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
						<input type="hidden" name="avinash_site_settings_action" value="reset">
						<button class="avinash-button avinash-button--secondary" type="submit"><?php esc_html_e( 'Reset Defaults', 'site-settings-by-avinash' ); ?></button>
					</form>
					<?php if ( 'database' !== $active_tab ) : ?>
						<button class="avinash-button avinash-button--primary" type="submit" form="avinash-settings-form"><?php esc_html_e( 'Save Changes', 'site-settings-by-avinash' ); ?></button>
					<?php endif; ?>
				</div>
			</header>

			<?php if ( is_array( $notice ) ) : ?>
				<div class="avinash-notice avinash-notice--<?php echo esc_attr( $notice['type'] ); ?>">
					<?php echo esc_html( $notice['message'] ); ?>
				</div>
			<?php endif; ?>

			<?php if ( $php_error ) : ?>
				<div class="avinash-notice avinash-notice--error">
					<?php echo esc_html( sprintf( __( 'Custom functions were not loaded: %s', 'site-settings-by-avinash' ), $php_error ) ); ?>
				</div>
			<?php endif; ?>

			<div class="avinash-layout">
				<aside class="avinash-sidebar">
					<nav aria-label="<?php esc_attr_e( 'Site settings sections', 'site-settings-by-avinash' ); ?>">
						<?php foreach ( $tabs as $tab => $tab_data ) : ?>
							<a class="<?php echo esc_attr( $active_tab === $tab ? 'is-active' : '' ); ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) ) ); ?>">
								<span class="dashicons <?php echo esc_attr( $tab_data['icon'] ); ?>"></span>
								<span><?php echo esc_html( $tab_data['label'] ); ?></span>
							</a>
						<?php endforeach; ?>
					</nav>
				</aside>

				<main class="avinash-content">
					<form id="avinash-settings-form" method="post">
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
						<input type="hidden" name="avinash_site_settings_action" value="save">
						<input type="hidden" name="avinash_site_settings_tab" value="<?php echo esc_attr( $active_tab ); ?>">

						<?php if ( 'smtp' === $active_tab ) : ?>
							<?php $this->render_smtp_tab( $options ); ?>
						<?php elseif ( 'scripts' === $active_tab ) : ?>
							<?php $this->render_scripts_tab( $options ); ?>
						<?php elseif ( 'functions' === $active_tab ) : ?>
							<?php $this->render_functions_tab( $options ); ?>
						<?php endif; ?>
					</form>

					<?php if ( 'smtp' === $active_tab ) : ?>
						<?php $this->render_test_email_panel(); ?>
					<?php endif; ?>

					<?php if ( 'database' === $active_tab ) : ?>
						<?php $this->render_database_tab( $revision_count, $comment_count, $transients, $db_size, $db_tables ); ?>
					<?php endif; ?>
				</main>
			</div>
		</div>
		<?php
	}

	private function render_smtp_tab( array $options ): void {
		?>
		<section class="avinash-panel">
			<div class="avinash-panel__header">
				<div>
					<h2><?php esc_html_e( 'SMTP Configuration', 'site-settings-by-avinash' ); ?></h2>
					<p><?php esc_html_e( 'Configure outgoing WordPress and form emails with forced sender details.', 'site-settings-by-avinash' ); ?></p>
				</div>
				<label class="avinash-switch">
					<input type="checkbox" name="avinash_site_settings[smtp_enabled]" value="1" <?php checked( $options['smtp_enabled'] ); ?>>
					<span></span>
					<strong><?php esc_html_e( 'Enabled', 'site-settings-by-avinash' ); ?></strong>
				</label>
			</div>

			<div class="avinash-panel__body">
				<div class="avinash-field-row">
					<div>
						<label for="avinash-smtp-host"><?php esc_html_e( 'SMTP Host', 'site-settings-by-avinash' ); ?></label>
						<p><?php esc_html_e( 'Pre-filled from your site domain as mail.domainname.tld.', 'site-settings-by-avinash' ); ?></p>
					</div>
					<input id="avinash-smtp-host" name="avinash_site_settings[smtp_host]" type="text" value="<?php echo esc_attr( $options['smtp_host'] ); ?>" required>
				</div>

				<div class="avinash-field-row">
					<div>
						<label><?php esc_html_e( 'Encryption', 'site-settings-by-avinash' ); ?></label>
						<p><?php esc_html_e( 'TLS is selected by default for port 587.', 'site-settings-by-avinash' ); ?></p>
					</div>
					<div class="avinash-radio-group">
						<label><input type="radio" name="avinash_site_settings[smtp_encryption]" value="tls" <?php checked( $options['smtp_encryption'], 'tls' ); ?>> <?php esc_html_e( 'TLS', 'site-settings-by-avinash' ); ?></label>
						<label><input type="radio" name="avinash_site_settings[smtp_encryption]" value="ssl" <?php checked( $options['smtp_encryption'], 'ssl' ); ?>> <?php esc_html_e( 'SSL', 'site-settings-by-avinash' ); ?></label>
						<label><input type="radio" name="avinash_site_settings[smtp_encryption]" value="none" <?php checked( $options['smtp_encryption'], 'none' ); ?>> <?php esc_html_e( 'None', 'site-settings-by-avinash' ); ?></label>
					</div>
				</div>

				<div class="avinash-field-row">
					<div>
						<label for="avinash-smtp-port"><?php esc_html_e( 'Port', 'site-settings-by-avinash' ); ?></label>
						<p><?php esc_html_e( 'Use 587 for TLS, 465 for SSL, or your provider value.', 'site-settings-by-avinash' ); ?></p>
					</div>
					<input id="avinash-smtp-port" class="avinash-input--short" name="avinash_site_settings[smtp_port]" type="number" min="1" value="<?php echo esc_attr( $options['smtp_port'] ); ?>" required>
				</div>

				<div class="avinash-field-row">
					<div>
						<label for="avinash-smtp-username"><?php esc_html_e( 'Email / Username', 'site-settings-by-avinash' ); ?></label>
						<p><?php esc_html_e( 'Pre-filled as noreply@domainname.tld.', 'site-settings-by-avinash' ); ?></p>
					</div>
					<input id="avinash-smtp-username" name="avinash_site_settings[smtp_username]" type="email" value="<?php echo esc_attr( $options['smtp_username'] ); ?>" required>
				</div>

				<div class="avinash-field-row">
					<div>
						<label for="avinash-smtp-password"><?php esc_html_e( 'Password', 'site-settings-by-avinash' ); ?></label>
						<p><?php esc_html_e( 'Leave blank to keep the saved password.', 'site-settings-by-avinash' ); ?></p>
					</div>
					<input id="avinash-smtp-password" name="avinash_site_settings[smtp_password]" type="password" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $options['smtp_password'] ? __( 'Saved password will be kept', 'site-settings-by-avinash' ) : __( 'Enter mailbox password', 'site-settings-by-avinash' ) ); ?>">
				</div>

				<div class="avinash-field-row">
					<div>
						<label for="avinash-from-email"><?php esc_html_e( 'From Email', 'site-settings-by-avinash' ); ?></label>
						<p><?php esc_html_e( 'Forced for all outgoing mail sent through WordPress.', 'site-settings-by-avinash' ); ?></p>
					</div>
					<input id="avinash-from-email" name="avinash_site_settings[smtp_from_email]" type="email" value="<?php echo esc_attr( $options['smtp_from_email'] ); ?>" required>
				</div>

				<div class="avinash-field-row">
					<div>
						<label for="avinash-from-name"><?php esc_html_e( 'From Name', 'site-settings-by-avinash' ); ?></label>
						<p><?php esc_html_e( 'Shown as the sender name in email clients.', 'site-settings-by-avinash' ); ?></p>
					</div>
					<input id="avinash-from-name" name="avinash_site_settings[smtp_from_name]" type="text" value="<?php echo esc_attr( $options['smtp_from_name'] ); ?>">
				</div>

				<div class="avinash-locked-row">
					<span class="dashicons dashicons-lock"></span>
					<strong><?php esc_html_e( 'Authentication enabled', 'site-settings-by-avinash' ); ?></strong>
					<span><?php esc_html_e( 'Sender email is forced on every message.', 'site-settings-by-avinash' ); ?></span>
				</div>
			</div>
		</section>
		<?php
	}

	private function render_test_email_panel(): void {
		?>
		<section class="avinash-panel avinash-panel--compact">
			<div class="avinash-panel__header">
				<div>
					<h2><?php esc_html_e( 'Test Email', 'site-settings-by-avinash' ); ?></h2>
					<p><?php esc_html_e( 'Send a live test after saving your SMTP password.', 'site-settings-by-avinash' ); ?></p>
				</div>
			</div>
			<div class="avinash-test-row">
				<input form="avinash-test-email-form" name="avinash_test_email" type="email" placeholder="<?php esc_attr_e( 'recipient@example.com', 'site-settings-by-avinash' ); ?>" required>
				<button form="avinash-test-email-form" class="avinash-link-button" type="submit">
					<span class="dashicons dashicons-email-alt"></span>
					<?php esc_html_e( 'Send Test Email', 'site-settings-by-avinash' ); ?>
				</button>
			</div>
		</section>

		<form id="avinash-test-email-form" method="post">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="avinash_site_settings_action" value="test_email">
			<input type="hidden" name="avinash_site_settings_tab" value="smtp">
		</form>
		<?php
	}

	private function render_scripts_tab( array $options ): void {
		?>
		<section class="avinash-panel">
			<div class="avinash-panel__header">
				<div>
					<h2><?php esc_html_e( 'Header & Footer Scripts', 'site-settings-by-avinash' ); ?></h2>
					<p><?php esc_html_e( 'Inject analytics tags, pixels, widgets, and custom JavaScript globally.', 'site-settings-by-avinash' ); ?></p>
				</div>
			</div>
			<div class="avinash-panel__body avinash-code-fields">
				<label for="avinash-header-scripts"><?php esc_html_e( 'Header Scripts', 'site-settings-by-avinash' ); ?></label>
				<textarea id="avinash-header-scripts" name="avinash_site_settings[header_scripts]" rows="8" spellcheck="false" placeholder="&lt;!-- Google Analytics, Meta Pixel, verification tags --&gt;"><?php echo esc_textarea( $options['header_scripts'] ); ?></textarea>
				<p><?php esc_html_e( 'Printed before the closing head tag.', 'site-settings-by-avinash' ); ?></p>

				<label for="avinash-footer-scripts"><?php esc_html_e( 'Footer Scripts', 'site-settings-by-avinash' ); ?></label>
				<textarea id="avinash-footer-scripts" name="avinash_site_settings[footer_scripts]" rows="8" spellcheck="false" placeholder="&lt;!-- Chat widgets, custom JS, tracking events --&gt;"><?php echo esc_textarea( $options['footer_scripts'] ); ?></textarea>
				<p><?php esc_html_e( 'Printed before the closing body tag.', 'site-settings-by-avinash' ); ?></p>
			</div>
		</section>
		<?php
	}

	private function render_functions_tab( array $options ): void {
		$snippets = $options['custom_functions'];

		if ( empty( $snippets ) ) {
			$snippets[] = array(
				'title'   => '',
				'code'    => '',
				'enabled' => true,
			);
		}
		?>
		<section class="avinash-panel avinash-functions-panel">
			<div class="avinash-panel__header">
				<div>
					<h2><?php esc_html_e( 'Custom Functions', 'site-settings-by-avinash' ); ?></h2>
					<p><?php esc_html_e( 'Manage PHP snippets individually and load only the enabled ones.', 'site-settings-by-avinash' ); ?></p>
				</div>
				<button class="avinash-button avinash-button--secondary" type="button" data-avinash-add-function>
					<span class="dashicons dashicons-plus-alt2"></span>
					<?php esc_html_e( 'Add Function', 'site-settings-by-avinash' ); ?>
				</button>
			</div>

			<div class="avinash-functions-list" data-avinash-functions-list>
				<?php foreach ( $snippets as $index => $snippet ) : ?>
					<?php $this->render_function_snippet( (int) $index, $snippet ); ?>
				<?php endforeach; ?>
			</div>

			<template data-avinash-function-template>
				<?php
				$this->render_function_snippet(
					'__INDEX__',
					array(
						'title'   => '',
						'code'    => '',
						'enabled' => true,
					)
				);
				?>
			</template>
		</section>
		<?php
	}

	private function render_function_snippet( $index, array $snippet ): void {
		$field_prefix = 'avinash_site_settings[custom_functions][' . $index . ']';
		$title_id     = 'avinash-function-title-' . $index;
		$code_id      = 'avinash-function-code-' . $index;
		?>
		<article class="avinash-function-item" data-avinash-function-item>
			<div class="avinash-function-item__header">
				<div class="avinash-function-title">
					<label for="<?php echo esc_attr( $title_id ); ?>"><?php esc_html_e( 'Title', 'site-settings-by-avinash' ); ?></label>
					<input id="<?php echo esc_attr( $title_id ); ?>" name="<?php echo esc_attr( $field_prefix ); ?>[title]" type="text" value="<?php echo esc_attr( $snippet['title'] ); ?>" placeholder="<?php esc_attr_e( 'Function title', 'site-settings-by-avinash' ); ?>">
				</div>
				<div class="avinash-function-actions">
					<label class="avinash-switch">
						<input type="checkbox" name="<?php echo esc_attr( $field_prefix ); ?>[enabled]" value="1" <?php checked( ! empty( $snippet['enabled'] ) ); ?>>
						<span></span>
						<strong><?php esc_html_e( 'Enabled', 'site-settings-by-avinash' ); ?></strong>
					</label>
					<button class="avinash-icon-button" type="button" data-avinash-remove-function title="<?php esc_attr_e( 'Remove function', 'site-settings-by-avinash' ); ?>">
						<span class="dashicons dashicons-trash"></span>
					</button>
				</div>
			</div>
			<textarea id="<?php echo esc_attr( $code_id ); ?>" name="<?php echo esc_attr( $field_prefix ); ?>[code]" rows="10" spellcheck="false" placeholder="<?php esc_attr_e( "Opening <?php tags are optional.\n\nadd_action('wp_head', function () {\n    echo '<!-- Site configured by Site Settings -->';\n});", 'site-settings-by-avinash' ); ?>"><?php echo esc_textarea( $snippet['code'] ); ?></textarea>
		</article>
		<?php
	}

	private function render_database_tab( int $revision_count, int $comment_count, array $transients, string $db_size, array $tables ): void {
		$myisam_overhead = 0;
		$myisam_count    = 0;
		$innodb_count    = 0;

		foreach ( $tables as $table ) {
			if ( 'myisam' === strtolower( $table['engine'] ) ) {
				++$myisam_count;
				$myisam_overhead += (int) $table['overhead'];
			}

			if ( 'innodb' === strtolower( $table['engine'] ) ) {
				++$innodb_count;
			}
		}
		?>
		<section class="avinash-panel avinash-db-panel">
			<div class="avinash-db-summary">
				<div class="avinash-db-icon"><span class="dashicons dashicons-database"></span></div>
				<div>
					<h2><?php esc_html_e( 'Database Health', 'site-settings-by-avinash' ); ?></h2>
					<p>
						<?php esc_html_e( 'Current DB Size:', 'site-settings-by-avinash' ); ?>
						<strong><?php echo esc_html( $db_size ); ?></strong>
					</p>
					<p>
						<?php esc_html_e( 'MyISAM Overhead:', 'site-settings-by-avinash' ); ?>
						<strong><?php echo esc_html( size_format( $myisam_overhead, 1 ) ); ?></strong>
					</p>
				</div>
				<form method="post">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
					<input type="hidden" name="avinash_site_settings_action" value="optimize_revisions">
					<input type="hidden" name="avinash_site_settings_tab" value="database">
					<button class="avinash-button avinash-button--primary" type="submit"><?php esc_html_e( 'Remove Revisions', 'site-settings-by-avinash' ); ?></button>
				</form>
			</div>
			<div class="avinash-db-task">
				<label>
					<input type="checkbox" checked disabled>
					<span>
						<strong><?php esc_html_e( 'Clean Post Revisions', 'site-settings-by-avinash' ); ?></strong>
						<em>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: Number of revisions. */
									_n( 'Remove old versions of posts and pages (%d found).', 'Remove old versions of posts and pages (%d found).', $revision_count, 'site-settings-by-avinash' ),
									$revision_count
								)
							);
							?>
						</em>
					</span>
				</label>
				<strong><?php esc_html_e( 'Recommended', 'site-settings-by-avinash' ); ?></strong>
			</div>
			<div class="avinash-db-task">
				<label>
					<input type="checkbox" checked disabled>
					<span>
						<strong><?php esc_html_e( 'Remove Unapproved Comments', 'site-settings-by-avinash' ); ?></strong>
						<em>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: Number of unapproved comments. */
									_n( '%d unapproved comment found.', '%d unapproved comments found.', $comment_count, 'site-settings-by-avinash' ),
									$comment_count
								)
							);
							?>
						</em>
					</span>
				</label>
				<form method="post">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
					<input type="hidden" name="avinash_site_settings_action" value="delete_unapproved_comments">
					<input type="hidden" name="avinash_site_settings_tab" value="database">
					<button class="avinash-link-button" type="submit"><?php esc_html_e( 'Remove', 'site-settings-by-avinash' ); ?></button>
				</form>
			</div>
			<div class="avinash-db-task">
				<label>
					<input type="checkbox" checked disabled>
					<span>
						<strong><?php esc_html_e( 'Remove Expired Transient Options', 'site-settings-by-avinash' ); ?></strong>
						<em>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: Number of expired transients, 2: Total transient timeout options. */
									__( '%1$d expired / %2$d total transient option(s).', 'site-settings-by-avinash' ),
									(int) $transients['expired'],
									(int) $transients['total']
								)
							);
							?>
						</em>
					</span>
				</label>
				<form method="post">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
					<input type="hidden" name="avinash_site_settings_action" value="delete_expired_transients">
					<input type="hidden" name="avinash_site_settings_tab" value="database">
					<button class="avinash-link-button" type="submit"><?php esc_html_e( 'Remove', 'site-settings-by-avinash' ); ?></button>
				</form>
			</div>
		</section>

		<section class="avinash-panel avinash-db-table-panel">
			<div class="avinash-panel__header">
				<div>
					<h2><?php esc_html_e( 'Database Tables', 'site-settings-by-avinash' ); ?></h2>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: MyISAM table count, 2: InnoDB table count. */
								__( '%1$d MyISAM table(s), %2$d InnoDB table(s).', 'site-settings-by-avinash' ),
								$myisam_count,
								$innodb_count
							)
						);
						?>
					</p>
				</div>
			</div>

			<?php if ( empty( $tables ) ) : ?>
				<div class="avinash-empty-state">
					<?php esc_html_e( 'No database table details are available from this database user.', 'site-settings-by-avinash' ); ?>
				</div>
			<?php else : ?>
				<div class="avinash-table-wrap">
					<table class="avinash-data-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Table', 'site-settings-by-avinash' ); ?></th>
								<th><?php esc_html_e( 'Engine', 'site-settings-by-avinash' ); ?></th>
								<th><?php esc_html_e( 'Rows', 'site-settings-by-avinash' ); ?></th>
								<th><?php esc_html_e( 'Data', 'site-settings-by-avinash' ); ?></th>
								<th><?php esc_html_e( 'Index', 'site-settings-by-avinash' ); ?></th>
								<th><?php esc_html_e( 'Total Size', 'site-settings-by-avinash' ); ?></th>
								<th><?php esc_html_e( 'Overhead', 'site-settings-by-avinash' ); ?></th>
								<th><?php esc_html_e( 'Action', 'site-settings-by-avinash' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $tables as $table ) : ?>
								<tr>
									<td><code><?php echo esc_html( $table['name'] ); ?></code></td>
									<td><span class="avinash-engine-badge avinash-engine-badge--<?php echo esc_attr( strtolower( $table['engine'] ) ); ?>"><?php echo esc_html( $table['engine'] ); ?></span></td>
									<td><?php echo esc_html( number_format_i18n( (int) $table['rows'] ) ); ?></td>
									<td><?php echo esc_html( size_format( (int) $table['data_length'], 1 ) ); ?></td>
									<td><?php echo esc_html( size_format( (int) $table['index_length'], 1 ) ); ?></td>
									<td><strong><?php echo esc_html( size_format( (int) $table['size'], 1 ) ); ?></strong></td>
									<td><?php echo esc_html( size_format( (int) $table['overhead'], 1 ) ); ?></td>
									<td>
										<form method="post">
											<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
											<input type="hidden" name="avinash_site_settings_action" value="optimize_table">
											<input type="hidden" name="avinash_site_settings_tab" value="database">
											<input type="hidden" name="avinash_table_name" value="<?php echo esc_attr( $table['name'] ); ?>">
											<button class="avinash-link-button" type="submit"><?php esc_html_e( 'Optimize', 'site-settings-by-avinash' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	private function get_options(): array {
		$options = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$options = wp_parse_args( $options, $this->get_defaults() );
		$options['custom_functions'] = is_array( $options['custom_functions'] ) ? $options['custom_functions'] : array();

		return $options;
	}

	private function get_defaults(): array {
		$domain = wp_parse_url( home_url(), PHP_URL_HOST );
		$domain = $domain ? preg_replace( '/^www\./i', '', $domain ) : 'domainname.tld';
		$email  = 'noreply@' . $domain;

		return array(
			'smtp_enabled'     => true,
			'smtp_host'        => 'mail.' . $domain,
			'smtp_port'        => 587,
			'smtp_encryption'  => 'tls',
			'smtp_auth'        => true,
			'smtp_username'    => $email,
			'smtp_password'    => '',
			'smtp_from_email'  => $email,
			'smtp_from_name'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'smtp_force_from'  => true,
			'header_scripts'   => '',
			'footer_scripts'   => '',
			'custom_functions' => array(),
		);
	}

	private function sanitize_custom_functions( $snippets ): array {
		if ( ! is_array( $snippets ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $snippets as $snippet ) {
			if ( ! is_array( $snippet ) ) {
				continue;
			}

			$title = sanitize_text_field( $snippet['title'] ?? '' );
			$code  = (string) ( $snippet['code'] ?? '' );

			if ( '' === trim( $title ) && '' === trim( $code ) ) {
				continue;
			}

			$normalized[] = array(
				'title'   => '' !== trim( $title ) ? $title : __( 'Untitled Function', 'site-settings-by-avinash' ),
				'code'    => $code,
				'enabled' => ! empty( $snippet['enabled'] ),
			);
		}

		return $normalized;
	}

	private function get_revision_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
	}

	private function get_unapproved_comment_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(comment_ID) FROM {$wpdb->comments} WHERE comment_approved = '0'" );
	}

	private function get_transient_counts(): array {
		global $wpdb;

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(option_id) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_' ) . '%'
			)
		);

		return array(
			'expired' => count( $this->get_expired_transient_timeout_names() ),
			'total'   => $total,
		);
	}

	private function get_expired_transient_timeout_names(): array {
		global $wpdb;

		$now = time();
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				WHERE (option_name LIKE %s OR option_name LIKE %s)
				AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
				$now
			)
		);

		return is_array( $names ) ? $names : array();
	}

	private function get_database_size(): string {
		global $wpdb;

		$bytes = (float) $wpdb->get_var( 'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()' );

		if ( $bytes <= 0 ) {
			return __( 'Unavailable', 'site-settings-by-avinash' );
		}

		return size_format( $bytes, 1 );
	}

	private function get_database_tables(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT table_name, engine, table_rows, data_length, index_length, data_free
			FROM information_schema.TABLES
			WHERE table_schema = DATABASE()
			ORDER BY table_name ASC',
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$tables = array();

		foreach ( $rows as $row ) {
			$data_length  = max( 0, (int) ( $row['data_length'] ?? 0 ) );
			$index_length = max( 0, (int) ( $row['index_length'] ?? 0 ) );
			$overhead     = max( 0, (int) ( $row['data_free'] ?? 0 ) );

			$tables[] = array(
				'name'         => (string) ( $row['table_name'] ?? '' ),
				'engine'       => (string) ( $row['engine'] ?? __( 'Unknown', 'site-settings-by-avinash' ) ),
				'rows'         => max( 0, (int) ( $row['table_rows'] ?? 0 ) ),
				'data_length'  => $data_length,
				'index_length' => $index_length,
				'size'         => $data_length + $index_length,
				'overhead'     => $overhead,
			);
		}

		return $tables;
	}

	private function database_table_exists( string $table_name ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT table_name FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = %s LIMIT 1',
				$table_name
			)
		);

		return $found === $table_name;
	}

	private function set_notice( string $message, string $type ): void {
		set_transient(
			self::NOTICE_TRANSIENT,
			array(
				'message' => $message,
				'type'    => 'error' === $type ? 'error' : 'success',
			),
			MINUTE_IN_SECONDS
		);
	}

	private function normalize_tab( string $tab ): string {
		return in_array( $tab, array( 'smtp', 'scripts', 'functions', 'database' ), true ) ? $tab : 'smtp';
	}

	private function normalize_encryption( string $encryption ): string {
		return in_array( strtolower( $encryption ), array( 'tls', 'ssl', 'none' ), true ) ? strtolower( $encryption ) : 'tls';
	}

	private function normalize_php_code( string $code ): string {
		$code = preg_replace( '/^\s*<\?(php)?/i', '', $code );
		$code = preg_replace( '/\?>\s*$/', '', $code );

		return (string) $code;
	}
}

Avinash_Site_Settings::instance();
