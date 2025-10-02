<?php
/**
 * Cloud Storage Interface
 *
 * @package ListenUp
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for cloud storage providers.
 *
 * @since 1.0.0
 */
interface ListenUp_Cloud_Storage_Interface {
	/**
	 * Upload a file to cloud storage.
	 *
	 * @since 1.0.0
	 *
	 * @param string $local_path Local file path.
	 * @param string $remote_path Remote file path/key.
	 * @return array|WP_Error Upload result with URL and metadata, or error.
	 */
	public function upload_file( $local_path, $remote_path );

	/**
	 * Delete a file from cloud storage.
	 *
	 * @since 1.0.0
	 *
	 * @param string $remote_path Remote file path/key.
	 * @return bool|WP_Error True on success, or error.
	 */
	public function delete_file( $remote_path );

	/**
	 * Check if a file exists in cloud storage.
	 *
	 * @since 1.0.0
	 *
	 * @param string $remote_path Remote file path/key.
	 * @return bool|WP_Error True if exists, false if not, or error.
	 */
	public function file_exists( $remote_path );

	/**
	 * Get the public URL for a file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $remote_path Remote file path/key.
	 * @return string|WP_Error Public URL or error.
	 */
	public function get_public_url( $remote_path );

	/**
	 * Test the connection to cloud storage.
	 *
	 * @since 1.0.0
	 *
	 * @return bool|WP_Error True if connection successful, or error.
	 */
	public function test_connection();

	/**
	 * Get the provider name.
	 *
	 * @since 1.0.0
	 *
	 * @return string Provider name.
	 */
	public function get_provider_name();
}
