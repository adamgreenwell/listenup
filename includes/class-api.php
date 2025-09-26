<?php
/**
 * Murf.ai API integration for ListenUp plugin.
 *
 * @package ListenUp
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API class for Murf.ai integration.
 */
class ListenUp_API {

	/**
	 * Single instance of the class.
	 *
	 * @var ListenUp_API
	 */
	private static $instance = null;

	/**
	 * Murf.ai API base URL.
	 *
	 * @var string
	 */
	private $api_base_url = 'https://api.murf.ai/v1';

	/**
	 * Get single instance of the class.
	 *
	 * @return ListenUp_API
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
		// No hooks needed for this class.
	}

	/**
	 * Generate audio from text using Murf.ai API.
	 *
	 * @param string $text Text to convert to speech.
	 * @param int    $post_id Post ID for caching purposes.
	 * @param string $voice_id Optional voice ID to override the default.
	 * @param string $voice_style Optional voice style to override the default.
	 * @return array|WP_Error Response data or error.
	 */
	public function generate_audio( $text, $post_id = 0, $voice_id = null, $voice_style = null ) {
		$options = get_option( 'listenup_options' );
		$api_key = isset( $options['murf_api_key'] ) ? $options['murf_api_key'] : '';

		if ( empty( $api_key ) ) {
			/* translators: Error message when API key is missing */
			return new WP_Error( 'no_api_key', __( 'Murf.ai API key is not configured.', 'listenup' ) );
		}

		if ( empty( $text ) ) {
			/* translators: Error message when no text is provided */
			return new WP_Error( 'empty_text', __( 'No text provided for audio generation.', 'listenup' ) );
		}

		// Check cache first.
		$cache = ListenUp_Cache::get_instance();
		$cached_audio = $cache->get_cached_audio( $post_id, $text, $voice_id, $voice_style );
		if ( $cached_audio ) {
			return array(
				'success' => true,
				'audio_url' => $cached_audio['url'],
				'cached' => true,
			);
		}

		// Get selected voice from options or use passed voice_id.
		$selected_voice = $voice_id ? $voice_id : ( isset( $options['selected_voice'] ) ? $options['selected_voice'] : 'en-US-natalie' );
		
		// Get selected voice style from options or use passed voice_style.
		$selected_voice_style = $voice_style ? $voice_style : ( isset( $options['selected_voice_style'] ) ? $options['selected_voice_style'] : 'Narration' );

		// Split text into chunks if necessary.
		$chunker = ListenUp_Text_Chunker::get_instance();
		$chunks = $chunker->split_text_into_chunks( $text );

		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Processing ' . count( $chunks ) . ' text chunks' );

		// Generate audio (single or chunked).
		$audio_result = null;
		if ( count( $chunks ) === 1 ) {
			$audio_result = $this->generate_single_chunk_audio( $chunks[0]['text'], $post_id, $selected_voice, $selected_voice_style );
		} else {
			$audio_result = $this->generate_chunked_audio( $chunks, $post_id, $selected_voice, $selected_voice_style );
		}

		if ( is_wp_error( $audio_result ) ) {
			return $audio_result;
		}

		// Add pre-roll audio if configured.
		$pre_roll_manager = ListenUp_Pre_Roll_Manager::get_instance();
		$content_audio = isset( $audio_result['chunks'] ) ? $audio_result['chunks'] : $audio_result['audio_url'];
		$final_result = $pre_roll_manager->add_pre_roll_to_audio( 
			$content_audio, 
			$post_id, 
			md5( $text ), 
			$selected_voice, 
			$selected_voice_style 
		);

		if ( is_wp_error( $final_result ) ) {
			// If pre-roll fails, return the original audio without pre-roll.
			$debug = ListenUp_Debug::get_instance();
			$debug->warning( 'Pre-roll addition failed, returning audio without pre-roll: ' . $final_result->get_error_message() );
			return $audio_result;
		}

		return $final_result;
	}

	/**
	 * Generate audio for a single chunk of text.
	 *
	 * @param string $text Text to convert to speech.
	 * @param int    $post_id Post ID for caching purposes.
	 * @param string $voice_id Voice ID.
	 * @param string $voice_style Voice style.
	 * @return array|WP_Error Response data or error.
	 */
	private function generate_single_chunk_audio( $text, $post_id, $voice_id, $voice_style ) {
		$options = get_option( 'listenup_options' );
		$api_key = $options['murf_api_key'];

		// Clean and prepare text.
		$text = $this->prepare_text( $text );

		// Prepare API request according to Murf.ai documentation.
		$request_data = array(
			'text' => $text,
			'voiceId' => $voice_id,
			'style' => $voice_style,
			'format' => 'WAV',
			'modelVersion' => 'GEN2',
		);

		// Debug logging
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'API Request Data: ' . wp_json_encode( $request_data ) );
		$debug->info( 'Using voice ID: ' . $voice_id );
		$debug->info( 'Using voice style: ' . $voice_style );

		$args = array(
			'method' => 'POST',
			'timeout' => 60,
			'headers' => array(
				'api-key' => $api_key,
				'Content-Type' => 'application/json',
			),
			'body' => wp_json_encode( $request_data ),
		);

		// Make API request to correct endpoint.
		$response = wp_remote_post( $this->api_base_url . '/speech/generate', $args );

		if ( is_wp_error( $response ) ) {
			$debug->error( 'API Request Failed: ' . $response->get_error_message() );
			return new WP_Error( 'api_request_failed', $response->get_error_message() );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		// Debug logging
		$debug->info( 'API Response Code: ' . $response_code );
		$debug->info( 'API Response Body: ' . $response_body );

		if ( 200 !== $response_code ) {
			$error_data = json_decode( $response_body, true );
			$error_message = isset( $error_data['message'] ) ? $error_data['message'] : __( 'Unknown API error occurred.', 'listenup' );
			$debug->error( 'API Error: ' . $error_message );
			return new WP_Error( 'api_error', $error_message );
		}

		$response_data = json_decode( $response_body, true );

		if ( ! isset( $response_data['audioFile'] ) ) {
			return new WP_Error( 'invalid_response', __( 'Invalid response from Murf.ai API.', 'listenup' ) );
		}

		// Cache the audio file.
		$cache = ListenUp_Cache::get_instance();
		$cache_result = $cache->cache_audio_file( $response_data['audioFile'], $post_id, $text, $voice_id, $voice_style );
		
		if ( is_wp_error( $cache_result ) ) {
			// Log the caching error but don't fail the request.
			$debug->warning( 'Failed to cache audio file - ' . $cache_result->get_error_message() );
		}

		return array(
			'success' => true,
			'audio_url' => $cache_result['url'] ?? $response_data['audioFile'],
			'cached' => false,
		);
	}

	/**
	 * Generate audio for multiple chunks of text.
	 *
	 * @param array  $chunks Array of text chunks.
	 * @param int    $post_id Post ID for caching purposes.
	 * @param string $voice_id Voice ID.
	 * @param string $voice_style Voice style.
	 * @return array|WP_Error Response data or error.
	 */
	private function generate_chunked_audio( $chunks, $post_id, $voice_id, $voice_style ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Starting chunked audio generation for ' . count( $chunks ) . ' chunks' );

		$chunk_audio_files = array();
		$options = get_option( 'listenup_options' );
		$api_key = $options['murf_api_key'];

		// Generate audio for each chunk.
		foreach ( $chunks as $chunk ) {
			$debug->info( 'Generating audio for chunk ' . $chunk['chunk_number'] . ' of ' . $chunk['total_chunks'] );

			// Clean and prepare text.
			$text = $this->prepare_text( $chunk['text'] );

			// Prepare API request.
			$request_data = array(
				'text' => $text,
				'voiceId' => $voice_id,
				'style' => $voice_style,
				'format' => 'MP3',
				'modelVersion' => 'GEN2',
			);

			$args = array(
				'method' => 'POST',
				'timeout' => 60,
				'headers' => array(
					'api-key' => $api_key,
					'Content-Type' => 'application/json',
				),
				'body' => wp_json_encode( $request_data ),
			);

			// Make API request.
			$response = wp_remote_post( $this->api_base_url . '/speech/generate', $args );

			if ( is_wp_error( $response ) ) {
				$debug->error( 'Chunk ' . $chunk['chunk_number'] . ' API Request Failed: ' . $response->get_error_message() );
				return new WP_Error( 'chunk_api_failed', sprintf( __( 'Failed to generate audio for chunk %d: %s', 'listenup' ), $chunk['chunk_number'], $response->get_error_message() ) );
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );

			if ( 200 !== $response_code ) {
				$error_data = json_decode( $response_body, true );
				$error_message = isset( $error_data['message'] ) ? $error_data['message'] : __( 'Unknown API error occurred.', 'listenup' );
				$debug->error( 'Chunk ' . $chunk['chunk_number'] . ' API Error: ' . $error_message );
				return new WP_Error( 'chunk_api_error', sprintf( __( 'API error for chunk %d: %s', 'listenup' ), $chunk['chunk_number'], $error_message ) );
			}

			$response_data = json_decode( $response_body, true );

			if ( ! isset( $response_data['audioFile'] ) ) {
				return new WP_Error( 'chunk_invalid_response', sprintf( __( 'Invalid response from API for chunk %d.', 'listenup' ), $chunk['chunk_number'] ) );
			}

			$chunk_audio_files[] = $response_data['audioFile'];
			$debug->info( 'Successfully generated audio for chunk ' . $chunk['chunk_number'] );
		}

		// Cache individual chunks and store chunked audio metadata.
		$cache = ListenUp_Cache::get_instance();
		$cached_chunks = array();
		
		foreach ( $chunk_audio_files as $index => $audio_url ) {
			$chunk_result = $cache->cache_audio_file( $audio_url, $post_id, md5( $text ) . '_chunk_' . $index, $voice_id, $voice_style );
			if ( ! is_wp_error( $chunk_result ) ) {
				$cached_chunks[] = $chunk_result['url'];
			}
		}
		
		// Store chunked audio metadata in post meta.
		$chunked_audio_meta = array(
			'chunks' => $cached_chunks,
			'chunked' => true,
			'created' => current_time( 'mysql' ),
			'text_hash' => substr( md5( $text ), 0, 8 ),
			'total_chunks' => count( $cached_chunks ),
		);
		
		update_post_meta( $post_id, '_listenup_chunked_audio', $chunked_audio_meta );
		
		$debug->info( 'Generated ' . count( $cached_chunks ) . ' audio chunks for client-side concatenation' );
		$debug->info( 'Stored chunked audio metadata in post meta' );
		
		return array(
			'success' => true,
			'audio_url' => $cached_chunks[0], // First chunk as fallback
			'chunks' => $cached_chunks,
			'chunked' => true,
			'cached' => false,
		);
	}

	/**
	 * Prepare text for API submission.
	 *
	 * @param string $text Raw text.
	 * @return string Cleaned text.
	 */
	private function prepare_text( $text ) {
		// Remove HTML tags.
		$text = wp_strip_all_tags( $text );
		
		// Decode HTML entities.
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		
		// Remove extra whitespace.
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );
		
		return $text;
	}

	/**
	 * Test API connection.
	 *
	 * @return array|WP_Error Test result.
	 */
	public function test_api_connection() {
		$options = get_option( 'listenup_options' );
		$api_key = isset( $options['murf_api_key'] ) ? $options['murf_api_key'] : '';

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Murf.ai API key is not configured.', 'listenup' ) );
		}

		$args = array(
			'method' => 'GET',
			'timeout' => 30,
			'headers' => array(
				'api-key' => $api_key,
			),
		);

		$response = wp_remote_get( $this->api_base_url . '/speech/voices', $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'connection_failed', $response->get_error_message() );
		}

		$response_code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $response_code ) {
			return array(
				'success' => true,
				'message' => __( 'API connection successful.', 'listenup' ),
			);
		} else {
			$response_body = wp_remote_retrieve_body( $response );
			$error_data = json_decode( $response_body, true );
			$error_message = isset( $error_data['message'] ) ? $error_data['message'] : __( 'API connection failed.', 'listenup' );
			return new WP_Error( 'api_error', $error_message );
		}
	}

	/**
	 * Get available voices from Murf.ai API.
	 *
	 * @return array|WP_Error Array of voices or error.
	 */
	public function get_available_voices() {
		$options = get_option( 'listenup_options' );
		$api_key = isset( $options['murf_api_key'] ) ? $options['murf_api_key'] : '';

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'Murf.ai API key is not configured.', 'listenup' ) );
		}

		$args = array(
			'method' => 'GET',
			'timeout' => 30,
			'headers' => array(
				'api-key' => $api_key,
			),
		);

		$response = wp_remote_get( $this->api_base_url . '/speech/voices', $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'connection_failed', $response->get_error_message() );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( 200 !== $response_code ) {
			$error_data = json_decode( $response_body, true );
			$error_message = isset( $error_data['message'] ) ? $error_data['message'] : __( 'Failed to fetch voices from API.', 'listenup' );
			return new WP_Error( 'api_error', $error_message );
		}

		$voices_data = json_decode( $response_body, true );

		if ( ! is_array( $voices_data ) ) {
			return new WP_Error( 'invalid_response', __( 'Invalid response format from voices API.', 'listenup' ) );
		}

		// Debug logging
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Retrieved ' . count( $voices_data ) . ' voices from API' );

		return $voices_data;
	}
}
