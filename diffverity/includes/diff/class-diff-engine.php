<?php
/**
 * Safe interactive source comparison.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Diff;

use DiffVerity\Filesystem\File_Reader;
use DiffVerity\Filesystem\Temp_Manager;
use DiffVerity\Plugin;
use DiffVerity\Scanner\Component_Registry;
use DiffVerity\Source\Bundled_Component_Source;
use DiffVerity\Source\Core_Source;
use DiffVerity\Source\Plugin_Source;
use DiffVerity\Source\Theme_Source;
use DiffVerity\Storage\History_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces escaped text diffs or binary metadata without executing source.
 */
final class Diff_Engine {
	/**
	 * Compares one DB-resolved result.
	 *
	 * @param array $result Stored scan result.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function compare( array $result ) {
		if ( 'Local Baseline' === $result['trust_source'] ) {
			return new \WP_Error( 'diffverity_baseline_no_source', __( 'Local baselines store hashes and metadata, not source-code copies. An original-line diff is therefore unavailable.', 'diffverity' ) );
		}
		$component = ( new Component_Registry() )->resolve( $result['component_type'], $result['component_slug'] );
		if ( false === $component ) {
			return new \WP_Error( 'diffverity_component_missing', __( 'The component is no longer installed at the scanned location.', 'diffverity' ) );
		}
		if ( $component['version'] !== $result['component_version'] ) {
			return new \WP_Error( 'diffverity_version_changed', __( 'The installed component version changed after this scan. Run a new scan before viewing source differences.', 'diffverity' ) );
		}
		if ( 'Official Core Bundle' === $result['trust_source'] ) {
			if ( empty( $result['source_version'] ) ) {
				return new \WP_Error( 'diffverity_bundle_source_version_missing', __( 'This older scan did not record the originating WordPress bundle version. Run a new scan before viewing this difference.', 'diffverity' ) );
			}
			$scan = ( new History_Repository() )->get( (int) $result['scan_id'] );
			if ( false === $scan || (string) get_bloginfo( 'version' ) !== (string) $scan['wp_version'] ) {
				return new \WP_Error( 'diffverity_core_version_changed', __( 'The WordPress version changed after this scan. Run a new scan before viewing a core-bundle difference.', 'diffverity' ) );
			}
		}
		$settings = Plugin::settings();
		$limit    = (int) $settings['max_diff_bytes'];
		$source   = null;
		if ( 'Official Core Bundle' === $result['trust_source'] ) {
			$source_file = ( new Bundled_Component_Source() )->get_source_file( $component, $result['relative_path'], $result['source_version'], $result['trusted_hash'], $limit );
			if ( is_wp_error( $source_file ) ) {
				return $source_file;
			}
			$source_root = dirname( $source_file );
			$source_path = basename( $source_file );
		} else {
			$provider = 'core' === $component['type'] ? new Core_Source() : ( 'theme' === $component['type'] ? new Theme_Source() : new Plugin_Source() );
			$source   = $provider->materialize( $component );
			if ( is_wp_error( $source ) ) {
				return $source;
			}
			$source_root = $source['source_root'];
			$source_path = $result['relative_path'];
		}
		try {
			$local    = File_Reader::contents( $component['root'], $result['relative_path'], $limit );
			$trusted  = File_Reader::contents( $source_root, $source_path, $limit );
			if ( false === $local || false === $trusted ) {
				return array(
					'kind'    => 'metadata',
					'message' => __( 'The file differs, but line diff is unavailable because a file is missing, unreadable, binary-sized, or exceeds the configured diff limit.', 'diffverity' ),
					'result'  => $result,
				);
			}
			if ( ! File_Reader::is_text( $local ) || ! File_Reader::is_text( $trusted ) ) {
				return array(
					'kind'    => 'binary',
					'message' => __( 'Binary files are compared by hash and size; their bytes are never rendered as HTML.', 'diffverity' ),
					'result'  => $result,
				);
			}
			require_once ABSPATH . WPINC . '/wp-diff.php';
			$trusted_for_diff = '' !== $trusted && "\n" !== substr( $trusted, -1 ) ? $trusted . "\n" : $trusted;
			$local_for_diff   = '' !== $local && "\n" !== substr( $local, -1 ) ? $local . "\n" : $local;
			$diff             = wp_text_diff(
				$trusted_for_diff,
				$local_for_diff,
				array(
					'title_left'      => __( 'Original / Trusted Source', 'diffverity' ),
					'title_right'     => __( 'Local File', 'diffverity' ),
					'show_split_view' => true,
				)
			);
			if ( '' === trim( (string) $diff ) && ! hash_equals( hash( 'sha256', $trusted ), hash( 'sha256', $local ) ) ) {
				return array(
					'kind'    => 'metadata',
					'message' => __( 'The byte hashes differ, but WordPress could not render a visible line-level change. This commonly indicates whitespace, encoding, line-termination, or a single-line minified-file difference. The hash evidence remains valid; use the recorded hashes or restore the official source if appropriate.', 'diffverity' ),
					'result'  => $result,
				);
			}
			$allowed = array(
				'table'    => array( 'class' => true ),
				'colgroup' => array(),
				'col'      => array( 'class' => true ),
				'tbody'    => array(),
				'tr'       => array(),
				'td'       => array( 'class' => true ),
				'th'       => array( 'scope' => true ),
				'del'      => array(),
				'ins'      => array(),
				'span'     => array( 'class' => true ),
			);
			return array(
				'kind'   => 'text',
				'html'   => wp_kses( (string) $diff, $allowed ),
				'result' => $result,
			);
		} finally {
			if ( is_array( $source ) && ! empty( $source['temp_root'] ) ) {
				Temp_Manager::remove_directory( $source['temp_root'] );
			}
		}
	}
}
