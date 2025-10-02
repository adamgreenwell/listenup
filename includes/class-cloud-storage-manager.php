<?php
/**
 * Cloud Storage Manager
 *
 * @package ListenUp
 * @since 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloud storage manager class.
 *
 * @since 1.0.0
 */
class ListenUp_Cloud_Storage_Manager {
	/**
	 * Instance of this class.
	 *
	 * @since 1.0.0
	 * @var ListenUp_Cloud_Storage_Manager
	 */
	private static $instance = null;

	/**
	 * Available storage providers.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $providers = array();

	/**
	 * Current storage provider instance.
	 *
	 * @since 1.0.0
	 * @var ListenUp_Cloud_Storage_Interface|null
	 */
	private $current_provider = null;

	/**
	 * Debug logger instance.
	 *
	 * @since 1.0.0
	 * @var ListenUp_Debug
	 */
	private $debug;

	/**
	 * Get the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return ListenUp_Cloud_Storage_Manager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->debug = ListenUp_Debug::get_instance();
		$this->init_providers();
		$this->load_current_provider();
	}

	/**
	 * Initialize available providers.
	 *
	 * @since 1.0.0
	 */
	private function init_providers() {
		$this->providers = array(
			'aws_s3' => array(
				'name' => __( 'AWS S3', 'listenup' ),
				'class' => 'ListenUp_Cloud_Storage_S3',
				'required_fields' => array( 'access_key', 'secret_key', 'bucket', 'region', 'base_url' ),
			),
			'cloudflare_r2' => array(
				'name' => __( 'Cloudflare R2', 'listenup' ),
				'class' => 'ListenUp_Cloud_Storage_R2',
				'required_fields' => array( 'access_key', 'secret_key', 'bucket', 'endpoint', 'base_url' ),
			),
			'google_cloud' => array(
				'name' => __( 'Google Cloud Storage', 'listenup' ),
				'class' => 'ListenUp_Cloud_Storage_GCS',
				'required_fields' => array( 'project_id', 'bucket', 'credentials_file', 'base_url' ),
			),
		);
	}

	/**
	 * Load the current storage provider.
	 *
	 * @since 1.0.0
	 */
	private function load_current_provider() {
		$options = get_option( 'listenup_options', array() );
		$provider = isset( $options['cloud_storage_provider'] ) ? $options['cloud_storage_provider'] : '';

		if ( empty( $provider ) || ! isset( $this->providers[ $provider ] ) ) {
			return;
		}

		$config = $this->get_provider_config( $provider );
		if ( empty( $config ) ) {
			return;
		}

		$class = $this->providers[ $provider ]['class'];
		if ( class_exists( $class ) ) {
			$this->current_provider = new $class( $config );
		}
	}

	/**
	 * Get configuration for a specific provider.
	 *
	 * @since 1.0.0
	 *
	 * @param string $provider Provider key.
	 * @return array|false Configuration array or false if not configured.
	 */
	private function get_provider_config( $provider ) {
		$options = get_option( 'listenup_options', array() );
		$config = array();

		switch ( $provider ) {
			case 'aws_s3':
				$config = array(
					'access_key' => isset( $options['aws_access_key'] ) ? $options['aws_access_key'] : '',
					'secret_key' => isset( $options['aws_secret_key'] ) ? $options['aws_secret_key'] : '',
					'bucket' => isset( $options['aws_bucket'] ) ? $options['aws_bucket'] : '',
					'region' => isset( $options['aws_region'] ) ? $options['aws_region'] : 'us-east-1',
					'base_url' => isset( $options['aws_base_url'] ) ? $options['aws_base_url'] : '',
				);
				break;

			case 'cloudflare_r2':
				$config = array(
					'access_key' => isset( $options['r2_access_key'] ) ? $options['r2_access_key'] : '',
					'secret_key' => isset( $options['r2_secret_key'] ) ? $options['r2_secret_key'] : '',
					'bucket' => isset( $options['r2_bucket'] ) ? $options['r2_bucket'] : '',
					'endpoint' => isset( $options['r2_endpoint'] ) ? $options['r2_endpoint'] : '',
					'base_url' => isset( $options['r2_base_url'] ) ? $options['r2_base_url'] : '',
				);
				break;

			case 'google_cloud':
				$config = array(
					'project_id' => isset( $options['gcs_project_id'] ) ? $options['gcs_project_id'] : '',
					'bucket' => isset( $options['gcs_bucket'] ) ? $options['gcs_bucket'] : '',
					'credentials_file' => isset( $options['gcs_credentials_file'] ) ? $options['gcs_credentials_file'] : '',
					'base_url' => isset( $options['gcs_base_url'] ) ? $options['gcs_base_url'] : '',
				);
				break;
		}

		// Check if all required fields are configured.
		$required_fields = $this->providers[ $provider ]['required_fields'];
		foreach ( $required_fields as $field ) {
			if ( empty( $config[ $field ] ) ) {
				return false;
			}
		}

		return $config;
	}

	/**
	 * Get available storage providers.
	 *
	 * @since 1.0.0
	 *
	 * @return array Available providers.
	 */
	public function get_available_providers() {
		return $this->providers;
	}

	/**
	 * Get the current storage provider.
	 *
	 * @since 1.0.0
	 *
	 * @return ListenUp_Cloud_Storage_Interface|null Current provider or null.
	 */
	public function get_current_provider() {
		return $this->current_provider;
	}

	/**
	 * Check if cloud storage is configured and available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if cloud storage is available.
	 */
	public function is_available() {
		return null !== $this->current_provider;
	}

	/**
	 * Upload a file to cloud storage.
	 *
	 * @since 1.0.0
	 *
	 * @param string $local_path Local file path.
	 * @param string $filename Original filename.
	 * @return array|WP_Error Upload result with URL and metadata, or error.
	 */
	public function upload_file( $local_path, $filename ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'cloud_storage_not_available', __( 'Cloud storage is not configured.', 'listenup' ) );
		}

		$remote_path = $this->current_provider->generate_remote_path( $filename );
		
		$this->debug->info( 'Uploading file to cloud storage', array(
			'local_path' => $local_path,
			'remote_path' => $remote_path,
			'provider' => $this->current_provider->get_provider_name(),
		) );

		$result = $this->current_provider->upload_file( $local_path, $remote_path );
		
		if ( is_wp_error( $result ) ) {
			$this->debug->error( 'Cloud storage upload failed', array(
				'error' => $result->get_error_message(),
				'provider' => $this->current_provider->get_provider_name(),
			) );
		} else {
			$this->debug->info( 'Cloud storage upload successful', array(
				'url' => $result['url'],
				'provider' => $this->current_provider->get_provider_name(),
			) );
		}

		return $result;
	}

	/**
	 * Delete a file from cloud storage.
	 *
	 * @since 1.0.0
	 *
	 * @param string $remote_path Remote file path/key.
	 * @return bool|WP_Error True on success, or error.
	 */
	public function delete_file( $remote_path ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'cloud_storage_not_available', __( 'Cloud storage is not configured.', 'listenup' ) );
		}

		$this->debug->info( 'Deleting file from cloud storage', array(
			'remote_path' => $remote_path,
			'provider' => $this->current_provider->get_provider_name(),
		) );

		$result = $this->current_provider->delete_file( $remote_path );
		
		if ( is_wp_error( $result ) ) {
			$this->debug->error( 'Cloud storage delete failed', array(
				'error' => $result->get_error_message(),
				'provider' => $this->current_provider->get_provider_name(),
			) );
		} else {
			$this->debug->info( 'Cloud storage delete successful', array(
				'remote_path' => $remote_path,
				'provider' => $this->current_provider->get_provider_name(),
			) );
		}

		return $result;
	}

	/**
	 * Test the current storage provider connection.
	 *
	 * @since 1.0.0
	 *
	 * @return bool|WP_Error True if connection successful, or error.
	 */
	public function test_connection() {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'cloud_storage_not_available', __( 'Cloud storage is not configured.', 'listenup' ) );
		}

		$this->debug->info( 'Testing cloud storage connection', array(
			'provider' => $this->current_provider->get_provider_name(),
		) );

		$result = $this->current_provider->test_connection();
		
		if ( is_wp_error( $result ) ) {
			$this->debug->error( 'Cloud storage connection test failed', array(
				'error' => $result->get_error_message(),
				'provider' => $this->current_provider->get_provider_name(),
			) );
		} else {
			$this->debug->info( 'Cloud storage connection test successful', array(
				'provider' => $this->current_provider->get_provider_name(),
			) );
		}

		return $result;
	}
}
