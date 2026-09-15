<?php
/**
 * Server-side component discovery and resolution.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Scanner;

use DiffVerity\Filesystem\Path_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces immutable identifiers instead of accepting browser paths.
 */
final class Component_Registry {
	/**
	 * Lists scan targets.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function all() {
		$components   = array();
		$components[] = array(
			'type'    => 'core',
			'slug'    => 'wordpress-core',
			'name'    => __( 'WordPress Core', 'diffverity-file-integrity-source-diff' ),
			'version' => get_bloginfo( 'version' ),
			'root'    => ABSPATH,
			'locale'  => get_locale(),
		);

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		foreach ( get_plugins() as $plugin_file => $data ) {
			$slug         = false !== strpos( $plugin_file, '/' )
				? dirname( $plugin_file )
				: ( ! empty( $data['TextDomain'] ) ? $data['TextDomain'] : basename( $plugin_file, '.php' ) );
			$root         = false !== strpos( $plugin_file, '/' ) ? WP_PLUGIN_DIR . '/' . dirname( $plugin_file ) : WP_PLUGIN_DIR;
			$components[] = array(
				'type'      => 'plugin',
				'slug'      => sanitize_key( $slug ),
				'name'      => isset( $data['Name'] ) ? wp_strip_all_tags( $data['Name'] ) : $slug,
				'version'   => isset( $data['Version'] ) ? sanitize_text_field( $data['Version'] ) : '',
				'root'      => $root,
				'entry'     => false !== strpos( $plugin_file, '/' ) ? '' : basename( $plugin_file ),
				'main_file' => basename( $plugin_file ),
				'locale'    => '',
			);
		}

		foreach ( wp_get_themes() as $slug => $theme ) {
			$components[] = array(
				'type'    => 'theme',
				'slug'    => sanitize_key( $slug ),
				'name'    => wp_strip_all_tags( $theme->get( 'Name' ) ),
				'version' => sanitize_text_field( $theme->get( 'Version' ) ),
				'root'    => $theme->get_stylesheet_directory(),
				'locale'  => '',
			);
		}
		return $components;
	}

	/**
	 * Re-resolves an identifier against the current installation.
	 *
	 * @param string $type Component type.
	 * @param string $slug Component slug.
	 * @return array<string,string>|false
	 */
	public function resolve( $type, $slug ) {
		foreach ( $this->all() as $component ) {
			if ( $component['type'] === $type && $component['slug'] === $slug ) {
				$root = realpath( $component['root'] );
				if ( false === $root ) {
					return false;
				}
				$allowed_root = 'core' === $type ? realpath( ABSPATH ) : ( 'plugin' === $type ? realpath( WP_PLUGIN_DIR ) : realpath( get_theme_root() ) );
				if ( false === $allowed_root || ( $root !== $allowed_root && ! Path_Guard::inside( $allowed_root, $root ) ) ) {
					return false;
				}
				$component['root'] = $root;
				return $component;
			}
		}
		return false;
	}
}
