<?php
/**
 * Plugin Name:       DiffVerity – File Integrity & Source Diff
 * Plugin URI:        https://wordpress.org/plugins/diffverity-file-integrity-source-diff/
 * Description:       Verify core, plugin, and theme files and inspect exact differences against trusted sources or local baselines.
 * Version:           1.2.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Shahid Irfan
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       diffverity-file-integrity-source-diff
 * Domain Path:       /languages
 *
 * @package DiffVerity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DIFFVERITY_VERSION', '1.2.1' );
define( 'DIFFVERITY_FILE', __FILE__ );
define( 'DIFFVERITY_PATH', plugin_dir_path( __FILE__ ) );
define( 'DIFFVERITY_URL', plugin_dir_url( __FILE__ ) );

require_once DIFFVERITY_PATH . 'includes/class-autoloader.php';

DiffVerity\Autoloader::register();

register_activation_hook( __FILE__, array( 'DiffVerity\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DiffVerity\Activator', 'deactivate' ) );

/**
 * Starts DiffVerity after all plugins are available.
 *
 * @return void
 */
function diffverity_run() {
	DiffVerity\Plugin::instance()->run();
}
add_action( 'plugins_loaded', 'diffverity_run' );
