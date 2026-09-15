<?php
/**
 * Scan history persistence and pruning.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Storage;

use DiffVerity\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps bounded scan summaries separate from file results.
 */
final class History_Repository {
	/**
	 * Starts a scan history row.
	 *
	 * @param int $total_components Total components.
	 * @return int
	 */
	public function create( $total_components ) {
		global $wpdb, $wp_version;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Writes one owned scan-history row.
			Database::table( 'scans' ),
			array(
				'status'               => 'running',
				'started_at'           => current_time( 'mysql', true ),
				'wp_version'           => $wp_version,
				'php_version'          => PHP_VERSION,
				'total_components'     => $total_components,
				'processed_components' => 0,
				'counts'               => wp_json_encode( array() ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates scan progress.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param int    $processed Processed component count.
	 * @param string $current Current component name.
	 * @return void
	 */
	public function progress( $scan_id, $processed, $current ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh progress must be persisted for resumable scans.
			Database::table( 'scans' ),
			array(
				'processed_components' => $processed,
				'current_component'    => $current,
			),
			array( 'id' => $scan_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Completes or cancels a scan.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $status Final status.
	 * @param float  $started_microtime Start timestamp.
	 * @return void
	 */
	public function finish( $scan_id, $status, $started_microtime ) {
		global $wpdb;
		$counts = ( new Results_Repository() )->counts( $scan_id );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Final scan state must be persisted immediately.
			Database::table( 'scans' ),
			array(
				'status'       => $status,
				'completed_at' => current_time( 'mysql', true ),
				'counts'       => wp_json_encode( $counts ),
				'duration_ms'  => max( 0, (int) round( ( microtime( true ) - (float) $started_microtime ) * 1000 ) ),
			),
			array( 'id' => $scan_id ),
			array( '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);
		$this->prune();
	}

	/**
	 * Gets recent scans.
	 *
	 * @param int $limit Maximum summaries.
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( $limit = 20 ) {
		global $wpdb;
		$table = Database::table( 'scans' );
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, max( 1, min( 100, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded administrator-requested history query.
	}

	/**
	 * Gets one scan.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array<string,mixed>|false
	 */
	public function get( $scan_id ) {
		global $wpdb;
		$table = Database::table( 'scans' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $scan_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Single owned scan lookup.
		return is_array( $row ) ? $row : false;
	}

	/**
	 * Deletes one scan and its owned results.
	 *
	 * @param int $scan_id Scan ID.
	 * @return bool
	 */
	public function delete( $scan_id ) {
		global $wpdb;
		$wpdb->delete( Database::table( 'results' ), array( 'scan_id' => $scan_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes only results linked to an administrator-selected scan.
		return false !== $wpdb->delete( Database::table( 'scans' ), array( 'id' => $scan_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes only the selected owned scan row.
	}

	/** Prunes history and linked results. */
	private function prune() {
		global $wpdb;
		$retention = max( 1, min( 100, (int) Plugin::settings()['history_retention'] ) );
		$table     = Database::table( 'scans' );
		do {
			$ids      = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 500 OFFSET %d', $table, $retention ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded retention query on an owned table.
			$id_count = count( $ids );
			foreach ( array_map( 'intval', $ids ) as $id ) {
				$this->delete( $id );
			}
		} while ( 500 === $id_count );
	}
}
