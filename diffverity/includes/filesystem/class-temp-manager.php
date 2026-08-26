<?php
/**
 * Temporary comparison package lifecycle.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Filesystem;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and removes only DiffVerity-owned temporary paths.
 */
final class Temp_Manager {
	const PREFIX = 'diffverity-';

	/**
	 * Creates a unique owned temporary directory.
	 *
	 * @return string|false
	 */
	public static function create_directory() {
		$base = trailingslashit( get_temp_dir() );
		$name = self::PREFIX . wp_generate_uuid4();
		$path = $base . $name;
		if ( ! wp_mkdir_p( $path ) ) {
			return false;
		}
		$marker = $path . '/.diffverity-temp';
		if ( false === file_put_contents( $marker, (string) time(), LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Owned temp marker.
			self::remove_directory( $path );
			return false;
		}
		return $path;
	}

	/**
	 * Removes an owned temporary tree without following symlinks.
	 *
	 * @param string $path Temporary directory.
	 * @return bool
	 */
	public static function remove_directory( $path ) {
		$base = realpath( get_temp_dir() );
		$real = realpath( $path );
		if ( false === $base || false === $real || ! Path_Guard::inside( $base, $real ) || 0 !== strpos( basename( $real ), self::PREFIX ) ) {
			return false;
		}
		if ( ! file_exists( $real . '/.diffverity-temp' ) ) {
			return false;
		}
		return self::delete_tree( $real );
	}

	/**
	 * Deletes stale owned temporary trees.
	 *
	 * @param int $age_seconds Minimum age. Zero removes every owned directory.
	 * @return void
	 */
	public static function cleanup_stale( $age_seconds = DAY_IN_SECONDS ) {
		$base = realpath( get_temp_dir() );
		if ( false === $base || ! is_readable( $base ) ) {
			return;
		}
		$entries = glob( trailingslashit( $base ) . self::PREFIX . '*', GLOB_ONLYDIR );
		if ( ! is_array( $entries ) ) {
			return;
		}
		foreach ( $entries as $entry ) {
			$marker = $entry . '/.diffverity-temp';
			if ( is_file( $marker ) && ( 0 === $age_seconds || ( time() - (int) filemtime( $marker ) ) >= $age_seconds ) ) {
				self::remove_directory( $entry );
			}
		}
	}

	/**
	 * Recursively removes an already validated owned tree.
	 *
	 * @param string $directory Directory path.
	 * @return bool
	 */
	private static function delete_tree( $directory ) {
		$iterator = new \FilesystemIterator( $directory, \FilesystemIterator::SKIP_DOTS );
		foreach ( $iterator as $item ) {
			$path = $item->getPathname();
			if ( $item->isLink() || $item->isFile() ) {
				if ( ! unlink( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Restricted to marked temp tree.
					return false;
				}
			} elseif ( $item->isDir() && ! self::delete_tree( $path ) ) {
				return false;
			}
		}
		return rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Restricted to marked temp tree.
	}
}
