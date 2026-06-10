<?php
/**
 * Static site module bootstrap and runtime behavior.
 *
 * @package Site_Settings_By_Avinash
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avinash_Static_Site_Module {
	const SETTINGS_OPTION           = 'avinash_static_site_settings';
	const CACHE_INDEX_OPTION        = 'avinash_static_site_cache_index';
	const BUILD_TOKEN_OPTION        = 'avinash_static_site_build_token';
	const LEGACY_SETTINGS_OPTION    = 'pssc_settings';
	const LEGACY_CACHE_INDEX_OPTION = 'pssc_cache_index';
	const LEGACY_BUILD_TOKEN_OPTION = 'pssc_build_token';

	private static $instance = null;

	/** @var Avinash_Static_Site_Cache */
	public $cache;

	/** @var Avinash_Static_Site_Rewrites */
	public $rewrites;

	/** @var Avinash_Static_Site_Generator */
	public $generator;

	private $capture_url = '';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function activate(): void {
		self::ensure_defaults();

		$cache = new Avinash_Static_Site_Cache();
		$cache->ensure_cache_dir();

		$settings = self::get_stored_settings();
		if ( ! empty( $settings['enabled'] ) ) {
			$rewrites = new Avinash_Static_Site_Rewrites();
			$rewrites->install();
		}
	}

	public static function deactivate(): void {
		$rewrites = new Avinash_Static_Site_Rewrites();
		$rewrites->remove();
	}

	public static function ensure_defaults(): void {
		if ( ! get_option( self::SETTINGS_OPTION ) ) {
			$legacy = get_option( self::LEGACY_SETTINGS_OPTION, array() );
			$legacy = is_array( $legacy ) ? $legacy : array();

			add_option(
				self::SETTINGS_OPTION,
				array(
					'enabled'         => empty( $legacy['enabled'] ) ? 0 : 1,
					'generation_mode' => isset( $legacy['generation_mode'] ) && in_array( $legacy['generation_mode'], array( 'on_demand', 'prebuild' ), true )
						? $legacy['generation_mode']
						: 'on_demand',
				),
				'',
				false
			);
		}

		if ( ! get_option( self::BUILD_TOKEN_OPTION ) ) {
			$legacy_token = get_option( self::LEGACY_BUILD_TOKEN_OPTION, '' );
			$token        = is_string( $legacy_token ) && '' !== $legacy_token ? $legacy_token : wp_generate_password( 32, false, false );
			add_option( self::BUILD_TOKEN_OPTION, $token, '', false );
		}
	}

	private static function get_stored_settings(): array {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		return is_array( $settings ) ? $settings : array();
	}

	private function __construct() {
		self::ensure_defaults();

		$this->cache     = new Avinash_Static_Site_Cache();
		$this->rewrites  = new Avinash_Static_Site_Rewrites();
		$this->generator = new Avinash_Static_Site_Generator( $this->cache );

		add_action( 'template_redirect', array( $this, 'maybe_start_capture' ), 0 );
		add_action( 'wp_ajax_avinash_static_elementor_nonce', array( $this, 'send_elementor_nonce' ) );
		add_action( 'wp_ajax_nopriv_avinash_static_elementor_nonce', array( $this, 'send_elementor_nonce' ) );
		add_action( 'wp_ajax_pssc_elementor_nonce', array( $this, 'send_elementor_nonce' ) );
		add_action( 'wp_ajax_nopriv_pssc_elementor_nonce', array( $this, 'send_elementor_nonce' ) );

		add_action( 'save_post', array( $this, 'handle_post_save' ), 20, 3 );
		add_action( 'deleted_post', array( $this, 'handle_post_deleted' ) );
		add_action( 'wp_update_nav_menu', array( $this, 'handle_global_change' ) );
		add_action( 'customize_save_after', array( $this, 'handle_global_change' ) );
		add_action( 'switch_theme', array( $this, 'handle_global_change' ) );
		add_action( 'avinash_static_regenerate_all_event', array( $this->generator, 'regenerate_all' ) );
		add_action( 'avinash_static_regenerate_post_event', array( $this, 'regenerate_post_event' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_items' ), 100 );
		add_action( 'admin_post_avinash_static_clear_cache', array( $this, 'handle_admin_bar_clear_cache' ) );
	}

	public function get_settings(): array {
		$defaults = array(
			'enabled'         => 0,
			'generation_mode' => 'on_demand',
		);

		return wp_parse_args( get_option( self::SETTINGS_OPTION, array() ), $defaults );
	}

	public function update_settings( array $settings ): void {
		$current = $this->get_settings();

		$updated = array(
			'enabled'         => empty( $settings['enabled'] ) ? 0 : 1,
			'generation_mode' => isset( $settings['generation_mode'] ) && in_array( $settings['generation_mode'], array( 'on_demand', 'prebuild' ), true )
				? $settings['generation_mode']
				: $current['generation_mode'],
		);

		update_option( self::SETTINGS_OPTION, $updated, false );

		if ( $updated['enabled'] ) {
			$this->cache->ensure_cache_dir();
			$this->rewrites->install();
		} else {
			$this->rewrites->remove();
		}
	}

	public function is_enabled(): bool {
		$settings = $this->get_settings();

		return ! empty( $settings['enabled'] );
	}

	public function generation_mode(): string {
		$settings = $this->get_settings();

		return (string) $settings['generation_mode'];
	}

	public function maybe_start_capture(): void {
		$is_build_request = $this->is_valid_build_request();

		if ( ! $is_build_request && ( ! $this->is_enabled() || 'on_demand' !== $this->generation_mode() ) ) {
			return;
		}

		if ( ! $this->is_cacheable_request( $is_build_request ) ) {
			return;
		}

		$this->capture_url = $this->current_public_url();
		ob_start( array( $this, 'capture_html' ) );
	}

	public function capture_html( string $html ): string {
		if ( strlen( $html ) < 100 ) {
			return $html;
		}

		$status = function_exists( 'http_response_code' ) ? http_response_code() : 200;
		if ( $status >= 400 || false === stripos( $html, '<html' ) ) {
			return $html;
		}

		$html = $this->prepare_html_for_static_cache( $html );
		$this->cache->write( $this->capture_url, $html );

		return $html;
	}

	public function prepare_html_for_static_cache( string $html ): string {
		if ( false === stripos( $html, 'elementor-form' ) ) {
			return $html;
		}

		if ( false !== stripos( $html, 'id="avinash-static-elementor-form-bridge"' ) ) {
			return $html;
		}

		$script = $this->elementor_form_bridge_script();
		if ( false !== stripos( $html, '</body>' ) ) {
			return (string) preg_replace( '/<\/body>/i', $script . "\n</body>", $html, 1 );
		}

		return $html . $script;
	}

	public function send_elementor_nonce(): void {
		wp_send_json_success(
			array(
				'nonce'   => wp_create_nonce( 'elementor-pro-frontend' ),
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
			)
		);
	}

	public function handle_post_save( int $post_id, $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! $this->is_enabled() || ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		$permalink = get_permalink( $post_id );
		if ( $permalink ) {
			$this->cache->delete_url( $permalink );
			$this->schedule_post_regeneration( $post_id );
		}
	}

	public function handle_post_deleted( int $post_id ): void {
		$permalink = get_permalink( $post_id );
		if ( $permalink ) {
			$this->cache->delete_url( $permalink );
		}
	}

	public function handle_global_change(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$this->cache->clear();
		$this->schedule_full_regeneration();
	}

	public function regenerate_post_event( int $post_id ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$url = get_permalink( $post_id );
		if ( $url ) {
			$this->generator->generate_url( $url );
		}
	}

	public function add_admin_bar_items( WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! $this->is_enabled() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'avinash-static-clear-cache',
				'title' => __( 'Clear Static Cache', 'site-settings-by-avinash' ),
				'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=avinash_static_clear_cache' ), 'avinash_static_clear_cache' ),
				'meta'  => array(
					'title' => __( 'Clear generated static HTML files', 'site-settings-by-avinash' ),
				),
			)
		);
	}

	public function handle_admin_bar_clear_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to clear the static cache.', 'site-settings-by-avinash' ) );
		}

		check_admin_referer( 'avinash_static_clear_cache' );
		$this->cache->clear();

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url();
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	private function elementor_form_bridge_script(): string {
		$ajax_url = wp_json_encode( admin_url( 'admin-ajax.php' ) );

		return '<script id="avinash-static-elementor-form-bridge">(function(){var ajaxUrl=' . $ajax_url . ';function setNonce(nonce){window.elementorProFrontendConfig=window.elementorProFrontendConfig||{};window.elementorProFrontendConfig.nonce=nonce;}document.addEventListener("submit",function(event){var form=event.target;if(!form||!form.matches||!form.matches("form.elementor-form")){return;}if(form.getAttribute("data-avinash-static-nonce-ready")==="1"){form.removeAttribute("data-avinash-static-nonce-ready");return;}event.preventDefault();event.stopImmediatePropagation();var body=new FormData();body.append("action","avinash_static_elementor_nonce");fetch(ajaxUrl,{method:"POST",credentials:"same-origin",body:body}).then(function(response){return response.json();}).then(function(payload){if(payload&&payload.success&&payload.data&&payload.data.nonce){setNonce(payload.data.nonce);}form.setAttribute("data-avinash-static-nonce-ready","1");if(form.requestSubmit){form.requestSubmit();}else{form.dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}));}}).catch(function(){form.setAttribute("data-avinash-static-nonce-ready","1");if(form.requestSubmit){form.requestSubmit();}else{form.dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}));}});},true);}());</script>';
	}

	private function schedule_post_regeneration( int $post_id ): void {
		if ( ! wp_next_scheduled( 'avinash_static_regenerate_post_event', array( $post_id ) ) ) {
			wp_schedule_single_event( time() + 5, 'avinash_static_regenerate_post_event', array( $post_id ) );
		}
	}

	private function schedule_full_regeneration(): void {
		if ( ! wp_next_scheduled( 'avinash_static_regenerate_all_event' ) ) {
			wp_schedule_single_event( time() + 10, 'avinash_static_regenerate_all_event' );
		}
	}

	private function is_cacheable_request( bool $is_build_request ): bool {
		if ( is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
			return false;
		}

		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return false;
		}

		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || ! in_array( $_SERVER['REQUEST_METHOD'], array( 'GET', 'HEAD' ), true ) ) {
			return false;
		}

		if ( is_user_logged_in() || is_preview() || is_404() || is_search() || is_feed() || is_trackback() ) {
			return false;
		}

		$query = $_GET;
		unset( $query['avinash_static_build'], $query['pssc_build'] );
		if ( ! empty( $query ) ) {
			return false;
		}

		$is_cacheable_view = is_front_page() || is_home() || is_singular();
		$is_cacheable_view = (bool) apply_filters( 'pssc_is_cacheable_request', $is_cacheable_view, $is_build_request );

		return (bool) apply_filters( 'avinash_static_site_is_cacheable_request', $is_cacheable_view, $is_build_request );
	}

	private function is_valid_build_request(): bool {
		$build_token = '';

		if ( ! empty( $_GET['avinash_static_build'] ) ) {
			$build_token = sanitize_text_field( wp_unslash( $_GET['avinash_static_build'] ) );
		} elseif ( ! empty( $_GET['pssc_build'] ) ) {
			$build_token = sanitize_text_field( wp_unslash( $_GET['pssc_build'] ) );
		}

		if ( '' === $build_token ) {
			return false;
		}

		$token = get_option( self::BUILD_TOKEN_OPTION );

		return is_string( $token ) && hash_equals( $token, $build_token );
	}

	private function current_public_url(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );
		$path        = $path ? $path : '/';
		$home_path   = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path   = $home_path ? rtrim( $home_path, '/' ) : '';

		if ( $home_path && 0 === strpos( $path, $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) );
		} elseif ( $home_path && $path === $home_path ) {
			$path = '/';
		}

		return home_url( $path );
	}
}
