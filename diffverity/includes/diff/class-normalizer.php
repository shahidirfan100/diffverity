<?php
/**
 * Harmless text normalization.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Diff;

use DiffVerity\Filesystem\File_Reader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes only UTF-8 BOM and line endings.
 */
final class Normalizer {
	/**
	 * Normalizes textual bytes.
	 *
	 * @param string $contents Source bytes.
	 * @return string
	 */
	public static function text( $contents ) {
		if ( 0 === strncmp( $contents, "\xEF\xBB\xBF", 3 ) ) {
			$contents = substr( $contents, 3 );
		}
		return str_replace( array( "\r\n", "\r" ), "\n", $contents );
	}

	/**
	 * Gets a normalized hash for a small UTF-8 text file.
	 *
	 * @param string $file Absolute path already constrained by its caller.
	 * @param int    $max_bytes Maximum bytes.
	 * @return string
	 */
	public static function normalized_file_hash( $file, $max_bytes ) {
		$size = filesize( $file );
		if ( false === $size || $size > $max_bytes ) {
			return '';
		}
		$data = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bounded read.
		if ( false === $data || ! File_Reader::is_text( $data ) ) {
			return '';
		}
		return hash( 'sha256', self::text( $data ) );
	}
}
