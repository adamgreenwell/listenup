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
}
