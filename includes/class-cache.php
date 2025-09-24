<?php
/**
 * Caching functionality for ListenUp plugin.
 *
 * @package ListenUp
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache class for audio file management.
 */
class ListenUp_Cache {

	/**
	 * Single instance of the class.
	 *
	 * @var ListenUp_Cache
	 */
	private static $instance = null;

	/**
	 * Cache directory path.
	 *
	 * @var string
	 */
	private $cache_dir;

	/**
	 * Get single instance of the class.
	 *
	 * @return ListenUp_Cache
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
		$upload_dir = wp_upload_dir();
		$this->cache_dir = $upload_dir['basedir'] . '/listenup-audio';
	}

	/**
	 * Get cached audio for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $text_hash Text hash for verification.
	 * @param string $voice_id Voice ID.
	 * @param string $voice_style Voice style.
	 * @return array|false Cached audio data or false if not found.
	 */
	public function get_cached_audio( $post_id, $text_hash = '', $voice_id = '', $voice_style = '' ) {
		// Debug logging
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Checking for cached audio for post ID: ' . $post_id );
		
		// First check for chunked audio.
		$chunked_audio_meta = get_post_meta( $post_id, '_listenup_chunked_audio', true );
		if ( ! empty( $chunked_audio_meta ) && isset( $chunked_audio_meta['chunks'] ) ) {
			$debug->info( 'Found chunked audio metadata with ' . count( $chunked_audio_meta['chunks'] ) . ' chunks' );
			
			// Verify all chunk files still exist.
			$valid_chunks = array();
			foreach ( $chunked_audio_meta['chunks'] as $chunk_url ) {
				$chunk_filename = basename( wp_parse_url( $chunk_url, PHP_URL_PATH ) );
				$chunk_file = $this->cache_dir . '/' . $chunk_filename;
				
				if ( file_exists( $chunk_file ) ) {
					$valid_chunks[] = $chunk_url;
				} else {
					$debug->warning( 'Chunk file not found: ' . $chunk_file );
				}
			}
			
			if ( count( $valid_chunks ) === count( $chunked_audio_meta['chunks'] ) ) {
				$debug->info( 'All chunk files exist, returning chunked audio data' );
				return array(
					'url' => $valid_chunks[0], // First chunk as fallback
					'chunks' => $valid_chunks,
					'chunked' => true,
					'created' => $chunked_audio_meta['created'],
				);
			} else {
				$debug->warning( 'Some chunk files missing, cleaning up chunked audio metadata' );
				delete_post_meta( $post_id, '_listenup_chunked_audio' );
			}
		}
		
		// Check for single audio file in post meta.
		$audio_meta = get_post_meta( $post_id, '_listenup_audio', true );
		$debug->info( 'Post meta result: ' . ( $audio_meta ? wp_json_encode( $audio_meta ) : 'empty' ) );
		
		if ( ! empty( $audio_meta ) && isset( $audio_meta['url'] ) ) {
			// Verify the audio file still exists.
			$audio_file = $this->cache_dir . '/' . $audio_meta['file'];
			$debug->info( 'Checking audio file: ' . $audio_file );
			$debug->info( 'File exists: ' . ( file_exists( $audio_file ) ? 'yes' : 'no' ) );
			
			if ( file_exists( $audio_file ) ) {
				$debug->info( 'Returning cached audio from post meta' );
				return array(
					'url' => $audio_meta['url'],
					'file' => $audio_file,
					'created' => $audio_meta['created'],
				);
			} else {
				// Clean up orphaned post meta.
				$debug->warning( 'Audio file not found, cleaning up post meta' );
				delete_post_meta( $post_id, '_listenup_audio' );
			}
		} else {
			$debug->info( 'No post meta found for audio' );
		}

		// Fallback to old cache system for backward compatibility.
		$cache_key = $this->get_cache_key( $post_id, $text_hash, $voice_id, $voice_style );
		$cache_file = $this->cache_dir . '/' . $cache_key . '.json';

		if ( ! file_exists( $cache_file ) ) {
			return false;
		}

		$cache_data = json_decode( file_get_contents( $cache_file ), true );
		
		if ( ! $cache_data || ! isset( $cache_data['audio_file'] ) ) {
			return false;
		}

		$audio_file = $this->cache_dir . '/' . $cache_data['audio_file'];
		
		if ( ! file_exists( $audio_file ) ) {
			// Clean up orphaned cache entry.
			wp_delete_file( $cache_file );
			return false;
		}

		$upload_dir = wp_upload_dir();
		$audio_url = $upload_dir['baseurl'] . '/listenup-audio/' . $cache_data['audio_file'];

		return array(
			'url' => $audio_url,
			'file' => $audio_file,
			'created' => $cache_data['created'],
		);
	}

	/**
	 * Cache audio file from URL.
	 *
	 * @param string $audio_url URL of the audio file to cache.
	 * @param int    $post_id Post ID.
	 * @param string $text_hash Text hash for verification.
	 * @param string $voice_id Voice ID.
	 * @param string $voice_style Voice style.
	 * @return array|WP_Error Cached audio data or error.
	 */
	public function cache_audio_file( $audio_url, $post_id, $text_hash = '', $voice_id = '', $voice_style = '' ) {
		// Download the audio file.
		$response = wp_remote_get( $audio_url, array( 'timeout' => 60 ) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'download_failed', $response->get_error_message() );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			return new WP_Error( 'download_failed', __( 'Failed to download audio file.', 'listenup' ) );
		}

		$audio_content = wp_remote_retrieve_body( $response );
		if ( empty( $audio_content ) ) {
			return new WP_Error( 'empty_file', __( 'Downloaded audio file is empty.', 'listenup' ) );
		}

		// Generate unique filename.
		$file_extension = $this->get_file_extension( $audio_url );
		$cache_key = $this->get_cache_key( $post_id, $text_hash, $voice_id, $voice_style );
		$filename = $cache_key . '.' . $file_extension;
		$file_path = $this->cache_dir . '/' . $filename;

		// Ensure cache directory exists.
		if ( ! file_exists( $this->cache_dir ) ) {
			wp_mkdir_p( $this->cache_dir );
		}

		// Write audio file.
		$result = file_put_contents( $file_path, $audio_content );
		if ( false === $result ) {
			return new WP_Error( 'write_failed', __( 'Failed to write audio file to cache.', 'listenup' ) );
		}

		// Create cache metadata.
		$cache_data = array(
			'audio_file' => $filename,
			'created' => current_time( 'mysql' ),
			'post_id' => $post_id,
			'text_hash' => $text_hash,
		);

		$cache_file = $this->cache_dir . '/' . $cache_key . '.json';
		file_put_contents( $cache_file, wp_json_encode( $cache_data ) );

		$upload_dir = wp_upload_dir();
		$audio_url = $upload_dir['baseurl'] . '/listenup-audio/' . $filename;

		// Store post meta to track audio association.
		if ( $post_id > 0 ) {
			// Create a proper hash of the text instead of storing the full text.
			$text_hash_short = substr( md5( $text_hash ), 0, 8 );
			
			$audio_meta = array(
				'url' => $audio_url,
				'file' => $filename,
				'created' => $cache_data['created'],
				'text_hash' => $text_hash_short,
			);
			
			// Debug logging
			$debug = ListenUp_Debug::get_instance();
			$debug->info( 'Storing post meta for post ID: ' . $post_id );
			$debug->info( 'Audio meta data: ' . wp_json_encode( $audio_meta ) );
			
			$result = update_post_meta( $post_id, '_listenup_audio', $audio_meta );
			$debug->info( 'Post meta update result: ' . ( $result ? 'success' : 'failed' ) );
		} else {
			$debug = ListenUp_Debug::get_instance();
			$debug->info( 'Post ID is 0 or invalid, skipping post meta storage' );
		}

		return array(
			'url' => $audio_url,
			'file' => $file_path,
			'created' => $cache_data['created'],
		);
	}

	/**
	 * Clear cache for a specific post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Success status.
	 */
	public function clear_post_cache( $post_id ) {
		// Clear post meta first.
		delete_post_meta( $post_id, '_listenup_audio' );
		delete_post_meta( $post_id, '_listenup_chunked_audio' );

		// Clear chunked audio files.
		$chunked_audio_meta = get_post_meta( $post_id, '_listenup_chunked_audio', true );
		if ( ! empty( $chunked_audio_meta ) && isset( $chunked_audio_meta['chunks'] ) ) {
			foreach ( $chunked_audio_meta['chunks'] as $chunk_url ) {
				$chunk_filename = basename( wp_parse_url( $chunk_url, PHP_URL_PATH ) );
				$chunk_file = $this->cache_dir . '/' . $chunk_filename;
				if ( file_exists( $chunk_file ) ) {
					wp_delete_file( $chunk_file );
				}
			}
		}

		// Clear file-based cache.
		$pattern = $this->cache_dir . '/' . $post_id . '_*.json';
		$files = glob( $pattern );

		$success = true;
		foreach ( $files as $cache_file ) {
			$cache_data = json_decode( file_get_contents( $cache_file ), true );
			
			// Delete audio file.
			if ( isset( $cache_data['audio_file'] ) ) {
				$audio_file = $this->cache_dir . '/' . $cache_data['audio_file'];
				if ( file_exists( $audio_file ) ) {
					wp_delete_file( $audio_file );
				}
			}
			
			// Delete cache metadata.
			wp_delete_file( $cache_file );
		}

		return $success;
	}

	/**
	 * Clear all cached audio files.
	 *
	 * @return bool Success status.
	 */
	public function clear_all_cache() {
		if ( ! file_exists( $this->cache_dir ) ) {
			return true;
		}

		$files = glob( $this->cache_dir . '/*' );
		$success = true;

		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				$success = wp_delete_file( $file ) && $success;
			}
		}

		return $success;
	}

	/**
	 * Get cache statistics.
	 *
	 * @return array Cache statistics.
	 */
	public function get_cache_stats() {
		if ( ! file_exists( $this->cache_dir ) ) {
			return array(
				'files' => 0,
				'size' => 0,
			);
		}

		$files = glob( $this->cache_dir . '/*.mp3' );
		$total_size = 0;

		foreach ( $files as $file ) {
			$total_size += filesize( $file );
		}

		return array(
			'files' => count( $files ),
			'size' => $total_size,
			'size_formatted' => size_format( $total_size ),
		);
	}

	/**
	 * Generate cache key for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $text_hash Text hash.
	 * @param string $voice_id Voice ID.
	 * @param string $voice_style Voice style.
	 * @return string Cache key.
	 */
	private function get_cache_key( $post_id, $text_hash = '', $voice_id = '', $voice_style = '' ) {
		if ( ! empty( $text_hash ) ) {
			// Use the same hash approach as post meta for consistency.
			$text_hash_short = substr( md5( $text_hash ), 0, 8 );
			$voice_hash = ! empty( $voice_id ) ? '_' . substr( md5( $voice_id ), 0, 4 ) : '';
			$style_hash = ! empty( $voice_style ) ? '_' . substr( md5( $voice_style ), 0, 4 ) : '';
			return $post_id . '_' . $text_hash_short . $voice_hash . $style_hash;
		}
		$voice_hash = ! empty( $voice_id ) ? '_' . substr( md5( $voice_id ), 0, 4 ) : '';
		$style_hash = ! empty( $voice_style ) ? '_' . substr( md5( $voice_style ), 0, 4 ) : '';
		return $post_id . '_default' . $voice_hash . $style_hash;
	}

	/**
	 * Get file extension from URL.
	 *
	 * @param string $url File URL.
	 * @return string File extension.
	 */
	private function get_file_extension( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$extension = pathinfo( $path, PATHINFO_EXTENSION );
		
		// Default to mp3 if no extension found.
		return ! empty( $extension ) ? $extension : 'mp3';
	}
}
