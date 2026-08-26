<?php
/**
 * Trusted source provider contract.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents a source of comparison evidence.
 */
interface Source_Provider_Interface {
	/**
	 * Gets trusted manifest data.
	 *
	 * @param array $component Server-resolved component.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_manifest( array $component );

	/**
	 * Gets the public trust label.
	 *
	 * @return string
	 */
	public function get_trust_level();

	/**
	 * Retrieves exact-version source files to a temporary root when supported.
	 *
	 * @param array $component Server-resolved component.
	 * @return array<string,string>|\WP_Error
	 */
	public function materialize( array $component );
}
