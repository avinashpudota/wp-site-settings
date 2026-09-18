<?php
/**
 * Static HTML generator for Site Settings.
 *
 * @package Site_Settings_By_Avinash
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avinash_Static_Site_Generator {
	/** @var Avinash_Static_Site_Cache */
	private $cache;

	public function __construct( Avinash_Static_Site_Cache $cache ) {
		$this->cache = $cache;
	}

	public function regenerate_all(): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		$this->cache->clear();

		$results = array(
			'total'   => 0,
			'success' => 0,
			'failed'  => 0,
			'errors'  => array(),
		);

		foreach ( $this->get_urls() as $url ) {
			++$results['total'];
			$result = $this->generate_url( $url );

			if ( is_wp_error( $result ) ) {
				++$results['failed'];
				$results['errors'][] = $url . ': ' . $result->get_error_message();
			} else {
				++$results['success'];
			}
		}

		return $results;
	}

	public function generate_url( string $url ) {
		$target = wp_parse_url( $url );
		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $target ) || ! is_array( $home ) || isset( $target['user'] ) || isset( $target['pass'] )
			|| ( $target['scheme'] ?? '' ) !== ( $home['scheme'] ?? '' )
			|| ( $target['host'] ?? '' ) !== ( $home['host'] ?? '' )
			|| ( $target['port'] ?? null ) !== ( $home['port'] ?? null ) ) {
			return new WP_Error( 'avinash_static_invalid_origin', __( 'Only URLs on this site can be generated.', 'site-settings-by-avinash' ) );
		}
		$token = get_option( Avinash_Static_Site_Module::BUILD_TOKEN_OPTION );
		$url   = remove_query_arg( array( 'avinash_static_build', 'pssc_build' ), $url );
		$build = add_query_arg( 'avinash_static_build', rawurlencode( (string) $token ), $url );

		$this->cache->delete_url( $url );
		$response = wp_safe_remote_get(
			$build,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array(
					'X-Avinash-Static-Build' => '1',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $status ) {
			return new WP_Error(
				'avinash_static_http_error',
				sprintf(
					/* translators: %d: HTTP response status code. */
					__( 'HTTP %d', 'site-settings-by-avinash' ),
					(int) $status
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( false === stripos( $body, '<html' ) ) {
			return new WP_Error( 'avinash_static_not_html', __( 'Response did not look like HTML.', 'site-settings-by-avinash' ) );
		}

		// The loopback capture enforces privacy/cookie/cache-control checks. Never
		// write the response here: doing so would bypass those checks.
		$file = $this->cache->path_for_url( $url );
		if ( ! is_file( $file ) ) {
			return new WP_Error( 'avinash_static_not_cacheable', __( 'Page was not cacheable or the cache could not be written.', 'site-settings-by-avinash' ) );
		}
		return $file;
	}

	public function get_urls(): array {
		$urls = array( home_url( '/' ) );

		$post_types = apply_filters( 'pssc_generated_post_types', array( 'page', 'post' ) );
		$post_types = apply_filters( 'avinash_static_site_generated_post_types', $post_types );
		$post_ids   = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'has_password'   => false,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		foreach ( $post_ids as $post_id ) {
			$url = get_permalink( $post_id );
			if ( $url ) {
				$urls[] = $url;
			}
		}

		// Keep canonical trailing slashes: builds intentionally do not follow redirects.
		$urls = array_values( array_unique( $urls ) );
		$urls = apply_filters( 'pssc_static_urls', $urls );

		return apply_filters( 'avinash_static_site_urls', $urls );
	}
}
