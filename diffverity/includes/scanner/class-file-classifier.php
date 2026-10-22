<?php
/**
 * Normalized file status classification.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts manifest evidence into conservative statuses.
 */
final class File_Classifier {
	/**
	 * Classifies one unioned path.
	 *
	 * @param array|null $local Local metadata.
	 * @param array|null $trusted Trusted metadata.
	 * @param string     $trust Trust label.
	 * @param string     $algorithm Trusted hash algorithm.
	 * @param bool       $strict Strict comparison.
	 * @param bool       $version_changed Baseline version mismatch.
	 * @return array<string,mixed>
	 */
	public function classify( $local, $trusted, $trust, $algorithm, $strict, $version_changed ) {
		if ( is_array( $local ) && ! empty( $local['symlink'] ) ) {
			return $this->result( 'Skipped', 'review', __( 'Symbolic link skipped; DiffVerity does not follow links during scans.', 'diffverity' ) );
		}
		$baseline = 'Local Baseline' === $trust;
		if ( null === $local ) {
			return $this->result( $baseline ? 'Baseline Missing' : 'Missing', 'important', __( 'The trusted manifest contains this file, but the installed file is missing.', 'diffverity' ) );
		}
		if ( null === $trusted ) {
			return $this->result( $baseline ? 'Baseline Added' : 'Unexpected', 'important', __( 'This file is present locally but is not part of the comparison manifest. That difference does not prove malicious intent.', 'diffverity' ) );
		}
		if ( $version_changed && $baseline ) {
			return $this->result( 'Baseline Changed', 'review', __( 'The component version changed after this baseline. Review the update before creating a replacement baseline.', 'diffverity' ) );
		}
		$file_algorithm = isset( $trusted['algorithm'] ) ? $trusted['algorithm'] : $algorithm;
		$local_hash     = 'md5' === $file_algorithm && isset( $local['md5'] ) ? $local['md5'] : $local['hash'];
		$trusted_hashes = ! empty( $trusted['hashes'] ) && is_array( $trusted['hashes'] ) ? $trusted['hashes'] : array( $trusted['hash'] );
		foreach ( $trusted_hashes as $trusted_hash ) {
			if ( is_scalar( $trusted_hash ) && hash_equals( strtolower( (string) $trusted_hash ), strtolower( (string) $local_hash ) ) ) {
				return $this->result(
					'Verified',
					'informational',
					$baseline ? __( 'The installed file matches the local baseline hash.', 'diffverity' ) : __( 'The installed file matches an accepted authoritative source checksum.', 'diffverity' ),
					array(
						'local_hash'   => $local_hash,
						'trusted_hash' => strtolower( (string) $trusted_hash ),
					)
				);
			}
		}
		if ( ! $baseline && ! empty( $trusted['package_hash'] ) && hash_equals( strtolower( (string) $trusted['package_hash'] ), strtolower( (string) $local['hash'] ) ) ) {
			return $this->result(
				'Verified',
				'informational',
				__( 'The installed file exactly matches the file in the official exact-version WordPress.org package.', 'diffverity' ),
				array(
					'trust_source' => 'Official Source Available',
					'local_hash'   => $local['hash'],
					'trusted_hash' => strtolower( (string) $trusted['package_hash'] ),
					'trusted_size' => isset( $trusted['package_size'] ) ? (int) $trusted['package_size'] : 0,
				)
			);
		}
		$bundled_hashes = ! empty( $trusted['bundled_hashes'] ) && is_array( $trusted['bundled_hashes'] ) ? $trusted['bundled_hashes'] : array();
		if ( ! empty( $trusted['bundled_hash'] ) ) {
			$bundled_hashes[ $trusted['bundled_hash'] ] = isset( $trusted['bundled_version'] ) ? $trusted['bundled_version'] : '';
		}
		$matched_bundle_hash = ! empty( $local['md5'] ) && isset( $bundled_hashes[ strtolower( (string) $local['md5'] ) ] ) ? strtolower( (string) $local['md5'] ) : '';
		if ( ! $baseline && '' !== $matched_bundle_hash ) {
			return $this->result(
				'Verified',
				'informational',
				__( 'The installed file matches an official WordPress core-bundled copy. Core updates can preserve an earlier official wp-content variant whose bytes differ from the separately published component package.', 'diffverity' ),
				array(
					'trust_source'   => 'Official Core Bundle',
					'source_version' => (string) $bundled_hashes[ $matched_bundle_hash ],
					'local_hash'     => $local['md5'],
					'trusted_hash'   => $matched_bundle_hash,
				)
			);
		}
		if ( ! $strict && ! empty( $local['normalized_hash'] ) && ! empty( $trusted['normalized_hash'] ) && hash_equals( $trusted['normalized_hash'], $local['normalized_hash'] ) ) {
			$evidence = array();
			if ( ! empty( $trusted['package_hash'] ) ) {
				$evidence = array(
					'trust_source' => 'Official Source Available',
					'trusted_hash' => strtolower( (string) $trusted['package_hash'] ),
					'trusted_size' => isset( $trusted['package_size'] ) ? (int) $trusted['package_size'] : 0,
				);
			}
			return $this->result( 'Soft Difference', 'review', __( 'Raw bytes differ, but normalized text is equivalent. The difference is limited to a UTF-8 BOM or line endings.', 'diffverity' ), $evidence );
		}
		if ( ! $baseline && ! empty( $trusted['preferred_bundle_hash'] ) && ! empty( $trusted['preferred_bundle_version'] ) ) {
			return $this->result(
				'Modified',
				'important',
				__( 'The installed file differs from the official core-bundled copy identified for this component. View the diff to inspect the remaining local change.', 'diffverity' ),
				array(
					'trust_source'   => 'Official Core Bundle',
					'source_version' => $trusted['preferred_bundle_version'],
					'trusted_hash'   => $trusted['preferred_bundle_hash'],
				)
			);
		}
		return $this->result( $baseline ? 'Baseline Changed' : 'Modified', 'important', __( 'The installed file exists in the comparison source, but its content differs. Legitimate customization, deployment tooling, an incomplete update, unintended modification, or compromise can cause this; DiffVerity cannot determine intent from the difference alone.', 'diffverity' ) );
	}

	/**
	 * Builds the status portion.
	 *
	 * @param string $status Status.
	 * @param string $severity Severity.
	 * @param string $message Explanation.
	 * @param array  $evidence Optional evidence overrides.
	 * @return array<string,string>
	 */
	private function result( $status, $severity, $message, array $evidence = array() ) {
		return array_merge(
			array(
				'status'   => $status,
				'severity' => $severity,
				'message'  => $message,
			),
			$evidence
		);
	}
}
