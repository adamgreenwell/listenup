<?php
/**
 * Conversion API integration for ListenUp plugin.
 *
 * Handles communication with the cloud audio conversion service.
 *
 * @package ListenUp
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conversion API class.
 */
class ListenUp_Conversion_API {

	/**
	 * Single instance of the class.
	 *
	 * @var ListenUp_Conversion_API
	 */
	private static $instance = null;

	/**
	 * API endpoint for single file WAV to MP3 conversion.
	 *
	 * @var string
	 */
	private $api_endpoint;

	/**
	 * API endpoint for multi-segment join and transcode.
	 *
	 * @var string
	 */
	private $join_endpoint;

	/**
	 * API key for authentication.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Get single instance of the class.
	 *
	 * @return ListenUp_Conversion_API
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_settings();
	}

	/**
	 * Load conversion settings from options.
	 */
	private function load_settings() {
		$options = get_option( 'listenup_options' );
		
		// Set API base URL from settings or use default.
		$base_url = isset( $options['conversion_api_endpoint'] ) 
			? $options['conversion_api_endpoint'] 
			: 'https://listenup-audio-converter-931597442473.us-central1.run.app';
		
		// Remove trailing slash if present.
		$base_url = rtrim( $base_url, '/' );
		
		// Set endpoints for different operations.
		$this->api_endpoint = $base_url . '/transcode/wav-to-mp3';
		$this->join_endpoint = $base_url . '/process/join-and-transcode';
		
		// Set API key from settings.
		$this->api_key = isset( $options['conversion_api_key'] ) 
			? $options['conversion_api_key'] 
			: '';
	}

	/**
	 * Convert WAV file(s) to MP3.
	 *
	 * Handles both single files and multi-segment audio.
	 *
	 * @param int         $post_id Post ID for tracking.
	 * @param string|null $wav_file_path Full path to the WAV file (for single files).
	 * @param string|null $wav_filename Filename of the WAV file (for single files).
	 * @return array|WP_Error Conversion result with MP3 file info or error.
	 */
	public function convert_wav_to_mp3( $post_id, $wav_file_path = null, $wav_filename = null ) {
		$debug = ListenUp_Debug::get_instance();
		
		// Check if this post has chunked audio (multiple segments).
		$chunked_meta = get_post_meta( $post_id, '_listenup_chunked_audio', true );
		$has_chunks = ! empty( $chunked_meta ) && isset( $chunked_meta['chunks'] ) && is_array( $chunked_meta['chunks'] );
		
		if ( $has_chunks ) {
			// Filter out pre-roll URLs from conversion - only convert content audio.
			$content_chunks = $this->filter_out_preroll_chunks( $chunked_meta['chunks'] );
			
			$debug->info( 'Starting multi-segment WAV to MP3 conversion', array(
				'post_id' => $post_id,
				'segment_count' => count( $chunked_meta['chunks'] ),
				'content_segments' => count( $content_chunks ),
				'has_preroll' => count( $chunked_meta['chunks'] ) > count( $content_chunks ),
			) );
			return $this->convert_multi_segment_audio( $post_id, $content_chunks );
		} else {
			$debug->info( 'Starting single WAV to MP3 conversion', array(
				'post_id' => $post_id,
				'wav_file' => $wav_filename,
			) );
			return $this->convert_single_file( $post_id, $wav_file_path, $wav_filename );
		}
	}

	/**
	 * Convert a single WAV file to MP3.
	 *
	 * @param int    $post_id Post ID for tracking.
	 * @param string $wav_file_path Full path to the WAV file.
	 * @param string $wav_filename Filename of the WAV file.
	 * @return array|WP_Error Conversion result with MP3 file info or error.
	 */
	private function convert_single_file( $post_id, $wav_file_path, $wav_filename ) {
		$debug = ListenUp_Debug::get_instance();

		// Validate API configuration.
		if ( empty( $this->api_key ) ) {
			$debug->error( 'Conversion API key not configured' );
			return new WP_Error( 
				'api_key_missing', 
				__( 'Conversion API key is not configured. Please add it in ListenUp settings.', 'listenup' ) 
			);
		}

		// Validate file exists.
		if ( ! file_exists( $wav_file_path ) ) {
			$debug->error( 'WAV file not found', array( 'path' => $wav_file_path ) );
			return new WP_Error( 
				'file_not_found', 
				__( 'WAV file not found.', 'listenup' ) 
			);
		}

		// Update post meta to show conversion in progress.
		$this->update_conversion_status( $post_id, 'converting' );

		// Prepare file for upload.
		$boundary = wp_generate_password( 24, false );
		$file_contents = file_get_contents( $wav_file_path );
		
		if ( false === $file_contents ) {
			$debug->error( 'Failed to read WAV file', array( 'path' => $wav_file_path ) );
			$this->update_conversion_status( $post_id, 'failed', __( 'Failed to read WAV file.', 'listenup' ) );
			return new WP_Error( 
				'file_read_error', 
				__( 'Failed to read WAV file.', 'listenup' ) 
			);
		}

		// Build multipart/form-data request body.
		$body = '';
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . $wav_filename . '"' . "\r\n";
		$body .= 'Content-Type: audio/wav' . "\r\n\r\n";
		$body .= $file_contents . "\r\n";
		$body .= '--' . $boundary . '--';

		// Prepare request headers.
		$headers = array(
			'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
			'X-API-Key' => $this->api_key,
		);

		$debug->info( 'Sending conversion request to API', array(
			'endpoint' => $this->api_endpoint,
			'file_size' => strlen( $file_contents ),
		) );

		// Send request to conversion API.
		$response = wp_remote_post(
			$this->api_endpoint,
			array(
				'method' => 'POST',
				'timeout' => 300, // 5 minutes timeout for large files.
				'headers' => $headers,
				'body' => $body,
			)
		);

		// Process response using shared method.
		return $this->process_conversion_response( $response, $post_id, $wav_filename );
	}

	/**
	 * Concatenate audio files using the cloud conversion service.
	 *
	 * @param array  $audio_urls Array of audio file URLs to concatenate.
	 * @param int    $post_id Post ID for tracking.
	 * @param string $format Audio format ('mp3' or 'wav').
	 * @return array|WP_Error Concatenated audio data or error.
	 */
	public function concatenate_audio_files( $audio_urls, $post_id, $format = 'wav' ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Starting cloud-based audio concatenation for ' . count( $audio_urls ) . ' files' );

		if ( empty( $audio_urls ) || ! is_array( $audio_urls ) ) {
			$debug->error( 'No audio URLs provided for concatenation' );
			return new WP_Error( 'invalid_input', __( 'No audio URLs provided for concatenation.', 'listenup' ) );
		}

		// Validate API configuration.
		if ( empty( $this->api_key ) ) {
			$debug->error( 'Conversion API key not configured' );
			return new WP_Error( 
				'api_key_missing', 
				__( 'Conversion API key is not configured. Please add it in ListenUp settings.', 'listenup' ) 
			);
		}

		// Use the join endpoint for concatenation.
		$debug->info( 'Using cloud conversion service for audio concatenation' );
		
		// Prepare request data.
		$request_data = array(
			'action' => 'concatenate',
			'files' => $audio_urls,
			'format' => $format,
			'post_id' => $post_id,
		);

		// Send request to conversion API.
		$response = wp_remote_post(
			$this->join_endpoint,
			array(
				'method' => 'POST',
				'timeout' => 300, // 5 minutes timeout for large files.
				'headers' => array(
					'Content-Type' => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				'body' => wp_json_encode( $request_data ),
			)
		);

		// Process response.
		if ( is_wp_error( $response ) ) {
			$debug->error( 'Cloud concatenation request failed: ' . $response->get_error_message() );
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			$debug->error( 'Cloud concatenation failed with status: ' . $response_code );
			return new WP_Error( 'concatenation_failed', __( 'Cloud concatenation service failed.', 'listenup' ) );
		}

		$response_body = wp_remote_retrieve_body( $response );
		$result = json_decode( $response_body, true );

		if ( ! $result || ! isset( $result['success'] ) || ! $result['success'] ) {
			$debug->error( 'Cloud concatenation returned error: ' . wp_json_encode( $result ) );
			return new WP_Error( 'concatenation_failed', __( 'Cloud concatenation service returned an error.', 'listenup' ) );
		}

		$debug->info( 'Cloud concatenation successful' );
		
		return array(
			'success' => true,
			'url' => $result['url'],
			'size' => $result['size'] ?? 0,
			'cloud_url' => $result['cloud_url'] ?? $result['url'],
		);
	}

	/**
	 * Filter out pre-roll chunks from the chunk list for conversion.
	 * Only content audio should be converted, not pre-roll.
	 *
	 * @param array $chunks Array of chunk URLs.
	 * @return array Filtered array with only content audio chunks.
	 */
	private function filter_out_preroll_chunks( $chunks ) {
		$debug = ListenUp_Debug::get_instance();
		$content_chunks = array();
		
		foreach ( $chunks as $chunk_url ) {
			// Extract filename from URL to check if it's a pre-roll file.
			$filename = basename( wp_parse_url( $chunk_url, PHP_URL_PATH ) );
			
			// Pre-roll files typically start with 'preroll-' prefix.
			if ( strpos( $filename, 'preroll-' ) === 0 ) {
				$debug->info( 'Filtering out pre-roll chunk: ' . $filename );
				continue;
			}
			
			$content_chunks[] = $chunk_url;
		}
		
		$debug->info( 'Filtered chunks for conversion', array(
			'original_count' => count( $chunks ),
			'content_count' => count( $content_chunks ),
		) );
		
		return $content_chunks;
	}

	/**
	 * Convert multiple WAV segments to a single concatenated MP3.
	 *
	 * @param int   $post_id Post ID for tracking.
	 * @param array $chunk_urls Array of WAV segment URLs.
	 * @return array|WP_Error Conversion result with MP3 file info or error.
	 */
	private function convert_multi_segment_audio( $post_id, $chunk_urls ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Converting multi-segment audio', array(
			'post_id' => $post_id,
			'segments' => count( $chunk_urls ),
		) );

		// Validate API configuration.
		if ( empty( $this->api_key ) ) {
			$debug->error( 'Conversion API key not configured' );
			return new WP_Error( 
				'api_key_missing', 
				__( 'Conversion API key is not configured. Please add it in ListenUp settings.', 'listenup' ) 
			);
		}

		// Update post meta to show conversion in progress.
		$this->update_conversion_status( $post_id, 'converting' );

		// Validate that segment files exist (without loading them into memory).
		$upload_dir = wp_upload_dir();
		$cache_dir = $upload_dir['basedir'] . '/listenup-audio';
		$preroll_dir = $upload_dir['basedir'] . '/listenup-preroll';
		$segment_files = array();

		foreach ( $chunk_urls as $index => $url ) {
			// Extract filename from URL.
			$filename = basename( wp_parse_url( $url, PHP_URL_PATH ) );
			
			// Check both cache and preroll directories.
			$file_path = $cache_dir . '/' . $filename;
			if ( ! file_exists( $file_path ) ) {
				$file_path = $preroll_dir . '/' . $filename;
			}

			if ( ! file_exists( $file_path ) ) {
				$debug->error( 'Segment file not found', array(
					'index' => $index,
					'file' => $filename,
					'checked_cache_dir' => $cache_dir,
					'checked_preroll_dir' => $preroll_dir,
				) );
				$this->update_conversion_status( $post_id, 'failed', sprintf(
					/* translators: %d: Segment number */
					__( 'Segment file %d not found.', 'listenup' ),
					$index + 1
				) );
				return new WP_Error( 
					'file_not_found', 
					sprintf(
						/* translators: %d: Segment number */
						__( 'Segment file %d not found.', 'listenup' ),
						$index + 1
					)
				);
			}

			$segment_files[] = array(
				'path' => $file_path,
				'filename' => $filename,
				'index' => $index,
			);
		}

		$debug->info( 'All segments validated', array(
			'segment_count' => count( $segment_files ),
		) );

		// Build JSON payload with segment URLs for the join endpoint.
		// Use cloud storage URLs if available, otherwise use local URLs.
		$cloud_storage = ListenUp_Cloud_Storage_Manager::get_instance();
		$segment_urls = array();
		
		if ( $cloud_storage->is_available() ) {
			// Smart upload: check if files already exist in cloud storage.
			foreach ( $segment_files as $index => $segment ) {
				// Generate the expected remote path for this file.
				$provider = $cloud_storage->get_current_provider();
				$remote_path = $provider->generate_remote_path( $segment['filename'] );
				
				// Check if file already exists in cloud storage.
				$file_exists = $provider->file_exists( $remote_path );
				if ( is_wp_error( $file_exists ) ) {
					$debug->error( 'Failed to check if segment exists in cloud storage', array(
						'error' => $file_exists->get_error_message(),
						'segment' => $segment['filename'],
						'index' => $index,
					) );
					// Continue with upload attempt if check fails.
					$file_exists = false;
				}
				
				if ( $file_exists ) {
					// File already exists, get the public URL.
					$public_url = $provider->get_public_url( $remote_path );
					$debug->info( 'Using existing cloud storage file', array(
						'segment' => $segment['filename'],
						'index' => $index,
						'url' => $public_url,
					) );
					$segment_urls[] = $public_url;
				} else {
					// File doesn't exist, upload it.
					$debug->info( 'Uploading new segment to cloud storage', array(
						'segment' => $segment['filename'],
						'index' => $index,
					) );
					$upload_result = $cloud_storage->upload_file( $segment['path'], $segment['filename'] );
					if ( is_wp_error( $upload_result ) ) {
						$debug->error( 'Failed to upload segment to cloud storage', array(
							'error' => $upload_result->get_error_message(),
							'segment' => $segment['filename'],
							'index' => $index,
						) );
						$this->update_conversion_status( $post_id, 'failed', sprintf(
							/* translators: %d: Segment number */
							__( 'Failed to upload segment %d to cloud storage.', 'listenup' ),
							$index + 1
						) );
						return $upload_result;
					}
					$segment_urls[] = $upload_result['url'];
				}
			}
		} else {
			// Use local URLs (will only work if server is publicly accessible).
			$segment_urls = $chunk_urls;
		}

		$payload = array(
			'files_to_join' => $segment_urls,
			'metadata' => array(
				'post_id' => $post_id,
				'segment_count' => count( $segment_files ),
			),
		);

		// Prepare request headers for JSON endpoint.
		$headers = array(
			'Content-Type' => 'application/json',
			'X-API-Key' => $this->api_key,
		);

		$debug->info( 'Sending multi-segment conversion request to join endpoint', array(
			'endpoint' => $this->join_endpoint,
			'segment_count' => count( $segment_files ),
			'payload_size' => strlen( wp_json_encode( $payload ) ),
		) );

		// Send request to join and transcode endpoint.
		$response = wp_remote_post(
			$this->join_endpoint,
			array(
				'method' => 'POST',
				'timeout' => 600, // 10 minutes for multi-segment.
				'headers' => $headers,
				'body' => wp_json_encode( $payload ),
			)
		);

		// Process response (same as single file).
		return $this->process_conversion_response( $response, $post_id, $segment_files[0]['filename'] );
	}

	/**
	 * Process conversion API response.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @param int            $post_id Post ID.
	 * @param string         $original_filename Original filename for naming.
	 * @return array|WP_Error Conversion result or error.
	 */
	private function process_conversion_response( $response, $post_id, $original_filename ) {
		$debug = ListenUp_Debug::get_instance();

		// Check for request errors.
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$debug->error( 'Conversion API request failed', array( 'error' => $error_message ) );
			$this->update_conversion_status( $post_id, 'failed', $error_message );
			return new WP_Error( 
				'api_request_failed', 
				sprintf(
					/* translators: %s: Error message */
					__( 'Conversion request failed: %s', 'listenup' ),
					$error_message
				)
			);
		}

		// Check response code.
		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		// Get content type to determine response format.
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		
		$debug->info( 'Conversion API response received', array(
			'status_code' => $response_code,
			'body_length' => strlen( $response_body ),
			'content_type' => $content_type,
			'body_preview' => substr( $response_body, 0, 100 ),
		) );

		if ( 200 !== $response_code ) {
			// Try to parse error message from response.
			$error_data = json_decode( $response_body, true );
			$error_message = isset( $error_data['error'] ) 
				? $error_data['error'] 
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'API returned status code %d', 'listenup' ),
					$response_code
				);
			
			$debug->error( 'Conversion API returned error', array(
				'status_code' => $response_code,
				'error' => $error_message,
			) );
			
			$this->update_conversion_status( $post_id, 'failed', $error_message );
			return new WP_Error( 'api_error', $error_message );
		}

		// Check if response is JSON or direct MP3 data.
		if ( strpos( $content_type, 'application/json' ) !== false ) {
			// Parse JSON response.
			$result = json_decode( $response_body, true );
			
			if ( null === $result ) {
				$debug->error( 'Failed to parse API response JSON', array(
					'json_error' => json_last_error_msg(),
				) );
				$this->update_conversion_status( $post_id, 'failed', __( 'Invalid JSON response from API.', 'listenup' ) );
				return new WP_Error( 
					'invalid_response', 
					__( 'Invalid JSON response from conversion API.', 'listenup' ) 
				);
			}
			
			// Check if conversion was successful.
			if ( ! isset( $result['success'] ) || ! $result['success'] ) {
				$error_message = isset( $result['error'] ) 
					? $result['error'] 
					: __( 'Conversion failed.', 'listenup' );
				
				$debug->error( 'Conversion failed', array( 'error' => $error_message ) );
				$this->update_conversion_status( $post_id, 'failed', $error_message );
				return new WP_Error( 'conversion_failed', $error_message );
			}

			// Check if MP3 data is present (either base64 or download URL).
			if ( isset( $result['mp3_base64'] ) && ! empty( $result['mp3_base64'] ) ) {
				// Legacy format: base64 encoded MP3 data.
				$debug->info( 'Processing base64 MP3 data from API response' );
				$mp3_data = base64_decode( $result['mp3_base64'] );
			} elseif ( isset( $result['download_url'] ) && ! empty( $result['download_url'] ) ) {
				// New format: download URL for MP3 file.
				$debug->info( 'Processing download URL from API response', array(
					'download_url' => $result['download_url'],
				) );
				
				// Check if we have cloud storage configured for direct upload.
				$cloud_storage = ListenUp_Cloud_Storage_Manager::get_instance();
				if ( $cloud_storage->is_available() ) {
					// Upload directly to cloud storage instead of downloading locally.
					$cloud_result = $this->upload_mp3_to_cloud_storage( $result['download_url'], $post_id, $original_filename );
					if ( is_wp_error( $cloud_result ) ) {
						$debug->error( 'Failed to upload MP3 to cloud storage', array(
							'error' => $cloud_result->get_error_message(),
							'download_url' => $result['download_url'],
						) );
						$this->update_conversion_status( $post_id, 'failed', $cloud_result->get_error_message() );
						return $cloud_result;
					}
					
					$debug->info( 'MP3 file uploaded directly to cloud storage', array(
						'cloud_url' => $cloud_result['cloud_url'],
						'file_size' => $cloud_result['file_size'],
					) );
					
					// Update conversion status to complete.
					$this->update_conversion_status( $post_id, 'complete', '' );
					
					$debug->info( 'Conversion completed successfully with cloud storage', array(
						'post_id' => $post_id,
						'mp3_file' => $cloud_result['filename'],
						'mp3_size' => $cloud_result['file_size'],
						'cloud_url' => $cloud_result['cloud_url'],
					) );
					
					return array(
						'success' => true,
						'cloud_url' => $cloud_result['cloud_url'],
						'file_size' => $cloud_result['file_size'],
					);
				} else {
					// Fallback to local download if cloud storage is not available.
					$debug->info( 'Cloud storage not available, falling back to local download' );
					
					$download_result = $this->download_mp3_to_file( $result['download_url'], $post_id, $original_filename );
					if ( is_wp_error( $download_result ) ) {
						$debug->error( 'Failed to download MP3 from URL', array(
							'error' => $download_result->get_error_message(),
							'download_url' => $result['download_url'],
						) );
						$this->update_conversion_status( $post_id, 'failed', $download_result->get_error_message() );
						return $download_result;
					}
					
					$debug->info( 'MP3 file saved locally as fallback', array(
						'file_path' => $download_result['file_path'],
						'file_size' => $download_result['file_size'],
					) );
					
					// Update conversion status to complete.
					$this->update_conversion_status( $post_id, 'complete', '' );
					
					$debug->info( 'Conversion completed successfully with local storage', array(
						'post_id' => $post_id,
						'mp3_file' => $download_result['filename'],
						'mp3_size' => $download_result['file_size'],
					) );
					
					return array(
						'success' => true,
						'file_path' => $download_result['file_path'],
						'file_size' => $download_result['file_size'],
					);
				}
			} else {
				$debug->error( 'MP3 data not found in API response', array(
					'response_keys' => array_keys( $result ),
				) );
				$this->update_conversion_status( $post_id, 'failed', __( 'MP3 data missing from response.', 'listenup' ) );
				return new WP_Error( 
					'mp3_data_missing', 
					__( 'MP3 data not found in conversion response.', 'listenup' ) 
				);
			}
		} elseif ( strpos( $content_type, 'audio/' ) !== false ) {
			// Response is direct MP3 data.
			$debug->info( 'API returned direct MP3 data' );
			$mp3_data = $response_body;
			$result = array( 'success' => true );
		} else {
			// Unknown content type - try to detect.
			$debug->warning( 'Unknown content type, attempting to detect format', array(
				'content_type' => $content_type,
			) );
			
			// Try JSON first.
			$result = json_decode( $response_body, true );
			if ( null !== $result && isset( $result['mp3_base64'] ) ) {
				$mp3_data = base64_decode( $result['mp3_base64'] );
			} else {
				// Assume it's direct MP3 data.
				$debug->info( 'Treating response as direct MP3 data' );
				$mp3_data = $response_body;
				$result = array( 'success' => true );
			}
		}

		// Validate MP3 data.
		if ( false === $mp3_data || empty( $mp3_data ) ) {
			$debug->error( 'Failed to decode MP3 data' );
			$this->update_conversion_status( $post_id, 'failed', __( 'Failed to decode MP3 data.', 'listenup' ) );
			return new WP_Error( 
				'decode_failed', 
				__( 'Failed to decode MP3 data.', 'listenup' ) 
			);
		}

		// Save MP3 file.
		$save_result = $this->save_mp3_file( $post_id, $mp3_data, $original_filename );
		
		if ( is_wp_error( $save_result ) ) {
			$this->update_conversion_status( $post_id, 'failed', $save_result->get_error_message() );
			return $save_result;
		}

		// Update post meta with success status.
		$this->update_conversion_status( $post_id, 'complete' );

		$debug->info( 'Conversion completed successfully', array(
			'post_id' => $post_id,
			'mp3_file' => $save_result['filename'],
			'mp3_size' => $save_result['size'],
		) );

		return array(
			'success' => true,
			'mp3_url' => $save_result['url'],
			'mp3_file' => $save_result['filename'],
			'mp3_size' => $save_result['size'],
			'conversion_stats' => isset( $result['stats'] ) ? $result['stats'] : array(),
		);
	}

	/**
	 * Save MP3 file to cache directory.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $mp3_data Binary MP3 data.
	 * @param string $original_wav_filename Original WAV filename.
	 * @return array|WP_Error File info or error.
	 */
	private function save_mp3_file( $post_id, $mp3_data, $original_wav_filename ) {
		$debug = ListenUp_Debug::get_instance();
		
		// Get upload directory.
		$upload_dir = wp_upload_dir();
		$cache_dir = $upload_dir['basedir'] . '/listenup-audio';

		// Ensure cache directory exists.
		if ( ! file_exists( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
		}

		// Generate MP3 filename (replace .wav with .mp3).
		$mp3_filename = preg_replace( '/\.wav$/i', '.mp3', $original_wav_filename );
		
		// If the filename didn't have .wav extension, just append .mp3.
		if ( $mp3_filename === $original_wav_filename ) {
			$mp3_filename .= '.mp3';
		}

		$mp3_file_path = $cache_dir . '/' . $mp3_filename;
		$mp3_url = $upload_dir['baseurl'] . '/listenup-audio/' . $mp3_filename;

		// Write MP3 file.
		$result = file_put_contents( $mp3_file_path, $mp3_data );
		
		if ( false === $result ) {
			$debug->error( 'Failed to write MP3 file', array(
				'path' => $mp3_file_path,
				'data_size' => strlen( $mp3_data ),
			) );
			return new WP_Error( 
				'write_failed', 
				__( 'Failed to save MP3 file.', 'listenup' ) 
			);
		}

		// Update post meta with MP3 file info.
		$audio_meta = get_post_meta( $post_id, '_listenup_audio', true );
		
		if ( ! is_array( $audio_meta ) ) {
			$audio_meta = array();
		}

		$audio_meta['mp3_url'] = $mp3_url;
		$audio_meta['mp3_file'] = $mp3_filename;
		$audio_meta['mp3_size'] = filesize( $mp3_file_path );
		$audio_meta['mp3_created'] = current_time( 'mysql' );

		update_post_meta( $post_id, '_listenup_audio', $audio_meta );

		$debug->info( 'MP3 file saved successfully', array(
			'filename' => $mp3_filename,
			'size' => $audio_meta['mp3_size'],
			'url' => $mp3_url,
		) );

		return array(
			'url' => $mp3_url,
			'filename' => $mp3_filename,
			'path' => $mp3_file_path,
			'size' => $audio_meta['mp3_size'],
		);
	}

	/**
	 * Update conversion status in post meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status Status: pending, converting, complete, failed.
	 * @param string $error_message Optional error message for failed status.
	 */
	private function update_conversion_status( $post_id, $status, $error_message = '' ) {
		$audio_meta = get_post_meta( $post_id, '_listenup_audio', true );
		
		if ( ! is_array( $audio_meta ) ) {
			$audio_meta = array();
		}

		$audio_meta['conversion_status'] = $status;
		$audio_meta['conversion_updated'] = current_time( 'mysql' );

		if ( ! empty( $error_message ) ) {
			$audio_meta['conversion_error'] = $error_message;
		} else {
			// Clear error message on success.
			unset( $audio_meta['conversion_error'] );
		}

		update_post_meta( $post_id, '_listenup_audio', $audio_meta );

		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Conversion status updated', array(
			'post_id' => $post_id,
			'status' => $status,
			'error' => $error_message,
		) );
	}

	/**
	 * Check if conversion API is configured.
	 *
	 * @return bool True if API is configured.
	 */
	public function is_configured() {
		return ! empty( $this->api_key ) && ! empty( $this->api_endpoint );
	}

	/**
	 * Get API endpoint.
	 *
	 * @return string API endpoint URL.
	 */
	public function get_api_endpoint() {
		return $this->api_endpoint;
	}

	/**
	 * Test API connection.
	 *
	 * @return array|WP_Error Test result or error.
	 */
	public function test_connection() {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Testing conversion API connection' );

		if ( ! $this->is_configured() ) {
			return new WP_Error( 
				'not_configured', 
				__( 'API endpoint and key must be configured before testing.', 'listenup' ) 
			);
		}

		// Send a POST request with empty body to test endpoint reachability.
		// The endpoint should respond even if the request is invalid.
		$response = wp_remote_post(
			$this->api_endpoint,
			array(
				'timeout' => 10,
				'headers' => array(
					'X-API-Key' => $this->api_key,
				),
				'body' => '',
			)
		);

		if ( is_wp_error( $response ) ) {
			$debug->error( 'API connection test failed', array(
				'error' => $response->get_error_message(),
			) );
			return new WP_Error(
				'connection_failed',
				sprintf(
					/* translators: %s: Error message */
					__( 'Cannot reach API endpoint: %s', 'listenup' ),
					$response->get_error_message()
				)
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		
		$debug->info( 'API connection test completed', array(
			'status_code' => $response_code,
			'body_preview' => substr( $response_body, 0, 200 ),
		) );

		// Accept any response code that shows the server is reachable.
		// Even 400/422 (bad request) means the endpoint exists and is responding.
		$acceptable_codes = array( 200, 201, 400, 401, 403, 422, 500 );
		
		if ( in_array( $response_code, $acceptable_codes, true ) ) {
			// Check if we got an auth error (401/403) - this means wrong API key.
			if ( 401 === $response_code || 403 === $response_code ) {
				return new WP_Error(
					'auth_failed',
					__( 'API endpoint is reachable but authentication failed. Please check your API key.', 'listenup' )
				);
			}
			
			return array(
				'success' => true,
				'status_code' => $response_code,
				'message' => __( 'Connection successful! API endpoint is reachable and responding.', 'listenup' ),
			);
		}

		// Unexpected response code.
		return new WP_Error(
			'unexpected_response',
			sprintf(
				/* translators: %d: HTTP status code */
				__( 'Unexpected response from API (HTTP %d). The endpoint may not be configured correctly.', 'listenup' ),
				$response_code
			)
		);
	}

	/**
	 * Download MP3 file from a secure download URL directly to the final location.
	 * This avoids memory issues by streaming directly to the target file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $download_url Secure download URL for the MP3 file.
	 * @param int    $post_id Post ID for generating filename.
	 * @param string $original_filename Original filename for MP3.
	 * @return array|WP_Error File information or error.
	 */
	private function download_mp3_to_file( $download_url, $post_id, $original_filename ) {
		$debug = ListenUp_Debug::get_instance();
		
		$debug->info( 'Downloading MP3 directly to final location', array(
			'download_url' => $download_url,
			'post_id' => $post_id,
		) );

		// Generate the final MP3 filename and path.
		$mp3_filename = $this->generate_mp3_filename( $post_id, $original_filename );
		$upload_dir = wp_upload_dir();
		$listenup_dir = $upload_dir['basedir'] . '/listenup-audio';
		
		// Ensure the directory exists.
		if ( ! wp_mkdir_p( $listenup_dir ) ) {
			$debug->error( 'Failed to create upload directory', array(
				'directory' => $listenup_dir,
			) );
			return new WP_Error(
				'directory_creation_failed',
				__( 'Failed to create upload directory', 'listenup' )
			);
		}

		$final_path = $listenup_dir . '/' . $mp3_filename;

		// Use cURL to stream download directly to the final file.
		$ch = curl_init();
		$fp = fopen( $final_path, 'w' );
		
		if ( ! $fp ) {
			curl_close( $ch );
			$debug->error( 'Failed to open final file for writing', array(
				'file_path' => $final_path,
			) );
			return new WP_Error(
				'download_failed',
				__( 'Failed to open final file for writing', 'listenup' )
			);
		}

		curl_setopt_array( $ch, array(
			CURLOPT_URL => $download_url,
			CURLOPT_FILE => $fp,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => 300, // 5 minutes timeout.
			CURLOPT_USERAGENT => 'ListenUp WordPress Plugin',
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		) );

		$success = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error = curl_error( $ch );
		
		fclose( $fp );
		curl_close( $ch );

		if ( ! $success ) {
			unlink( $final_path );
			$debug->error( 'Failed to download MP3 from URL', array(
				'error' => $error,
				'download_url' => $download_url,
			) );
			return new WP_Error(
				'download_failed',
				sprintf(
					/* translators: %s: Error message */
					__( 'Failed to download MP3 file: %s', 'listenup' ),
					$error
				)
			);
		}

		if ( 200 !== $http_code ) {
			unlink( $final_path );
			$debug->error( 'Download failed with HTTP error', array(
				'status_code' => $http_code,
				'download_url' => $download_url,
			) );
			return new WP_Error(
				'download_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Download failed with HTTP %d', 'listenup' ),
					$http_code
				)
			);
		}

		// Check if file was downloaded and has content.
		$file_size = filesize( $final_path );
		if ( 0 === $file_size ) {
			unlink( $final_path );
			$debug->error( 'Downloaded MP3 file is empty', array(
				'download_url' => $download_url,
			) );
			return new WP_Error(
				'download_failed',
				__( 'Downloaded MP3 file is empty', 'listenup' )
			);
		}

		// Generate the public URL.
		$public_url = $upload_dir['baseurl'] . '/listenup-audio/' . $mp3_filename;

		$debug->info( 'MP3 file downloaded successfully to final location', array(
			'file_path' => $final_path,
			'file_size' => $file_size,
			'public_url' => $public_url,
		) );

		// Store the MP3 file information in post meta.
		update_post_meta( $post_id, '_listenup_mp3_file', $mp3_filename );
		update_post_meta( $post_id, '_listenup_mp3_url', $public_url );
		update_post_meta( $post_id, '_listenup_mp3_size', $file_size );

		return array(
			'filename' => $mp3_filename,
			'file_path' => $final_path,
			'file_size' => $file_size,
			'public_url' => $public_url,
		);
	}

	/**
	 * Upload MP3 file from download URL directly to cloud storage.
	 * This streams the download directly to cloud storage without using local disk.
	 *
	 * @since 1.0.0
	 *
	 * @param string $download_url Secure download URL for the MP3 file.
	 * @param int    $post_id Post ID for generating filename.
	 * @param string $original_filename Original filename for MP3.
	 * @return array|WP_Error File information or error.
	 */
	private function upload_mp3_to_cloud_storage( $download_url, $post_id, $original_filename ) {
		$debug = ListenUp_Debug::get_instance();
		
		$debug->info( 'Uploading MP3 directly to cloud storage from download URL', array(
			'download_url' => $download_url,
			'post_id' => $post_id,
		) );

		// Generate the MP3 filename.
		$mp3_filename = $this->generate_mp3_filename( $post_id, $original_filename );
		
		// Get cloud storage manager.
		$cloud_storage = ListenUp_Cloud_Storage_Manager::get_instance();
		$provider = $cloud_storage->get_current_provider();
		
		// Generate the remote path for cloud storage.
		$remote_path = $provider->generate_remote_path( $mp3_filename );
		
		$debug->info( 'Streaming download to cloud storage', array(
			'remote_path' => $remote_path,
			'provider' => $provider->get_provider_name(),
		) );

		// Create a temporary file for streaming download.
		$temp_file = wp_tempnam( 'listenup_mp3_' );
		if ( ! $temp_file ) {
			$debug->error( 'Failed to create temporary file for download' );
			return new WP_Error(
				'cloud_upload_failed',
				__( 'Failed to create temporary file for download', 'listenup' )
			);
		}

		// Use cURL to stream download to temporary file.
		$ch = curl_init();
		$fp = fopen( $temp_file, 'w' );
		
		if ( ! $fp ) {
			curl_close( $ch );
			unlink( $temp_file );
			$debug->error( 'Failed to open temporary file for writing' );
			return new WP_Error(
				'cloud_upload_failed',
				__( 'Failed to open temporary file for writing', 'listenup' )
			);
		}

		curl_setopt_array( $ch, array(
			CURLOPT_URL => $download_url,
			CURLOPT_FILE => $fp,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => 300, // 5 minutes timeout.
			CURLOPT_USERAGENT => 'ListenUp WordPress Plugin',
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_HEADERFUNCTION => function( $curl, $header ) use ( &$content_length ) {
				if ( preg_match( '/Content-Length:\s*(\d+)/i', $header, $matches ) ) {
					$content_length = (int) $matches[1];
				}
				return strlen( $header );
			},
		) );

		// Stream the download to temporary file.
		$success = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error = curl_error( $ch );
		
		fclose( $fp );
		curl_close( $ch );

		if ( ! $success ) {
			unlink( $temp_file );
			$debug->error( 'Failed to download MP3 from URL', array(
				'error' => $error,
				'download_url' => $download_url,
			) );
			return new WP_Error(
				'cloud_upload_failed',
				sprintf(
					/* translators: %s: Error message */
					__( 'Failed to download MP3 file: %s', 'listenup' ),
					$error
				)
			);
		}

		if ( 200 !== $http_code ) {
			unlink( $temp_file );
			$debug->error( 'Download failed with HTTP error', array(
				'status_code' => $http_code,
				'download_url' => $download_url,
			) );
			return new WP_Error(
				'cloud_upload_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Download failed with HTTP %d', 'listenup' ),
					$http_code
				)
			);
		}

		// Check if file was downloaded and has content.
		$file_size = filesize( $temp_file );
		if ( 0 === $file_size ) {
			unlink( $temp_file );
			$debug->error( 'Downloaded MP3 file is empty', array(
				'download_url' => $download_url,
			) );
			return new WP_Error(
				'cloud_upload_failed',
				__( 'Downloaded MP3 file is empty', 'listenup' )
			);
		}

		// Upload the temporary file to cloud storage.
		$upload_result = $cloud_storage->upload_file( $temp_file, $mp3_filename );
		unlink( $temp_file ); // Clean up temporary file.

		if ( is_wp_error( $upload_result ) ) {
			$debug->error( 'Failed to upload MP3 to cloud storage', array(
				'error' => $upload_result->get_error_message(),
				'remote_path' => $remote_path,
			) );
			return new WP_Error(
				'cloud_upload_failed',
				sprintf(
					/* translators: %s: Error message */
					__( 'Failed to upload MP3 to cloud storage: %s', 'listenup' ),
					$upload_result->get_error_message()
				)
			);
		}

		// Get the public URL for the uploaded file.
		$cloud_url = $upload_result['url'];

		$debug->info( 'MP3 file uploaded successfully to cloud storage', array(
			'cloud_url' => $cloud_url,
			'file_size' => $file_size,
			'remote_path' => $remote_path,
		) );

		// Store the MP3 file information in post meta.
		update_post_meta( $post_id, '_listenup_mp3_file', $mp3_filename );
		update_post_meta( $post_id, '_listenup_mp3_url', $cloud_url );
		update_post_meta( $post_id, '_listenup_mp3_size', $file_size );
		update_post_meta( $post_id, '_listenup_mp3_cloud_path', $remote_path );

		return array(
			'filename' => $mp3_filename,
			'cloud_url' => $cloud_url,
			'file_size' => $file_size,
			'remote_path' => $remote_path,
		);
	}

	/**
	 * Generate MP3 filename based on post ID and original filename.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id Post ID.
	 * @param string $original_filename Original filename.
	 * @return string MP3 filename.
	 */
	private function generate_mp3_filename( $post_id, $original_filename ) {
		// Extract the base name from the original filename.
		$base_name = pathinfo( $original_filename, PATHINFO_FILENAME );
		
		// Generate a unique identifier.
		$unique_id = wp_generate_password( 8, false );
		
		// Create the MP3 filename.
		return $post_id . '_' . $base_name . '_' . $unique_id . '.mp3';
	}
}

