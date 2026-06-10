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
		$token = get_option( Avinash_Static_Site_Module::BUILD_TOKEN_OPTION );
		$url   = remove_query_arg( array( 'avinash_static_build', 'pssc_build' ), $url );
		$build = add_query_arg( 'avinash_static_build', rawurlencode( (string) $token ), $url );

		$response = wp_remote_get(
			$build,
			array(
				'timeout'     => 30,
				'redirection' => 5,
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

		$body = Avinash_Static_Site_Module::instance()->prepare_html_for_static_cache( $body );

		return $this->cache->write( $url, $body );
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

		$urls = array_values( array_unique( array_map( 'untrailingslashit', $urls ) ) );
		$urls = apply_filters( 'pssc_static_urls', $urls );

		return apply_filters( 'avinash_static_site_urls', $urls );
	}
}
