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

		// Get pre-roll audio URL.
		$pre_roll_url = $this->get_pre_roll_url();
		if ( ! $pre_roll_url ) {
			$debug->warning( 'Pre-roll file exists but URL could not be generated' );
			return array(
				'success' => true,
				'audio_url' => is_array( $content_audio ) ? $content_audio : $content_audio,
				'has_pre_roll' => false,
			);
		}

		// Prepare audio chunks array with pre-roll first.
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
	 * @return string|false Pre-roll audio URL or false if not available.
	 */
	public function get_pre_roll_url() {
		$pre_roll_file = $this->get_pre_roll_file();
		if ( ! $pre_roll_file ) {
			return false;
		}

		// Convert file path to URL.
		$upload_dir = wp_upload_dir();
		$relative_path = str_replace( $upload_dir['basedir'], '', $pre_roll_file );
		$pre_roll_url = $upload_dir['baseurl'] . $relative_path;

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

		// Check file extension.
		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$allowed_extensions = array( 'mp3', 'wav', 'ogg', 'm4a' );
		if ( ! in_array( $extension, $allowed_extensions, true ) ) {
			return new WP_Error( 'invalid_format', __( 'Pre-roll audio file must be in MP3, WAV, OGG, or M4A format.', 'listenup' ) );
		}

		return array(
			'success' => true,
			'file_size' => $file_size,
			'file_size_formatted' => size_format( $file_size ),
			'extension' => $extension,
		);
	}

	/**
	 * Generate pre-roll audio using Murf.ai API.
	 *
	 * @param string $text Text to convert to audio.
	 * @param string $voice_id Voice ID (optional, uses default if not provided).
	 * @param string $voice_style Voice style (optional).
	 * @return array|WP_Error Result with file path or error.
	 */
	public function generate_pre_roll_audio( $text, $voice_id = '', $voice_style = '' ) {
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

		// Generate unique filename.
		$filename = 'preroll-' . md5( $text . $voice_id . $voice_style ) . '.mp3';
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

		return array(
			'success' => true,
			'file_path' => $file_path,
			'file_url' => $upload_dir['baseurl'] . '/listenup-preroll/' . $filename,
			'filename' => $filename,
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

		// Check file extension.
		$extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		$allowed_extensions = array( 'mp3', 'wav', 'ogg', 'm4a' );
		if ( ! in_array( $extension, $allowed_extensions, true ) ) {
			return new WP_Error( 'invalid_format', __( 'Pre-roll audio file must be in MP3, WAV, OGG, or M4A format.', 'listenup' ) );
		}

		// Prepare upload directory.
		$upload_dir = wp_upload_dir();
		$preroll_dir = $upload_dir['basedir'] . '/listenup-preroll';

		if ( ! file_exists( $preroll_dir ) ) {
			wp_mkdir_p( $preroll_dir );
		}

		// Generate unique filename.
		$filename = 'preroll-uploaded-' . time() . '.' . $extension;
		$file_path = $preroll_dir . '/' . $filename;

		// Move uploaded file.
		if ( ! move_uploaded_file( $file['tmp_name'], $file_path ) ) {
			return new WP_Error( 'move_failed', __( 'Failed to save uploaded file.', 'listenup' ) );
		}

		$debug->info( 'Pre-roll audio uploaded successfully: ' . $filename );

		return array(
			'success' => true,
			'file_path' => $file_path,
			'file_url' => $upload_dir['baseurl'] . '/listenup-preroll/' . $filename,
			'filename' => $filename,
		);
	}
}
