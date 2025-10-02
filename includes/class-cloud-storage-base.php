<?php
/**
 * Cloud Storage Base Class
 *
 * @package ListenUp
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for cloud storage providers.
 *
 * @since 1.0.0
 */
abstract class ListenUp_Cloud_Storage_Base implements ListenUp_Cloud_Storage_Interface {
	/**
	 * Storage configuration.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	protected $config;

	/**
	 * Debug logger instance.
	 *
	 * @since 1.0.0
	 * @var ListenUp_Debug
	 */
	protected $debug;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param array $config Storage configuration.
	 */
	public function __construct( $config ) {
		$this->config = $config;
		$this->debug = ListenUp_Debug::get_instance();
	}

	/**
	 * Generate a unique remote path for a file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $filename Original filename.
	 * @param string $prefix Optional prefix for the path.
	 * @return string Remote path.
	 */
	public function generate_remote_path( $filename, $prefix = 'listenup-audio' ) {
		// Get site slug for multi-site organization.
		$site_name = get_bloginfo( 'name' );
		$site_slug = sanitize_title( $site_name );
		
		// Fallback to domain if site name is empty.
		if ( empty( $site_slug ) ) {
			$site_url = get_site_url();
			$parsed_url = wp_parse_url( $site_url );
			$site_slug = isset( $parsed_url['host'] ) ? sanitize_title( $parsed_url['host'] ) : 'default';
		}
		
		$timestamp = current_time( 'Y/m/d' );
		$extension = pathinfo( $filename, PATHINFO_EXTENSION );
		$basename = pathinfo( $filename, PATHINFO_FILENAME );
		
		// Generate a deterministic unique ID based on the filename and timestamp.
		// This ensures the same file always gets the same path.
		$unique_id = substr( md5( $filename . $timestamp ), 0, 8 );
		
		return sprintf( '%s/%s/%s/%s_%s.%s', $prefix, $site_slug, $timestamp, $basename, $unique_id, $extension );
	}

	/**
	 * Validate file before upload.
	 *
	 * @since 1.0.0
	 *
	 * @param string $local_path Local file path.
	 * @return bool|WP_Error True if valid, or error.
	 */
	protected function validate_file( $local_path ) {
		if ( ! file_exists( $local_path ) ) {
			return new WP_Error( 'file_not_found', __( 'File not found.', 'listenup' ) );
		}

		if ( ! is_readable( $local_path ) ) {
			return new WP_Error( 'file_not_readable', __( 'File is not readable.', 'listenup' ) );
		}

		$file_size = filesize( $local_path );
		if ( false === $file_size || $file_size === 0 ) {
			return new WP_Error( 'file_empty', __( 'File is empty.', 'listenup' ) );
		}

		// Check file size limit (100MB).
		$max_size = 100 * 1024 * 1024;
		if ( $file_size > $max_size ) {
			return new WP_Error( 'file_too_large', __( 'File is too large for upload.', 'listenup' ) );
		}

		return true;
	}

	/**
	 * Log storage operations.
	 *
	 * @since 1.0.0
	 *
	 * @param string $operation Operation name.
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 */
	protected function log_operation( $operation, $message, $context = array() ) {
		$this->debug->info( $message, array_merge( $context, array(
			'provider' => $this->get_provider_name(),
			'operation' => $operation,
		) ) );
	}

	/**
	 * Log storage errors.
	 *
	 * @since 1.0.0
	 *
	 * @param string $operation Operation name.
	 * @param string $message Error message.
	 * @param array  $context Additional context.
	 */
	protected function log_error( $operation, $message, $context = array() ) {
		$this->debug->error( $message, array_merge( $context, array(
			'provider' => $this->get_provider_name(),
			'operation' => $operation,
		) ) );
	}

	/**
	 * Check if a file exists in cloud storage.
	 * Base implementation - should be overridden by providers.
	 *
	 * @since 1.0.0
	 *
	 * @param string $remote_path Remote file path/key.
	 * @return bool|WP_Error True if exists, false if not, or error.
	 */
	public function file_exists( $remote_path ) {
		return new WP_Error( 'not_implemented', __( 'File existence check not implemented for this provider.', 'listenup' ) );
	}

	/**
	 * Get the public URL for a file.
	 * Base implementation - should be overridden by providers.
	 *
	 * @since 1.0.0
	 *
	 * @param string $remote_path Remote file path/key.
	 * @return string|WP_Error Public URL or error.
	 */
	public function get_public_url( $remote_path ) {
		return new WP_Error( 'not_implemented', __( 'Public URL generation not implemented for this provider.', 'listenup' ) );
	}
}
