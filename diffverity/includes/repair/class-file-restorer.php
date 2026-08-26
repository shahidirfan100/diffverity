<?php
/**
 * Exact-version official source restoration.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Repair;

use DiffVerity\Filesystem\Path_Guard;
use DiffVerity\Filesystem\Temp_Manager;
use DiffVerity\Plugin;
use DiffVerity\Scanner\Component_Registry;
use DiffVerity\Source\Bundled_Component_Source;
use DiffVerity\Source\Core_Source;
use DiffVerity\Source\Plugin_Source;
use DiffVerity\Source\Theme_Source;
use DiffVerity\Storage\History_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restores one scan-confirmed file from an exact official package.
 */
final class File_Restorer {
	/**
	 * Restores one eligible result without changing scan history.
	 *
	 * @param array<string,mixed> $result Stored scan result.
	 * @return array<string,string>|\WP_Error
	 */
	public function restore( array $result ) {
		if ( ! in_array( $result['status'], array( 'Modified', 'Missing', 'Soft Difference' ), true ) ) {
			return new \WP_Error( 'diffverity_restore_status', __( 'Only modified, missing, or soft-difference files can be restored.', 'diffverity' ) );
		}
		if ( ! in_array( $result['trust_source'], array( 'Officially Verified', 'Official Source Available', 'Official Core Bundle' ), true ) ) {
			return new \WP_Error( 'diffverity_restore_trust', __( 'This file has no eligible official WordPress.org source.', 'diffverity' ) );
		}

		$path = Path_Guard::normalize_relative( $result['relative_path'] );
		if ( '' === $path ) {
			return new \WP_Error( 'diffverity_restore_path', __( 'The stored file path is invalid.', 'diffverity' ) );
		}

		$component = ( new Component_Registry() )->resolve( $result['component_type'], $result['component_slug'] );
		if ( false === $component ) {
			return new \WP_Error( 'diffverity_restore_component', __( 'The installed component could not be resolved safely.', 'diffverity' ) );
		}
		if ( (string) $component['version'] !== (string) $result['component_version'] ) {
			return new \WP_Error( 'diffverity_restore_stale', __( 'The installed component version changed after this scan. Run a new scan before restoring.', 'diffverity' ) );
		}
		if ( 'Official Core Bundle' === $result['trust_source'] ) {
			if ( empty( $result['source_version'] ) ) {
				return new \WP_Error( 'diffverity_restore_bundle_version_missing', __( 'This older scan did not record the originating WordPress bundle version. Run a new scan before restoring this file.', 'diffverity' ) );
			}
			$scan = ( new History_Repository() )->get( (int) $result['scan_id'] );
			if ( false === $scan || (string) get_bloginfo( 'version' ) !== (string) $scan['wp_version'] ) {
				return new \WP_Error( 'diffverity_restore_core_stale', __( 'The WordPress version changed after this scan. Run a new scan before restoring a core-bundled file.', 'diffverity' ) );
			}
		}
		if ( 'plugin' === $component['type'] && 'diffverity' === $component['slug'] ) {
			return new \WP_Error( 'diffverity_restore_self', __( 'DiffVerity will not overwrite its own running files.', 'diffverity' ) );
		}

		$materialized = null;
		if ( 'Official Core Bundle' === $result['trust_source'] ) {
			$source = ( new Bundled_Component_Source() )->get_source_file( $component, $path, $result['source_version'], $result['trusted_hash'], (int) Plugin::settings()['max_package_bytes'] );
			if ( is_wp_error( $source ) ) {
				return $source;
			}
		} else {
			$materialized = $this->provider( $component['type'] )->materialize( $component );
			if ( is_wp_error( $materialized ) ) {
				return $materialized;
			}
			$source = Path_Guard::resolve_existing( $materialized['source_root'], $path );
		}

		try {
			if ( false === $source ) {
				return new \WP_Error( 'diffverity_restore_source_missing', __( 'The exact official package does not contain this file.', 'diffverity' ) );
			}
			$verification_hash = $result['trusted_hash'];
			if ( ! $this->source_matches_result( $source, $verification_hash ) ) {
				$verification_hash = 'plugin' === $component['type'] ? $this->accepted_plugin_package_hash( $component, $path, $source ) : '';
				if ( '' === $verification_hash ) {
					return new \WP_Error( 'diffverity_restore_hash', __( 'The downloaded source file does not match any official checksum accepted for this exact component version.', 'diffverity' ) );
				}
			}

			$target = Path_Guard::resolve_write_target( $component['root'], $path );
			if ( false === $target ) {
				return new \WP_Error( 'diffverity_restore_target', __( 'The destination path is unsafe or cannot be prepared.', 'diffverity' ) );
			}
			if ( file_exists( $target ) && ! is_writable( $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Validated local restore target.
				return new \WP_Error( 'diffverity_restore_not_writable', __( 'The installed file is not writable.', 'diffverity' ) );
			}
			if ( ! is_writable( dirname( $target ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Validated contained destination directory.
				return new \WP_Error( 'diffverity_restore_directory_not_writable', __( 'The destination directory is not writable.', 'diffverity' ) );
			}

			$default_mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
			$permissions  = file_exists( $target ) ? fileperms( $target ) : false;
			$mode         = false !== $permissions ? ( $permissions & 0777 ) : $default_mode;
			$temp         = tempnam( dirname( $target ), '.diffverity-' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_tempnam -- Same-directory staging is required for atomic replacement.
			if ( ! $temp || realpath( dirname( $temp ) ) !== realpath( dirname( $target ) ) || false === copy( $source, $temp ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Same-directory atomic replacement staging.
				if ( $temp && file_exists( $temp ) ) {
					wp_delete_file( $temp );
				}
				return new \WP_Error( 'diffverity_restore_stage', __( 'The official file could not be staged for restoration.', 'diffverity' ) );
			}
			chmod( $temp, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Preserves the existing destination mode.
			if ( ! $this->source_matches_result( $temp, $verification_hash ) ) {
				wp_delete_file( $temp );
				return new \WP_Error( 'diffverity_restore_stage_verify', __( 'The staged file failed checksum verification.', 'diffverity' ) );
			}
			if ( ! rename( $temp, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic same-directory replacement.
				wp_delete_file( $temp );
				return new \WP_Error( 'diffverity_restore_replace', __( 'The staged official file could not replace the installed file.', 'diffverity' ) );
			}
			if ( ! $this->source_matches_result( $target, $verification_hash ) ) {
				return new \WP_Error( 'diffverity_restore_verify', __( 'The restored file failed the final checksum verification.', 'diffverity' ) );
			}
			if ( function_exists( 'opcache_invalidate' ) ) {
				opcache_invalidate( $target, true );
			}
			return array(
				'path'    => $path,
				'status'  => 'restored',
				'message' => __( 'The file was restored and verified. Run a new scan to refresh the historical result.', 'diffverity' ),
			);
		} finally {
			if ( is_array( $materialized ) && ! empty( $materialized['temp_root'] ) ) {
				Temp_Manager::remove_directory( $materialized['temp_root'] );
			}
		}
	}

	/**
	 * Matches a downloaded plugin-package file to every checksum WordPress.org
	 * accepts for the exact plugin version. This also repairs older scan rows that
	 * recorded only the first hash from a multi-checksum response.
	 *
	 * @param array  $component Resolved plugin component.
	 * @param string $path Relative path.
	 * @param string $source Downloaded source file.
	 * @return string Matching checksum, or an empty string.
	 */
	private function accepted_plugin_package_hash( array $component, $path, $source ) {
		$manifest = ( new Plugin_Source() )->get_manifest( $component );
		if ( is_wp_error( $manifest ) || empty( $manifest['files'][ $path ] ) ) {
			return '';
		}
		$trusted  = $manifest['files'][ $path ];
		$hashes   = ! empty( $trusted['hashes'] ) && is_array( $trusted['hashes'] ) ? $trusted['hashes'] : array( $trusted['hash'] );
		$algorithm = isset( $trusted['algorithm'] ) ? $trusted['algorithm'] : $manifest['algorithm'];
		$actual    = hash_file( $algorithm, $source );
		if ( ! is_string( $actual ) ) {
			return '';
		}
		foreach ( $hashes as $hash ) {
			$hash = is_scalar( $hash ) ? strtolower( trim( (string) $hash ) ) : '';
			if ( '' !== $hash && hash_equals( $hash, strtolower( $actual ) ) ) {
				return $hash;
			}
		}
		return '';
	}

	/**
	 * Returns the exact package provider for one component type.
	 *
	 * @param string $type Component type.
	 * @return Core_Source|Plugin_Source|Theme_Source
	 */
	private function provider( $type ) {
		if ( 'core' === $type ) {
			return new Core_Source();
		}
		if ( 'theme' === $type ) {
			return new Theme_Source();
		}
		return new Plugin_Source();
	}

	/**
	 * Verifies a file against a stored MD5 or SHA-256 checksum.
	 *
	 * @param string $file File path.
	 * @param string $expected Expected checksum.
	 * @return bool
	 */
	private function source_matches_result( $file, $expected ) {
		$expected  = strtolower( trim( (string) $expected ) );
		$algorithm = 32 === strlen( $expected ) ? 'md5' : ( 64 === strlen( $expected ) ? 'sha256' : '' );
		if ( '' === $algorithm || ! ctype_xdigit( $expected ) ) {
			return false;
		}
		$actual = hash_file( $algorithm, $file );
		return is_string( $actual ) && hash_equals( $expected, strtolower( $actual ) );
	}
}
