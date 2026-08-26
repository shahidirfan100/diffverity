<?php
/**
 * Safe integrity report exports.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Export;

use DiffVerity\Storage\History_Repository;
use DiffVerity\Storage\Results_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams reports without source code or absolute filesystem paths.
 */
final class Report_Exporter {
	/**
	 * Sends a selected report and ends the request.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $format json, csv, or txt.
	 * @return void
	 */
	public function output( $scan_id, $format ) {
		$scan = ( new History_Repository() )->get( $scan_id );
		if ( false === $scan ) {
			wp_die( esc_html__( 'Scan not found.', 'diffverity' ), 404 );
		}
		$allowed = array( 'json', 'csv', 'txt' );
		if ( ! in_array( $format, $allowed, true ) ) {
			wp_die( esc_html__( 'Unsupported export format.', 'diffverity' ), 400 );
		}
		$rows     = ( new Results_Repository() )->query( $scan_id, '', '', 50000, 0 );
		$filename = 'diffverity-scan-' . $scan_id . '.' . $format;
		nocache_headers();
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		$columns = array( 'component_type', 'component_slug', 'component_name', 'component_version', 'relative_path', 'status', 'trust_source', 'source_version', 'local_hash', 'trusted_hash', 'local_size', 'trusted_size', 'severity', 'message', 'checked_at' );
		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode(
				array(
					'scan'    => $this->public_scan( $scan ),
					'results' => $this->select_columns( $rows, $columns ),
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download with fixed structure.
			exit;
		}
		if ( 'csv' === $format ) {
			header( 'Content-Type: text/csv; charset=utf-8' );
			$output = fopen( 'php://output', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming a download response.
			fputcsv( $output, $columns );
			foreach ( $this->select_columns( $rows, $columns ) as $row ) {
				fputcsv( $output, array_values( $row ) );
			}
			fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the streamed response.
			exit;
		}
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'DiffVerity scan #' . absint( $scan_id ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text download.
		foreach ( $rows as $row ) {
			$source_version = ! empty( $row['source_version'] ) ? ' | WordPress ' . $row['source_version'] : '';
			echo sanitize_text_field( $row['component_type'] . ':' . $row['component_slug'] . ' | ' . $row['relative_path'] . ' | ' . $row['status'] . ' | ' . $row['trust_source'] . $source_version ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized plain text download.
		}
		exit;
	}

	/**
	 * Removes internal fields from a scan summary.
	 *
	 * @param array $scan Stored scan row.
	 * @return array<string,mixed>
	 */
	private function public_scan( array $scan ) {
		return array_intersect_key( $scan, array_flip( array( 'id', 'status', 'started_at', 'completed_at', 'wp_version', 'php_version', 'total_components', 'processed_components', 'counts', 'duration_ms' ) ) );
	}

	/**
	 * Selects report-safe fields.
	 *
	 * @param array $rows Stored result rows.
	 * @param array $columns Allowed columns.
	 * @return array<int,array<string,mixed>>
	 */
	private function select_columns( array $rows, array $columns ) {
		return array_map(
			static function ( $row ) use ( $columns ) {
				return array_intersect_key( $row, array_flip( $columns ) );
			},
			$rows
		);
	}
}
