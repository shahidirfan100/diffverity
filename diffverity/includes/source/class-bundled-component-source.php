<?php
/**
 * Official WordPress distribution checksums for bundled components.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Source;

use DiffVerity\Filesystem\Path_Guard;
use DiffVerity\Http\WordPress_Org_Client;
use DiffVerity\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accepts exact core-distribution copies of bundled plugins and themes.
 */
final class Bundled_Component_Source {
	/**
	 * Returns bundled MD5 evidence for paths in the exact WordPress distribution.
	 *
	 * @param array<string,mixed>               $component Resolved component.
	 * @param array<string,array<string,mixed>> $local Local manifest.
	 * @param bool                              $primary_header_match Whether the package header matches locally.
	 * @return array{versions:array<string,array<string,string>>,preferred_version:string}
	 */
	public function get_evidence( array $component, array $local, $primary_header_match = false ) {
		if ( ! in_array( $component['type'], array( 'plugin', 'theme' ), true ) ) {
			return array(
				'versions'          => array(),
				'preferred_version' => '',
			);
		}

		$core_version = get_bloginfo( 'version' );
		$locale       = get_locale();
		$prefix       = $this->component_prefix( $component );
		$header_file  = 'theme' === $component['type'] ? 'style.css' : $component['main_file'];
		$versions     = array();
		$current      = $this->version_hashes( $core_version, $locale, $prefix );
		if ( $current ) {
			$versions[ $core_version ] = $current;
		}

		$preferred = $this->header_matches( $current, $header_file, $local ) ? $core_version : '';
		if ( '' === $preferred && ! $primary_header_match ) {
			foreach ( array_slice( $this->candidate_versions( $core_version ), 1 ) as $candidate ) {
				$hashes = $this->version_hashes( $candidate, $locale, $prefix );
				if ( $this->header_matches( $hashes, $header_file, $local ) ) {
					$versions[ $candidate ] = $hashes;
					$preferred              = $candidate;
					break;
				}
			}
		}

		return array(
			'versions'          => $versions,
			'preferred_version' => $preferred,
		);
	}

	/**
	 * Returns component-relative hashes from one WordPress release.
	 *
	 * @param string $version WordPress version.
	 * @param string $locale WordPress locale.
	 * @param string $prefix Component package prefix.
	 * @return array<string,string>
	 */
	private function version_hashes( $version, $locale, $prefix ) {
		$url  = add_query_arg(
			array(
				'version' => $version,
				'locale'  => $locale,
			),
			'https://api.wordpress.org/core/checksums/1.0/'
		);
		$data = ( new WordPress_Org_Client() )->get_json( $url, 'core-bundle:' . $version . ':' . $locale );
		if ( is_wp_error( $data ) || empty( $data['checksums'] ) || ! is_array( $data['checksums'] ) ) {
			return array();
		}

		$hashes = array();
		foreach ( $data['checksums'] as $key => $checksum ) {
			if ( 0 !== strpos( $key, $prefix ) ) {
				continue;
			}
			$relative = Path_Guard::normalize_relative( substr( $key, strlen( $prefix ) ) );
			$hash     = strtolower( (string) $checksum );
			if ( '' !== $relative && 32 === strlen( $hash ) && ctype_xdigit( $hash ) ) {
				$hashes[ $relative ] = $hash;
			}
		}
		return $hashes;
	}

	/**
	 * Checks whether the installed component header matches one core release.
	 *
	 * @param array<string,string>              $hashes Component hashes.
	 * @param string                            $header_file Header path.
	 * @param array<string,array<string,mixed>> $local Local manifest.
	 * @return bool
	 */
	private function header_matches( array $hashes, $header_file, array $local ) {
		return ! empty( $hashes[ $header_file ] )
			&& ! empty( $local[ $header_file ]['md5'] )
			&& hash_equals( strtolower( (string) $hashes[ $header_file ] ), strtolower( (string) $local[ $header_file ]['md5'] ) );
	}

	/**
	 * Builds a bounded newest-to-oldest WordPress minor-version list.
	 *
	 * @param string $current Installed WordPress version.
	 * @return string[]
	 */
	private function candidate_versions( $current ) {
		if ( ! preg_match( '/^(\d+)\.(\d+)/', $current, $matches ) ) {
			return array( $current );
		}
		$major    = (int) $matches[1];
		$minor    = (int) $matches[2];
		$versions = array();
		for ( $attempt = 0; $attempt < 6; ++$attempt ) {
			$candidate = $major . '.' . $minor;
			if ( version_compare( $candidate, '6.2', '<' ) ) {
				break;
			}
			$versions[] = $candidate;
			--$minor;
			if ( $minor < 0 ) {
				--$major;
				$minor = 9;
			}
		}
		return $versions;
	}

	/**
	 * Returns one checksum-verified core-bundled source file with a short cache.
	 *
	 * @param array<string,mixed> $component Resolved component.
	 * @param string              $path Component-relative path.
	 * @param string              $source_version WordPress source version.
	 * @param string              $expected_hash Expected MD5 or SHA-256.
	 * @param int                 $max_bytes Maximum accepted bytes.
	 * @return string|\WP_Error
	 */
	public function get_source_file( array $component, $path, $source_version, $expected_hash, $max_bytes ) {
		$path           = Path_Guard::normalize_relative( $path );
		$source_version = trim( (string) $source_version );
		$expected_hash  = strtolower( trim( (string) $expected_hash ) );
		$hash_algorithm = 32 === strlen( $expected_hash ) ? 'md5' : ( 64 === strlen( $expected_hash ) ? 'sha256' : '' );
		$component_path = $this->component_prefix( $component ) . $path;
		$max_bytes      = max( 1, min( (int) Plugin::settings()['max_package_bytes'], (int) $max_bytes ) );
		if ( '' === $path || ! preg_match( '/^\d+\.\d+(?:\.\d+)?$/', $source_version ) || '' === $hash_algorithm || ! ctype_xdigit( $expected_hash ) ) {
			return new \WP_Error( 'diffverity_bundle_source_invalid', __( 'The stored core-bundle source evidence is invalid.', 'diffverity-file-integrity-source-diff' ) );
		}

		$cache_dir = trailingslashit( get_temp_dir() ) . 'diffverity-source-cache';
		if ( ! wp_mkdir_p( $cache_dir ) || false === realpath( $cache_dir ) ) {
			return new \WP_Error( 'diffverity_bundle_cache_unavailable', __( 'A protected source-file cache could not be prepared.', 'diffverity-file-integrity-source-diff' ) );
		}
		chmod( $cache_dir, 0700 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restricts the private temporary cache.
		self::cleanup_source_cache( 6 * HOUR_IN_SECONDS );

		$cache_file = trailingslashit( $cache_dir ) . hash( 'sha256', $source_version . '|' . $component_path . '|' . $expected_hash ) . '.source';
		if ( $this->cached_file_is_valid( $cache_file, $hash_algorithm, $expected_hash, $max_bytes ) ) {
			return $cache_file;
		}

		$staging = tempnam( $cache_dir, '.diffverity-' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_tempnam -- Same-directory staging prevents partial cache entries.
		if ( ! $staging ) {
			return new \WP_Error( 'diffverity_bundle_cache_stage', __( 'The official source file could not be staged.', 'diffverity-file-integrity-source-diff' ) );
		}
		$url      = 'https://core.svn.wordpress.org/tags/' . rawurlencode( $source_version ) . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $component_path ) ) );
		$download = ( new WordPress_Org_Client() )->download_source_file( $url, $staging, $max_bytes );
		if ( is_wp_error( $download ) || ! $this->file_hash_matches( $staging, $hash_algorithm, $expected_hash ) ) {
			if ( is_file( $staging ) ) {
				wp_delete_file( $staging );
			}
			return is_wp_error( $download ) ? $download : new \WP_Error( 'diffverity_bundle_source_hash', __( 'The retrieved official source file did not match its recorded WordPress checksum.', 'diffverity-file-integrity-source-diff' ) );
		}
		chmod( $staging, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restricts cached source bytes.
		if ( ! rename( $staging, $cache_file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic same-directory cache replacement.
			wp_delete_file( $staging );
			return new \WP_Error( 'diffverity_bundle_cache_commit', __( 'The verified official source file could not be cached.', 'diffverity-file-integrity-source-diff' ) );
		}
		return $cache_file;
	}

	/**
	 * Validates one cached source file.
	 *
	 * @param string $file Cache file.
	 * @param string $algorithm Hash algorithm.
	 * @param string $expected Expected checksum.
	 * @param int    $max_bytes Size limit.
	 * @return bool
	 */
	private function cached_file_is_valid( $file, $algorithm, $expected, $max_bytes ) {
		$size = is_file( $file ) && ! is_link( $file ) ? filesize( $file ) : false;
		$time = is_file( $file ) && ! is_link( $file ) ? filemtime( $file ) : false;
		return false !== $size && $size > 0 && $size <= $max_bytes && false !== $time && $time >= time() - 6 * HOUR_IN_SECONDS && $this->file_hash_matches( $file, $algorithm, $expected );
	}

	/**
	 * Compares a local cache file with an expected checksum.
	 *
	 * @param string $file File path.
	 * @param string $algorithm Hash algorithm.
	 * @param string $expected Expected checksum.
	 * @return bool
	 */
	private function file_hash_matches( $file, $algorithm, $expected ) {
		$actual = is_file( $file ) ? hash_file( $algorithm, $file ) : false;
		return is_string( $actual ) && hash_equals( $expected, strtolower( $actual ) );
	}

	/**
	 * Removes stale private source-cache files.
	 *
	 * @param int $max_age Maximum cache age in seconds.
	 * @return void
	 */
	public static function cleanup_source_cache( $max_age = 21600 ) {
		$cache_dir = trailingslashit( get_temp_dir() ) . 'diffverity-source-cache';
		if ( ! is_dir( $cache_dir ) ) {
			return;
		}
		$iterator = new \DirectoryIterator( $cache_dir );
		foreach ( $iterator as $item ) {
			if ( $item->isFile() && ! $item->isLink() && $item->getMTime() <= time() - max( 0, (int) $max_age ) ) {
				wp_delete_file( $item->getPathname() );
			}
		}
	}

	/**
	 * Maps a component root to its path inside the WordPress release ZIP.
	 *
	 * @param array<string,mixed> $component Resolved component.
	 * @return string
	 */
	private function component_prefix( array $component ) {
		if ( 'theme' === $component['type'] ) {
			return 'wp-content/themes/' . basename( $component['root'] ) . '/';
		}
		if ( ! empty( $component['entry'] ) ) {
			return 'wp-content/plugins/';
		}
		return 'wp-content/plugins/' . basename( $component['root'] ) . '/';
	}
}
