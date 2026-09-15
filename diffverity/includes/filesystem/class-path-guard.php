<?php
/**
 * Filesystem path validation.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Filesystem;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prevents traversal and symlink escapes.
 */
final class Path_Guard {
	/**
	 * Normalizes a package-relative path or returns an empty string.
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	public static function normalize_relative( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$path = ltrim( $path, '/' );
		if ( '' === $path || false !== strpos( $path, "\0" ) || preg_match( '#(^|/)\.\.(/|$)#', $path ) ) {
			return '';
		}
		$parts = array();
		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				return '';
			}
			$parts[] = $part;
		}
		return implode( '/', $parts );
	}

	/**
	 * Resolves an existing file and proves it remains inside the root.
	 *
	 * @param string $root Root directory.
	 * @param string $relative Relative path.
	 * @return string|false
	 */
	public static function resolve_existing( $root, $relative ) {
		$relative  = self::normalize_relative( $relative );
		$root_real = realpath( $root );
		if ( '' === $relative || false === $root_real ) {
			return false;
		}
		$candidate = $root_real . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
		if ( is_link( $candidate ) ) {
			return false;
		}
		$real = realpath( $candidate );
		if ( false === $real || ! is_file( $real ) || ! self::inside( $root_real, $real ) ) {
			return false;
		}
		return $real;
	}

	/**
	 * Checks containment using path-segment boundaries.
	 *
	 * @param string $root Root path.
	 * @param string $path Candidate path.
	 * @return bool
	 */
	public static function inside( $root, $path ) {
		$root = rtrim( wp_normalize_path( $root ), '/' ) . '/';
		$path = wp_normalize_path( $path );
		return 0 === strpos( $path . ( is_dir( $path ) ? '/' : '' ), $root );
	}
}
