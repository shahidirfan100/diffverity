<?php
/**
 * Shared exact-version official package handling.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Source;

use DiffVerity\Filesystem\Path_Guard;
use DiffVerity\Filesystem\Temp_Manager;
use DiffVerity\Http\WordPress_Org_Client;
use DiffVerity\Scanner\Manifest_Builder;
use DiffVerity\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Downloads, validates and extracts comparison packages without execution.
 */
abstract class Package_Source implements Source_Provider_Interface {
	/**
	 * Restricted WordPress.org client.
	 *
	 * @var WordPress_Org_Client
	 */
	protected $client;

	/** Constructor. */
	public function __construct() {
		$this->client = new WordPress_Org_Client();
	}

	/**
	 * Builds the exact official package URL.
	 *
	 * @param array $component Component.
	 * @return string
	 */
	abstract protected function package_url( array $component );

	/**
	 * Downloads and extracts an exact package into an owned temporary directory.
	 *
	 * @param array $component Component.
	 * @return array<string,string>|\WP_Error
	 */
	public function materialize( array $component ) {
		$temp = Temp_Manager::create_directory();
		if ( false === $temp ) {
			return new \WP_Error( 'diffverity_temp_failed', __( 'A secure temporary directory could not be created.', 'diffverity' ) );
		}
		$zip = $this->client->download_package( $this->package_url( $component ), $temp );
		if ( is_wp_error( $zip ) ) {
			Temp_Manager::remove_directory( $temp );
			return $zip;
		}
		if ( ! $this->archive_entries_are_safe( $zip ) ) {
			Temp_Manager::remove_directory( $temp );
			return new \WP_Error( 'diffverity_unsafe_archive', __( 'The official package contains an unsafe archive path.', 'diffverity' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		$extract = trailingslashit( $temp ) . 'source';
		if ( ! wp_mkdir_p( $extract ) ) {
			Temp_Manager::remove_directory( $temp );
			return new \WP_Error( 'diffverity_extract_directory', __( 'The package extraction directory could not be created.', 'diffverity' ) );
		}
		$result = unzip_file( $zip, $extract );
		if ( is_wp_error( $result ) ) {
			Temp_Manager::remove_directory( $temp );
			return $result;
		}
		$source_root = $this->find_package_root( $extract, $component );
		if ( false === $source_root || ! Path_Guard::inside( $extract, $source_root ) ) {
			Temp_Manager::remove_directory( $temp );
			return new \WP_Error( 'diffverity_package_layout', __( 'The official package has an unexpected directory layout.', 'diffverity' ) );
		}
		return array(
			'temp_root'   => $temp,
			'source_root' => $source_root,
		);
	}

	/**
	 * Generates a manifest from an exact official package.
	 *
	 * @param array $component Component.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_manifest( array $component ) {
		return $this->get_package_manifest( $component );
	}

	/**
	 * Generates a manifest from an exact official package.
	 *
	 * Exposed so checksum providers can make a precise package fallback.
	 *
	 * @param array $component Component.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_package_manifest( array $component ) {
		$materialized = $this->materialize( $component );
		if ( is_wp_error( $materialized ) ) {
			return $materialized;
		}
		$manifest = ( new Manifest_Builder() )->build( $materialized['source_root'], Plugin::settings(), false );
		return array(
			'files'       => $manifest,
			'algorithm'   => 'sha256',
			'source_root' => $materialized['source_root'],
			'temp_root'   => $materialized['temp_root'],
			'trust'       => $this->get_trust_level(),
		);
	}

	/**
	 * Locates the expected single component folder.
	 *
	 * @param string $extract Extract root.
	 * @param array  $component Component.
	 * @return string|false
	 */
	protected function find_package_root( $extract, array $component ) {
		$expected = realpath( trailingslashit( $extract ) . $component['slug'] );
		if ( false !== $expected && is_dir( $expected ) ) {
			return $expected;
		}
		$entries = array_values( array_filter( glob( trailingslashit( $extract ) . '*' ), 'is_dir' ) );
		return 1 === count( $entries ) ? realpath( $entries[0] ) : false;
	}

	/**
	 * Preflights archive entry names. WordPress extraction performs its own checks too.
	 *
	 * @param string $zip ZIP path.
	 * @return bool
	 */
	private function archive_entries_are_safe( $zip ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return true;
		}
		$archive = new \ZipArchive();
		if ( true !== $archive->open( $zip ) ) {
			return false;
		}
		for ( $index = 0; $index < $archive->numFiles; $index++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Third-party ZipArchive property.
			$name = $archive->getNameIndex( $index );
			if ( false === $name || '' === Path_Guard::normalize_relative( rtrim( $name, '/' ) ) || 0 === strpos( $name, '/' ) || preg_match( '#^[A-Za-z]:#', $name ) ) {
				$archive->close();
				return false;
			}
		}
		$archive->close();
		return true;
	}
}
