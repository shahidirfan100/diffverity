<?php
/**
 * Local baseline comparison source.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Source;

use DiffVerity\Storage\Baseline_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides administrator-created hashes without claiming authenticity.
 */
final class Baseline_Source implements Source_Provider_Interface {
	/**
	 * Gets the current local baseline manifest.
	 *
	 * @param array $component Resolved component.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_manifest( array $component ) {
		$baseline = ( new Baseline_Repository() )->current( $component['type'], $component['slug'] );
		if ( false === $baseline ) {
			return new \WP_Error( 'diffverity_no_baseline', __( 'No local baseline exists for this component.', 'diffverity' ) );
		}
		return array(
			'files'            => $baseline['files'],
			'algorithm'        => 'sha256',
			'source_root'      => '',
			'temp_root'        => '',
			'trust'            => $this->get_trust_level(),
			'baseline_version' => $baseline['component_version'],
			'baseline_id'      => (int) $baseline['id'],
		);
	}

	/**
	 * Gets the local baseline trust label.
	 *
	 * @return string
	 */
	public function get_trust_level() {
		return 'Local Baseline';
	}

	/**
	 * Explains that baseline source copies are intentionally unavailable.
	 *
	 * @param array $component Resolved component.
	 * @return \WP_Error
	 */
	public function materialize( array $component ) {
		return new \WP_Error( 'diffverity_baseline_hashes_only', __( 'Local baselines retain hashes and metadata, not source-code copies.', 'diffverity' ) );
	}
}
