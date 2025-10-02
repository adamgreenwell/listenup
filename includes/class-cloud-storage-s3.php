<?php
/**
 * AWS S3 Cloud Storage Provider
 *
 * @package ListenUp
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AWS S3 cloud storage provider.
 *
 * @since 1.0.0
 */
class ListenUp_Cloud_Storage_S3 extends ListenUp_Cloud_Storage_Base {
	/**
	 * S3 client instance.
	 *
	 * @since 1.0.0
	 * @var Aws\S3\S3Client|null
	 */
	private $client;

	/**
	 * Initialize the S3 client.
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
				'region'  => $this->config['region'],
				'credentials' => array(
					'key'    => $this->config['access_key'],
					'secret' => $this->config['secret_key'],
				),
			) );
		} catch ( Exception $e ) {
			$this->log_error( 'init_client', 'Failed to initialize S3 client', array(
				'error' => $e->getMessage(),
			) );
			return new WP_Error( 's3_client_init_failed', $e->getMessage() );
		}

		return true;
	}

	/**
	 * Upload a file to S3.
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

		$this->log_operation( 'upload_file', 'Starting S3 upload', array(
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

			$this->log_operation( 'upload_file', 'S3 upload completed', array(
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
			$this->log_error( 'upload_file', 'S3 upload failed', array(
				'error' => $e->getMessage(),
				'remote_path' => $remote_path,
			) );
			return new WP_Error( 's3_upload_failed', $e->getMessage() );
		}
	}

	/**
	 * Delete a file from S3.
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

		$this->log_operation( 'delete_file', 'Starting S3 delete', array(
			'remote_path' => $remote_path,
		) );

		try {
			$this->client->deleteObject( array(
				'Bucket' => $this->config['bucket'],
				'Key'    => $remote_path,
			) );

			$this->log_operation( 'delete_file', 'S3 delete completed', array(
				'remote_path' => $remote_path,
			) );

			return true;

		} catch ( Exception $e ) {
			$this->log_error( 'delete_file', 'S3 delete failed', array(
				'error' => $e->getMessage(),
				'remote_path' => $remote_path,
			) );
			return new WP_Error( 's3_delete_failed', $e->getMessage() );
		}
	}

	/**
	 * Check if a file exists in S3.
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

		$this->log_operation( 'file_exists', 'Checking if file exists in S3', array(
			'remote_path' => $remote_path,
			'bucket' => $this->config['bucket'],
			'region' => $this->config['region'],
		) );

		try {
			$this->client->headObject( array(
				'Bucket' => $this->config['bucket'],
				'Key'    => $remote_path,
			) );

			$this->log_operation( 'file_exists', 'File exists in S3', array(
				'remote_path' => $remote_path,
			) );
			return true;

		} catch ( Exception $e ) {
			// If the object doesn't exist, S3 returns a 404.
			if ( $e->getCode() === 404 ) {
				$this->log_operation( 'file_exists', 'File does not exist in S3', array(
					'remote_path' => $remote_path,
				) );
				return false;
			}

			$this->log_error( 'file_exists', 'S3 file check failed', array(
				'error' => $e->getMessage(),
				'remote_path' => $remote_path,
				'error_code' => $e->getCode(),
				'aws_error_code' => method_exists( $e, 'getAwsErrorCode' ) ? $e->getAwsErrorCode() : 'N/A',
			) );
			return new WP_Error( 's3_file_check_failed', $e->getMessage() );
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
		$url = $base_url . '/' . ltrim( $remote_path, '/' );
		
		// Ensure HTTPS is used for security
		$url = str_replace( 'http://', 'https://', $url );
		
		// Convert S3 website endpoints to regular S3 endpoints for HTTPS support
		$url = str_replace( 's3-website.', 's3.', $url );
		
		return $url;
	}

	/**
	 * Test the connection to S3.
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

		$this->log_operation( 'test_connection', 'Testing S3 connection' );

		try {
			$this->client->headBucket( array(
				'Bucket' => $this->config['bucket'],
			) );

			$this->log_operation( 'test_connection', 'S3 connection successful' );
			return true;

		} catch ( Exception $e ) {
			$this->log_error( 'test_connection', 'S3 connection failed', array(
				'error' => $e->getMessage(),
			) );
			return new WP_Error( 's3_connection_failed', $e->getMessage() );
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
		return 'AWS S3';
	}
}
