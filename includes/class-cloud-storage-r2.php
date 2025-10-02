<?php
/**
 * Cloudflare R2 Cloud Storage Provider
 *
 * @package ListenUp
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare R2 cloud storage provider.
 *
 * @since 1.0.0
 */
class ListenUp_Cloud_Storage_R2 extends ListenUp_Cloud_Storage_Base {
	/**
	 * S3 client instance (R2 is S3-compatible).
	 *
	 * @since 1.0.0
	 * @var Aws\S3\S3Client|null
	 */
	private $client;

	/**
	 * Initialize the R2 client.
	 *
	 * @since 1.0.0
	 *
	 * @return bool|WP_Error True if successful, or error.
	 */
	private function init_client() {
		if ( null !== $this->client ) {
			return true;
		}

		// Check if AWS SDK is available.
		if ( ! class_exists( 'Aws\S3\S3Client' ) ) {
			return new WP_Error( 'aws_sdk_missing', __( 'AWS SDK is not available. Please install aws/aws-sdk-php.', 'listenup' ) );
		}

		try {
			$this->client = new Aws\S3\S3Client( array(
				'version' => 'latest',
				'region'  => 'auto', // R2 uses 'auto' region.
				'endpoint' => $this->config['endpoint'],
				'credentials' => array(
					'key'    => $this->config['access_key'],
					'secret' => $this->config['secret_key'],
				),
				'use_path_style_endpoint' => true,
			) );
		} catch ( Exception $e ) {
			$this->log_error( 'init_client', 'Failed to initialize R2 client', array(
				'error' => $e->getMessage(),
			) );
			return new WP_Error( 'r2_client_init_failed', $e->getMessage() );
		}

		return true;
	}

	/**
	 * Upload a file to R2.
	 *
	 * @since 1.0.0
	 *
	 * @param string $local_path Local file path.
	 * @param string $remote_path Remote file path/key.
	 * @return array|WP_Error Upload result with URL and metadata, or error.
	 */
	public function upload_file( $local_path, $remote_path ) {
		$init_result = $this->init_client();
		if ( is_wp_error( $init_result ) ) {
			return $init_result;
		}

		$validation = $this->validate_file( $local_path );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$this->log_operation( 'upload_file', 'Starting R2 upload', array(
			'local_path' => $local_path,
			'remote_path' => $remote_path,
			'file_size' => filesize( $local_path ),
		) );

		try {
			$result = $this->client->putObject( array(
				'Bucket' => $this->config['bucket'],
				'Key'    => $remote_path,
				'SourceFile' => $local_path,
				'ContentType' => 'audio/wav',
				'ACL' => 'public-read',
			) );

			$public_url = $this->get_public_url( $remote_path );

			$this->log_operation( 'upload_file', 'R2 upload completed', array(
				'remote_path' => $remote_path,
				'public_url' => $public_url,
				'etag' => $result['ETag'],
			) );

			return array(
				'url' => $public_url,
				'key' => $remote_path,
				'etag' => $result['ETag'],
				'size' => filesize( $local_path ),
			);

		} catch ( Exception $e ) {
			$this->log_error( 'upload_file', 'R2 upload failed', array(
				'error' => $e->getMessage(),
				'remote_path' => $remote_path,
			) );
			return new WP_Error( 'r2_upload_failed', $e->getMessage() );
		}
	}

	/**
	 * Delete a file from R2.
	 *
	 * @since 1.0.0
	 *
	 * @param string $remote_path Remote file path/key.
	 * @return bool|WP_Error True on success, or error.
	 */
	public function delete_file( $remote_path ) {
		$init_result = $this->init_client();
		if ( is_wp_error( $init_result ) ) {
			return $init_result;
		}

		$this->log_operation( 'delete_file', 'Starting R2 delete', array(
			'remote_path' => $remote_path,
		) );

		try {
			$this->client->deleteObject( array(
				'Bucket' => $this->config['bucket'],
				'Key'    => $remote_path,
			) );

			$this->log_operation( 'delete_file', 'R2 delete completed', array(
				'remote_path' => $remote_path,
			) );

			return true;

		} catch ( Exception $e ) {
			$this->log_error( 'delete_file', 'R2 delete failed', array(
				'error' => $e->getMessage(),
				'remote_path' => $remote_path,
			) );
			return new WP_Error( 'r2_delete_failed', $e->getMessage() );
		}
	}

	/**
	 * Check if a file exists in R2.
	 *
	 * @since 1.0.0
	 *
	 * @param string $remote_path Remote file path/key.
	 * @return bool|WP_Error True if exists, false if not, or error.
	 */
	public function file_exists( $remote_path ) {
		$init_result = $this->init_client();
		if ( is_wp_error( $init_result ) ) {
			return $init_result;
		}

		try {
			$this->client->headObject( array(
				'Bucket' => $this->config['bucket'],
				'Key'    => $remote_path,
			) );
			return true;
		} catch ( Exception $e ) {
			if ( $e->getAwsErrorCode() === 'NotFound' ) {
				return false;
			}
			$this->log_error( 'file_exists', 'R2 file check failed', array(
				'error' => $e->getMessage(),
				'remote_path' => $remote_path,
			) );
			return new WP_Error( 'r2_file_check_failed', $e->getMessage() );
		}
	}

	/**
	 * Get the public URL for a file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $remote_path Remote file path/key.
	 * @return string|WP_Error Public URL or error.
	 */
	public function get_public_url( $remote_path ) {
		$base_url = rtrim( $this->config['base_url'], '/' );
		return $base_url . '/' . ltrim( $remote_path, '/' );
	}

	/**
	 * Test the connection to R2.
	 *
	 * @since 1.0.0
	 *
	 * @return bool|WP_Error True if connection successful, or error.
	 */
	public function test_connection() {
		$init_result = $this->init_client();
		if ( is_wp_error( $init_result ) ) {
			return $init_result;
		}

		$this->log_operation( 'test_connection', 'Testing R2 connection' );

		try {
			$this->client->headBucket( array(
				'Bucket' => $this->config['bucket'],
			) );

			$this->log_operation( 'test_connection', 'R2 connection successful' );
			return true;

		} catch ( Exception $e ) {
			$this->log_error( 'test_connection', 'R2 connection failed', array(
				'error' => $e->getMessage(),
			) );
			return new WP_Error( 'r2_connection_failed', $e->getMessage() );
		}
	}

	/**
	 * Get the provider name.
	 *
	 * @since 1.0.0
	 *
	 * @return string Provider name.
	 */
	public function get_provider_name() {
		return 'Cloudflare R2';
	}
}
