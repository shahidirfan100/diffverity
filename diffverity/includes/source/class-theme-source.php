<?php
/**
 * Exact-version official theme package source.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds authoritative manifests from WordPress.org theme packages.
 */
final class Theme_Source extends Package_Source {
	/**
	 * Gets the package-derived source trust label.
	 *
	 * @return string
	 */
	public function get_trust_level() {
		return 'Official Source Available';
	}

	/**
	 * Builds the exact theme package URL.
	 *
	 * @param array $component Resolved theme component.
	 * @return string
	 */
	protected function package_url( array $component ) {
		return 'https://downloads.wordpress.org/theme/' . rawurlencode( $component['slug'] ) . '.' . rawurlencode( $component['version'] ) . '.zip';
	}
}
