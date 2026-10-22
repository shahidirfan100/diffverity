<?php
/**
 * Capability policy.
 *
 * @package DiffVerity
 */

namespace DiffVerity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralizes privileged access decisions.
 */
final class Capabilities {
	/**
	 * Gets the required capability for this installation context.
	 *
	 * @return string
	 */
	public static function required() {
		$capability = is_multisite() && is_network_admin() ? 'manage_network_options' : 'manage_options';

		/**
		 * Filters the capability needed to use DiffVerity.
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'diffverity_required_capability', $capability );
	}

	/**
	 * Whether the current user may inspect integrity data.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return current_user_can( self::required() );
	}

	/**
	 * Stops unauthorized requests.
	 *
	 * @return void
	 */
	public static function enforce() {
		if ( ! self::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to access DiffVerity integrity data.', 'diffverity' ), 403 );
		}
	}
}
