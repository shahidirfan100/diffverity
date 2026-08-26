<?php
/**
 * Namespace autoloader.
 *
 * @package DiffVerity
 */

namespace DiffVerity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads plugin classes without Composer runtime dependencies.
 */
final class Autoloader {
	/**
	 * Registers the loader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Loads a DiffVerity class.
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return void
	 */
	public static function autoload( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );
		$folders  = array_map( 'strtolower', $parts );
		$file     = 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		$path     = DIFFVERITY_PATH . 'includes/' . ( $folders ? implode( '/', $folders ) . '/' : '' ) . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
