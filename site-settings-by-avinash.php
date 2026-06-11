<?php
/**
 * Plugin Name: Site Settings
 * Description: Lightweight personal utility plugin for SMTP, scripts, custom functions, and database maintenance.
 * Version: 1.1.0
 * Author: Avinash
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: site-settings-by-avinash
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-avinash-static-site-cache.php';
require_once __DIR__ . '/includes/class-avinash-static-site-rewrites.php';
require_once __DIR__ . '/includes/class-avinash-static-site-generator.php';
require_once __DIR__ . '/includes/class-avinash-static-site-module.php';

final class Avinash_Site_Settings {
	private const VERSION          = '1.1.0';
	private const OPTION_NAME      = 'avinash_site_settings_options';
	private const NOTICE_TRANSIENT = 'avinash_site_settings_notice';
	private const UPDATE_TRANSIENT = 'avinash_site_settings_github_update';
	private const PAGE_SLUG        = 'avinash-site-settings';
	private const NONCE_ACTION     = 'avinash_site_settings_action';
	private const NONCE_NAME       = 'avinash_site_settings_nonce';
	private const GITHUB_OWNER     = 'avinashpudota';
	private const GITHUB_REPO      = 'wp-site-settings';
	private const GITHUB_BRANCH    = 'master';
	private const REQUIRES_WP      = '6.0';
	private const REQUIRES_PHP     = '7.4';

	private static $instance = null;

	/** @var Avinash_Static_Site_Module */
	private $static_site;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->static_site = Avinash_Static_Site_Module::instance();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'phpmailer_init', array( $this, 'configure_phpmailer' ) );
		add_action( 'wp_head', array( $this, 'print_header_scripts' ), 99 );
		add_action( 'wp_footer', array( $this, 'print_footer_scripts' ), 99 );
		add_action( 'plugins_loaded', array( $this, 'load_custom_functions' ), 20 );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_action_links' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_plugin_update' ) );
		add_filter( 'plugins_api', array( $this, 'get_plugin_update_info' ), 10, 3 );
		add_filter( 'auto_update_plugin', array( $this, 'enable_automatic_plugin_updates' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'normalize_github_update_source' ), 10, 4 );
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

	public function check_for_plugin_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$plugin_file = plugin_basename( __FILE__ );

		if ( empty( $transient->checked ) || empty( $transient->checked[ $plugin_file ] ) ) {
			return $transient;
		}

		$release = $this->get_github_update_data( $this->should_force_update_check() );

		if ( empty( $release['version'] ) || empty( $release['package'] ) ) {
			return $transient;
		}

		if ( ! version_compare( $release['version'], self::VERSION, '>' ) ) {
			return $transient;
		}

		if ( empty( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ $plugin_file ] = (object) array(
			'id'             => $this->get_github_repository_url(),
			'slug'           => self::GITHUB_REPO,
			'plugin'         => $plugin_file,
			'new_version'    => $release['version'],
			'url'            => $release['details_url'],
			'package'        => $release['package'],
			'requires'       => self::REQUIRES_WP,
			'requires_php'   => self::REQUIRES_PHP,
			'tested'         => $release['tested'],
			'last_updated'   => $release['published_at'],
			'upgrade_notice' => $release['name'],
		);

		return $transient;
	}

	public function get_plugin_update_info( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::GITHUB_REPO !== $args->slug ) {
			return $result;
		}

		$release = $this->get_github_update_data( $this->should_force_update_check() );

		if ( empty( $release['version'] ) ) {
			return $result;
		}

		return (object) array(
			'name'          => __( 'Site Settings', 'site-settings-by-avinash' ),
			'slug'          => self::GITHUB_REPO,
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/' . esc_attr( self::GITHUB_OWNER ) . '">Avinash</a>',
			'homepage'      => $this->get_github_repository_url(),
			'download_link' => $release['package'],
			'requires'      => self::REQUIRES_WP,
			'requires_php'  => self::REQUIRES_PHP,
			'tested'        => $release['tested'],
			'last_updated'  => $release['published_at'],
			'sections'      => array(
				'description' => __( 'Lightweight personal utility plugin for SMTP, scripts, custom functions, and database maintenance.', 'site-settings-by-avinash' ),
				'changelog'   => wp_kses_post( wpautop( $release['body'] ) ),
			),
		);
	}

	public function enable_automatic_plugin_updates( $update, $item ) {
		if ( isset( $item->plugin ) && plugin_basename( __FILE__ ) === $item->plugin ) {
			return true;
		}

		return $update;
	}

	public function normalize_github_update_source( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( empty( $hook_extra['plugin'] ) || plugin_basename( __FILE__ ) !== $hook_extra['plugin'] ) {
			return $source;
		}

		global $wp_filesystem;

		if ( empty( $wp_filesystem ) || ! $wp_filesystem->is_dir( $source ) ) {
			return $source;
		}

		$directory_name = $this->get_installed_plugin_directory_name();
		$new_source     = trailingslashit( $remote_source ) . $directory_name;

		if ( untrailingslashit( $source ) === untrailingslashit( $new_source ) ) {
			return $source;
		}

		if ( $wp_filesystem->exists( $new_source ) ) {
			$wp_filesystem->delete( $new_source, true );
		}

		if ( $wp_filesystem->move( $source, $new_source, true ) ) {
			return trailingslashit( $new_source );
		}

		return $source;
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
			case 'static_regenerate':
				$this->regenerate_static_site();
				$tab = 'static';
				break;
			case 'static_clear_cache':
				$this->clear_static_site_cache();
				$tab = 'static';
				break;
			case 'static_rebuild_rewrites':
				$this->rebuild_static_site_rewrites();
				$tab = 'static';
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

		if ( 'static' === $tab ) {
			$this->static_site->update_settings(
				array(
					'enabled'         => ! empty( $posted['static_enabled'] ),
					'generation_mode' => isset( $posted['static_generation_mode'] ) ? sanitize_key( $posted['static_generation_mode'] ) : 'on_demand',
				)
			);
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

	private function regenerate_static_site(): void {
		if ( $this->static_site->is_enabled() ) {
			$rewrite_result = $this->static_site->rewrites->install();
			if ( is_wp_error( $rewrite_result ) ) {
				$this->set_notice( $rewrite_result->get_error_message(), 'error' );
				return;
			}
		}

		$results = $this->static_site->generator->regenerate_all();

		$message = sprintf(
			/* translators: 1: Successful pages, 2: Total pages, 3: Failed pages. */
			__( 'Regenerated %1$d of %2$d static page(s). Failed: %3$d.', 'site-settings-by-avinash' ),
			(int) $results['success'],
			(int) $results['total'],
			(int) $results['failed']
		);

		if ( ! empty( $results['errors'] ) ) {
			$message .= ' ' . implode( ' | ', array_slice( $results['errors'], 0, 3 ) );
		}

		$this->set_notice( $message, empty( $results['failed'] ) ? 'success' : 'error' );
	}

	private function clear_static_site_cache(): void {
		$this->static_site->cache->clear();
		$this->set_notice( __( 'Static cache cleared.', 'site-settings-by-avinash' ), 'success' );
	}

	private function rebuild_static_site_rewrites(): void {
		$result = $this->static_site->is_enabled()
			? $this->static_site->rewrites->install()
			: $this->static_site->rewrites->remove();

		if ( is_wp_error( $result ) ) {
			$this->set_notice( $result->get_error_message(), 'error' );
			return;
		}

		$this->set_notice( __( 'Static cache rewrite rules rebuilt.', 'site-settings-by-avinash' ), 'success' );
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
		$static_settings = 'static' === $active_tab ? $this->static_site->get_settings() : array();
		$static_files    = 'static' === $active_tab ? $this->static_site->cache->list_files() : array();

		if ( false !== $notice ) {
			delete_transient( self::NOTICE_TRANSIENT );
		}

		$tabs = array(
			'smtp'      => array( 'label' => __( 'SMTP Config', 'site-settings-by-avinash' ), 'icon' => 'dashicons-email-alt2' ),
			'scripts'   => array( 'label' => __( 'Header & Footer', 'site-settings-by-avinash' ), 'icon' => 'dashicons-editor-code' ),
			'functions' => array( 'label' => __( 'Custom Functions', 'site-settings-by-avinash' ), 'icon' => 'dashicons-editor-kitchensink' ),
			'static'    => array( 'label' => __( 'Static Site', 'site-settings-by-avinash' ), 'icon' => 'dashicons-media-code' ),
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
						<?php elseif ( 'static' === $active_tab ) : ?>
							<?php $this->render_static_site_tab( $static_settings ); ?>
						<?php endif; ?>
					</form>

					<?php if ( 'smtp' === $active_tab ) : ?>
						<?php $this->render_test_email_panel(); ?>
					<?php endif; ?>

					<?php if ( 'database' === $active_tab ) : ?>
						<?php $this->render_database_tab( $revision_count, $comment_count, $transients, $db_size, $db_tables ); ?>
					<?php endif; ?>

					<?php if ( 'static' === $active_tab ) : ?>
						<?php $this->render_static_site_actions( $static_files ); ?>
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

	private function render_static_site_tab( array $settings ): void {
		$settings = wp_parse_args(
			$settings,
			array(
				'enabled'         => 0,
				'generation_mode' => 'on_demand',
			)
		);
		?>
		<section class="avinash-panel">
			<div class="avinash-panel__header">
				<div>
					<h2><?php esc_html_e( 'Static Site Generator', 'site-settings-by-avinash' ); ?></h2>
					<p><?php esc_html_e( 'Serve generated HTML directly from LiteSpeed/Apache while WordPress still handles admin, REST, logged-in visits, and Elementor form submissions.', 'site-settings-by-avinash' ); ?></p>
				</div>
				<label class="avinash-switch">
					<input type="checkbox" name="avinash_site_settings[static_enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
					<span></span>
					<strong><?php esc_html_e( 'Enabled', 'site-settings-by-avinash' ); ?></strong>
				</label>
			</div>

			<div class="avinash-panel__body">
				<div class="avinash-field-row">
					<div>
						<label><?php esc_html_e( 'Generation Mode', 'site-settings-by-avinash' ); ?></label>
						<p><?php esc_html_e( 'Use on-demand for simple personal sites, or prebuild when you want every page regenerated manually after edits.', 'site-settings-by-avinash' ); ?></p>
					</div>
					<div class="avinash-radio-group avinash-radio-group--stacked">
						<label><input type="radio" name="avinash_site_settings[static_generation_mode]" value="on_demand" <?php checked( $settings['generation_mode'], 'on_demand' ); ?>> <?php esc_html_e( 'Generate on first uncached visit', 'site-settings-by-avinash' ); ?></label>
						<label><input type="radio" name="avinash_site_settings[static_generation_mode]" value="prebuild" <?php checked( $settings['generation_mode'], 'prebuild' ); ?>> <?php esc_html_e( 'Generate all pages manually or after updates', 'site-settings-by-avinash' ); ?></label>
					</div>
				</div>

				<div class="avinash-locked-row">
					<span class="dashicons dashicons-shield-alt"></span>
					<strong><?php esc_html_e( 'Static bypasses are always active', 'site-settings-by-avinash' ); ?></strong>
					<span><?php esc_html_e( 'POST requests, wp-admin, wp-json, search, feeds, previews, query strings, and logged-in users are served by WordPress.', 'site-settings-by-avinash' ); ?></span>
				</div>
			</div>
		</section>
		<?php
	}

	private function render_static_site_actions( array $files ): void {
		?>
		<section class="avinash-panel avinash-static-actions-panel">
			<div class="avinash-panel__header">
				<div>
					<h2><?php esc_html_e( 'Static Cache Actions', 'site-settings-by-avinash' ); ?></h2>
					<p>
						<?php
						printf(
							/* translators: %s: Static cache directory path. */
							esc_html__( 'Cache directory: %s', 'site-settings-by-avinash' ),
							esc_html( $this->static_site->cache->root() )
						);
						?>
					</p>
				</div>
			</div>
			<div class="avinash-static-actions">
				<?php $this->render_static_site_action_button( 'static_regenerate', __( 'Regenerate All Pages', 'site-settings-by-avinash' ), 'primary' ); ?>
				<?php $this->render_static_site_action_button( 'static_clear_cache', __( 'Clear Cache', 'site-settings-by-avinash' ), 'secondary' ); ?>
				<?php $this->render_static_site_action_button( 'static_rebuild_rewrites', __( 'Rebuild Rewrite Rules', 'site-settings-by-avinash' ), 'secondary' ); ?>
			</div>
		</section>

		<section class="avinash-panel avinash-static-files-panel">
			<div class="avinash-panel__header">
				<div>
					<h2><?php esc_html_e( 'Cached Files', 'site-settings-by-avinash' ); ?></h2>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: Number of cached HTML files. */
								_n( '%d static HTML file found.', '%d static HTML files found.', count( $files ), 'site-settings-by-avinash' ),
								count( $files )
							)
						);
						?>
					</p>
				</div>
			</div>

			<?php if ( empty( $files ) ) : ?>
				<div class="avinash-empty-state">
					<?php esc_html_e( 'No static HTML files have been generated yet.', 'site-settings-by-avinash' ); ?>
				</div>
			<?php else : ?>
				<div class="avinash-table-wrap">
					<table class="avinash-data-table avinash-static-files-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'URL', 'site-settings-by-avinash' ); ?></th>
								<th><?php esc_html_e( 'File', 'site-settings-by-avinash' ); ?></th>
								<th><?php esc_html_e( 'Age', 'site-settings-by-avinash' ); ?></th>
								<th><?php esc_html_e( 'Size', 'site-settings-by-avinash' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $files as $file ) : ?>
								<tr>
									<td><a href="<?php echo esc_url( $file['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $file['url'] ); ?></a></td>
									<td><code><?php echo esc_html( $file['relative'] ); ?></code></td>
									<td><?php echo esc_html( $this->format_static_cache_age( (int) $file['generated_at'] ) ); ?></td>
									<td><?php echo esc_html( size_format( (int) $file['size'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	private function render_static_site_action_button( string $action, string $label, string $style ): void {
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="avinash_site_settings_action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="avinash_site_settings_tab" value="static">
			<button class="avinash-button avinash-button--<?php echo esc_attr( 'primary' === $style ? 'primary' : 'secondary' ); ?>" type="submit"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function format_static_cache_age( int $timestamp ): string {
		$age = max( 0, time() - $timestamp );

		if ( $age < HOUR_IN_SECONDS ) {
			$value = max( 1, (int) floor( $age / MINUTE_IN_SECONDS ) );
			return sprintf(
				/* translators: %d: Age in minutes. */
				_n( '%d minute old', '%d minutes old', $value, 'site-settings-by-avinash' ),
				$value
			);
		}

		if ( $age < DAY_IN_SECONDS ) {
			$value = max( 1, (int) floor( $age / HOUR_IN_SECONDS ) );
			return sprintf(
				/* translators: %d: Age in hours. */
				_n( '%d hour old', '%d hours old', $value, 'site-settings-by-avinash' ),
				$value
			);
		}

		$value = max( 1, (int) floor( $age / DAY_IN_SECONDS ) );
		return sprintf(
			/* translators: %d: Age in days. */
			_n( '%d day old', '%d days old', $value, 'site-settings-by-avinash' ),
			$value
		);
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

	private function get_github_update_data( bool $force = false ): array {
		$cached = get_site_transient( self::UPDATE_TRANSIENT );

		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}

		$candidates = array_filter(
			array(
				$this->get_latest_github_release(),
				$this->get_latest_github_tag(),
				$this->get_github_branch_update(),
			)
		);

		$update = array();

		foreach ( $candidates as $candidate ) {
			if ( empty( $candidate['version'] ) ) {
				continue;
			}

			if ( empty( $update ) || version_compare( $candidate['version'], $update['version'], '>' ) ) {
				$update = $candidate;
			}
		}

		$update = wp_parse_args(
			$update,
			array(
				'version'      => '',
				'name'         => '',
				'body'         => '',
				'package'      => '',
				'details_url'  => $this->get_github_repository_url(),
				'published_at' => '',
				'tested'       => '',
			)
		);

		set_site_transient( self::UPDATE_TRANSIENT, $update, HOUR_IN_SECONDS );

		return $update;
	}

	private function get_latest_github_release(): array {
		$release = $this->github_get_json(
			sprintf(
				'https://api.github.com/repos/%1$s/%2$s/releases/latest',
				rawurlencode( self::GITHUB_OWNER ),
				rawurlencode( self::GITHUB_REPO )
			)
		);

		if ( empty( $release['tag_name'] ) ) {
			return array();
		}

		$version = $this->normalize_version( (string) $release['tag_name'] );
		$body    = ! empty( $release['body'] ) ? (string) $release['body'] : __( 'No changelog was provided for this release.', 'site-settings-by-avinash' );

		return array(
			'version'      => $version,
			'name'         => ! empty( $release['name'] ) ? (string) $release['name'] : $release['tag_name'],
			'body'         => $body,
			'package'      => $this->get_release_package_url( $release ),
			'details_url'  => ! empty( $release['html_url'] ) ? (string) $release['html_url'] : $this->get_github_repository_url(),
			'published_at' => ! empty( $release['published_at'] ) ? (string) $release['published_at'] : '',
			'tested'       => '',
		);
	}

	private function get_latest_github_tag(): array {
		$tags = $this->github_get_json(
			sprintf(
				'https://api.github.com/repos/%1$s/%2$s/tags?per_page=1',
				rawurlencode( self::GITHUB_OWNER ),
				rawurlencode( self::GITHUB_REPO )
			)
		);

		if ( empty( $tags[0]['name'] ) ) {
			return array();
		}

		$tag_name = (string) $tags[0]['name'];
		$package  = ! empty( $tags[0]['zipball_url'] )
			? (string) $tags[0]['zipball_url']
			: sprintf(
				'https://github.com/%1$s/%2$s/archive/refs/tags/%3$s.zip',
				rawurlencode( self::GITHUB_OWNER ),
				rawurlencode( self::GITHUB_REPO ),
				rawurlencode( $tag_name )
			);

		return array(
			'version'      => $this->normalize_version( $tag_name ),
			'name'         => sprintf(
				/* translators: %s: GitHub tag name. */
				__( 'GitHub tag %s', 'site-settings-by-avinash' ),
				$tag_name
			),
			'body'         => __( 'This update was discovered from the latest GitHub tag.', 'site-settings-by-avinash' ),
			'package'      => $package,
			'details_url'  => $this->get_github_repository_url() . '/releases/tag/' . rawurlencode( $tag_name ),
			'published_at' => '',
			'tested'       => '',
		);
	}

	private function get_github_branch_update(): array {
		$plugin_source = $this->github_get_body(
			sprintf(
				'https://raw.githubusercontent.com/%1$s/%2$s/%3$s/%4$s',
				rawurlencode( self::GITHUB_OWNER ),
				rawurlencode( self::GITHUB_REPO ),
				rawurlencode( self::GITHUB_BRANCH ),
				rawurlencode( basename( __FILE__ ) )
			)
		);

		if ( '' === $plugin_source || ! preg_match( '/^[ \t\/*#@]*Version:\s*([^\s]+)/mi', $plugin_source, $matches ) ) {
			return array();
		}

		$version = $this->normalize_version( (string) $matches[1] );

		return array(
			'version'      => $version,
			'name'         => sprintf(
				/* translators: %s: GitHub branch name. */
				__( 'Latest code from %s', 'site-settings-by-avinash' ),
				self::GITHUB_BRANCH
			),
			'body'         => __( 'This update was discovered from the plugin version in the GitHub branch.', 'site-settings-by-avinash' ),
			'package'      => sprintf(
				'https://github.com/%1$s/%2$s/archive/refs/heads/%3$s.zip',
				rawurlencode( self::GITHUB_OWNER ),
				rawurlencode( self::GITHUB_REPO ),
				rawurlencode( self::GITHUB_BRANCH )
			),
			'details_url'  => $this->get_github_repository_url(),
			'published_at' => '',
			'tested'       => '',
		);
	}

	private function get_release_package_url( array $release ): string {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( empty( $asset['browser_download_url'] ) || empty( $asset['name'] ) ) {
					continue;
				}

				if ( 'zip' === strtolower( pathinfo( (string) $asset['name'], PATHINFO_EXTENSION ) ) ) {
					return (string) $asset['browser_download_url'];
				}
			}
		}

		return ! empty( $release['zipball_url'] ) ? (string) $release['zipball_url'] : '';
	}

	private function github_get_json( string $url ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => $this->get_github_request_headers(),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $decoded ) ? $decoded : array();
	}

	private function github_get_body( string $url ): string {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => $this->get_github_request_headers(),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	private function get_github_request_headers(): array {
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
		);

		if ( defined( 'AVINASH_SITE_SETTINGS_GITHUB_TOKEN' ) && AVINASH_SITE_SETTINGS_GITHUB_TOKEN ) {
			$headers['Authorization'] = 'Bearer ' . AVINASH_SITE_SETTINGS_GITHUB_TOKEN;
		}

		return $headers;
	}

	private function get_github_repository_url(): string {
		return sprintf(
			'https://github.com/%1$s/%2$s',
			rawurlencode( self::GITHUB_OWNER ),
			rawurlencode( self::GITHUB_REPO )
		);
	}

	private function normalize_version( string $version ): string {
		return ltrim( trim( $version ), 'vV' );
	}

	private function should_force_update_check(): bool {
		return is_admin() && isset( $_GET['force-check'] ) && '1' === (string) wp_unslash( $_GET['force-check'] );
	}

	private function get_installed_plugin_directory_name(): string {
		$directory = dirname( plugin_basename( __FILE__ ) );

		return '.' !== $directory ? $directory : self::GITHUB_REPO;
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
		return in_array( $tab, array( 'smtp', 'scripts', 'functions', 'static', 'database' ), true ) ? $tab : 'smtp';
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

register_activation_hook( __FILE__, array( 'Avinash_Static_Site_Module', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Avinash_Static_Site_Module', 'deactivate' ) );

Avinash_Site_Settings::instance();
