<?php
/**
 * Google Cloud Storage Provider
 *
 * @package ListenUp
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Cloud Storage provider.
 *
 * @since 1.0.0
 */
class ListenUp_Cloud_Storage_GCS extends ListenUp_Cloud_Storage_Base {
	/**
	 * GCS client instance.
	 *
	 * @since 1.0.0
	 * @var Google\Cloud\Storage\StorageClient|null
	 */
	private $client;

	/**
	 * Initialize the GCS client.
	 *
	 * @since 1.0.0
	 *
	 * @return bool|WP_Error True if successful, or error.
	 */
	private function init_client() {
		if ( null !== $this->client ) {
			return true;
		}

		// Check if Google Cloud SDK is available.
		if ( ! class_exists( 'Google\Cloud\Storage\StorageClient' ) ) {
			return new WP_Error( 'gcs_sdk_missing', __( 'Google Cloud SDK is not available. Please install google/cloud-storage.', 'listenup' ) );
		}

		try {
			// Set up authentication.
			putenv( 'GOOGLE_APPLICATION_CREDENTIALS=' . $this->config['credentials_file'] );
			
			$this->client = new Google\Cloud\Storage\StorageClient( array(
				'projectId' => $this->config['project_id'],
			) );
		} catch ( Exception $e ) {
			$this->log_error( 'init_client', 'Failed to initialize GCS client', array(
				'error' => $e->getMessage(),
			) );
			return new WP_Error( 'gcs_client_init_failed', $e->getMessage() );
		}

		return true;
	}

	/**
	 * Upload a file to GCS.
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

		$this->log_operation( 'upload_file', 'Starting GCS upload', array(
			'local_path' => $local_path,
			'remote_path' => $remote_path,
			'file_size' => filesize( $local_path ),
		) );

		try {
			$bucket = $this->client->bucket( $this->config['bucket'] );
			$object = $bucket->upload( fopen( $local_path, 'r' ), array(
				'name' => $remote_path,
				'predefinedAcl' => 'publicRead',
			) );

			$public_url = $this->get_public_url( $remote_path );

			$this->log_operation( 'upload_file', 'GCS upload completed', array(
				'remote_path' => $remote_path,
				'public_url' => $public_url,
				'generation' => $object->info()['generation'],
			) );

			return array(
				'url' => $public_url,
				'key' => $remote_path,
				'generation' => $object->info()['generation'],
				'size' => filesize( $local_path ),
			);

		} catch ( Exception $e ) {
			$this->log_error( 'upload_file', 'GCS upload failed', array(
				'error' => $e->getMessage(),
				'remote_path' => $remote_path,
			) );
			return new WP_Error( 'gcs_upload_failed', $e->getMessage() );
		}
	}

	/**
	 * Delete a file from GCS.
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

		$this->log_operation( 'delete_file', 'Starting GCS delete', array(
			'remote_path' => $remote_path,
		) );

		try {
			$bucket = $this->client->bucket( $this->config['bucket'] );
			$object = $bucket->object( $remote_path );
			$object->delete();

			$this->log_operation( 'delete_file', 'GCS delete completed', array(
				'remote_path' => $remote_path,
			) );

			return true;

		} catch ( Exception $e ) {
			$this->log_error( 'delete_file', 'GCS delete failed', array(
				'error' => $e->getMessage(),
				'remote_path' => $remote_path,
			) );
			return new WP_Error( 'gcs_delete_failed', $e->getMessage() );
		}
	}

	/**
	 * Check if a file exists in GCS.
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
			$bucket = $this->client->bucket( $this->config['bucket'] );
			$object = $bucket->object( $remote_path );
			return $object->exists();
		} catch ( Exception $e ) {
			$this->log_error( 'file_exists', 'GCS file check failed', array(
				'error' => $e->getMessage(),
				'remote_path' => $remote_path,
			) );
			return new WP_Error( 'gcs_file_check_failed', $e->getMessage() );
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
	 * Test the connection to GCS.
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

		$this->log_operation( 'test_connection', 'Testing GCS connection' );

		try {
			$bucket = $this->client->bucket( $this->config['bucket'] );
			$bucket->exists();

			$this->log_operation( 'test_connection', 'GCS connection successful' );
			return true;

		} catch ( Exception $e ) {
			$this->log_error( 'test_connection', 'GCS connection failed', array(
				'error' => $e->getMessage(),
			) );
			return new WP_Error( 'gcs_connection_failed', $e->getMessage() );
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
		return 'Google Cloud Storage';
	}
}
