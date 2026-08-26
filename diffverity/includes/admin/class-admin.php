<?php
/**
 * WordPress admin interface and authenticated controllers.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Admin;

use DiffVerity\Capabilities;
use DiffVerity\Diff\Diff_Engine;
use DiffVerity\Export\Report_Exporter;
use DiffVerity\Filesystem\Path_Guard;
use DiffVerity\Plugin;
use DiffVerity\Repair\File_Restorer;
use DiffVerity\Scanner\Component_Registry;
use DiffVerity\Scanner\Scan_Manager;
use DiffVerity\Storage\Baseline_Repository;
use DiffVerity\Storage\History_Repository;
use DiffVerity\Storage\Results_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders focused screens and handles privileged actions.
 */
final class Admin {
	/**
	 * Admin page slugs that may load DiffVerity assets.
	 *
	 * @var string[]
	 */
	private $pages = array( 'diffverity', 'diffverity-scan', 'diffverity-changes', 'diffverity-baselines', 'diffverity-history', 'diffverity-settings' );

	/** Registers hooks. */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'network_admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_diffverity_start_scan', array( $this, 'ajax_start_scan' ) );
		add_action( 'wp_ajax_diffverity_scan_step', array( $this, 'ajax_scan_step' ) );
		add_action( 'wp_ajax_diffverity_cancel_scan', array( $this, 'ajax_cancel_scan' ) );
		add_action( 'wp_ajax_diffverity_create_baseline', array( $this, 'ajax_create_baseline' ) );
		add_action( 'wp_ajax_diffverity_delete_baseline', array( $this, 'ajax_delete_baseline' ) );
		add_action( 'wp_ajax_diffverity_view_diff', array( $this, 'ajax_view_diff' ) );
		add_action( 'wp_ajax_diffverity_restore_file', array( $this, 'ajax_restore_file' ) );
		add_action( 'admin_post_diffverity_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_diffverity_export', array( $this, 'export' ) );
		add_action( 'admin_post_diffverity_delete_history', array( $this, 'delete_history' ) );
	}

	/** Registers the six focused menu screens. */
	public function register_menu() {
		$cap  = Capabilities::required();
		$icon = 'dashicons-media-code';
		add_menu_page( __( 'DiffVerity', 'diffverity' ), __( 'DiffVerity', 'diffverity' ), $cap, 'diffverity', array( $this, 'render_overview' ), $icon, 65 );
		add_submenu_page( 'diffverity', __( 'Integrity Overview', 'diffverity' ), __( 'Overview', 'diffverity' ), $cap, 'diffverity', array( $this, 'render_overview' ) );
		add_submenu_page( 'diffverity', __( 'Run Integrity Scan', 'diffverity' ), __( 'Scan', 'diffverity' ), $cap, 'diffverity-scan', array( $this, 'render_scan' ) );
		add_submenu_page( 'diffverity', __( 'File Changes', 'diffverity' ), __( 'Changes', 'diffverity' ), $cap, 'diffverity-changes', array( $this, 'render_changes' ) );
		add_submenu_page( 'diffverity', __( 'Local Baselines', 'diffverity' ), __( 'Baselines', 'diffverity' ), $cap, 'diffverity-baselines', array( $this, 'render_baselines' ) );
		add_submenu_page( 'diffverity', __( 'Scan History', 'diffverity' ), __( 'History', 'diffverity' ), $cap, 'diffverity-history', array( $this, 'render_history' ) );
		add_submenu_page( 'diffverity', __( 'DiffVerity Settings', 'diffverity' ), __( 'Settings', 'diffverity' ), $cap, 'diffverity-settings', array( $this, 'render_settings' ) );
	}

	/** Loads assets only on DiffVerity pages. */
	public function enqueue_assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		if ( ! in_array( $page, $this->pages, true ) ) {
			return;
		}
		wp_enqueue_style( 'diffverity-admin', DIFFVERITY_URL . 'admin/assets/css/admin.css', array(), DIFFVERITY_VERSION );
		wp_enqueue_script( 'diffverity-admin', DIFFVERITY_URL . 'admin/assets/js/admin.js', array(), DIFFVERITY_VERSION, true );
		wp_localize_script(
			'diffverity-admin',
			'diffverityAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'diffverity_admin' ),
				'labels'  => array(
					'starting'        => __( 'Starting scan…', 'diffverity' ),
					'complete'        => __( 'Scan complete. Reloading results…', 'diffverity' ),
					'cancelled'       => __( 'Scan cancelled.', 'diffverity' ),
					'confirmBaseline' => __( 'A local baseline records the current files but does not prove they are clean or authentic. Create it now?', 'diffverity' ),
					'confirmDelete'   => __( 'Delete this baseline?', 'diffverity' ),
					'confirmRestore'  => __( 'Restore this file from the exact installed-version package on WordPress.org? The current file will be overwritten and scan history will remain unchanged.', 'diffverity' ),
					'restoring'       => __( 'Restoring…', 'diffverity' ),
					'restored'        => __( 'Restored — rescan required', 'diffverity' ),
				),
			)
		);
	}

	/** Overview screen. */
	public function render_overview() {
		Capabilities::enforce();
		$scans       = ( new History_Repository() )->recent( 1 );
		$scan        = $scans ? $scans[0] : false;
		$counts      = $scan && $scan['counts'] ? json_decode( $scan['counts'], true ) : array();
		$components  = $scan ? ( new Results_Repository() )->component_summaries( (int) $scan['id'] ) : array();
		$differences = 0;
		foreach ( array( 'Modified', 'Missing', 'Unexpected', 'Baseline Changed', 'Baseline Missing', 'Baseline Added', 'Soft Difference' ) as $status ) {
			$differences += isset( $counts[ $status ] ) ? (int) $counts[ $status ] : 0;
		}
		$this->header( __( 'Integrity Overview', 'diffverity' ), __( 'Evidence-based source comparison for core, plugins, and themes.', 'diffverity' ) );
		?>
		<div class="diffverity-grid diffverity-grid--metrics">
			<?php $this->metric( __( 'Verified files', 'diffverity' ), isset( $counts['Verified'] ) ? (int) $counts['Verified'] : 0, 'verified' ); ?>
			<?php $this->metric( __( 'Differences to review', 'diffverity' ), $differences, $differences ? 'changed' : 'verified' ); ?>
			<?php $this->metric( __( 'Source unavailable', 'diffverity' ), isset( $counts['Source Unavailable'] ) ? (int) $counts['Source Unavailable'] : 0, 'neutral' ); ?>
			<?php $this->metric( __( 'Last scan', 'diffverity' ), $scan ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $scan['started_at'] ) : __( 'Never', 'diffverity' ), 'info' ); ?>
		</div>
		<?php if ( $components ) : ?>
		<div class="diffverity-panel">
			<h2><?php esc_html_e( 'Latest scan components', 'diffverity' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Select a component to open only its file results from the latest scan.', 'diffverity' ); ?></p>
			<div class="diffverity-table-wrap"><table class="widefat striped diffverity-table"><thead><tr><th><?php esc_html_e( 'Component', 'diffverity' ); ?></th><th><?php esc_html_e( 'Type', 'diffverity' ); ?></th><th><?php esc_html_e( 'Version', 'diffverity' ); ?></th><th><?php esc_html_e( 'Scanned files', 'diffverity' ); ?></th><th><?php esc_html_e( 'Differences', 'diffverity' ); ?></th></tr></thead><tbody>
			<?php foreach ( $components as $component ) : ?>
				<?php $component_url = add_query_arg( array( 'page' => 'diffverity-changes', 'scan_id' => absint( $scan['id'] ), 'type' => $component['component_type'], 'component' => $component['component_slug'] ), admin_url( 'admin.php' ) ); ?>
				<tr><td><strong><a href="<?php echo esc_url( $component_url ); ?>"><?php echo esc_html( $component['component_name'] ? $component['component_name'] : $component['component_slug'] ); ?></a></strong></td><td><?php echo esc_html( ucfirst( $component['component_type'] ) ); ?></td><td><?php echo esc_html( $component['component_version'] ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $component['total_files'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $component['differences'] ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table></div>
		</div>
		<?php endif; ?>
		<div class="diffverity-panel">
			<h2><?php esc_html_e( 'Trust labels are evidence labels', 'diffverity' ); ?></h2>
			<div class="diffverity-trust-grid">
				<?php $this->trust_item( 'Officially Verified', __( 'Compared with authoritative checksums for the exact installed version.', 'diffverity' ) ); ?>
				<?php $this->trust_item( 'Official Source Available', __( 'Compared with an exact-version package retrieved from WordPress.org.', 'diffverity' ) ); ?>
				<?php $this->trust_item( 'Official Core Bundle', __( 'Matches a recorded component copy from the current or a recent official WordPress distribution.', 'diffverity' ) ); ?>
				<?php $this->trust_item( 'Local Baseline', __( 'Shows changes after an administrator-created snapshot; it does not prove authenticity.', 'diffverity' ) ); ?>
				<?php $this->trust_item( 'No Trusted Source', __( 'No independent source was available. This is not itself a security warning.', 'diffverity' ) ); ?>
			</div>
			<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=diffverity-scan' ) ); ?>"><?php esc_html_e( 'Run Integrity Scan', 'diffverity' ); ?></a></p>
		</div>
		<?php
		$this->footer();
	}

	/** Scan screen. */
	public function render_scan() {
		Capabilities::enforce();
		$settings   = Plugin::settings();
		$components = array_values(
			array_filter(
				( new Component_Registry() )->all(),
				static function ( $component ) use ( $settings ) {
					return ! in_array( $component['type'] . ':' . $component['slug'], (array) $settings['excluded_components'], true );
				}
			)
		);
		$this->header( __( 'Run Integrity Scan', 'diffverity' ), __( 'Components are processed in bounded batches. You can safely cancel the scan.', 'diffverity' ) );
		?>
		<div class="diffverity-panel diffverity-scan" data-diffverity-scan>
			<div class="diffverity-inline-form">
				<label for="diffverity-scan-component"><strong><?php esc_html_e( 'Scan target', 'diffverity' ); ?></strong></label>
				<select id="diffverity-scan-component" data-diffverity-scan-component>
					<option value=""><?php esc_html_e( 'All core, plugins, and themes', 'diffverity' ); ?></option>
					<?php foreach ( $components as $component ) : ?>
						<option value="<?php echo esc_attr( $component['type'] . ':' . $component['slug'] ); ?>"><?php echo esc_html( $component['name'] . ' ' . $component['version'] . ' (' . ucfirst( $component['type'] ) . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="diffverity-actions"><button type="button" class="button button-primary button-hero" data-diffverity-start><?php esc_html_e( 'Run Integrity Scan', 'diffverity' ); ?></button><button type="button" class="button" data-diffverity-cancel hidden><?php esc_html_e( 'Cancel Scan', 'diffverity' ); ?></button></div>
			<div class="diffverity-progress" hidden data-diffverity-progress role="status" aria-live="polite">
				<div class="diffverity-progress__track"><span data-diffverity-progress-bar></span></div>
				<p data-diffverity-progress-text></p>
			</div>
			<p class="description"><?php esc_html_e( 'A source lookup failure is reported separately from an integrity mismatch. Scanning never modifies installed files.', 'diffverity' ); ?></p>
		</div>
		<?php
		$this->footer();
	}

	/** Changes screen. */
	public function render_changes() {
		Capabilities::enforce();
		$history          = new History_Repository();
		$scans            = $history->recent( 1 );
		$scan_id          = isset( $_GET['scan_id'] ) ? absint( $_GET['scan_id'] ) : ( $scans ? (int) $scans[0]['id'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$allowed_statuses = array( 'Modified', 'Missing', 'Unexpected', 'Soft Difference', 'Source Unavailable', 'Baseline Changed', 'Baseline Added', 'Baseline Missing', 'Verified', 'Skipped' );
		$status           = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$status           = in_array( $status, $allowed_statuses, true ) ? $status : '';
		$type             = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$type             = in_array( $type, array( 'core', 'plugin', 'theme' ), true ) ? $type : '';
		$component        = isset( $_GET['component'] ) ? sanitize_key( wp_unslash( $_GET['component'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$rows             = $scan_id ? ( new Results_Repository() )->query( $scan_id, $status, $type, 500, 0, $component ) : array();
		$this->header( __( 'Changes', 'diffverity' ), __( 'Every status describes evidence, not intent.', 'diffverity' ) );
		?>
		<form class="diffverity-filters" method="get">
			<input type="hidden" name="page" value="diffverity-changes">
			<?php if ( $scan_id ) : ?><input type="hidden" name="scan_id" value="<?php echo esc_attr( $scan_id ); ?>"><?php endif; ?>
			<?php if ( $component ) : ?><input type="hidden" name="component" value="<?php echo esc_attr( $component ); ?>"><?php endif; ?>
			<label><?php esc_html_e( 'Status', 'diffverity' ); ?> <select name="status"><option value=""><?php esc_html_e( 'All', 'diffverity' ); ?></option>
			<?php
			foreach ( $allowed_statuses as $item ) :
				?>
				<option value="<?php echo esc_attr( $item ); ?>" <?php selected( $status, $item ); ?>><?php echo esc_html( $item ); ?></option><?php endforeach; ?></select></label>
			<label><?php esc_html_e( 'Component type', 'diffverity' ); ?> <select name="type"><option value=""><?php esc_html_e( 'All', 'diffverity' ); ?></option><option value="core" <?php selected( $type, 'core' ); ?>><?php esc_html_e( 'Core', 'diffverity' ); ?></option><option value="plugin" <?php selected( $type, 'plugin' ); ?>><?php esc_html_e( 'Plugin', 'diffverity' ); ?></option><option value="theme" <?php selected( $type, 'theme' ); ?>><?php esc_html_e( 'Theme', 'diffverity' ); ?></option></select></label>
			<button class="button"><?php esc_html_e( 'Filter', 'diffverity' ); ?></button>
			<?php if ( $component ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'diffverity-changes', 'scan_id' => $scan_id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Clear component', 'diffverity' ); ?></a><?php endif; ?>
		</form>
		<div class="diffverity-table-wrap"><table class="widefat striped diffverity-table"><thead><tr><th><?php esc_html_e( 'Component', 'diffverity' ); ?></th><th><?php esc_html_e( 'Version', 'diffverity' ); ?></th><th><?php esc_html_e( 'File', 'diffverity' ); ?></th><th><?php esc_html_e( 'Status', 'diffverity' ); ?></th><th><?php esc_html_e( 'Trust Source', 'diffverity' ); ?></th><th><?php esc_html_e( 'Last Checked', 'diffverity' ); ?></th><th><?php esc_html_e( 'Action', 'diffverity' ); ?></th></tr></thead><tbody>
		<?php
		if ( ! $rows ) :
			?>
			<tr><td colspan="7"><?php esc_html_e( 'No results match this view.', 'diffverity' ); ?></td></tr><?php endif; ?>
		<?php
		foreach ( $rows as $row ) :
			?>
			<?php $component_url = add_query_arg( array( 'page' => 'diffverity-changes', 'scan_id' => $scan_id, 'type' => $row['component_type'], 'component' => $row['component_slug'] ), admin_url( 'admin.php' ) ); ?>
			<tr><td data-label="<?php esc_attr_e( 'Component', 'diffverity' ); ?>"><strong><a href="<?php echo esc_url( $component_url ); ?>"><?php echo esc_html( $row['component_name'] ? $row['component_name'] : $row['component_slug'] ); ?></a></strong><br><small><?php echo esc_html( ucfirst( $row['component_type'] ) ); ?></small></td><td data-label="<?php esc_attr_e( 'Version', 'diffverity' ); ?>"><?php echo esc_html( $row['component_version'] ); ?></td><td data-label="<?php esc_attr_e( 'File', 'diffverity' ); ?>"><code><?php echo esc_html( $row['relative_path'] ? $row['relative_path'] : '—' ); ?></code></td><td data-label="<?php esc_attr_e( 'Status', 'diffverity' ); ?>"><?php $this->status_badge( $row['status'] ); ?></td><td data-label="<?php esc_attr_e( 'Trust Source', 'diffverity' ); ?>"><?php echo esc_html( $row['trust_source'] ); ?></td><td data-label="<?php esc_attr_e( 'Last Checked', 'diffverity' ); ?>"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row['checked_at'] ) ); ?></td><td data-label="<?php esc_attr_e( 'Action', 'diffverity' ); ?>"><button type="button" class="button button-small" data-diffverity-details data-result='<?php echo esc_attr( wp_json_encode( array_intersect_key( $row, array_flip( array( 'status', 'message', 'local_hash', 'trusted_hash', 'local_size', 'trusted_size', 'relative_path', 'trust_source', 'source_version' ) ) ) ) ); ?>'><?php esc_html_e( 'Details', 'diffverity' ); ?></button> 
			<?php
			if ( in_array( $row['status'], array( 'Modified', 'Soft Difference' ), true ) && 'Local Baseline' !== $row['trust_source'] ) :
				?>
			<button type="button" class="button button-small" data-diffverity-diff data-id="<?php echo esc_attr( $row['id'] ); ?>" data-scan="<?php echo esc_attr( $scan_id ); ?>"><?php esc_html_e( 'View Diff', 'diffverity' ); ?></button><?php endif; ?>
			<?php if ( in_array( $row['status'], array( 'Modified', 'Missing', 'Soft Difference' ), true ) && in_array( $row['trust_source'], array( 'Officially Verified', 'Official Source Available', 'Official Core Bundle' ), true ) ) : ?>
			<button type="button" class="button button-small" data-diffverity-restore data-id="<?php echo esc_attr( $row['id'] ); ?>" data-scan="<?php echo esc_attr( $scan_id ); ?>"><?php esc_html_e( 'Restore Original', 'diffverity' ); ?></button><?php endif; ?></td></tr><?php endforeach; ?>
		</tbody></table></div>
		<?php
		if ( $scan_id ) :
			?>
			<div class="diffverity-export"><span><?php esc_html_e( 'Export:', 'diffverity' ); ?></span>
			<?php
			foreach ( array( 'json', 'csv', 'txt' ) as $format ) :
				?>
			<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=diffverity_export&scan_id=' . $scan_id . '&format=' . $format ), 'diffverity_export_' . $scan_id ) ); ?>"><?php echo esc_html( strtoupper( $format ) ); ?></a><?php endforeach; ?></div><?php endif; ?>
		<?php
		$this->modal();
		$this->footer();
	}

	/** Baselines screen. */
	public function render_baselines() {
		Capabilities::enforce();
		$components = array_filter(
			( new Component_Registry() )->all(),
			static function ( $component ) {
				return in_array( $component['type'], array( 'plugin', 'theme' ), true );
			}
		);
		$baselines  = ( new Baseline_Repository() )->all();
		$this->header( __( 'Local Baselines', 'diffverity' ), __( 'Monitor private, premium, or custom components that have no independently verifiable source.', 'diffverity' ) );
		?>
		<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Important:', 'diffverity' ); ?></strong> <?php esc_html_e( 'DiffVerity cannot determine whether the current files are already compromised. A baseline only records their current state so future changes can be detected.', 'diffverity' ); ?></p></div>
		<div class="diffverity-panel"><h2><?php esc_html_e( 'Create Local Baseline', 'diffverity' ); ?></h2><div class="diffverity-inline-form"><label class="screen-reader-text" for="diffverity-component"><?php esc_html_e( 'Component', 'diffverity' ); ?></label><select id="diffverity-component" data-diffverity-component>
		<?php
		foreach ( $components as $component ) :
			?>
			<option value="<?php echo esc_attr( $component['type'] . ':' . $component['slug'] ); ?>"><?php echo esc_html( $component['name'] . ' ' . $component['version'] . ' (' . ucfirst( $component['type'] ) . ')' ); ?></option><?php endforeach; ?></select><button type="button" class="button button-primary" data-diffverity-baseline-create><?php esc_html_e( 'Create Local Baseline', 'diffverity' ); ?></button></div></div>
		<div class="diffverity-table-wrap"><table class="widefat striped diffverity-table"><thead><tr><th><?php esc_html_e( 'Component', 'diffverity' ); ?></th><th><?php esc_html_e( 'Version', 'diffverity' ); ?></th><th><?php esc_html_e( 'Created', 'diffverity' ); ?></th><th><?php esc_html_e( 'State', 'diffverity' ); ?></th><th><?php esc_html_e( 'Action', 'diffverity' ); ?></th></tr></thead><tbody>
		<?php
		if ( ! $baselines ) :
			?>
			<tr><td colspan="5"><?php esc_html_e( 'No local baselines exist.', 'diffverity' ); ?></td></tr><?php endif; ?>
			<?php
			foreach ( $baselines as $baseline ) :
				?>
			<tr><td><?php echo esc_html( $baseline['component_name'] ); ?><br><small><?php echo esc_html( ucfirst( $baseline['component_type'] ) ); ?></small></td><td><?php echo esc_html( $baseline['component_version'] ); ?></td><td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $baseline['created_at'] ) ); ?></td><td><?php echo $baseline['is_current'] ? esc_html__( 'Current baseline', 'diffverity' ) : esc_html__( 'Previous baseline', 'diffverity' ); ?></td><td><button type="button" class="button button-small button-link-delete" data-diffverity-baseline-delete="<?php echo esc_attr( $baseline['id'] ); ?>"><?php esc_html_e( 'Delete', 'diffverity' ); ?></button></td></tr><?php endforeach; ?></tbody></table></div>
		<?php
		$this->footer();
	}

	/** History screen. */
	public function render_history() {
		Capabilities::enforce();
		$repository = new History_Repository();
		$scans      = $repository->recent( 100 );
		$comparison = $this->history_comparison( $scans );
		$this->header( __( 'Scan History', 'diffverity' ), __( 'History is capped; full source code is never stored.', 'diffverity' ) );
		if ( $comparison ) :
			?>
		<div class="diffverity-grid diffverity-grid--metrics"><?php $this->metric( __( 'New differences', 'diffverity' ), $comparison['new'], 'changed' ); ?><?php $this->metric( __( 'Resolved differences', 'diffverity' ), $comparison['resolved'], 'verified' ); ?><?php $this->metric( __( 'Unchanged differences', 'diffverity' ), $comparison['unchanged'], 'neutral' ); ?></div><?php endif; ?>
		<div class="diffverity-table-wrap"><table class="widefat striped diffverity-table"><thead><tr><th><?php esc_html_e( 'Scan', 'diffverity' ); ?></th><th><?php esc_html_e( 'Started', 'diffverity' ); ?></th><th><?php esc_html_e( 'Status', 'diffverity' ); ?></th><th><?php esc_html_e( 'Components', 'diffverity' ); ?></th><th><?php esc_html_e( 'Duration', 'diffverity' ); ?></th><th><?php esc_html_e( 'Actions', 'diffverity' ); ?></th></tr></thead><tbody>
		<?php
		if ( ! $scans ) :
			?>
			<tr><td colspan="6"><?php esc_html_e( 'No scans have run yet.', 'diffverity' ); ?></td></tr><?php endif; ?>
			<?php
			foreach ( $scans as $scan ) :
				?>
			<tr><td>#<?php echo esc_html( $scan['id'] ); ?></td><td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $scan['started_at'] ) ); ?></td><td><?php echo esc_html( ucfirst( $scan['status'] ) ); ?></td><td><?php echo esc_html( $scan['processed_components'] . ' / ' . $scan['total_components'] ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $scan['duration_ms'] / 1000, 2 ) . 's' ); ?></td><td><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=diffverity-changes&scan_id=' . absint( $scan['id'] ) ) ); ?>"><?php esc_html_e( 'View', 'diffverity' ); ?></a> <a class="button button-small button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=diffverity_delete_history&scan_id=' . absint( $scan['id'] ) ), 'diffverity_delete_history_' . absint( $scan['id'] ) ) ); ?>"><?php esc_html_e( 'Delete', 'diffverity' ); ?></a></td></tr><?php endforeach; ?></tbody></table></div>
		<?php
		$this->footer();
	}

	/** Settings screen. */
	public function render_settings() {
		Capabilities::enforce();
		$s = Plugin::settings();
		$this->header( __( 'Settings', 'diffverity' ), __( 'Conservative resource limits protect shared hosting.', 'diffverity' ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="diffverity-panel diffverity-settings"><input type="hidden" name="action" value="diffverity_save_settings"><?php wp_nonce_field( 'diffverity_save_settings', 'diffverity_nonce' ); ?>
		<table class="form-table" role="presentation"><tbody>
		<?php $this->number_setting( 'files_per_batch', __( 'Files per batch', 'diffverity' ), $s['files_per_batch'], 25, 500, __( 'Default 200. Larger values use more CPU per request.', 'diffverity' ) ); ?>
		<?php $this->number_setting( 'max_diff_bytes', __( 'Maximum text diff bytes', 'diffverity' ), $s['max_diff_bytes'], 65536, 5242880, __( 'Default 1 MB. Larger or binary files show metadata only.', 'diffverity' ) ); ?>
		<?php $this->number_setting( 'http_timeout', __( 'HTTP timeout (seconds)', 'diffverity' ), $s['http_timeout'], 5, 60, __( 'Applies only to authoritative WordPress.org requests.', 'diffverity' ) ); ?>
		<?php $this->number_setting( 'max_package_bytes', __( 'Maximum package bytes', 'diffverity' ), $s['max_package_bytes'], 10485760, 209715200, __( 'Default 50 MB.', 'diffverity' ) ); ?>
		<?php $this->number_setting( 'history_retention', __( 'Scan history retention', 'diffverity' ), $s['history_retention'], 1, 100, __( 'Default 20, maximum 100.', 'diffverity' ) ); ?>
		<?php $this->number_setting( 'baseline_retention', __( 'Baseline retention per component', 'diffverity' ), $s['baseline_retention'], 1, 10, __( 'Default 3, maximum 10.', 'diffverity' ) ); ?>
		<tr><th scope="row"><?php esc_html_e( 'Strict comparison', 'diffverity' ); ?></th><td><label><input type="checkbox" name="strict_comparison" value="1" <?php checked( $s['strict_comparison'] ); ?>> <?php esc_html_e( 'Report every byte-level mismatch as a full change.', 'diffverity' ); ?></label></td></tr>
		<tr><th scope="row"><label for="diffverity-excluded-components"><?php esc_html_e( 'Excluded components', 'diffverity' ); ?></label></th><td><textarea class="large-text code" rows="4" id="diffverity-excluded-components" name="excluded_components"><?php echo esc_textarea( implode( "\n", $s['excluded_components'] ) ); ?></textarea><p class="description"><?php esc_html_e( 'One controlled identifier per line, such as plugin:example-plugin or theme:example-theme.', 'diffverity' ); ?></p></td></tr>
		<tr><th scope="row"><label for="diffverity-excluded-paths"><?php esc_html_e( 'Excluded relative paths', 'diffverity' ); ?></label></th><td><textarea class="large-text code" rows="4" id="diffverity-excluded-paths" name="excluded_paths"><?php echo esc_textarea( implode( "\n", $s['excluded_paths'] ) ); ?></textarea><p class="description"><?php esc_html_e( 'Relative paths only. Traversal and absolute paths are rejected.', 'diffverity' ); ?></p></td></tr>
		<tr><th scope="row"><label for="diffverity-excluded-extensions"><?php esc_html_e( 'Excluded extensions', 'diffverity' ); ?></label></th><td><input class="regular-text" id="diffverity-excluded-extensions" name="excluded_extensions" value="<?php echo esc_attr( implode( ', ', $s['excluded_extensions'] ) ); ?>"><p class="description"><?php esc_html_e( 'Comma-separated extensions without dots. PHP is never excluded by default.', 'diffverity' ); ?></p></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Uninstall cleanup', 'diffverity' ); ?></th><td><label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( $s['delete_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete DiffVerity settings, history, and baselines when the plugin is deleted.', 'diffverity' ); ?></label></td></tr>
		</tbody></table><?php submit_button(); ?></form>
		<?php
		$this->footer();
	}

	/** Starts scan AJAX. */
	public function ajax_start_scan() {
		$this->guard_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by guard_ajax().
		$key   = isset( $_POST['component'] ) ? sanitize_text_field( wp_unslash( $_POST['component'] ) ) : '';
		$parts = '' === $key ? array( '', '' ) : explode( ':', $key, 2 );
		if ( 2 !== count( $parts ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid scan component identifier.', 'diffverity' ) ), 400 );
		}
		$this->send_result( ( new Scan_Manager() )->start( sanitize_key( $parts[0] ), sanitize_key( $parts[1] ) ) ); }
	/** Steps scan AJAX. */
	public function ajax_scan_step() {
		$this->guard_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by guard_ajax().
		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		$this->send_result( ( new Scan_Manager() )->step( $scan_id ) ); }
	/** Cancels scan AJAX. */
	public function ajax_cancel_scan() {
		$this->guard_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by guard_ajax().
		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		$this->send_result( ( new Scan_Manager() )->cancel( $scan_id ) ); }

	/** Creates baseline AJAX. */
	public function ajax_create_baseline() {
		$this->guard_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by guard_ajax().
		$key   = isset( $_POST['component'] ) ? sanitize_text_field( wp_unslash( $_POST['component'] ) ) : '';
		$parts = explode( ':', $key, 2 );
		if ( 2 !== count( $parts ) || ! in_array( $parts[0], array( 'plugin', 'theme' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid component identifier.', 'diffverity' ) ), 400 ); }
		$component = ( new Component_Registry() )->resolve( $parts[0], sanitize_key( $parts[1] ) );
		if ( false === $component ) {
			wp_send_json_error( array( 'message' => __( 'Component could not be resolved.', 'diffverity' ) ), 404 ); }
		$this->send_result( ( new Baseline_Repository() )->create( $component ) );
	}

	/** Deletes baseline AJAX. */
	public function ajax_delete_baseline() {
		$this->guard_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by guard_ajax().
		$id = isset( $_POST['baseline_id'] ) ? absint( $_POST['baseline_id'] ) : 0;
		$ok = ( new Baseline_Repository() )->delete( $id );
		$ok ? wp_send_json_success( array( 'deleted' => $id ) ) : wp_send_json_error( array( 'message' => __( 'Baseline not found.', 'diffverity' ) ), 404 ); }

	/** Returns a safe source diff AJAX. */
	public function ajax_view_diff() {
		$this->guard_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by guard_ajax().
		$id = isset( $_POST['result_id'] ) ? absint( $_POST['result_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by guard_ajax().
		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		$result  = ( new Results_Repository() )->get( $id, $scan_id );
		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Scan result not found.', 'diffverity' ) ), 404 ); }
		$this->send_result( ( new Diff_Engine() )->compare( $result ) );
	}

	/** Restores one eligible file from its exact official package. */
	public function ajax_restore_file() {
		$this->guard_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by guard_ajax().
		$id = isset( $_POST['result_id'] ) ? absint( $_POST['result_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by guard_ajax().
		$scan_id = isset( $_POST['scan_id'] ) ? absint( $_POST['scan_id'] ) : 0;
		$result  = ( new Results_Repository() )->get( $id, $scan_id );
		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Scan result not found.', 'diffverity' ) ), 404 );
		}
		$this->send_result( ( new File_Restorer() )->restore( $result ) );
	}

	/** Saves validated settings. */
	public function save_settings() {
		Capabilities::enforce();
		check_admin_referer( 'diffverity_save_settings', 'diffverity_nonce' );
		$settings                        = Plugin::default_settings();
		$settings['files_per_batch']     = $this->bounded_post_int( 'files_per_batch', 25, 500, 200 );
		$settings['max_diff_bytes']      = $this->bounded_post_int( 'max_diff_bytes', 65536, 5242880, 1048576 );
		$settings['http_timeout']        = $this->bounded_post_int( 'http_timeout', 5, 60, 20 );
		$settings['max_package_bytes']   = $this->bounded_post_int( 'max_package_bytes', 10485760, 209715200, 52428800 );
		$settings['history_retention']   = $this->bounded_post_int( 'history_retention', 1, 100, 20 );
		$settings['baseline_retention']  = $this->bounded_post_int( 'baseline_retention', 1, 10, 3 );
		$settings['strict_comparison']   = isset( $_POST['strict_comparison'] ) ? 1 : 0;
		$settings['delete_on_uninstall'] = isset( $_POST['delete_on_uninstall'] ) ? 1 : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$excluded_components = isset( $_POST['excluded_components'] ) ? sanitize_textarea_field( wp_unslash( $_POST['excluded_components'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$excluded_paths = isset( $_POST['excluded_paths'] ) ? sanitize_textarea_field( wp_unslash( $_POST['excluded_paths'] ) ) : '';

		$settings['excluded_components'] = $this->sanitize_lines( $excluded_components, 'component' );
		$settings['excluded_paths']      = $this->sanitize_lines( $excluded_paths, 'path' );
		$extensions                      = isset( $_POST['excluded_extensions'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_POST['excluded_extensions'] ) ) ) : array();
		$settings['excluded_extensions'] = array_values(
			array_filter(
				array_unique(
					array_map(
						static function ( $extension ) {
							return sanitize_key( ltrim( trim( $extension ), '.' ) );
						},
						$extensions
					)
				)
			)
		);
		update_option( 'diffverity_settings', $settings, false );
		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=diffverity-settings' ) ) );
		exit;
	}

	/** Exports report after nonce/capability validation. */
	public function export() {
		Capabilities::enforce();
		$scan_id = isset( $_GET['scan_id'] ) ? absint( $_GET['scan_id'] ) : 0;
		check_admin_referer( 'diffverity_export_' . $scan_id );
		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : '';
		( new Report_Exporter() )->output( $scan_id, $format ); }
	/** Deletes history after nonce/capability validation. */
	public function delete_history() {
		Capabilities::enforce();
		$scan_id = isset( $_GET['scan_id'] ) ? absint( $_GET['scan_id'] ) : 0;
		check_admin_referer( 'diffverity_delete_history_' . $scan_id );
		( new History_Repository() )->delete( $scan_id );
		wp_safe_redirect( admin_url( 'admin.php?page=diffverity-history' ) );
		exit; }

	/** AJAX nonce + capability gate. */
	private function guard_ajax() {
		Capabilities::enforce();
		check_ajax_referer( 'diffverity_admin', 'nonce' ); }
	/**
	 * Sends a WP_Error-aware JSON response.
	 *
	 * @param mixed $result Result or WP_Error.
	 * @return void
	 */
	private function send_result( $result ) {
		is_wp_error( $result ) ? wp_send_json_error(
			array(
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			),
			400
		) : wp_send_json_success( $result ); }

	/**
	 * Renders the shared page header.
	 *
	 * @param string $title Page title.
	 * @param string $description Page description.
	 * @return void
	 */
	private function header( $title, $description ) {

		?>
	<div class="wrap diffverity-wrap"><div class="diffverity-header"><div class="diffverity-brand" aria-hidden="true"><span>−</span><span>+</span></div><div><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $description ); ?></p></div></div>
		<?php
	}
	/** Shared page footer. */
	private function footer() {

		?>
	</div>
		<?php
	}
	/**
	 * Renders a metric card.
	 *
	 * @param string     $label Metric label.
	 * @param int|string $value Metric value.
	 * @param string     $tone Visual tone.
	 * @return void
	 */
	private function metric( $label, $value, $tone ) {

		?>
	<div class="diffverity-metric diffverity-metric--<?php echo esc_attr( $tone ); ?>"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( $value ); ?></strong></div>
		<?php
	}
	/**
	 * Renders a trust definition.
	 *
	 * @param string $label Trust label.
	 * @param string $description Trust explanation.
	 * @return void
	 */
	private function trust_item( $label, $description ) {

		?>
	<div><strong><?php echo esc_html( $label ); ?></strong><p><?php echo esc_html( $description ); ?></p></div>
		<?php
	}
	/**
	 * Renders an accessible status badge.
	 *
	 * @param string $status File status.
	 * @return void
	 */
	private function status_badge( $status ) {
		$icons = array(
			'Verified'           => '✓',
			'Modified'           => '!',
			'Missing'            => '×',
			'Unexpected'         => '+',
			'Soft Difference'    => '≈',
			'Source Unavailable' => '?',
			'Skipped'            => '↷',
			'Baseline Changed'   => '!',
			'Baseline Added'     => '+',
			'Baseline Missing'   => '×',
		);
		?>
	<span class="diffverity-status diffverity-status--<?php echo esc_attr( sanitize_html_class( strtolower( str_replace( ' ', '-', $status ) ) ) ); ?>"><span aria-hidden="true"><?php echo esc_html( isset( $icons[ $status ] ) ? $icons[ $status ] : '•' ); ?></span> <?php echo esc_html( $status ); ?></span>
		<?php
	}
	/** Modal shell. */
	private function modal() {

		?>
	<div class="diffverity-modal" data-diffverity-modal hidden><div class="diffverity-modal__backdrop" data-diffverity-close></div><section class="diffverity-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="diffverity-modal-title"><header><h2 id="diffverity-modal-title"><?php esc_html_e( 'File Evidence', 'diffverity' ); ?></h2><button type="button" class="button" data-diffverity-close aria-label="<?php esc_attr_e( 'Close dialog', 'diffverity' ); ?>">×</button></header><div class="diffverity-modal__body" data-diffverity-modal-body></div></section></div>
		<?php
	}
	/**
	 * Renders one bounded numeric setting.
	 *
	 * @param string $name Field name.
	 * @param string $label Field label.
	 * @param int    $value Current value.
	 * @param int    $min Minimum value.
	 * @param int    $max Maximum value.
	 * @param string $description Field help.
	 * @return void
	 */
	private function number_setting( $name, $label, $value, $min, $max, $description ) {

		?>
	<tr><th scope="row"><label for="diffverity-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input type="number" id="diffverity-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>"><p class="description"><?php echo esc_html( $description ); ?></p></td></tr>
		<?php
	}
	/**
	 * Reads one bounded integer after the settings nonce was verified.
	 *
	 * @param string $key Field key.
	 * @param int    $min Minimum value.
	 * @param int    $max Maximum value.
	 * @param int    $fallback Fallback value.
	 * @return int
	 */
	private function bounded_post_int( $key, $min, $max, $fallback ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verifies settings nonce.
		$value = isset( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : $fallback;
		return max( $min, min( $max, $value ) ); }
	/**
	 * Sanitizes newline-delimited controlled settings.
	 *
	 * @param string $text Submitted lines.
	 * @param string $mode Component or path mode.
	 * @return string[]
	 */
	private function sanitize_lines( $text, $mode ) {
		$output = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( 'component' === $mode ) {
				if ( preg_match( '/^(plugin|theme):[a-z0-9_-]+$/', $line ) ) {
					$output[] = $line;
				}
			} else {
				$line = Path_Guard::normalize_relative( $line );
				if ( $line ) {
					$output[] = $line;
				}
			}
		} return array_values( array_unique( $output ) ); }

	/**
	 * Compares the latest two completed scans by difference identity.
	 *
	 * @param array $scans Recent scans.
	 * @return array<string,int>|false
	 */
	private function history_comparison( array $scans ) {
		$complete = array_values(
			array_filter(
				$scans,
				static function ( $scan ) {
					return 'complete' === $scan['status'];
				}
			)
		);
		if ( count( $complete ) < 2 ) {
			return false; }
		$repo     = new Results_Repository();
		$now      = $repo->query( (int) $complete[0]['id'], '', '', 50000, 0 );
		$before   = $repo->query( (int) $complete[1]['id'], '', '', 50000, 0 );
		$build    = static function ( $rows ) {
			$set = array();
			foreach ( $rows as $row ) {
				if ( ! in_array( $row['status'], array( 'Verified', 'Skipped' ), true ) ) {
					$set[ $row['component_type'] . '|' . $row['component_slug'] . '|' . $row['relative_path'] . '|' . $row['status'] ] = true;
				}
			} return $set;
		};
		$current  = $build( $now );
		$previous = $build( $before );
		return array(
			'new'       => count( array_diff_key( $current, $previous ) ),
			'resolved'  => count( array_diff_key( $previous, $current ) ),
			'unchanged' => count( array_intersect_key( $current, $previous ) ),
		);
	}
}
