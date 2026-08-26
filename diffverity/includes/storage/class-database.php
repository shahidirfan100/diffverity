<?php
/**
 * Database schema and table names.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the bounded custom tables required for high-volume manifests.
 */
final class Database {
	const SCHEMA_VERSION = '1.1.0';

	/**
	 * Returns a prefixed owned table name.
	 *
	 * @param string $suffix Approved suffix.
	 * @return string
	 */
	public static function table( $suffix ) {
		global $wpdb;
		$allowed = array( 'scans', 'results', 'baselines', 'baseline_files' );
		if ( ! in_array( $suffix, $allowed, true ) ) {
			return '';
		}
		return $wpdb->prefix . 'diffverity_' . $suffix;
	}

	/**
	 * Creates or upgrades owned tables.
	 *
	 * @return void
	 */
	public static function create_schema() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate        = $wpdb->get_charset_collate();
		$scans          = self::table( 'scans' );
		$results        = self::table( 'results' );
		$baselines      = self::table( 'baselines' );
		$baseline_files = self::table( 'baseline_files' );

		$sql   = array();
		$sql[] = "CREATE TABLE {$scans} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			status varchar(20) NOT NULL DEFAULT 'pending',
			started_at datetime NOT NULL,
			completed_at datetime NULL,
			wp_version varchar(30) NOT NULL DEFAULT '',
			php_version varchar(30) NOT NULL DEFAULT '',
			total_components int(10) unsigned NOT NULL DEFAULT 0,
			processed_components int(10) unsigned NOT NULL DEFAULT 0,
			current_component varchar(191) NOT NULL DEFAULT '',
			counts longtext NULL,
			duration_ms bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY started_at (started_at)
		) {$collate};";
		$sql[] = "CREATE TABLE {$results} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id bigint(20) unsigned NOT NULL,
			component_type varchar(20) NOT NULL,
			component_slug varchar(191) NOT NULL,
			component_name varchar(191) NOT NULL,
			component_version varchar(50) NOT NULL DEFAULT '',
			relative_path text NOT NULL,
			status varchar(32) NOT NULL,
			trust_source varchar(40) NOT NULL,
			source_version varchar(30) NOT NULL DEFAULT '',
			local_hash varchar(128) NOT NULL DEFAULT '',
			trusted_hash varchar(128) NOT NULL DEFAULT '',
			local_size bigint(20) unsigned NOT NULL DEFAULT 0,
			trusted_size bigint(20) unsigned NOT NULL DEFAULT 0,
			severity varchar(20) NOT NULL DEFAULT 'informational',
			message text NULL,
			checked_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY scan_id (scan_id),
			KEY component (component_type,component_slug),
			KEY status (status)
		) {$collate};";
		$sql[] = "CREATE TABLE {$baselines} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			component_type varchar(20) NOT NULL,
			component_slug varchar(191) NOT NULL,
			component_name varchar(191) NOT NULL,
			component_version varchar(50) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			is_current tinyint(1) unsigned NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY component_current (component_type,component_slug,is_current),
			KEY created_at (created_at)
		) {$collate};";
		$sql[] = "CREATE TABLE {$baseline_files} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			baseline_id bigint(20) unsigned NOT NULL,
			relative_path text NOT NULL,
			path_key char(64) NOT NULL,
			sha256 char(64) NOT NULL,
			normalized_sha256 char(64) NOT NULL DEFAULT '',
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY baseline_path (baseline_id,path_key),
			KEY baseline_id (baseline_id)
		) {$collate};";

		dbDelta( $sql );
	}
}
