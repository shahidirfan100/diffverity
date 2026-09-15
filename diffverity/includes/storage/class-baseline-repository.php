<?php
/**
 * Local baseline persistence.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Storage;

use DiffVerity\Diff\Normalizer;
use DiffVerity\Plugin;
use DiffVerity\Scanner\Manifest_Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores bounded hash-only local baselines.
 */
final class Baseline_Repository {
	/**
	 * Creates a deliberate administrator-requested baseline.
	 *
	 * @param array $component Resolved component.
	 * @return int|\WP_Error Baseline ID.
	 */
	public function create( array $component ) {
		global $wpdb;
		if ( ! in_array( $component['type'], array( 'plugin', 'theme' ), true ) ) {
			return new \WP_Error( 'diffverity_invalid_baseline_type', __( 'Only plugins and themes can have local baselines.', 'diffverity-file-integrity-source-diff' ) );
		}
		$manifest = ( new Manifest_Builder() )->build( $component['root'], Plugin::settings(), false );
		if ( ! $manifest ) {
			return new \WP_Error( 'diffverity_empty_baseline', __( 'No readable files were found for this component.', 'diffverity-file-integrity-source-diff' ) );
		}
		$table = Database::table( 'baselines' );
		$files = Database::table( 'baseline_files' );
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic owned-table write.
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Demotes the prior owned baseline inside the transaction.
			$table,
			array( 'is_current' => 0 ),
			array(
				'component_type' => $component['type'],
				'component_slug' => $component['slug'],
				'is_current'     => 1,
			),
			array( '%d' ),
			array( '%s', '%s', '%d' )
		);
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Inserts the new owned baseline summary.
			$table,
			array(
				'component_type'    => $component['type'],
				'component_slug'    => $component['slug'],
				'component_name'    => $component['name'],
				'component_version' => $component['version'],
				'created_at'        => current_time( 'mysql', true ),
				'is_current'        => 1,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d' )
		);
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic owned-table write.
			return new \WP_Error( 'diffverity_baseline_insert', __( 'The local baseline could not be stored.', 'diffverity-file-integrity-source-diff' ) );
		}
		$baseline_id = (int) $wpdb->insert_id;
		foreach ( $manifest as $path => $metadata ) {
			if ( ! empty( $metadata['symlink'] ) ) {
				continue;
			}
			$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Inserts bounded hash-only baseline rows.
				$files,
				array(
					'baseline_id'       => $baseline_id,
					'relative_path'     => $path,
					'path_key'          => hash( 'sha256', $path ),
					'sha256'            => $metadata['hash'],
					'normalized_sha256' => isset( $metadata['normalized_hash'] ) ? $metadata['normalized_hash'] : '',
					'file_size'         => $metadata['size'],
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d' )
			);
			if ( false === $ok ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic owned-table write.
				return new \WP_Error( 'diffverity_baseline_file_insert', __( 'A baseline file hash could not be stored.', 'diffverity-file-integrity-source-diff' ) );
			}
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic owned-table write.
		$this->prune( $component['type'], $component['slug'] );
		return $baseline_id;
	}

	/**
	 * Gets the current baseline plus file manifest.
	 *
	 * @param string $type Component type.
	 * @param string $slug Component slug.
	 * @return array<string,mixed>|false
	 */
	public function current( $type, $slug ) {
		global $wpdb;
		$table = Database::table( 'baselines' );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current baseline is mutable administrator-owned state.
			$wpdb->prepare( 'SELECT * FROM %i WHERE component_type = %s AND component_slug = %s AND is_current = 1 ORDER BY id DESC LIMIT 1', $table, $type, $slug ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return false;
		}
		$files_table  = Database::table( 'baseline_files' );
		$file_rows    = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads bounded files for the current owned baseline.
			$wpdb->prepare( 'SELECT relative_path, sha256, normalized_sha256, file_size FROM %i WHERE baseline_id = %d', $files_table, (int) $row['id'] ),
			ARRAY_A
		);
		$row['files'] = array();
		foreach ( $file_rows as $file ) {
			$row['files'][ $file['relative_path'] ] = array(
				'hash'            => $file['sha256'],
				'normalized_hash' => $file['normalized_sha256'],
				'size'            => (int) $file['file_size'],
			);
		}
		return $row;
	}

	/**
	 * Lists baseline summaries.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all() {
		global $wpdb;
		$table = Database::table( 'baselines' );
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, component_type, component_slug, component_name, component_version, created_at, is_current FROM %i ORDER BY created_at DESC', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Administrator-requested owned baseline history.
	}

	/**
	 * Deletes one baseline and promotes the newest remaining one.
	 *
	 * @param int $baseline_id Baseline ID.
	 * @return bool
	 */
	public function delete( $baseline_id ) {
		global $wpdb;
		$table = Database::table( 'baselines' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT component_type, component_slug FROM %i WHERE id = %d', $table, $baseline_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Resolves one administrator-selected owned baseline.
		if ( ! $row ) {
			return false;
		}
		$wpdb->delete( Database::table( 'baseline_files' ), array( 'baseline_id' => $baseline_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes only child hashes for the selected baseline.
		$wpdb->delete( $table, array( 'id' => $baseline_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes only the selected owned baseline.
		$new_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE component_type = %s AND component_slug = %s ORDER BY id DESC LIMIT 1', $table, $row['component_type'], $row['component_slug'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Finds the replacement current baseline after deletion.
		if ( $new_id ) {
			$wpdb->update( $table, array( 'is_current' => 1 ), array( 'id' => $new_id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Promotes one owned baseline.
		}
		return true;
	}

	/**
	 * Prunes old baselines for one component.
	 *
	 * @param string $type Type.
	 * @param string $slug Slug.
	 * @return void
	 */
	private function prune( $type, $slug ) {
		global $wpdb;
		$retention = max( 1, min( 10, (int) Plugin::settings()['baseline_retention'] ) );
		$table     = Database::table( 'baselines' );
		$ids       = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE component_type = %s AND component_slug = %s ORDER BY id DESC', $table, $type, $slug ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retention pruning requires current owned baseline IDs.
		foreach ( array_slice( array_map( 'intval', $ids ), $retention ) as $id ) {
			$this->delete( $id );
		}
	}
}
