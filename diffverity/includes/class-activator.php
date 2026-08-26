<?php
/**
 * Activation and deactivation.
 *
 * @package DiffVerity
 */

namespace DiffVerity;

use DiffVerity\Storage\Database;
use DiffVerity\Filesystem\Temp_Manager;
use DiffVerity\Source\Bundled_Component_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performs lightweight lifecycle operations.
 */
final class Activator {
	/**
	 * Creates owned storage and defaults. It performs no scan or HTTP request.
	 *
	 * @return void
	 */
	public static function activate() {
		Database::create_schema();

		$defaults = Plugin::default_settings();
		if ( false === get_option( 'diffverity_settings', false ) ) {
			add_option( 'diffverity_settings', $defaults, '', 'no' );
		}
		update_option( 'diffverity_schema_version', Database::SCHEMA_VERSION, false );
	}

	/**
	 * Clears transient runtime state but preserves user data.
	 *
	 * @return void
	 */
	public static function deactivate() {
		delete_transient( 'diffverity_scan_lock' );
		Temp_Manager::cleanup_stale( 0 );
		Bundled_Component_Source::cleanup_source_cache( 0 );
	}
}
