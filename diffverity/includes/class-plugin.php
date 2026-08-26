<?php
/**
 * Plugin composition root.
 *
 * @package DiffVerity
 */

namespace DiffVerity;

use DiffVerity\Admin\Admin;
use DiffVerity\Storage\Database;
use DiffVerity\Filesystem\Temp_Manager;
use DiffVerity\Source\Bundled_Component_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin services to WordPress.
 */
final class Plugin {
	/**
	 * Shared plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance;

	/**
	 * Gets the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Returns bounded defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_settings() {
		return array(
			'files_per_batch'     => 200,
			'max_diff_bytes'      => 1048576,
			'http_timeout'        => 20,
			'max_package_bytes'   => 52428800,
			'history_retention'   => 20,
			'baseline_retention'  => 3,
			'strict_comparison'   => 0,
			'delete_on_uninstall' => 0,
			'excluded_components' => array(),
			'excluded_paths'      => array(),
			'excluded_extensions' => array(),
		);
	}

	/**
	 * Gets sanitized effective settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings() {
		$saved = get_option( 'diffverity_settings', array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::default_settings() );
	}

	/**
	 * Registers runtime hooks.
	 *
	 * @return void
	 */
	public function run() {
		if ( get_option( 'diffverity_schema_version' ) !== Database::SCHEMA_VERSION ) {
			Database::create_schema();
			update_option( 'diffverity_schema_version', Database::SCHEMA_VERSION, false );
		}

		add_action( 'init', array( $this, 'maybe_cleanup_stale_temporary_files' ) );
		if ( is_admin() ) {
			( new Admin() )->register_hooks();
		}
	}

	/**
	 * Runs inexpensive stale cleanup at most twice daily.
	 *
	 * @return void
	 */
	public function maybe_cleanup_stale_temporary_files() {
		if ( false === get_transient( 'diffverity_cleanup_gate' ) ) {
			Temp_Manager::cleanup_stale( DAY_IN_SECONDS );
			Bundled_Component_Source::cleanup_source_cache( 6 * HOUR_IN_SECONDS );
			set_transient( 'diffverity_cleanup_gate', 1, 12 * HOUR_IN_SECONDS );
		}
	}

	/** Prevent direct construction. */
	private function __construct() {}
}
