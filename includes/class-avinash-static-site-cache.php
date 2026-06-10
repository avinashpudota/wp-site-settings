<?php
/**
 * Static HTML cache storage for Site Settings.
 *
 * @package Site_Settings_By_Avinash
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Avinash_Static_Site_Cache {
	private $root = '';
	private $base_url = '';

	public function __construct() {
		$uploads        = wp_upload_dir();
		$this->root     = trailingslashit( $uploads['basedir'] ) . 'pssc-cache';
		$this->base_url = trailingslashit( $uploads['baseurl'] ) . 'pssc-cache';
	}

	public function root(): string {
		return $this->root;
	}

	public function base_url(): string {
		return $this->base_url;
	}

	public function ensure_cache_dir(): void {
		if ( ! is_dir( $this->root ) ) {
			wp_mkdir_p( $this->root );
		}

		$index = trailingslashit( $this->root ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	public function write( string $url, string $html ) {
		$this->ensure_cache_dir();

		$file = $this->path_for_url( $url );
		$dir  = dirname( $file );

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$tmp = $file . '.tmp';
		file_put_contents( $tmp, $html, LOCK_EX );
		rename( $tmp, $file );

		$this->remember_file( $url, $file );

		return $file;
	}

	public function delete_url( string $url ): void {
		$file = $this->path_for_url( $url );
		if ( file_exists( $file ) ) {
			unlink( $file );
		}

		$index = $this->get_index();
		$key   = sha1( $this->normalize_url( $url ) );
		unset( $index[ $key ] );
		update_option( Avinash_Static_Site_Module::CACHE_INDEX_OPTION, $index, false );
	}

	public function clear(): void {
		$this->ensure_cache_dir();

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( 'index.php' === $item->getFilename() ) {
				continue;
			}

			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}

		update_option( Avinash_Static_Site_Module::CACHE_INDEX_OPTION, array(), false );
	}

	public function list_files(): array {
		$this->ensure_cache_dir();

		$known   = $this->get_index();
		$by_path = array();

		foreach ( $known as $entry ) {
			if ( ! empty( $entry['path'] ) ) {
				$by_path[ wp_normalize_path( $entry['path'] ) ] = $entry;
			}
		}

		$files = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() || 'html' !== strtolower( $item->getExtension() ) ) {
				continue;
			}

			$path       = $item->getPathname();
			$normalized = wp_normalize_path( $path );
			$entry      = isset( $by_path[ $normalized ] ) ? $by_path[ $normalized ] : array();

			$files[] = array(
				'url'          => ! empty( $entry['url'] ) ? $entry['url'] : $this->url_for_file( $path ),
				'path'         => $path,
				'relative'     => $this->relative_path( $path ),
				'generated_at' => filemtime( $path ),
				'size'         => filesize( $path ),
			);
		}

		usort(
			$files,
			static function ( array $a, array $b ): int {
				return (int) $b['generated_at'] - (int) $a['generated_at'];
			}
		);

		return $files;
	}

	public function path_for_url( string $url ): string {
		$path      = wp_parse_url( $url, PHP_URL_PATH );
		$path      = $path ? $path : '/';
		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = $home_path ? rtrim( $home_path, '/' ) : '';

		if ( $home_path && 0 === strpos( $path, $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) );
		} elseif ( $home_path && $path === $home_path ) {
			$path = '/';
		}

		$path     = trim( $path, '/' );
		$segments = array();

		if ( '' !== $path ) {
			foreach ( explode( '/', $path ) as $segment ) {
				$segment = sanitize_file_name( rawurldecode( $segment ) );
				if ( '' !== $segment && '.' !== $segment && '..' !== $segment ) {
					$segments[] = $segment;
				}
			}
		}

		if ( empty( $segments ) ) {
			return trailingslashit( $this->root ) . 'index.html';
		}

		return trailingslashit( $this->root ) . implode( '/', $segments ) . '/index.html';
	}

	private function remember_file( string $url, string $file ): void {
		$index = $this->get_index();
		$key   = sha1( $this->normalize_url( $url ) );

		$index[ $key ] = array(
			'url'          => $this->normalize_url( $url ),
			'path'         => $file,
			'generated_at' => time(),
			'size'         => file_exists( $file ) ? filesize( $file ) : 0,
		);

		update_option( Avinash_Static_Site_Module::CACHE_INDEX_OPTION, $index, false );
	}

	private function get_index(): array {
		$index = get_option( Avinash_Static_Site_Module::CACHE_INDEX_OPTION, null );

		if ( null === $index ) {
			$legacy = get_option( Avinash_Static_Site_Module::LEGACY_CACHE_INDEX_OPTION, array() );
			$index  = is_array( $legacy ) ? $legacy : array();
			update_option( Avinash_Static_Site_Module::CACHE_INDEX_OPTION, $index, false );
		}

		return is_array( $index ) ? $index : array();
	}

	private function normalize_url( string $url ): string {
		$parts = wp_parse_url( $url );
		$path  = empty( $parts['path'] ) ? '/' : $parts['path'];

		return home_url( $path );
	}

	private function relative_path( string $path ): string {
		return ltrim( str_replace( wp_normalize_path( $this->root ), '', wp_normalize_path( $path ) ), '/' );
	}

	private function url_for_file( string $file ): string {
		$relative = $this->relative_path( $file );
		$relative = preg_replace( '#/index\.html$#', '/', $relative );

		if ( 'index.html' === $relative ) {
			$relative = '';
		}

		return home_url( '/' . ltrim( (string) $relative, '/' ) );
	}
}
