<?php
/**
 * LiteSpeed/Apache rewrite rules for the static site module.
 *
 * @package Site_Settings_By_Avinash
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avinash_Static_Site_Rewrites {
	const MARKER = 'Personal Static Site Cache';

	public function install() {
		$file = $this->htaccess_file();
		if ( ! $file ) {
			return new WP_Error( 'avinash_static_no_htaccess', __( 'Could not locate .htaccess.', 'site-settings-by-avinash' ) );
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$result = insert_with_markers( $file, self::MARKER, $this->rules() );

		return $result ? true : new WP_Error( 'avinash_static_rewrite_failed', __( 'Could not write static cache rules to .htaccess.', 'site-settings-by-avinash' ) );
	}

	public function remove() {
		$file = $this->htaccess_file();
		if ( ! $file || ! file_exists( $file ) ) {
			return true;
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		insert_with_markers( $file, self::MARKER, array() );

		return true;
	}

	public function rules(): array {
		$cache_path = $this->cache_url_path();

		return array(
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$ [NC]',
			'RewriteCond %{QUERY_STRING} ^$',
			'RewriteCond %{HTTP_COOKIE} !(wordpress_logged_in_|comment_author_|wp-postpass_) [NC]',
			'RewriteCond %{REQUEST_URI} !^/wp-admin/ [NC]',
			'RewriteCond %{REQUEST_URI} !^/wp-json/? [NC]',
			'RewriteCond %{DOCUMENT_ROOT}' . $cache_path . '/index.html -f',
			'RewriteRule ^$ ' . $cache_path . '/index.html [L]',
			'RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$ [NC]',
			'RewriteCond %{QUERY_STRING} ^$',
			'RewriteCond %{HTTP_COOKIE} !(wordpress_logged_in_|comment_author_|wp-postpass_) [NC]',
			'RewriteCond %{REQUEST_URI} !^/wp-admin/ [NC]',
			'RewriteCond %{REQUEST_URI} !^/wp-json/? [NC]',
			'RewriteCond %{DOCUMENT_ROOT}' . $cache_path . '/$1/index.html -f',
			'RewriteRule ^(.+?)/?$ ' . $cache_path . '/$1/index.html [L]',
			'</IfModule>',
		);
	}

	private function htaccess_file(): string {
		return defined( 'ABSPATH' ) ? trailingslashit( ABSPATH ) . '.htaccess' : '';
	}

	private function cache_url_path(): string {
		$uploads = wp_upload_dir();
		$path    = wp_parse_url( trailingslashit( $uploads['baseurl'] ) . 'pssc-cache', PHP_URL_PATH );

		return '/' . trim( (string) $path, '/' );
	}
}
