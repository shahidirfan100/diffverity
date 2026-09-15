<?php
/**
 * Official WordPress core source.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses native core checksum infrastructure for the exact version and locale.
 */
final class Core_Source extends Package_Source {
	/**
	 * Gets exact-version, exact-locale core checksums.
	 *
	 * @param array $component Resolved core component.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_manifest( array $component ) {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		$checksums = get_core_checksums( $component['version'], $component['locale'] );
		if ( ! is_array( $checksums ) || ! $checksums ) {
			return new \WP_Error( 'diffverity_core_checksums_unavailable', __( 'Official core checksums are unavailable for this exact version and locale.', 'diffverity-file-integrity-source-diff' ) );
		}
		$files = array();
		foreach ( $checksums as $path => $hash ) {
			$path = str_replace( '\\', '/', ltrim( $path, '/' ) );
			if ( 0 === strpos( $path, 'wp-content/' ) || 'wp-config-sample.php' === $path ) {
				continue;
			}
			$files[ $path ] = array(
				'hash' => (string) $hash,
				'size' => 0,
			);
		}
		return array(
			'files'       => $files,
			'algorithm'   => 'md5',
			'source_root' => '',
			'temp_root'   => '',
			'trust'       => $this->get_trust_level(),
		);
	}

	/**
	 * Gets the official checksum trust label.
	 *
	 * @return string
	 */
	public function get_trust_level() {
		return 'Officially Verified';
	}

	/**
	 * Builds the exact core release package URL.
	 *
	 * @param array $component Resolved core component.
	 * @return string
	 */
	protected function package_url( array $component ) {
		$locale = 'en_US' === $component['locale'] ? '' : '-' . rawurlencode( $component['locale'] );
		return 'https://downloads.wordpress.org/release/wordpress-' . rawurlencode( $component['version'] ) . $locale . '.zip';
	}

	/**
	 * Locates the WordPress directory in the extracted archive.
	 *
	 * @param string $extract Extraction directory.
	 * @param array  $component Resolved core component.
	 * @return string|false
	 */
	protected function find_package_root( $extract, array $component ) {
		$root = realpath( trailingslashit( $extract ) . 'wordpress' ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Exact archive directory name.
		return false !== $root ? $root : false;
	}
}
