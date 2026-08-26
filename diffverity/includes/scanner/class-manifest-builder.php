<?php
/**
 * Local manifest creation.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Scanner;

use DiffVerity\Diff\Normalizer;
use DiffVerity\Filesystem\Path_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enumerates component files without following symbolic links.
 */
final class Manifest_Builder {
	/**
	 * Builds a SHA-256 local manifest.
	 *
	 * @param string $root Approved component root.
	 * @param array  $settings Effective settings.
	 * @param bool   $core_mode Limit unexpected core discovery to core directories.
	 * @return array<string,array<string,mixed>>
	 */
	public function build( $root, array $settings, $core_mode = false ) {
		$manifest  = array();
		$root_real = realpath( $root );
		if ( false === $root_real ) {
			return $manifest;
		}
		$roots = $core_mode ? array( $root_real . '/wp-admin', $root_real . '/wp-includes' ) : array( $root_real );
		foreach ( $roots as $scan_root ) {
			if ( ! is_dir( $scan_root ) ) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $scan_root, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY,
				\RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			foreach ( $iterator as $item ) {
				$path     = $item->getPathname();
				$relative = ltrim( substr( wp_normalize_path( $path ), strlen( rtrim( wp_normalize_path( $root_real ), '/' ) ) ), '/' );
				if ( $item->isLink() ) {
					$manifest[ $relative ] = array(
						'symlink'         => true,
						'hash'            => '',
						'size'            => 0,
						'normalized_hash' => '',
					);
					continue;
				}
				if ( ! $item->isFile() || ! Path_Guard::inside( $root_real, $path ) || $this->is_excluded( $relative, $settings ) ) {
					continue;
				}
				$hash                  = hash_file( 'sha256', $path );
				$md5                   = hash_file( 'md5', $path );
				$manifest[ $relative ] = array(
					'hash'            => false === $hash ? '' : $hash,
					'md5'             => false === $md5 ? '' : $md5,
					'size'            => (int) $item->getSize(),
					'mtime'           => (int) $item->getMTime(),
					'symlink'         => false,
					'normalized_hash' => Normalizer::normalized_file_hash( $path, (int) $settings['max_diff_bytes'] ),
				);
			}
		}
		ksort( $manifest );
		return $manifest;
	}

	/**
	 * Whether a relative file is excluded by controlled settings.
	 *
	 * @param string $relative Relative path.
	 * @param array  $settings Settings.
	 * @return bool
	 */
	private function is_excluded( $relative, array $settings ) {
		$extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
		if ( $extension && in_array( $extension, (array) $settings['excluded_extensions'], true ) ) {
			return true;
		}
		foreach ( (array) $settings['excluded_paths'] as $excluded ) {
			$excluded = Path_Guard::normalize_relative( $excluded );
			if ( $excluded && ( $relative === $excluded || 0 === strpos( $relative, trailingslashit( $excluded ) ) ) ) {
				return true;
			}
		}
		return false;
	}
}
