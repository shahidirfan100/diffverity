<?php
/**
 * Restricted WordPress.org HTTP client.
 *
 * @package DiffVerity
 */

namespace DiffVerity\Http;

use DiffVerity\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes bounded HTTPS requests only to authoritative WordPress.org hosts.
 */
final class WordPress_Org_Client {
	/**
	 * Authoritative hosts that may receive requests.
	 *
	 * @var string[]
	 */
	private $allowed_hosts = array( 'api.wordpress.org', 'downloads.wordpress.org', 'core.svn.wordpress.org', 'wordpress.org' );

	/**
	 * Gets and validates a JSON document with a transient cache.
	 *
	 * @param string $url URL.
	 * @param string $cache_key Safe cache suffix.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_json( $url, $cache_key ) {
		$cache_key = 'diffverity_http_' . md5( $cache_key );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( ! $this->is_allowed_url( $url ) ) {
			return new \WP_Error( 'diffverity_host_not_allowed', __( 'The trusted-source host is not allowed.', 'diffverity' ) );
		}
		$settings = Plugin::settings();
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => (int) $settings['http_timeout'],
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => 8 * MB_IN_BYTES,
				'user-agent'          => 'DiffVerity/' . DIFFVERITY_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error( 'diffverity_http_status', sprintf( /* translators: %d: HTTP status code. */ __( 'Trusted source returned HTTP %d.', 'diffverity' ), $code ), array( 'status' => $code ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error( 'diffverity_malformed_json', __( 'Trusted source returned a malformed response.', 'diffverity' ) );
		}
		set_transient( $cache_key, $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Streams a bounded official package to an owned temporary file.
	 *
	 * @param string $url Official package URL.
	 * @param string $directory Owned temporary directory.
	 * @return string|\WP_Error
	 */
	public function download_package( $url, $directory ) {
		if ( ! $this->is_allowed_url( $url ) ) {
			return new \WP_Error( 'diffverity_host_not_allowed', __( 'The package host is not allowed.', 'diffverity' ) );
		}
		$settings = Plugin::settings();
		$file     = trailingslashit( $directory ) . 'source.zip';
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => (int) $settings['http_timeout'],
				'redirection'         => 0,
				'sslverify'           => true,
				'stream'              => true,
				'filename'            => $file,
				'limit_response_size' => (int) $settings['max_package_bytes'],
				'user-agent'          => 'DiffVerity/' . DIFFVERITY_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code || ! is_file( $file ) ) {
			return new \WP_Error( 'diffverity_package_unavailable', sprintf( /* translators: %d: HTTP status code. */ __( 'Official package returned HTTP %d.', 'diffverity' ), $code ), array( 'status' => $code ) );
		}
		$size = filesize( $file );
		if ( false === $size || $size <= 0 || $size > (int) $settings['max_package_bytes'] ) {
			return new \WP_Error( 'diffverity_package_size', __( 'The official package exceeds the configured size limit.', 'diffverity' ) );
		}
		return $file;
	}

	/**
	 * Streams one bounded official source file to a caller-owned destination.
	 *
	 * @param string $url Official source URL.
	 * @param string $destination Caller-owned destination.
	 * @param int    $max_bytes Maximum accepted bytes.
	 * @return string|\WP_Error
	 */
	public function download_source_file( $url, $destination, $max_bytes ) {
		if ( ! $this->is_allowed_url( $url ) ) {
			return new \WP_Error( 'diffverity_host_not_allowed', __( 'The source-file host is not allowed.', 'diffverity' ) );
		}
		$settings = Plugin::settings();
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => min( 12, (int) $settings['http_timeout'] ),
				'redirection'         => 0,
				'sslverify'           => true,
				'stream'              => true,
				'filename'            => $destination,
				'limit_response_size' => $max_bytes,
				'user-agent'          => 'DiffVerity/' . DIFFVERITY_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$size = is_file( $destination ) ? filesize( $destination ) : false;
		if ( 200 !== $code || false === $size || $size <= 0 || $size > $max_bytes ) {
			if ( is_file( $destination ) ) {
				wp_delete_file( $destination );
			}
			return new \WP_Error( 'diffverity_source_file_unavailable', sprintf( /* translators: %d: HTTP status code. */ __( 'Official source file returned HTTP %d or exceeded the size limit.', 'diffverity' ), $code ), array( 'status' => $code ) );
		}
		return $destination;
	}

	/**
	 * Validates scheme, host, credentials, query, and fragment.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public function is_allowed_url( $url ) {
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& 'https' === strtolower( $parts['scheme'] )
			&& in_array( strtolower( $parts['host'] ), $this->allowed_hosts, true )
			&& empty( $parts['user'] )
			&& empty( $parts['pass'] )
			&& empty( $parts['fragment'] );
	}
}
