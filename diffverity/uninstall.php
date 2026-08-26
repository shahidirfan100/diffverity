<?php
/**
 * Removes only DiffVerity-owned data when the administrator opted in.
 *
 * @package DiffVerity
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$diffverity_settings = get_option( 'diffverity_settings', array() );
if ( empty( $diffverity_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
$diffverity_tables = array(
	$wpdb->prefix . 'diffverity_baseline_files',
	$wpdb->prefix . 'diffverity_baselines',
	$wpdb->prefix . 'diffverity_results',
	$wpdb->prefix . 'diffverity_scans',
);
foreach ( $diffverity_tables as $diffverity_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$diffverity_table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Allowlisted owned table names during uninstall.
}

delete_option( 'diffverity_settings' );
delete_option( 'diffverity_schema_version' );
delete_transient( 'diffverity_scan_lock' );
delete_transient( 'diffverity_cleanup_gate' );

$diffverity_like = $wpdb->esc_like( 'diffverity_scan_state_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $diffverity_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Deletes only owned state options.
