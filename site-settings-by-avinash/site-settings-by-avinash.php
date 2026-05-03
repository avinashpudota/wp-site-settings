<?php
/**
 * Plugin Name: site settings
 * Description: By Avinash. Lightweight personal utility plugin for SMTP, scripts, custom functions, and database maintenance.
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
			$options['custom_functions'] = (string) ( $posted['custom_functions'] ?? '' );
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
		$code    = trim( $options['custom_functions'] );

		if ( '' === $code ) {
			return;
		}

		try {
			eval( $this->normalize_php_code( $code ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
			delete_transient( 'avinash_site_settings_php_error' );
		} catch ( Throwable $throwable ) {
			set_transient( 'avinash_site_settings_php_error', $throwable->getMessage(), HOUR_IN_SECONDS );
		}
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
		$db_size        = $this->get_database_size();

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
						<?php $this->render_database_tab( $revision_count, $db_size ); ?>
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
		?>
		<section class="avinash-editor">
			<div class="avinash-editor__top">
				<div>
					<span class="dashicons dashicons-editor-code"></span>
					<strong><?php esc_html_e( 'CUSTOM FUNCTIONS (PHP)', 'site-settings-by-avinash' ); ?></strong>
				</div>
				<div class="avinash-editor__lights" aria-hidden="true"><span></span><span></span><span></span></div>
			</div>
			<div class="avinash-editor__body">
				<div class="avinash-editor__lines" aria-hidden="true">
					<?php for ( $i = 1; $i <= 14; $i++ ) : ?>
						<span><?php echo esc_html( (string) $i ); ?></span>
					<?php endfor; ?>
				</div>
				<textarea name="avinash_site_settings[custom_functions]" rows="14" spellcheck="false" placeholder="<?php esc_attr_e( "Add custom WordPress functions here. Opening <?php tags are optional.\n\nExample:\nadd_action('wp_head', function () {\n    echo '<!-- Site configured by Site Settings -->';\n});", 'site-settings-by-avinash' ); ?>"><?php echo esc_textarea( $options['custom_functions'] ); ?></textarea>
			</div>
			<div class="avinash-editor__status">
				<span><?php esc_html_e( 'PHP snippets load on plugins_loaded', 'site-settings-by-avinash' ); ?></span>
				<span><?php esc_html_e( 'UTF-8', 'site-settings-by-avinash' ); ?></span>
			</div>
		</section>
		<?php
	}

	private function render_database_tab( int $revision_count, string $db_size ): void {
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
				</div>
				<form method="post">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
					<input type="hidden" name="avinash_site_settings_action" value="optimize_revisions">
					<input type="hidden" name="avinash_site_settings_tab" value="database">
					<button class="avinash-button avinash-button--primary" type="submit"><?php esc_html_e( 'Run Optimization', 'site-settings-by-avinash' ); ?></button>
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
		</section>
		<?php
	}

	private function get_options(): array {
		$options = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $options ) ) {
			$options = array();
		}

		return wp_parse_args( $options, $this->get_defaults() );
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
			'custom_functions' => '',
		);
	}

	private function get_revision_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
	}

	private function get_database_size(): string {
		global $wpdb;

		$bytes = (float) $wpdb->get_var( 'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()' );

		if ( $bytes <= 0 ) {
			return __( 'Unavailable', 'site-settings-by-avinash' );
		}

		return size_format( $bytes, 1 );
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
