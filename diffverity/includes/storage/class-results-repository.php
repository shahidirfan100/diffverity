<?php
/**
 * Scan result persistence.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and retrieves normalized scan evidence.
 */
final class Results_Repository {
	/**
	 * Inserts a bounded result batch in one query.
	 *
	 * @param array<int,array<string,mixed>> $rows Result rows.
	 * @return bool
	 */
	public function insert_batch( array $rows ) {
		global $wpdb;
		if ( ! $rows ) {
			return true;
		}
		$table  = Database::table( 'results' );
		$values = array();
		$args   = array();
		foreach ( $rows as $row ) {
			$values[] = '(%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,%s,%s,%s)';
			$args[]   = (int) $row['scan_id'];
			$args[]   = $row['component_type'];
			$args[]   = $row['component_slug'];
			$args[]   = $row['component_name'];
			$args[]   = $row['component_version'];
			$args[]   = $row['relative_path'];
			$args[]   = $row['status'];
			$args[]   = $row['trust_source'];
			$args[]   = $row['source_version'];
			$args[]   = $row['local_hash'];
			$args[]   = $row['trusted_hash'];
			$args[]   = (int) $row['local_size'];
			$args[]   = (int) $row['trusted_size'];
			$args[]   = $row['severity'];
			$args[]   = $row['message'];
			$args[]   = current_time( 'mysql', true );
		}
		$sql = 'INSERT INTO %i (scan_id,component_type,component_slug,component_name,component_version,relative_path,status,trust_source,source_version,local_hash,trusted_hash,local_size,trusted_size,severity,message,checked_at) VALUES ' . implode( ',', $values );
		array_unshift( $args, $table );
		return false !== $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Owned table; identifier and all values are prepared.
	}

	/**
	 * Queries results with fixed filters.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $status Optional status.
	 * @param string $type Optional component type.
	 * @param int    $limit Page size.
	 * @param int    $offset Offset.
	 * @param string $component Optional component slug.
	 * @return array<int,array<string,mixed>>
	 */
	public function query( $scan_id, $status = '', $type = '', $limit = 100, $offset = 0, $component = '' ) {
		global $wpdb;
		$table = Database::table( 'results' );
		$where = array( 'scan_id = %d' );
		$args  = array( $scan_id );
		if ( $status ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		if ( $type ) {
			$where[] = 'component_type = %s';
			$args[]  = $type;
		}
		if ( $component ) {
			$where[] = 'component_slug = %s';
			$args[]  = $component;
		}
		$args[] = max( 1, min( 500, $limit ) );
		$args[] = max( 0, $offset );
		$sql    = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . " ORDER BY FIELD(status,'Modified','Missing','Unexpected','Baseline Changed','Baseline Missing','Baseline Added','Soft Difference','Source Unavailable','Skipped','Verified'), component_name, relative_path, id LIMIT %d OFFSET %d";
		array_unshift( $args, $table );
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Bounded report query on an owned table; identifier and values are prepared.
	}

	/**
	 * Iterates every matching result in bounded database pages.
	 *
	 * Completed scan results are immutable, so offset pagination is stable for
	 * history comparisons and exports without loading the full scan into memory.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $status Optional status.
	 * @param string $type Optional component type.
	 * @param string $component Optional component slug.
	 * @param int    $page_size Bounded database page size.
	 * @return \Generator<int,array<string,mixed>>
	 */
	public function iterate( $scan_id, $status = '', $type = '', $component = '', $page_size = 500 ) {
		$page_size = max( 1, min( 500, (int) $page_size ) );
		$offset    = 0;

		do {
			$rows      = $this->query( $scan_id, $status, $type, $page_size, $offset, $component );
			$row_count = count( $rows );
			foreach ( $rows as $row ) {
				yield $row;
			}
			$offset += $row_count;
		} while ( $row_count === $page_size );
	}

	/**
	 * Counts results using the same fixed filters as query().
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $status Optional status.
	 * @param string $type Optional component type.
	 * @param string $component Optional component slug.
	 * @return int
	 */
	public function count_filtered( $scan_id, $status = '', $type = '', $component = '' ) {
		global $wpdb;
		$table = Database::table( 'results' );
		$where = array( 'scan_id = %d' );
		$args  = array( $scan_id );
		if ( $status ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		if ( $type ) {
			$where[] = 'component_type = %s';
			$args[]  = $type;
		}
		if ( $component ) {
			$where[] = 'component_slug = %s';
			$args[]  = $component;
		}
		$sql = 'SELECT COUNT(*) FROM %i WHERE ' . implode( ' AND ', $where );
		array_unshift( $args, $table );
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Exact count on an owned table; identifier and values are prepared.
	}

	/**
	 * Returns the complete set of non-verified result identities for one scan.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array<string,bool>
	 */
	public function difference_identities( $scan_id ) {
		$set = array();
		foreach ( $this->iterate( $scan_id ) as $row ) {
			if ( in_array( $row['status'], array( 'Verified', 'Skipped' ), true ) ) {
				continue;
			}
			$key         = $row['component_type'] . '|' . $row['component_slug'] . '|' . $row['relative_path'] . '|' . $row['status'];
			$set[ $key ] = true;
		}
		return $set;
	}

	/**
	 * Returns one bounded summary row per component in a scan.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function component_summaries( $scan_id ) {
		global $wpdb;
		$table = Database::table( 'results' );
		$sql   = "SELECT component_type, component_slug, component_name, component_version, COUNT(*) AS total_files, SUM(CASE WHEN status IN ('Modified','Missing','Unexpected','Baseline Changed','Baseline Missing','Baseline Added','Soft Difference') THEN 1 ELSE 0 END) AS differences FROM %i WHERE scan_id = %d GROUP BY component_type, component_slug, component_name, component_version ORDER BY component_type, component_name";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed query on an owned table; the identifier and scan ID are prepared.
		return $wpdb->get_results( $wpdb->prepare( $sql, $table, $scan_id ), ARRAY_A );
	}

	/**
	 * Gets one result by owned primary key and scan.
	 *
	 * @param int $id Result ID.
	 * @param int $scan_id Scan ID.
	 * @return array<string,mixed>|false
	 */
	public function get( $id, $scan_id ) {
		global $wpdb;
		$table = Database::table( 'results' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND scan_id = %d', $table, $id, $scan_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Single owned result lookup; identifier and values are prepared.
		return is_array( $row ) ? $row : false;
	}

	/**
	 * Returns status counts for a scan.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array<string,int>
	 */
	public function counts( $scan_id ) {
		global $wpdb;
		$table  = Database::table( 'results' );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i WHERE scan_id = %d GROUP BY status', $table, $scan_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh scan counts are required before finalization.
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['total'];
		}
		return $counts;
	}
}
