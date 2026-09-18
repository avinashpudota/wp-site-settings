<?php
/** Run with php tests/regression.php. WordPress APIs are isolated test doubles. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
$options = array();
$caps = array( 'manage_options' => true, 'edit_plugins' => true, 'unfiltered_html' => true );
$hooks = array();
$screen = (object) array( 'base' => 'dashboard' );
$context = array();
$nonce_valid = true;
$checks = 0;
$mail_calls = 0;
$http_calls = 0;

class TestStop extends RuntimeException {}
class WP_Post { public $post_status = 'publish'; public $post_password = ''; }
class WP_Error {
	public $code;
	public function __construct( $code, $message ) { $this->code = $code; }
}
class TestDB {
	public $posts = 'wp_posts';
	public $last_error = '';
	public $count = 0;
	public $queries = 0;
	public function get_col( $sql ) { ++$this->queries; return $this->count ? range( 1, min( 11, $this->count ) ) : array(); }
}
$wpdb = new TestDB();
$queried_post = new WP_Post();
function add_action( $name, $callback, ...$args ) { $GLOBALS['hooks'][$name][] = $callback; }
function add_filter( $name, $callback, ...$args ) { add_action( $name, $callback ); }
function remove_action( ...$args ) {}
function register_activation_hook( ...$args ) {}
function register_deactivation_hook( ...$args ) {}
function plugin_basename( $file ) { return basename( $file ); }
function get_option( $name, $default = false ) { return $GLOBALS['options'][$name] ?? $default; }
function update_option( $name, $value, ...$args ) { $GLOBALS['options'][$name] = $value; return true; }
function add_option( $name, $value, ...$args ) { $GLOBALS['options'][$name] = $value; }
function set_transient( $name, $value, ...$args ) { $GLOBALS['transients'][$name] = $value; }
function delete_transient( $name ) { unset( $GLOBALS['transients'][$name] ); }
function get_site_transient( $name ) { return $GLOBALS['release_data'] ?? array(); }
function current_user_can( $cap ) { return ! empty( $GLOBALS['caps'][$cap] ); }
function is_network_admin() { return ! empty( $GLOBALS['context']['network'] ); }
function is_user_admin() { return ! empty( $GLOBALS['context']['user_admin'] ); }
function is_multisite() { return ! empty( $GLOBALS['context']['multisite'] ); }
function is_admin() { return ! empty( $GLOBALS['context']['admin'] ); }
function wp_doing_ajax() { return ! empty( $GLOBALS['context']['ajax'] ); }
function wp_is_json_request() { return ! empty( $GLOBALS['context']['json'] ); }
function is_user_logged_in() { return ! empty( $GLOBALS['context']['logged_in'] ); }
function is_preview() { return ! empty( $GLOBALS['context']['preview'] ); }
function is_404() { return false; }
function is_search() { return false; }
function is_feed() { return false; }
function is_trackback() { return false; }
function is_front_page() { return false; }
function is_home() { return false; }
function is_singular() { return true; }
function is_cart() { return ! empty( $GLOBALS['context']['cart'] ); }
function is_checkout() { return ! empty( $GLOBALS['context']['checkout'] ); }
function is_account_page() { return ! empty( $GLOBALS['context']['account'] ); }
function get_queried_object() { return $GLOBALS['queried_post']; }
function get_post( $id ) { return $GLOBALS['queried_post']; }
function get_permalink( $id ) { return 'https://example.org/page/'; }
function get_posts( $args ) { return array( 42 ); }
function wp_is_post_revision( $id ) { return false; }
function wp_is_post_autosave( $id ) { return false; }
function wp_next_scheduled( ...$args ) { return false; }
function wp_schedule_single_event( ...$args ) { $GLOBALS['scheduled'][] = $args; }
function apply_filters( $name, $value, ...$args ) { return $value; }
function get_current_screen() { return $GLOBALS['screen']; }
function home_url( $path = '' ) { return 'https://example.org' . $path; }
function admin_url( $path = '' ) { return 'https://example.org/wp-admin/' . $path; }
function wp_upload_dir() { return array( 'basedir' => __DIR__ . '/tmp', 'baseurl' => 'https://example.org/uploads' ); }
function trailingslashit( $path ) { return rtrim( $path, '/' ) . '/'; }
function wp_generate_password( ...$args ) { return 'build-token'; }
function wp_parse_args( $value, $defaults ) { return array_merge( $defaults, $value ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function get_bloginfo( $key ) { return 'Example Site'; }
function wp_specialchars_decode( $text, $flags ) { return htmlspecialchars_decode( $text, $flags ); }
function __( $text, ...$args ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $text ) { return esc_html( $text ); }
function esc_html__( $text, ...$args ) { return esc_html( $text ); }
function esc_html_e( $text, ...$args ) { echo esc_html( $text ); }
function wp_nonce_field( $action ) { echo '<input name="_wpnonce" value="test-nonce">'; }
function check_admin_referer( ...$args ) { if ( ! $GLOBALS['nonce_valid'] ) { throw new TestStop( 'nonce', 403 ); } }
function wp_die( $text, $title = '', $args = array() ) { throw new TestStop( $text, $args['response'] ?? 500 ); }
function wp_get_referer() { return admin_url( 'plugins.php' ); }
function wp_safe_redirect( $url ) { throw new TestStop( $url, 302 ); }
function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return is_scalar( $value ) ? trim( strip_tags( $value ) ) : ''; }
function sanitize_email( $value ) { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_mail( ...$args ) { ++$GLOBALS['mail_calls']; return true; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function remove_query_arg( $keys, $url ) { return $url; }
function add_query_arg( $key, $value, $url ) { return $url . '?' . $key . '=' . $value; }
function wp_safe_remote_get( $url, $args ) { ++$GLOBALS['http_calls']; $GLOBALS['last_http'] = array( $url, $args ); return array(); }
function wp_remote_retrieve_response_code( $response ) { return 200; }
function wp_remote_retrieve_body( $response ) { return '<html>' . str_repeat( 'content ', 30 ) . '</html>'; }

require dirname( __DIR__ ) . '/site-settings-by-avinash.php';
function check( $condition, $message ) {
	++$GLOBALS['checks'];
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); }
}
function invoke( $object, $method, ...$args ) {
	$reflection = new ReflectionMethod( $object, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $args );
}
function stopped( callable $callback, $code ) {
	try { $callback(); } catch ( TestStop $stop ) { check( $stop->getCode() === $code, 'Expected HTTP ' . $code ); return; }
	check( false, 'Expected request to stop' );
}
function rendered( $checklist ) { ob_start(); $checklist->render(); return ob_get_clean(); }

$plugin = Avinash_Site_Settings::instance();
$key = 'avinash_site_settings_options';
check( ! $plugin->is_smtp_ready(), 'Fresh installation is inactive' );
$options[$key] = array( 'smtp_password' => 'secret' );
check( $plugin->is_smtp_ready(), 'Complete configuration is active' );
$good = invoke( $plugin, 'get_options' );
foreach ( array( 'smtp_host', 'smtp_username', 'smtp_password', 'smtp_from_email', 'smtp_from_name' ) as $field ) {
	$options[$key] = $good;
	$options[$key][$field] = '   ';
	check( ! $plugin->is_smtp_ready(), 'Missing ' . $field );
}
foreach ( array( 0, -1, 65536, 'abc', 25.5 ) as $port ) {
	$options[$key] = array_merge( $good, array( 'smtp_port' => $port ) );
	check( ! $plugin->is_smtp_ready(), 'Invalid port' );
}
foreach ( array( 1, 25, 465, 587, 65535 ) as $port ) {
	$options[$key] = array_merge( $good, array( 'smtp_port' => $port ) );
	check( $plugin->is_smtp_ready(), 'Valid port' );
}
$options[$key] = array_merge( $good, array( 'smtp_enabled' => false ) );
check( ! $plugin->is_smtp_ready(), 'Disabled SMTP inactive' );
$_POST = array( 'avinash_test_email' => 'test@example.org' );
invoke( $plugin, 'send_test_email' );
check( 0 === $mail_calls, 'Disabled SMTP must not test through fallback mail' );
$options[$key] = array_merge( $good, array( 'smtp_username' => 'apikey' ) );
check( $plugin->is_smtp_ready(), 'Provider usernames need not be email addresses' );
invoke( $plugin, 'send_test_email' );
check( 1 === $mail_calls, 'Ready SMTP sends test' );
$_POST = array( 'avinash_site_settings' => array( 'smtp_enabled' => 1, 'smtp_password' => '' ) );
invoke( $plugin, 'save_settings', 'smtp' );
check( 'secret' === $options[$key]['smtp_password'], 'Blank password preserves secret' );
$_POST['avinash_site_settings']['smtp_clear_password'] = 1;
invoke( $plugin, 'save_settings', 'smtp' );
check( '' === $options[$key]['smtp_password'] && ! $plugin->is_smtp_ready(), 'Explicit removal clears password' );
$options[$key] = $good;

$checklist = new Avinash_Live_Checklist( array( $plugin, 'is_smtp_ready' ) );
foreach ( array( 'dashboard', 'plugins', 'edit', 'users', 'toplevel_page_avinash-site-settings' ) as $base ) {
	$screen->base = $base;
	check( $checklist->should_show(), 'Visible on ' . $base );
}
foreach ( array( 'post', 'site-editor', 'widgets', 'customize', 'term', 'user-edit', 'theme-editor', 'plugin-editor' ) as $base ) {
	$screen->base = $base;
	check( ! $checklist->should_show(), 'Hidden on editor ' . $base );
}
$screen->base = 'dashboard';
foreach ( array( 0, 10, 11, 1000 ) as $count ) {
	$wpdb->count = $count;
	$html = rendered( $checklist );
	check( false !== strpos( $html, $count <= 10 ? '>Optimized<' : '>Not Optimized<' ), 'Revision boundary ' . $count );
	check( false !== strpos( $html, '>Active<' ), 'SMTP status rendered' );
	check( false === strpos( $html, 'secret' ), 'Password never rendered' );
}
$wpdb->last_error = 'query failed';
check( false !== strpos( rendered( $checklist ), '>Not Optimized<' ), 'Database error cannot show success' );
$wpdb->last_error = '';
$caps['manage_options'] = false;
$queries = $wpdb->queries;
check( '' === rendered( $checklist ) && $queries === $wpdb->queries, 'Unauthorized users get no notice or DB query' );
stopped( function () use ( $checklist ) { $checklist->dismiss(); }, 403 );
$caps['manage_options'] = true;
$_SERVER['REQUEST_METHOD'] = 'GET';
stopped( function () use ( $checklist ) { $checklist->dismiss(); }, 405 );
$_SERVER['REQUEST_METHOD'] = 'POST';
$nonce_valid = false;
stopped( function () use ( $checklist ) { $checklist->dismiss(); }, 403 );
check( ! get_option( Avinash_Live_Checklist::DISMISSED_OPTION ), 'Rejected dismissal never persists' );
$nonce_valid = true;
stopped( function () use ( $checklist ) { $checklist->dismiss(); }, 302 );
check( ! $checklist->should_show(), 'Dismissal hides notice' );
unset( $options[$key] ); // Same option deletion as Reset Defaults.
$another_request = new Avinash_Live_Checklist( array( $plugin, 'is_smtp_ready' ) );
check( ! $another_request->should_show(), 'Dismissal persists for another administrator/request after reset' );
unset( $options[Avinash_Live_Checklist::DISMISSED_OPTION] );
$context['network'] = true;
check( ! $checklist->should_show(), 'No misleading network-wide status' );
$context = array();

foreach ( array( 'functions' => 'edit_plugins', 'scripts' => 'unfiltered_html' ) as $tab => $cap ) {
	$caps[$cap] = false;
	$before = $options;
	stopped( function () use ( $plugin, $tab ) { invoke( $plugin, 'save_settings', $tab ); }, 403 );
	check( $before === $options, 'Rejected code save makes no changes' );
	$caps[$cap] = true;
}
$context['multisite'] = true;
stopped( function () use ( $plugin ) { invoke( $plugin, 'optimize_database_table' ); }, 403 );
check( array() === invoke( $plugin, 'get_database_tables' ), 'Site admins cannot enumerate shared database tables' );
$context = array();
check( empty( $hooks['auto_update_plugin'] ), 'Automatic updates respect WordPress setting' );
$update_state = (object) array( 'checked' => array( 'site-settings-by-avinash.php' => '1.2.0' ) );
$update_state = $plugin->check_for_plugin_update( $update_state );
check( isset( $update_state->no_update['site-settings-by-avinash.php'] ), 'Auto-update toggle available without a newer release' );
$release_data = array( 'version' => '1.3.0', 'package' => 'https://example.org/release.zip', 'details_url' => 'https://example.org', 'tested' => '', 'published_at' => '', 'name' => 'Test release' );
$update_state = $plugin->check_for_plugin_update( $update_state );
check( '1.3.0' === $update_state->response['site-settings-by-avinash.php']->new_version, 'Newer release offered' );
check( ! isset( $update_state->no_update['site-settings-by-avinash.php'] ), 'New release clears no-update entry' );

class MemoryCache extends Avinash_Static_Site_Cache {
	public $clears = 0;
	public $writes = 0;
	public function clear(): void { ++$this->clears; }
	public function delete_url( string $url ): void {}
	public function write( string $url, string $html ) { ++$this->writes; return 'cached'; }
	public function path_for_url( string $url ): string { return __DIR__ . '/nonexistent-cache.html'; }
}
$module = Avinash_Static_Site_Module::instance();
$cache = new MemoryCache();
$module->cache = $cache;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = $_COOKIE = array();
check( invoke( $module, 'is_cacheable_request', false ), 'Anonymous public post cacheable' );
foreach ( array( 'wp-postpass_hash', 'comment_author_hash', 'wp_woocommerce_session_test', 'custom_session' ) as $cookie ) {
	$_COOKIE = array( $cookie => 'value' );
	check( ! invoke( $module, 'is_cacheable_request', false ), 'Cookie request excluded: ' . $cookie );
}
$_COOKIE = array();
$queried_post->post_password = 'protected';
check( ! invoke( $module, 'is_cacheable_request', true ), 'Build request cannot cache password-protected post' );
$queried_post->post_password = '';
$queried_post->post_status = 'private';
check( ! invoke( $module, 'is_cacheable_request', true ), 'Build request cannot cache private post' );
$module->handle_post_save( 1, $queried_post, true );
check( 1 === $cache->clears, 'Private transition clears stale public cache' );
$queried_post->post_status = 'publish';
$module->handle_post_deleted( 1 );
check( 2 === $cache->clears && isset( $hooks['before_delete_post'] ), 'Deletion invalidates before permalink disappears' );
foreach ( array( 'admin', 'ajax', 'json', 'logged_in', 'preview', 'cart', 'checkout', 'account' ) as $flag ) {
	$context[$flag] = true;
	check( ! invoke( $module, 'is_cacheable_request', false ), 'Private context excluded: ' . $flag );
	$context[$flag] = false;
}
$_GET = array( 'avinash_static_build' => 'invalid' );
check( ! invoke( $module, 'is_cacheable_request', false ), 'Invalid build token cannot bypass query exclusion' );
$_GET = array();
$html = '<html>' . str_repeat( 'public ', 30 ) . '</html>';
http_response_code( 302 );
$module->capture_html( $html );
check( 0 === $cache->writes, 'Redirect response not cached' );
http_response_code( 200 );
$module->capture_html( $html );
check( 1 === $cache->writes, 'Public HTML cached' );
$generator = new Avinash_Static_Site_Generator( $cache );
check( in_array( 'https://example.org/page/', $generator->get_urls(), true ), 'Keep canonical permalink trailing slash' );
check( in_array( 'https://example.org/', $generator->get_urls(), true ), 'Keep canonical homepage trailing slash' );
check( is_wp_error( $generator->generate_url( 'https://evil.example/page/' ) ) && 0 === $http_calls, 'External build URL blocked before HTTP' );
check( is_wp_error( $generator->generate_url( 'https://example.org/page/' ) ), 'Generator must not cache a response rejected by capture' );
check( 0 === $last_http[1]['redirection'], 'Build token never follows redirect' );
check( 1 === $cache->writes, 'Generator never bypasses capture validation' );
$rules = ( new Avinash_Static_Site_Rewrites() )->rules();
check( 2 === count( array_keys( $rules, 'RewriteCond %{HTTP_COOKIE} ^$', true ) ), 'Both static rewrite routes bypass cookies' );
define( 'DONOTCACHEPAGE', true );
check( ! invoke( $module, 'is_cacheable_request', true ), 'Explicit cache opt-out respected' );
$module->capture_html( $html );
check( 1 === $cache->writes, 'Late cache opt-out respected' );
define( 'AVINASH_SITE_SETTINGS_DISABLE_CUSTOM_FUNCTIONS', true );
$options[$key] = array( 'custom_functions' => array( array( 'enabled' => true, 'code' => 'throw new RuntimeException("must not execute");', 'title' => 'Bad code' ) ) );
$plugin->load_custom_functions();
check( empty( $GLOBALS['transients']['avinash_site_settings_php_error'] ), 'Recovery switch prevents code execution' );
echo "PASS: {$checks} regression checks\n";
