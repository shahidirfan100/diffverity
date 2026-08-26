<?php
/**
 * Official plugin checksum and package source.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses WordPress.org plugin checksums for the exact installed version.
 */
final class Plugin_Source extends Package_Source {
	/**
	 * Gets exact-version WordPress.org plugin checksums.
	 *
	 * @param array $component Resolved plugin component.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_manifest( array $component ) {
		$url  = 'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode( $component['slug'] ) . '/' . rawurlencode( $component['version'] ) . '.json';
		$data = $this->client->get_json( $url, 'plugin:' . $component['slug'] . ':' . $component['version'] );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['files'] ) || ! is_array( $data['files'] ) ) {
			return new \WP_Error( 'diffverity_plugin_checksums_malformed', __( 'The plugin checksum response does not contain a file manifest.', 'diffverity' ) );
		}
		$files     = array();
		$algorithm = 'md5';
		foreach ( $data['files'] as $path => $hashes ) {
			if ( ! is_array( $hashes ) ) {
				continue;
			}
			$sha256 = isset( $hashes['sha256'] ) ? $this->checksum_list( $hashes['sha256'], 64 ) : array();
			$md5    = isset( $hashes['md5'] ) ? $this->checksum_list( $hashes['md5'], 32 ) : array();
			if ( $sha256 ) {
				$accepted  = $sha256;
				$file_algo = 'sha256';
				$algorithm = 'sha256';
			} elseif ( $md5 ) {
				$accepted  = $md5;
				$file_algo = 'md5';
			} else {
				continue;
			}
			$files[ str_replace( '\\', '/', ltrim( $path, '/' ) ) ] = array(
				'hash'      => $accepted[0],
				'hashes'    => $accepted,
				'algorithm' => $file_algo,
				'size'      => 0,
			);
		}
		return array(
			'files'       => $files,
			'algorithm'   => $algorithm,
			'source_root' => '',
			'temp_root'   => '',
			'trust'       => $this->get_trust_level(),
		);
	}

	/**
	 * Normalizes a scalar or array checksum field from WordPress.org.
	 *
	 * WordPress.org can publish multiple accepted hashes for one plugin file.
	 * Each hash represents an official package variant and must be retained.
	 *
	 * @param mixed $value Checksum value or values.
	 * @param int   $length Expected hexadecimal length.
	 * @return array<int,string>
	 */
	private function checksum_list( $value, $length ) {
		$values = is_array( $value ) ? $value : array( $value );
		$valid  = array();
		foreach ( $values as $checksum ) {
			if ( ! is_scalar( $checksum ) ) {
				continue;
			}
			$checksum = strtolower( trim( (string) $checksum ) );
			if ( $length === strlen( $checksum ) && ctype_xdigit( $checksum ) ) {
				$valid[] = $checksum;
			}
		}
		return array_values( array_unique( $valid ) );
	}

	/** {@inheritDoc} */
	public function get_trust_level() {
		return 'Officially Verified';
	}

	/**
	 * Builds the exact plugin package URL.
	 *
	 * @param array $component Resolved plugin component.
	 * @return string
	 */
	protected function package_url( array $component ) {
		return 'https://downloads.wordpress.org/plugins/' . rawurlencode( $component['slug'] ) . '.' . rawurlencode( $component['version'] ) . '.zip';
	}
}
