<?php
/**
 * Resumable bounded scan orchestration.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Scanner;

use DiffVerity\Filesystem\File_Reader;
use DiffVerity\Filesystem\Temp_Manager;
use DiffVerity\Plugin;
use DiffVerity\Source\Baseline_Source;
use DiffVerity\Source\Bundled_Component_Source;
use DiffVerity\Source\Core_Source;
use DiffVerity\Source\Plugin_Source;
use DiffVerity\Source\Theme_Source;
use DiffVerity\Storage\Baseline_Repository;
use DiffVerity\Storage\History_Repository;
use DiffVerity\Storage\Results_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists scan state between authenticated admin batches.
 */
final class Scan_Manager {
	const LOCK_TTL = 1800;

	/**
	 * Scan summary persistence.
	 *
	 * @var History_Repository
	 */
	private $history;
	/**
	 * File result persistence.
	 *
	 * @var Results_Repository
	 */
	private $results;

	/** Constructor. */
	public function __construct() {
		$this->history = new History_Repository();
		$this->results = new Results_Repository();
	}

	/**
	 * Starts a full scan or one explicitly selected component scan.
	 *
	 * @param string $component_type Optional component type.
	 * @param string $component_slug Optional component slug.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function start( $component_type = '', $component_slug = '' ) {
		$lock = get_transient( 'diffverity_scan_lock' );
		if ( is_array( $lock ) && ! empty( $lock['scan_id'] ) ) {
			return new \WP_Error( 'diffverity_scan_locked', __( 'Another integrity scan is already running.', 'diffverity' ), $lock );
		}
		$settings       = Plugin::settings();
		$all_components = array_values(
			array_filter(
				( new Component_Registry() )->all(),
				static function ( $component ) use ( $settings ) {
					return ! in_array( $component['type'] . ':' . $component['slug'], (array) $settings['excluded_components'], true );
				}
			)
		);
		$components     = $all_components;
		if ( '' !== $component_type || '' !== $component_slug ) {
			if ( ! in_array( $component_type, array( 'core', 'plugin', 'theme' ), true ) || '' === $component_slug ) {
				return new \WP_Error( 'diffverity_scan_component_invalid', __( 'The selected scan component is invalid.', 'diffverity' ) );
			}
			$selected = ( new Component_Registry() )->resolve( $component_type, $component_slug );
			if ( false === $selected ) {
				return new \WP_Error( 'diffverity_scan_component_missing', __( 'The selected component is no longer installed or cannot be resolved safely.', 'diffverity' ) );
			}
			$key = $selected['type'] . ':' . $selected['slug'];
			if ( in_array( $key, (array) $settings['excluded_components'], true ) ) {
				return new \WP_Error( 'diffverity_scan_component_excluded', __( 'The selected component is excluded in DiffVerity settings.', 'diffverity' ) );
			}
			$components = array( $selected );
		}
		$scan_id = $this->history->create( count( $components ) );
		if ( ! $scan_id ) {
			return new \WP_Error( 'diffverity_scan_create_failed', __( 'The scan record could not be created.', 'diffverity' ) );
		}
		$state = array(
			'scan_id'   => $scan_id,
			'started'   => microtime( true ),
			'queue'     => array_map(
				static function ( $component ) {
					return array(
						'type' => $component['type'],
						'slug' => $component['slug'],
					);
				},
				$components
			),
			'processed' => 0,
			'current'   => null,
			'cancelled' => false,
		);
		$this->save_state( $state );
		set_transient(
			'diffverity_scan_lock',
			array(
				'scan_id' => $scan_id,
				'user_id' => get_current_user_id(),
			),
			self::LOCK_TTL
		);
		return $this->public_progress( $state );
	}

	/**
	 * Processes at most the configured number of files.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function step( $scan_id ) {
		$state = $this->load_state( $scan_id );
		if ( false === $state ) {
			$lock = get_transient( 'diffverity_scan_lock' );
			if ( is_array( $lock ) && isset( $lock['scan_id'] ) && (int) $lock['scan_id'] === (int) $scan_id ) {
				delete_transient( 'diffverity_scan_lock' );
			}
			return new \WP_Error( 'diffverity_scan_state_missing', __( 'The scan state is missing or expired.', 'diffverity' ) );
		}
		if ( ! empty( $state['cancelled'] ) ) {
			return $this->cancel( $scan_id );
		}
		set_transient(
			'diffverity_scan_lock',
			array(
				'scan_id' => $scan_id,
				'user_id' => get_current_user_id(),
			),
			self::LOCK_TTL
		);

		if ( null === $state['current'] ) {
			if ( ! $state['queue'] ) {
				return $this->complete( $state );
			}
			$identifier = array_shift( $state['queue'] );
			$prepared   = $this->prepare_component( $identifier, $scan_id );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}
			if ( ! empty( $prepared['terminal'] ) ) {
				++$state['processed'];
				$this->history->progress( $scan_id, $state['processed'], '' );
				$this->save_state( $state );
				return $this->public_progress( $state );
			}
			$state['current'] = $prepared;
		}

		$current  = $state['current'];
		$settings = Plugin::settings();
		$limit    = max( 25, min( 500, (int) $settings['files_per_batch'] ) );
		$paths    = array_slice( $current['paths'], (int) $current['cursor'], $limit );
		$rows     = array();

		if ( ! $settings['strict_comparison'] && ! $current['source_root'] && $this->batch_may_need_normalized_source( $current, $paths ) ) {
			$materialized = $this->materialize_source( $current['component'] );
			if ( ! is_wp_error( $materialized ) ) {
				$current['source_root'] = $materialized['source_root'];
				$current['temp_root']   = $materialized['temp_root'];
			}
		}

		$classifier = new File_Classifier();
		foreach ( $paths as $path ) {
			$local   = isset( $current['local'][ $path ] ) ? $current['local'][ $path ] : null;
			$trusted = isset( $current['trusted'][ $path ] ) ? $current['trusted'][ $path ] : null;
			if ( is_array( $trusted ) && $current['source_root'] ) {
				$package_metadata = File_Reader::metadata( $current['source_root'], $path, 'sha256' );
				if ( is_array( $package_metadata ) ) {
					$trusted['package_hash'] = $package_metadata['hash'];
					$trusted['package_size'] = $package_metadata['size'];
				}
				if ( empty( $trusted['normalized_hash'] ) ) {
					$source_data = File_Reader::contents( $current['source_root'], $path, (int) $settings['max_diff_bytes'] );
					if ( is_string( $source_data ) && File_Reader::is_text( $source_data ) ) {
						$trusted['normalized_hash'] = hash( 'sha256', \DiffVerity\Diff\Normalizer::text( $source_data ) );
						$trusted['size']            = strlen( $source_data );
					}
				}
			}
			$status = $classifier->classify( $local, $trusted, $current['trust'], $current['algorithm'], ! empty( $settings['strict_comparison'] ), ! empty( $current['version_changed'] ) );
			$rows[] = $this->result_row( $scan_id, $current['component'], $path, $local, $trusted, $current['trust'], $status );
		}
		if ( ! $this->results->insert_batch( $rows ) ) {
			return new \WP_Error( 'diffverity_result_write_failed', __( 'The scan batch results could not be stored.', 'diffverity' ) );
		}

		$current['cursor'] += count( $paths );
		if ( $current['cursor'] >= count( $current['paths'] ) ) {
			$this->cleanup_current( $current );
			$state['current'] = null;
			++$state['processed'];
			$this->history->progress( $scan_id, $state['processed'], '' );
		} else {
			$state['current'] = $current;
			$this->history->progress( $scan_id, $state['processed'], $current['component']['name'] );
		}
		$this->save_state( $state );
		return $this->public_progress( $state );
	}

	/**
	 * Cancels a scan and cleans its temporary package.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function cancel( $scan_id ) {
		$state = $this->load_state( $scan_id );
		if ( false === $state ) {
			return new \WP_Error( 'diffverity_scan_state_missing', __( 'The scan state is missing or expired.', 'diffverity' ) );
		}
		if ( is_array( $state['current'] ) ) {
			$this->cleanup_current( $state['current'] );
		}
		$this->history->finish( $scan_id, 'cancelled', $state['started'] );
		delete_option( $this->state_key( $scan_id ) );
		delete_transient( 'diffverity_scan_lock' );
		return array(
			'scan_id' => $scan_id,
			'status'  => 'cancelled',
		);
	}

	/**
	 * Prepares one component and its source evidence.
	 *
	 * @param array $identifier Server-side component identifier.
	 * @param int   $scan_id Scan ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function prepare_component( array $identifier, $scan_id ) {
		$component = ( new Component_Registry() )->resolve( $identifier['type'], $identifier['slug'] );
		if ( false === $component ) {
			$this->results->insert_batch( array( $this->source_failure_row( $scan_id, $identifier, 'Not Verifiable', __( 'The component could not be resolved inside an approved WordPress directory.', 'diffverity' ) ) ) );
			return array( 'terminal' => true );
		}
		$source   = $this->source_for( $component );
		$manifest = $source->get_manifest( $component );
		if ( is_wp_error( $manifest ) && 'plugin' === $component['type'] && $this->is_not_found( $manifest ) ) {
			$manifest = $source->get_package_manifest( $component );
			if ( ! is_wp_error( $manifest ) ) {
				$manifest['trust'] = 'Official Source Available';
			}
		}
		if ( is_wp_error( $manifest ) ) {
			$baseline = ( new Baseline_Source() )->get_manifest( $component );
			if ( ! is_wp_error( $baseline ) ) {
				$manifest = $baseline;
			} else {
				$trust = $this->is_not_found( $manifest ) ? 'No Trusted Source' : 'Not Verifiable';
				$this->results->insert_batch( array( $this->source_failure_row( $scan_id, $component, $trust, $manifest->get_error_message() ) ) );
				return array( 'terminal' => true );
			}
		}
		if ( 'plugin' === $component['type'] && ! empty( $component['entry'] ) ) {
			$entry             = $component['main_file'];
			$manifest['files'] = isset( $manifest['files'][ $entry ] ) ? array( $entry => $manifest['files'][ $entry ] ) : array();
		}

		$settings = Plugin::settings();
		if ( ! empty( $component['entry'] ) ) {
			$local    = array();
			$metadata = File_Reader::metadata( $component['root'], $component['entry'], 'sha256' );
			if ( is_array( $metadata ) ) {
				$metadata['md5']              = hash_file( 'md5', $component['root'] . '/' . $component['entry'] );
				$local[ $component['entry'] ] = $metadata;
			}
		} else {
			$local = ( new Manifest_Builder() )->build( $component['root'], $settings, 'core' === $component['type'] );
		}
		if ( 'core' === $component['type'] ) {
			foreach ( $manifest['files'] as $path => $unused ) {
				if ( ! isset( $local[ $path ] ) && 0 !== strpos( $path, 'wp-admin/' ) && 0 !== strpos( $path, 'wp-includes/' ) ) {
					$metadata = File_Reader::metadata( $component['root'], $path, 'sha256' );
					if ( is_array( $metadata ) ) {
						$metadata['md5'] = hash_file( 'md5', $component['root'] . '/' . $path );
						$local[ $path ]  = $metadata;
					}
				}
			}
		}
		if ( in_array( $component['type'], array( 'plugin', 'theme' ), true ) && 'Local Baseline' !== $manifest['trust'] ) {
			$header_file          = 'theme' === $component['type'] ? 'style.css' : $component['main_file'];
			$header_trusted       = isset( $manifest['files'][ $header_file ] ) ? $manifest['files'][ $header_file ] : array();
			$header_algorithm     = isset( $header_trusted['algorithm'] ) ? $header_trusted['algorithm'] : $manifest['algorithm'];
			$local_header_hash    = 'md5' === $header_algorithm && isset( $local[ $header_file ]['md5'] ) ? $local[ $header_file ]['md5'] : ( isset( $local[ $header_file ]['hash'] ) ? $local[ $header_file ]['hash'] : '' );
			$primary_header_match = '' !== $local_header_hash && $this->trusted_accepts_hash( $header_trusted, $local_header_hash );
			$bundled_evidence     = ( new Bundled_Component_Source() )->get_evidence( $component, $local, $primary_header_match );
			foreach ( $bundled_evidence['versions'] as $bundle_version => $bundled_hashes ) {
				foreach ( $bundled_hashes as $path => $hash ) {
					if ( ! isset( $manifest['files'][ $path ] ) && ( isset( $local[ $path ] ) || $bundle_version === $bundled_evidence['preferred_version'] ) ) {
						$manifest['files'][ $path ] = array(
							'hash' => '',
							'size' => 0,
						);
					}
					if ( isset( $manifest['files'][ $path ] ) ) {
						$manifest['files'][ $path ]['bundled_hashes'][ $hash ] = $bundle_version;
						if ( $bundle_version === $bundled_evidence['preferred_version'] ) {
							$manifest['files'][ $path ]['preferred_bundle_hash']    = $hash;
							$manifest['files'][ $path ]['preferred_bundle_version'] = $bundle_version;
						}
					}
				}
			}
		}
		$paths = array_values( array_unique( array_merge( array_keys( $local ), array_keys( $manifest['files'] ) ) ) );
		sort( $paths, SORT_STRING );
		return array(
			'terminal'        => false,
			'component'       => $component,
			'local'           => $local,
			'trusted'         => $manifest['files'],
			'paths'           => $paths,
			'cursor'          => 0,
			'algorithm'       => $manifest['algorithm'],
			'trust'           => $manifest['trust'],
			'source_root'     => isset( $manifest['source_root'] ) ? $manifest['source_root'] : '',
			'temp_root'       => isset( $manifest['temp_root'] ) ? $manifest['temp_root'] : '',
			'version_changed' => ! empty( $manifest['baseline_version'] ) && $manifest['baseline_version'] !== $component['version'],
		);
	}

	/**
	 * Returns a provider for a resolved component.
	 *
	 * @param array $component Resolved component.
	 * @return Core_Source|Theme_Source|Plugin_Source
	 */
	private function source_for( array $component ) {
		if ( 'core' === $component['type'] ) {
			return new Core_Source();
		}
		if ( 'theme' === $component['type'] ) {
			return new Theme_Source();
		}
		return new Plugin_Source();
	}

	/**
	 * Materializes exact official source for soft comparison or diffs.
	 *
	 * @param array $component Resolved component.
	 * @return array<string,string>|\WP_Error
	 */
	private function materialize_source( array $component ) {
		return $this->source_for( $component )->materialize( $component );
	}

	/**
	 * Whether the current batch contains a textual raw mismatch.
	 *
	 * @param array $current Current component state.
	 * @param array $paths Batch paths.
	 * @return bool
	 */
	private function batch_may_need_normalized_source( array $current, array $paths ) {
		if ( 'Local Baseline' === $current['trust'] ) {
			return false;
		}
		foreach ( $paths as $path ) {
			if ( isset( $current['local'][ $path ], $current['trusted'][ $path ] ) && ! empty( $current['local'][ $path ]['normalized_hash'] ) ) {
				$file_algorithm = isset( $current['trusted'][ $path ]['algorithm'] ) ? $current['trusted'][ $path ]['algorithm'] : $current['algorithm'];
				$local_hash     = 'md5' === $file_algorithm ? $current['local'][ $path ]['md5'] : $current['local'][ $path ]['hash'];
				if ( ! $this->trusted_accepts_hash( $current['trusted'][ $path ], $local_hash ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Checks one local hash against every checksum accepted by the source.
	 *
	 * @param array  $trusted Trusted file evidence.
	 * @param string $local_hash Local hash in the trusted algorithm.
	 * @return bool
	 */
	private function trusted_accepts_hash( array $trusted, $local_hash ) {
		$hashes = ! empty( $trusted['hashes'] ) && is_array( $trusted['hashes'] ) ? $trusted['hashes'] : ( isset( $trusted['hash'] ) ? array( $trusted['hash'] ) : array() );
		foreach ( $hashes as $hash ) {
			if ( is_scalar( $hash ) && hash_equals( strtolower( (string) $hash ), strtolower( (string) $local_hash ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Builds a normalized database result row.
	 *
	 * @param int        $scan_id Scan ID.
	 * @param array      $component Resolved component.
	 * @param string     $path Relative path.
	 * @param array|null $local Local metadata.
	 * @param array|null $trusted Trusted metadata.
	 * @param string     $trust Trust label.
	 * @param array      $status Classified status.
	 * @return array<string,mixed>
	 */
	private function result_row( $scan_id, array $component, $path, $local, $trusted, $trust, array $status ) {
		return array(
			'scan_id'           => $scan_id,
			'component_type'    => $component['type'],
			'component_slug'    => $component['slug'],
			'component_name'    => $component['name'],
			'component_version' => $component['version'],
			'relative_path'     => $path,
			'status'            => $status['status'],
			'trust_source'      => isset( $status['trust_source'] ) ? $status['trust_source'] : $trust,
			'source_version'    => isset( $status['source_version'] ) ? $status['source_version'] : '',
			'local_hash'        => isset( $status['local_hash'] ) ? $status['local_hash'] : ( is_array( $local ) && isset( $local['hash'] ) ? $local['hash'] : '' ),
			'trusted_hash'      => isset( $status['trusted_hash'] ) ? $status['trusted_hash'] : ( is_array( $trusted ) && isset( $trusted['hash'] ) ? $trusted['hash'] : '' ),
			'local_size'        => is_array( $local ) && isset( $local['size'] ) ? $local['size'] : 0,
			'trusted_size'      => isset( $status['trusted_size'] ) ? $status['trusted_size'] : ( is_array( $trusted ) && isset( $trusted['size'] ) ? $trusted['size'] : 0 ),
			'severity'          => $status['severity'],
			'message'           => $status['message'],
		);
	}

	/**
	 * Builds a component-level unavailable result.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param array  $component Component metadata.
	 * @param string $trust Trust label.
	 * @param string $message Failure explanation.
	 * @return array<string,mixed>
	 */
	private function source_failure_row( $scan_id, array $component, $trust, $message ) {
		$component = wp_parse_args(
			$component,
			array(
				'type'    => '',
				'slug'    => '',
				'name'    => '',
				'version' => '',
			)
		);
		return $this->result_row(
			$scan_id,
			$component,
			'',
			null,
			null,
			$trust,
			array(
				'status'   => 'Source Unavailable',
				'severity' => 'informational',
				'message'  => $message,
			)
		);
	}

	/**
	 * Detects authoritative 404 responses.
	 *
	 * @param \WP_Error $error Source error.
	 * @return bool
	 */
	private function is_not_found( \WP_Error $error ) {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) && 404 === (int) $data['status'];
	}

	/**
	 * Cleans the source package owned by the current component.
	 *
	 * @param array $current Current component state.
	 * @return void
	 */
	private function cleanup_current( array $current ) {
		if ( ! empty( $current['temp_root'] ) ) {
			Temp_Manager::remove_directory( $current['temp_root'] );
		}
	}

	/**
	 * Finalizes a scan.
	 *
	 * @param array $state Scan state.
	 * @return array<string,mixed>
	 */
	private function complete( array $state ) {
		$this->history->finish( $state['scan_id'], 'complete', $state['started'] );
		delete_option( $this->state_key( $state['scan_id'] ) );
		delete_transient( 'diffverity_scan_lock' );
		$state['status'] = 'complete';
		$state['counts'] = $this->results->counts( $state['scan_id'] );
		return $this->public_progress( $state );
	}

	/**
	 * Returns browser-safe progress only.
	 *
	 * @param array $state Scan state.
	 * @return array<string,mixed>
	 */
	private function public_progress( array $state ) {
		$current = is_array( $state['current'] ) ? $state['current'] : array();
		return array(
			'scan_id'              => (int) $state['scan_id'],
			'status'               => isset( $state['status'] ) ? $state['status'] : 'running',
			'processed_components' => (int) $state['processed'],
			'total_components'     => (int) $state['processed'] + count( $state['queue'] ) + ( $current ? 1 : 0 ),
			'current_component'    => $current ? $current['component']['name'] : '',
			'processed_files'      => $current ? (int) $current['cursor'] : 0,
			'total_files'          => $current ? count( $current['paths'] ) : 0,
			'counts'               => isset( $state['counts'] ) ? $state['counts'] : array(),
		);
	}

	/**
	 * Builds the state option key.
	 *
	 * @param int $scan_id Scan ID.
	 * @return string
	 */
	private function state_key( $scan_id ) {
		return 'diffverity_scan_state_' . absint( $scan_id );
	}

	/**
	 * Persists non-autoloaded scan state.
	 *
	 * @param array $state Scan state.
	 * @return void
	 */
	private function save_state( array $state ) {
		update_option( $this->state_key( $state['scan_id'] ), $state, false );
	}

	/**
	 * Loads scan state.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array<string,mixed>|false
	 */
	private function load_state( $scan_id ) {
		$state = get_option( $this->state_key( $scan_id ), false );
		return is_array( $state ) && (int) $state['scan_id'] === (int) $scan_id ? $state : false;
	}
}
