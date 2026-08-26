<?php
/**
 * Bounded safe file inspection.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Filesystem;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads only server-resolved files within an approved root.
 */
final class File_Reader {
	/**
	 * Returns streaming metadata for a file.
	 *
	 * @param string $root Root directory.
	 * @param string $relative Relative path.
	 * @param string $algorithm Hash algorithm.
	 * @return array<string,mixed>|false
	 */
	public static function metadata( $root, $relative, $algorithm = 'sha256' ) {
		$file = Path_Guard::resolve_existing( $root, $relative );
		if ( false === $file || ! is_readable( $file ) || ! in_array( $algorithm, hash_algos(), true ) ) {
			return false;
		}
		$hash = hash_file( $algorithm, $file );
		$size = filesize( $file );
		return array(
			'path'    => $relative,
			'hash'    => false === $hash ? '' : $hash,
			'size'    => false === $size ? 0 : (int) $size,
			'mtime'   => (int) filemtime( $file ),
			'symlink' => false,
		);
	}

	/**
	 * Reads a file up to a strict byte limit.
	 *
	 * @param string $root Root directory.
	 * @param string $relative Relative path.
	 * @param int    $max_bytes Maximum permitted size.
	 * @return string|false
	 */
	public static function contents( $root, $relative, $max_bytes ) {
		$file = Path_Guard::resolve_existing( $root, $relative );
		if ( false === $file || ! is_readable( $file ) ) {
			return false;
		}
		$size = filesize( $file );
		if ( false === $size || $size > $max_bytes ) {
			return false;
		}
		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded read-only stream.
		if ( false === $handle ) {
			return false;
		}
		$data = stream_get_contents( $handle, $max_bytes + 1 );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes bounded read-only stream.
		return is_string( $data ) && strlen( $data ) <= $max_bytes ? $data : false;
	}

	/**
	 * Detects text conservatively without trusting the extension alone.
	 *
	 * @param string $contents Bounded bytes.
	 * @return bool
	 */
	public static function is_text( $contents ) {
		if ( false !== strpos( substr( $contents, 0, 8192 ), "\0" ) ) {
			return false;
		}
		return 1 === preg_match( '//u', $contents );
	}
}
