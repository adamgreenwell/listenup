<?php
/**
 * Pre-roll audio management functionality for ListenUp plugin.
 *
 * @package ListenUp
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pre-roll manager class for handling pre-roll audio integration.
 */
class ListenUp_Pre_Roll_Manager {

	/**
	 * Single instance of the class.
	 *
	 * @var ListenUp_Pre_Roll_Manager
	 */
	private static $instance = null;


	/**
	 * Get single instance of the class.
	 *
	 * @return ListenUp_Pre_Roll_Manager
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
		// No initialization needed for client-side approach
	}

	/**
	 * Add pre-roll audio to content audio.
	 *
	 * @param string|array $content_audio Content audio URL or array of chunk URLs.
	 * @param int          $post_id Post ID for caching purposes.
	 * @param string       $text_hash Text hash for verification.
	 * @param string       $voice_id Voice ID.
	 * @param string       $voice_style Voice style.
	 * @return array|WP_Error Audio with pre-roll or error.
	 */
	public function add_pre_roll_to_audio( $content_audio, $post_id, $text_hash = '', $voice_id = '', $voice_style = '' ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Adding pre-roll audio to content audio (client-side)' );

		// Check if pre-roll is configured.
		$pre_roll_file = $this->get_pre_roll_file();
		if ( ! $pre_roll_file ) {
			$debug->info( 'No pre-roll audio configured, returning content audio as-is' );
			return array(
				'success' => true,
				'audio_url' => is_array( $content_audio ) ? $content_audio : $content_audio,
				'has_pre_roll' => false,
			);
		}

		// Get pre-roll audio URL (with format matching).
		$pre_roll_url = $this->get_pre_roll_url( $post_id );
		if ( ! $pre_roll_url ) {
			$debug->warning( 'Pre-roll file exists but URL could not be generated' );
			return array(
				'success' => true,
				'audio_url' => is_array( $content_audio ) ? $content_audio : $content_audio,
				'has_pre_roll' => false,
			);
		}
		
		// Validate pre-roll file format for Web Audio API compatibility
		$pre_roll_file = $this->get_pre_roll_file();
		if ( $pre_roll_file ) {
			$validation = $this->validate_pre_roll_file( $pre_roll_file );
			if ( is_wp_error( $validation ) ) {
				$debug->warning( 'Pre-roll file validation failed: ' . $validation->get_error_message() );
				return array(
					'success' => true,
					'audio_url' => is_array( $content_audio ) ? $content_audio : $content_audio,
					'has_pre_roll' => false,
				);
			}
			$debug->info( 'Pre-roll file validation passed: ' . $validation['file_size_formatted'] . ' ' . strtoupper( $validation['extension'] ) );
		}

		// Check if content audio is cloud storage
		$is_cloud_storage = false;
		if ( is_string( $content_audio ) && ( strpos( $content_audio, 'amazonaws.com' ) !== false || strpos( $content_audio, 'cloudfront.net' ) !== false || strpos( $content_audio, 'r2.cloudflarestorage.com' ) !== false ) ) {
			$is_cloud_storage = true;
		}

		// For cloud storage, we need to handle pre-roll differently
		if ( $is_cloud_storage ) {
			$debug->info( 'Content audio is cloud storage, using server-side concatenation approach' );
			
			// For cloud storage, we'll use server-side concatenation instead of client-side
			// This means we need to download the cloud audio and concatenate it server-side
			return array(
				'success' => true,
				'audio_url' => $content_audio,
				'has_pre_roll' => true,
				'chunked' => false,
				'cached' => false,
				'cloud_storage' => true,
				'pre_roll_url' => $pre_roll_url,
				'server_side_concatenation' => true,
			);
		}

		// For local files, use client-side concatenation
		$audio_chunks = array( $pre_roll_url );
		
		if ( is_array( $content_audio ) ) {
			// Multiple chunks
			$audio_chunks = array_merge( $audio_chunks, $content_audio );
		} else {
			// Single audio file
			$audio_chunks[] = $content_audio;
		}

		$debug->info( 'Prepared ' . count( $audio_chunks ) . ' audio chunks with pre-roll for client-side concatenation' );

		return array(
			'success' => true,
			'audio_url' => is_array( $content_audio ) ? $content_audio[0] : $content_audio, // Fallback
			'chunks' => $audio_chunks,
			'has_pre_roll' => true,
			'chunked' => is_array( $content_audio ),
			'cached' => false,
		);
	}

	/**
	 * Get the configured pre-roll audio file path.
	 *
	 * @return string|false Pre-roll file path or false if not configured.
	 */
	public function get_pre_roll_file() {
		$options = get_option( 'listenup_options' );
		$pre_roll_file = isset( $options['pre_roll_audio'] ) ? $options['pre_roll_audio'] : '';

		if ( empty( $pre_roll_file ) ) {
			return false;
		}

		// Check if the file exists.
		if ( ! file_exists( $pre_roll_file ) ) {
			$debug = ListenUp_Debug::get_instance();
			$debug->warning( 'Pre-roll audio file not found: ' . $pre_roll_file );
			return false;
		}

		return $pre_roll_file;
	}

	/**
	 * Get the pre-roll audio URL.
	 *
	 * @param int $post_id Post ID to determine format (optional).
	 * @return string|false Pre-roll audio URL or false if not available.
	 */
	public function get_pre_roll_url( $post_id = 0 ) {
		$pre_roll_file = $this->get_pre_roll_file();
		if ( ! $pre_roll_file ) {
			return false;
		}

		// Convert file path to URL.
		$upload_dir = wp_upload_dir();
		$relative_path = str_replace( $upload_dir['basedir'], '', $pre_roll_file );
		$pre_roll_url = $upload_dir['baseurl'] . $relative_path;

		// If we have a post ID, try to return the format that matches the post's audio format.
		if ( $post_id > 0 ) {
			$post_format = $this->detect_post_audio_format( $post_id );
			$debug = ListenUp_Debug::get_instance();
			$debug->info( 'Post format: ' . $post_format . ', Pre-roll file: ' . $pre_roll_file );
			
			// If post is MP3 and we have a WAV pre-roll, try to find the MP3 version.
			if ( 'mp3' === $post_format && strpos( $pre_roll_file, '.wav' ) !== false ) {
				$mp3_file = str_replace( '.wav', '.mp3', $pre_roll_file );
				if ( file_exists( $mp3_file ) ) {
					$mp3_relative_path = str_replace( $upload_dir['basedir'], '', $mp3_file );
					$mp3_url = $upload_dir['baseurl'] . $mp3_relative_path;
					$debug->info( 'Using MP3 pre-roll to match post format: ' . $mp3_url );
					return $mp3_url;
				} else {
					$debug->warning( 'MP3 pre-roll file not found: ' . $mp3_file );
				}
			}
		}

		return $pre_roll_url;
	}


	/**
	 * Validate pre-roll audio file.
	 *
	 * @param string $file_path Path to the audio file.
	 * @return array|WP_Error Validation result or error.
	 */
	public function validate_pre_roll_file( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Pre-roll audio file not found.', 'listenup' ) );
		}

		// Check file size (limit to 10MB).
		$file_size = filesize( $file_path );
		if ( $file_size > 10 * 1024 * 1024 ) {
			return new WP_Error( 'file_too_large', __( 'Pre-roll audio file is too large. Maximum size is 10MB.', 'listenup' ) );
		}

		// Check file extension - mandate WAV format for pre-roll.
		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		if ( 'wav' !== $extension ) {
			return new WP_Error( 'invalid_format', __( 'Pre-roll audio file must be in WAV format.', 'listenup' ) );
		}

		return array(
			'success' => true,
			'file_size' => $file_size,
			'file_size_formatted' => size_format( $file_size ),
			'extension' => $extension,
		);
	}

	/**
	 * Detect the audio format of a post's audio files.
	 *
	 * @param int $post_id Post ID to check.
	 * @return string Audio format ('wav' or 'mp3').
	 */
	private function detect_post_audio_format( $post_id ) {
		$debug = ListenUp_Debug::get_instance();
		
		// Check if post has cloud storage MP3 URL.
		$cloud_mp3_url = get_post_meta( $post_id, '_listenup_mp3_url', true );
		if ( ! empty( $cloud_mp3_url ) ) {
			$debug->info( 'Post has cloud storage MP3 audio format' );
			return 'mp3';
		}
		
		// Check if post has MP3 files (converted audio).
		$audio_meta = get_post_meta( $post_id, '_listenup_audio', true );
		if ( ! empty( $audio_meta ) && isset( $audio_meta['mp3_file'] ) ) {
			$debug->info( 'Post has MP3 audio format' );
			return 'mp3';
		}
		
		// Check chunked audio for MP3 format.
		$chunked_meta = get_post_meta( $post_id, '_listenup_chunked_audio', true );
		if ( ! empty( $chunked_meta ) && isset( $chunked_meta['chunks'] ) ) {
			// Check the first chunk to determine format.
			$first_chunk = $chunked_meta['chunks'][0];
			$parsed_url = wp_parse_url( $first_chunk );
			$file_extension = strtolower( pathinfo( $parsed_url['path'], PATHINFO_EXTENSION ) );
			
			if ( 'mp3' === $file_extension ) {
				$debug->info( 'Post has chunked MP3 audio format' );
				return 'mp3';
			}
		}
		
		// Default to WAV format (Murf.ai default).
		$debug->info( 'Post has WAV audio format (default)' );
		return 'wav';
	}

	/**
	 * Generate pre-roll audio using Murf.ai API.
	 *
	 * @param string $text Text to convert to audio.
	 * @param string $voice_id Voice ID (optional, uses default if not provided).
	 * @param string $voice_style Voice style (optional).
	 * @param int    $post_id Post ID to match audio format (optional).
	 * @return array|WP_Error Result with file path or error.
	 */
	public function generate_pre_roll_audio( $text, $voice_id = '', $voice_style = '', $post_id = 0 ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Generating pre-roll audio with Murf.ai' );

		// Validate text.
		if ( empty( $text ) ) {
			return new WP_Error( 'empty_text', __( 'Pre-roll text cannot be empty.', 'listenup' ) );
		}

		if ( strlen( $text ) > 500 ) {
			return new WP_Error( 'text_too_long', __( 'Pre-roll text must be 500 characters or less.', 'listenup' ) );
		}

		// Use default voice if not provided.
		if ( empty( $voice_id ) ) {
			$options = get_option( 'listenup_options' );
			$voice_id = isset( $options['selected_voice'] ) ? $options['selected_voice'] : '';
			$voice_style = isset( $options['selected_voice_style'] ) ? $options['selected_voice_style'] : '';
		}

		// Generate audio using API.
		$api = ListenUp_API::get_instance();
		// Use post_id = 0 for pre-roll (not tied to a specific post).
		$result = $api->generate_audio( $text, 0, $voice_id, $voice_style );

		if ( is_wp_error( $result ) ) {
			$debug->error( 'Failed to generate pre-roll audio: ' . $result->get_error_message() );
			return $result;
		}

		// Get the audio URL from the result.
		// The generate_audio method may return audio_url or chunks.
		if ( isset( $result['chunks'] ) && is_array( $result['chunks'] ) && ! empty( $result['chunks'] ) ) {
			$audio_url = $result['chunks'][0];
		} elseif ( isset( $result['audio_url'] ) ) {
			$audio_url = $result['audio_url'];
		} else {
			$debug->error( 'Invalid API response format for pre-roll audio', array( 'result' => $result ) );
			return new WP_Error( 'invalid_response', __( 'Failed to get audio URL from API response.', 'listenup' ) );
		}

		if ( empty( $audio_url ) ) {
			return new WP_Error( 'no_audio_url', __( 'No audio URL returned from API.', 'listenup' ) );
		}

		// The audio is already cached by the API, so we need to convert the URL to a file path.
		$upload_dir = wp_upload_dir();

		// Parse the URL to get the file path.
		// The audio_url is typically in format: http://site.com/wp-content/uploads/listenup-audio/filename.mp3
		$parsed_url = wp_parse_url( $audio_url );
		$url_path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';

		// Extract the relative path from uploads directory.
		$uploads_base = wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH );
		if ( strpos( $url_path, $uploads_base ) === 0 ) {
			$relative_path = substr( $url_path, strlen( $uploads_base ) );
			$source_file_path = $upload_dir['basedir'] . $relative_path;
		} else {
			// Fallback: try to download from URL.
			$debug->warning( 'Could not parse audio URL to file path, attempting download', array( 'url' => $audio_url ) );
			$source_file_path = null;
		}

		// Create pre-roll directory.
		$preroll_dir = $upload_dir['basedir'] . '/listenup-preroll';
		if ( ! file_exists( $preroll_dir ) ) {
			wp_mkdir_p( $preroll_dir );
		}

		// Detect the post's audio format to match pre-roll format.
		$audio_format = $post_id > 0 ? $this->detect_post_audio_format( $post_id ) : 'wav';
		$debug->info( 'Detected post audio format: ' . $audio_format );
		
		// Generate unique filename with correct extension.
		$filename = 'preroll-' . md5( $text . $voice_id . $voice_style ) . '.' . $audio_format;
		$file_path = $preroll_dir . '/' . $filename;

		// Copy the cached file to pre-roll directory or download if needed.
		if ( $source_file_path && file_exists( $source_file_path ) ) {
			// Copy the already-cached file.
			$copied = copy( $source_file_path, $file_path );
			if ( ! $copied ) {
				return new WP_Error( 'copy_failed', __( 'Failed to copy pre-roll audio file.', 'listenup' ) );
			}
			$debug->info( 'Pre-roll audio copied from cache: ' . $filename );
		} else {
			// Download the file.
			$response = wp_remote_get( $audio_url, array( 'timeout' => 30 ) );

			if ( is_wp_error( $response ) ) {
				$debug->error( 'Failed to download pre-roll audio: ' . $response->get_error_message() );
				return $response;
			}

			$audio_data = wp_remote_retrieve_body( $response );

			if ( empty( $audio_data ) ) {
				return new WP_Error( 'download_failed', __( 'Failed to download pre-roll audio file.', 'listenup' ) );
			}

			// Save audio file.
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Necessary for binary audio file
			$saved = file_put_contents( $file_path, $audio_data );
			// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

			if ( false === $saved ) {
				return new WP_Error( 'save_failed', __( 'Failed to save pre-roll audio file.', 'listenup' ) );
			}

			$debug->info( 'Pre-roll audio downloaded successfully: ' . $filename );
		}
		
		// Always create both WAV and MP3 formats for maximum compatibility.
		$debug->info( 'Creating dual-format pre-roll audio (WAV + MP3)' );
		
		// Use the conversion API to convert WAV to MP3.
		$conversion_api = ListenUp_Conversion_API::get_instance();
		$conversion_result = $conversion_api->convert_wav_to_mp3( 0, $file_path, basename( $file_path ) );
		
		$mp3_filename = null;
		$mp3_file_path = null;
		
		if ( is_wp_error( $conversion_result ) ) {
			$debug->warning( 'Failed to convert pre-roll to MP3: ' . $conversion_result->get_error_message() );
			// Continue with WAV only if conversion fails.
		} else {
			// Handle both local file and cloud storage responses.
			if ( isset( $conversion_result['filename'] ) && isset( $conversion_result['path'] ) ) {
				// Local file conversion.
				$mp3_filename = $conversion_result['filename'];
				$mp3_file_path = $preroll_dir . '/' . $mp3_filename;
				
				if ( copy( $conversion_result['path'], $mp3_file_path ) ) {
					// Clean up the temporary MP3 file.
					wp_delete_file( $conversion_result['path'] );
					$debug->info( 'Pre-roll MP3 audio created successfully: ' . $mp3_filename );
				} else {
					$debug->warning( 'Failed to move converted MP3 to preroll directory' );
					$mp3_filename = null;
					$mp3_file_path = null;
				}
			} elseif ( isset( $conversion_result['cloud_url'] ) ) {
				// Cloud storage conversion - download the MP3 file.
				$debug->info( 'Downloading MP3 from cloud storage for local storage' );
				$response = wp_remote_get( $conversion_result['cloud_url'], array( 'timeout' => 30 ) );
				
				if ( is_wp_error( $response ) ) {
					$debug->warning( 'Failed to download MP3 from cloud storage: ' . $response->get_error_message() );
					$mp3_filename = null;
					$mp3_file_path = null;
				} else {
					$mp3_data = wp_remote_retrieve_body( $response );
					if ( empty( $mp3_data ) ) {
						$debug->warning( 'Downloaded MP3 data is empty' );
						$mp3_filename = null;
						$mp3_file_path = null;
					} else {
						// Generate MP3 filename based on WAV filename.
						$mp3_filename = str_replace( '.wav', '.mp3', $filename );
						$mp3_file_path = $preroll_dir . '/' . $mp3_filename;
						
						// Save MP3 file.
						$saved = file_put_contents( $mp3_file_path, $mp3_data );
						if ( false === $saved ) {
							$debug->warning( 'Failed to save MP3 file to preroll directory' );
							$mp3_filename = null;
							$mp3_file_path = null;
						} else {
							$debug->info( 'Pre-roll MP3 audio downloaded and saved: ' . $mp3_filename );
						}
					}
				}
			} else {
				$debug->warning( 'Conversion result missing expected keys (filename/path or cloud_url)' );
				$mp3_filename = null;
				$mp3_file_path = null;
			}
		}

		return array(
			'success' => true,
			'wav_file_path' => $file_path,
			'wav_file_url' => $upload_dir['baseurl'] . '/listenup-preroll/' . $filename,
			'wav_filename' => $filename,
			'mp3_file_path' => $mp3_file_path,
			'mp3_file_url' => $mp3_file_path ? $upload_dir['baseurl'] . '/listenup-preroll/' . $mp3_filename : null,
			'mp3_filename' => $mp3_filename,
			'has_mp3' => ! empty( $mp3_filename ),
		);
	}

	/**
	 * Save uploaded pre-roll audio file.
	 *
	 * @param array $file Uploaded file data from $_FILES.
	 * @return array|WP_Error Result with file path or error.
	 */
	public function save_uploaded_pre_roll( $file ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Saving uploaded pre-roll audio file' );

		// Validate file upload.
		if ( empty( $file ) || ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_upload', __( 'Invalid file upload.', 'listenup' ) );
		}

		// Check file size.
		if ( $file['size'] > 10 * 1024 * 1024 ) {
			return new WP_Error( 'file_too_large', __( 'Pre-roll audio file is too large. Maximum size is 10MB.', 'listenup' ) );
		}

		// Check file extension - mandate WAV format for pre-roll.
		$extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'wav' !== $extension ) {
			return new WP_Error( 'invalid_format', __( 'Pre-roll audio file must be in WAV format.', 'listenup' ) );
		}

		// Prepare upload directory.
		$upload_dir = wp_upload_dir();
		$preroll_dir = $upload_dir['basedir'] . '/listenup-preroll';

		if ( ! file_exists( $preroll_dir ) ) {
			wp_mkdir_p( $preroll_dir );
		}

		// Generate unique filename for WAV file.
		$wav_filename = 'preroll-uploaded-' . time() . '.wav';
		$wav_file_path = $preroll_dir . '/' . $wav_filename;

		// Move uploaded WAV file.
		if ( ! move_uploaded_file( $file['tmp_name'], $wav_file_path ) ) {
			return new WP_Error( 'move_failed', __( 'Failed to save uploaded file.', 'listenup' ) );
		}

		$debug->info( 'Pre-roll WAV audio uploaded successfully: ' . $wav_filename );

		// Convert WAV to MP3 using the conversion API.
		$debug->info( 'Converting pre-roll WAV to MP3 for dual-format support' );
		$conversion_api = ListenUp_Conversion_API::get_instance();
		$conversion_result = $conversion_api->convert_wav_to_mp3( 0, $wav_file_path, $wav_filename );
		
		$mp3_filename = null;
		$mp3_file_path = null;
		
		if ( is_wp_error( $conversion_result ) ) {
			$debug->warning( 'Failed to convert pre-roll WAV to MP3: ' . $conversion_result->get_error_message() );
			// Continue with WAV only if conversion fails.
		} else {
			// Handle both local file and cloud storage responses.
			if ( isset( $conversion_result['filename'] ) && isset( $conversion_result['path'] ) ) {
				// Local file conversion.
				$mp3_filename = $conversion_result['filename'];
				$mp3_file_path = $preroll_dir . '/' . $mp3_filename;
				
				if ( copy( $conversion_result['path'], $mp3_file_path ) ) {
					// Clean up the temporary MP3 file.
					wp_delete_file( $conversion_result['path'] );
					$debug->info( 'Pre-roll MP3 audio created successfully: ' . $mp3_filename );
				} else {
					$debug->warning( 'Failed to move converted MP3 to preroll directory' );
					$mp3_filename = null;
					$mp3_file_path = null;
				}
			} elseif ( isset( $conversion_result['cloud_url'] ) ) {
				// Cloud storage conversion - download the MP3 file.
				$debug->info( 'Downloading MP3 from cloud storage for local storage' );
				$response = wp_remote_get( $conversion_result['cloud_url'], array( 'timeout' => 30 ) );
				
				if ( is_wp_error( $response ) ) {
					$debug->warning( 'Failed to download MP3 from cloud storage: ' . $response->get_error_message() );
					$mp3_filename = null;
					$mp3_file_path = null;
				} else {
					$mp3_data = wp_remote_retrieve_body( $response );
					if ( empty( $mp3_data ) ) {
						$debug->warning( 'Downloaded MP3 data is empty' );
						$mp3_filename = null;
						$mp3_file_path = null;
					} else {
						// Generate MP3 filename based on WAV filename.
						$mp3_filename = str_replace( '.wav', '.mp3', $wav_filename );
						$mp3_file_path = $preroll_dir . '/' . $mp3_filename;
						
						// Save MP3 file.
						$saved = file_put_contents( $mp3_file_path, $mp3_data );
						if ( false === $saved ) {
							$debug->warning( 'Failed to save MP3 file to preroll directory' );
							$mp3_filename = null;
							$mp3_file_path = null;
						} else {
							$debug->info( 'Pre-roll MP3 audio downloaded and saved: ' . $mp3_filename );
						}
					}
				}
			} else {
				$debug->warning( 'Conversion result missing expected keys (filename/path or cloud_url)' );
				$mp3_filename = null;
				$mp3_file_path = null;
			}
		}

		return array(
			'success' => true,
			'wav_file_path' => $wav_file_path,
			'wav_file_url' => $upload_dir['baseurl'] . '/listenup-preroll/' . $wav_filename,
			'wav_filename' => $wav_filename,
			'mp3_file_path' => $mp3_file_path,
			'mp3_file_url' => $mp3_file_path ? $upload_dir['baseurl'] . '/listenup-preroll/' . $mp3_filename : null,
			'mp3_filename' => $mp3_filename,
			'has_mp3' => ! empty( $mp3_filename ),
		);
	}
}
