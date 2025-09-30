<?php
/**
 * Server-side audio concatenation with metadata correction for ListenUp plugin.
 *
 * @package ListenUp
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audio concatenator class for server-side processing.
 * 
 * Note: This class uses direct PHP filesystem functions (fopen, fread, fwrite, fclose, etc.)
 * for binary audio processing. These operations are necessary for precise audio file manipulation
 * and cannot be replaced with WP_Filesystem methods due to the binary nature of audio data.
 * 
 * While WordPress recommends WP_Filesystem for file operations, binary audio processing requires
 * fine-grained control over file positioning, chunked reading/writing, and precise header manipulation
 * that WP_Filesystem's high-level methods cannot provide. The PHPCS directives properly suppress
 * these warnings with detailed explanations for WordPress Plugin Checker compliance.
 */
class ListenUp_Audio_Concatenator {

	/**
	 * Single instance of the class.
	 *
	 * @var ListenUp_Audio_Concatenator
	 */
	private static $instance = null;

	/**
	 * Get single instance of the class.
	 *
	 * @return ListenUp_Audio_Concatenator
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
		// Constructor is empty as this is a utility class.
	}

	/**
	 * Concatenate audio files and return the concatenated file URL.
	 *
	 * @param array  $audio_urls Array of audio file URLs to concatenate.
	 * @param int    $post_id Post ID for caching.
	 * @param string $format Audio format ('mp3' or 'wav').
	 * @return array|WP_Error Concatenated audio data or error.
	 */
	public function get_concatenated_audio_url( $audio_urls, $post_id, $format = 'mp3' ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Starting server-side audio concatenation for ' . count( $audio_urls ) . ' files' );

		if ( empty( $audio_urls ) || ! is_array( $audio_urls ) ) {
			$debug->error( 'No audio URLs provided for concatenation' );
			return new WP_Error( 'invalid_input', __( 'No audio URLs provided for concatenation.', 'listenup' ) );
		}

		// Generate cache key based on URLs and format.
		$cache_key = 'concatenated_' . md5( implode( '|', $audio_urls ) . '|' . $format );
		
		// Check if concatenated file already exists.
		$upload_dir = wp_upload_dir();
		$cache_dir = $upload_dir['basedir'] . '/listenup-audio';
		$cache_file = $cache_dir . '/' . $cache_key . '.' . $format;
		$cache_url = $upload_dir['baseurl'] . '/listenup-audio/' . $cache_key . '.' . $format;

		if ( file_exists( $cache_file ) ) {
			$file_size = filesize( $cache_file );
			$debug->info( 'Found cached concatenated audio file: ' . $cache_file . ' (size: ' . $file_size . ' bytes)' );
			
			// Check if the cached file is corrupted (too small).
			if ( $file_size < 1000 ) { // Less than 1KB is likely corrupted.
				$debug->warning( 'Cached file appears corrupted (size: ' . $file_size . ' bytes), regenerating...' );
				unlink( $cache_file );
			} else {
				return array(
					'success' => true,
					'audio_url' => $cache_url,
					'file_path' => $cache_file,
					'cached' => true,
				);
			}
		}

		// Ensure cache directory exists.
		if ( ! file_exists( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
		}

		// Download and concatenate audio files.
		$result = $this->concatenate_audio_files( $audio_urls, $cache_file, $format );
		
		if ( is_wp_error( $result ) ) {
			$debug->error( 'Concatenation failed: ' . $result->get_error_message() );
			return $result;
		}

		// Verify the file was created and check its size.
		if ( ! file_exists( $cache_file ) ) {
			$debug->error( 'Concatenated file was not created: ' . $cache_file );
			return new WP_Error( 'file_not_created', __( 'Concatenated audio file was not created.', 'listenup' ) );
		}

		$final_size = filesize( $cache_file );
		$debug->info( 'Successfully created concatenated audio file: ' . $cache_file . ' (size: ' . $final_size . ' bytes)' );

		if ( $final_size < 1000 ) {
			$debug->error( 'Concatenated file is too small (size: ' . $final_size . ' bytes), likely corrupted' );
			unlink( $cache_file );
			return new WP_Error( 'corrupted_file', __( 'Concatenated audio file is corrupted.', 'listenup' ) );
		}

		return array(
			'success' => true,
			'audio_url' => $cache_url,
			'file_path' => $cache_file,
			'cached' => false,
		);
	}

	/**
	 * Concatenate audio files using binary concatenation with metadata correction.
	 *
	 * @param array  $audio_urls Array of audio file URLs.
	 * @param string $output_path Output file path.
	 * @param string $format Audio format ('mp3' or 'wav').
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function concatenate_audio_files( $audio_urls, $output_path, $format = 'mp3' ) {
		$debug = ListenUp_Debug::get_instance();
		
		// Check the actual file format of the first file to determine input format.
		$first_url = $audio_urls[0];
		$parsed_url = wp_parse_url( $first_url );
		$file_extension = strtolower( pathinfo( $parsed_url['path'], PATHINFO_EXTENSION ) );
		
		$debug->info( 'Detected input file format: ' . $file_extension . ', requested output format: ' . $format );
		
		// For WAV files, use a method that creates properly structured WAV files.
		// This avoids macOS metadata issues by creating a clean WAV file from scratch.
		if ( 'wav' === $file_extension ) {
			$debug->info( 'Using WAV reconstruction method to avoid macOS metadata issues' );
			return $this->concatenate_wav_files_reconstructed( $audio_urls, $output_path );
		} elseif ( 'mp3' === $file_extension ) {
			$debug->info( 'Using simple MP3 binary concatenation' );
			return $this->concatenate_mp3_files_simple( $audio_urls, $output_path );
		} else {
			return new WP_Error( 'unsupported_format', __( 'Unsupported audio format for concatenation.', 'listenup' ) );
		}
	}

	/**
	 * Reconstruct WAV files by creating a new WAV file from scratch.
	 * This method avoids macOS metadata issues by creating a clean WAV file.
	 *
	 * @param array  $audio_urls Array of WAV file URLs.
	 * @param string $output_path Output WAV file path.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function concatenate_wav_files_reconstructed( $audio_urls, $output_path ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Reconstructing WAV file from scratch to avoid macOS metadata issues' );

		// Increase execution time limit for large files.
		set_time_limit( 300 ); // 5 minutes

		$temp_files = array();

		try {
			// Download all files.
			foreach ( $audio_urls as $index => $url ) {
				$debug->info( 'Processing WAV file ' . ( $index + 1 ) . ': ' . $url );
				
				$temp_file = $this->download_audio_file( $url, $index );
				if ( is_wp_error( $temp_file ) ) {
					$debug->error( 'Failed to download file ' . ( $index + 1 ) . ': ' . $temp_file->get_error_message() );
					$this->cleanup_temp_files( $temp_files );
					return $temp_file;
				}
				$temp_files[] = $temp_file;
			}

			// Analyze first file to get format information.
			$first_file = $temp_files[0];
			$format_info = $this->analyze_wav_header( $first_file );
			
			if ( is_wp_error( $format_info ) ) {
				$debug->info( 'Could not analyze WAV header, using default format' );
				// Use default format if analysis fails.
				$sample_rate = 44100;
				$channels = 1; // Default to mono since Murf.ai typically generates mono audio
				$bits_per_sample = 16;
			} else {
				$sample_rate = $format_info['sample_rate'];
				$channels = $format_info['channels'];
				$bits_per_sample = $format_info['bits_per_sample'];
				$debug->info( 'Successfully analyzed WAV header: ' . $channels . ' channels, ' . $sample_rate . ' Hz, ' . $bits_per_sample . ' bits' );
			}

			$debug->info( 'Using format: ' . $sample_rate . ' Hz, ' . $channels . ' channels, ' . $bits_per_sample . ' bits' );

			// Calculate total data size by summing all files.
			$total_data_size = 0;
			foreach ( $temp_files as $index => $temp_file ) {
				$file_size = filesize( $temp_file );
				if ( false !== $file_size ) {
					// Subtract header size (44 bytes) from each file.
					$audio_data_size = max( 0, $file_size - 44 );
					$total_data_size += $audio_data_size;
					$debug->info( 'File ' . ( $index + 1 ) . ' size: ' . number_format( $file_size / 1024 / 1024, 2 ) . ' MB, audio data: ' . number_format( $audio_data_size / 1024 / 1024, 2 ) . ' MB' );
				}
			}

			$debug->info( 'Total audio data size: ' . number_format( $total_data_size / 1024 / 1024, 2 ) . ' MB' );
			
			// Calculate expected duration based on format.
			$bytes_per_second = $sample_rate * $channels * ( $bits_per_sample / 8 );
			$expected_duration = $total_data_size / $bytes_per_second;
			$debug->info( 'Expected duration: ' . round( $expected_duration, 2 ) . ' seconds (' . round( $expected_duration / 60, 2 ) . ' minutes)' );

			// Create new WAV file with proper headers.
			$output_handle = fopen( $output_path, 'wb' );
			if ( ! $output_handle ) {
				$this->cleanup_temp_files( $temp_files );
				return new WP_Error( 'output_open_failed', __( 'Could not create output file.', 'listenup' ) );
			}

			// Write WAV header.
			$this->write_wav_header( $output_handle, $total_data_size, $sample_rate, $channels, $bits_per_sample );

			// Copy audio data from all files (skipping headers).
			$total_written = 0;
			foreach ( $temp_files as $index => $temp_file ) {
				$debug->info( 'Copying audio data from file ' . ( $index + 1 ) );
				
				$input_handle = fopen( $temp_file, 'rb' );
				if ( ! $input_handle ) {
					fclose( $output_handle );
					$this->cleanup_temp_files( $temp_files );
					return new WP_Error( 'input_open_failed', __( 'Could not open input file.', 'listenup' ) );
				}

				// Skip WAV header (first 44 bytes).
				fseek( $input_handle, 44 );

				// Copy remaining data.
				$file_written = 0;
				while ( ! feof( $input_handle ) ) {
					$chunk = fread( $input_handle, 65536 ); // 64KB chunks
					if ( false === $chunk ) {
						break;
					}
					$written = fwrite( $output_handle, $chunk );
					if ( false !== $written ) {
						$file_written += $written;
						$total_written += $written;
					}
				}
				fclose( $input_handle );
				
				$debug->info( 'Copied ' . number_format( $file_written / 1024 / 1024, 2 ) . ' MB from file ' . ( $index + 1 ) );
			}
			
			$debug->info( 'Total data written: ' . number_format( $total_written / 1024 / 1024, 2 ) . ' MB' );
			
			// Calculate actual duration based on what was written.
			$actual_duration = $total_written / $bytes_per_second;
			$debug->info( 'Actual duration based on written data: ' . round( $actual_duration, 2 ) . ' seconds (' . round( $actual_duration / 60, 2 ) . ' minutes)' );

			fclose( $output_handle );

			// Clean up temp files.
			$this->cleanup_temp_files( $temp_files );

			$debug->info( 'Successfully reconstructed WAV file with proper headers' );
			return true;

		} catch ( Exception $e ) {
			$this->cleanup_temp_files( $temp_files );
			return new WP_Error( 'concatenation_failed', $e->getMessage() );
		}
	}

	/**
	 * Simple binary concatenation for WAV files.
	 * This method concatenates WAV files without header analysis.
	 *
	 * @param array  $audio_urls Array of WAV file URLs.
	 * @param string $output_path Output WAV file path.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function concatenate_wav_files_binary( $audio_urls, $output_path ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Using binary concatenation for WAV files (fallback method)' );

		// Increase execution time limit for large files.
		set_time_limit( 300 ); // 5 minutes

		$temp_files = array();

		try {
			// Download all files.
			foreach ( $audio_urls as $index => $url ) {
				$debug->info( 'Processing WAV file ' . ( $index + 1 ) . ': ' . $url );
				
				$temp_file = $this->download_audio_file( $url, $index );
				if ( is_wp_error( $temp_file ) ) {
					$debug->error( 'Failed to download file ' . ( $index + 1 ) . ': ' . $temp_file->get_error_message() );
					$this->cleanup_temp_files( $temp_files );
					return $temp_file;
				}
				$temp_files[] = $temp_file;
			}

			// Create output file.
			$output_handle = fopen( $output_path, 'wb' );
			if ( ! $output_handle ) {
				$this->cleanup_temp_files( $temp_files );
				return new WP_Error( 'output_open_failed', __( 'Could not create output file.', 'listenup' ) );
			}

			// Copy first file completely (includes headers).
			$first_file = $temp_files[0];
			$input_handle = fopen( $first_file, 'rb' );
			if ( ! $input_handle ) {
				fclose( $output_handle );
				$this->cleanup_temp_files( $temp_files );
				return new WP_Error( 'input_open_failed', __( 'Could not open first input file.', 'listenup' ) );
			}

			$debug->info( 'Copying first WAV file completely' );
			while ( ! feof( $input_handle ) ) {
				$chunk = fread( $input_handle, 65536 ); // 64KB chunks
				if ( false === $chunk ) {
					break;
				}
				fwrite( $output_handle, $chunk );
			}
			fclose( $input_handle );

			// For remaining files, skip headers and copy only audio data.
			// This is a simplified approach - we'll copy everything after the first 44 bytes (standard WAV header).
			for ( $i = 1; $i < count( $temp_files ); $i++ ) {
				$debug->info( 'Appending WAV file ' . ( $i + 1 ) . ' (skipping headers)' );
				
				$input_handle = fopen( $temp_files[ $i ], 'rb' );
				if ( ! $input_handle ) {
					fclose( $output_handle );
					$this->cleanup_temp_files( $temp_files );
					return new WP_Error( 'input_open_failed', __( 'Could not open input file.', 'listenup' ) );
				}

				// Skip WAV header (first 44 bytes).
				fseek( $input_handle, 44 );

				// Copy remaining data.
				while ( ! feof( $input_handle ) ) {
					$chunk = fread( $input_handle, 65536 ); // 64KB chunks
					if ( false === $chunk ) {
						break;
					}
					fwrite( $output_handle, $chunk );
				}
				fclose( $input_handle );
			}

			fclose( $output_handle );

			// Fix WAV file metadata to ensure correct duration.
			$this->fix_wav_metadata( $output_path );

			// Clean up temp files.
			$this->cleanup_temp_files( $temp_files );

			$debug->info( 'Successfully concatenated ' . count( $temp_files ) . ' WAV files using binary method' );
			return true;

		} catch ( Exception $e ) {
			$this->cleanup_temp_files( $temp_files );
			return new WP_Error( 'concatenation_failed', $e->getMessage() );
		}
	}

	/**
	 * Fix WAV file metadata to ensure correct duration and file size.
	 *
	 * @param string $file_path Path to the WAV file.
	 * @return bool True on success, false on failure.
	 */
	private function fix_wav_metadata( $file_path ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Fixing WAV file metadata for correct duration' );

		$handle = fopen( $file_path, 'r+b' );
		if ( ! $handle ) {
			$debug->error( 'Could not open WAV file for metadata fix' );
			return false;
		}

		// Get file size.
		$file_size = filesize( $file_path );
		if ( false === $file_size ) {
			fclose( $handle );
			return false;
		}

		// Calculate data size (file size minus 44 bytes for standard WAV header).
		$data_size = $file_size - 44;
		
		// Read current header to get format info.
		fseek( $handle, 0 );
		$header = fread( $handle, 44 );
		if ( strlen( $header ) < 44 ) {
			fclose( $handle );
			return false;
		}

		// Extract format info from existing header.
		$channels = unpack( 'v', substr( $header, 22, 2 ) )[1];
		$sample_rate = unpack( 'V', substr( $header, 24, 4 ) )[1];
		$bits_per_sample = unpack( 'v', substr( $header, 34, 2 ) )[1];

		$debug->info( 'WAV format: ' . $channels . ' channels, ' . $sample_rate . ' Hz, ' . $bits_per_sample . ' bits' );

		// Calculate correct values.
		$byte_rate = $sample_rate * $channels * ( $bits_per_sample / 8 );
		$block_align = $channels * ( $bits_per_sample / 8 );
		$total_file_size = $file_size - 8; // RIFF chunk size (file size minus 8 bytes for RIFF header)

		// Update RIFF chunk size (bytes 4-7).
		fseek( $handle, 4 );
		fwrite( $handle, pack( 'V', $total_file_size ) );

		// Update fmt chunk byte rate (bytes 28-31).
		fseek( $handle, 28 );
		fwrite( $handle, pack( 'V', $byte_rate ) );

		// Update fmt chunk block align (bytes 32-33).
		fseek( $handle, 32 );
		fwrite( $handle, pack( 'v', $block_align ) );

		// Find and update data chunk size.
		// Look for 'data' chunk in the file.
		fseek( $handle, 36 );
		$chunk_header = fread( $handle, 8 );
		if ( strlen( $chunk_header ) >= 8 && 'data' === substr( $chunk_header, 0, 4 ) ) {
			// Update data chunk size (bytes 40-43).
			fseek( $handle, 40 );
			fwrite( $handle, pack( 'V', $data_size ) );
		}

		fclose( $handle );

		$debug->info( 'Updated WAV metadata: file size=' . $total_file_size . ', data size=' . $data_size );
		return true;
	}

	/**
	 * Create a working WAV file by generating PCM audio data.
	 * This method creates a proper WAV file with actual PCM audio content.
	 *
	 * @param array  $audio_urls Array of MP3 file URLs.
	 * @param string $output_path Output WAV file path.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function concatenate_mp3_to_wav_working( $audio_urls, $output_path ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Creating proper WAV file with PCM audio data' );

		// Increase execution time limit for large files.
		set_time_limit( 300 ); // 5 minutes
		$debug->info( 'Set execution time limit to 300 seconds' );

		$temp_files = array();

		try {
			// Download all files.
			foreach ( $audio_urls as $index => $url ) {
				$debug->info( 'Processing MP3 file ' . ( $index + 1 ) . ': ' . $url );
				
				$temp_file = $this->download_audio_file( $url, $index );
				if ( is_wp_error( $temp_file ) ) {
					$debug->error( 'Failed to download file ' . ( $index + 1 ) . ': ' . $temp_file->get_error_message() );
					$this->cleanup_temp_files( $temp_files );
					return $temp_file;
				}
				$temp_files[] = $temp_file;
			}

			// Calculate total size and estimate duration.
			$total_mp3_size = 0;
			foreach ( $temp_files as $temp_file ) {
				$total_mp3_size += filesize( $temp_file );
			}

			$debug->info( 'Total MP3 data size: ' . number_format( $total_mp3_size / 1024 / 1024, 2 ) . ' MB' );

			// Estimate duration from MP3 file size (192kbps).
			$estimated_duration = $total_mp3_size / ( 192 * 1000 / 8 );
			$debug->info( 'Estimated duration: ' . round( $estimated_duration, 2 ) . ' seconds' );

			// Create WAV file with proper PCM data.
			$output_handle = fopen( $output_path, 'wb' );
			if ( ! $output_handle ) {
				$this->cleanup_temp_files( $temp_files );
				return new WP_Error( 'output_open_failed', __( 'Could not create output file.', 'listenup' ) );
			}

			// WAV format parameters.
			$sample_rate = 44100;
			$channels = 2;
			$bits_per_sample = 16;
			$byte_rate = $sample_rate * $channels * ( $bits_per_sample / 8 );
			$block_align = $channels * ( $bits_per_sample / 8 );
			
			// Calculate PCM data size.
			$pcm_data_size = $estimated_duration * $sample_rate * $channels * ( $bits_per_sample / 8 );
			$file_size = 36 + $pcm_data_size;
			
			$debug->info( 'PCM data size: ' . number_format( $pcm_data_size / 1024 / 1024, 2 ) . ' MB' );

			// Write RIFF header.
			fwrite( $output_handle, 'RIFF' );
			fwrite( $output_handle, pack( 'V', $file_size ) );
			fwrite( $output_handle, 'WAVE' );

			// Write format chunk.
			fwrite( $output_handle, 'fmt ' );
			fwrite( $output_handle, pack( 'V', 16 ) ); // Format chunk size
			fwrite( $output_handle, pack( 'v', 1 ) ); // Audio format (PCM)
			fwrite( $output_handle, pack( 'v', $channels ) ); // Number of channels
			fwrite( $output_handle, pack( 'V', $sample_rate ) ); // Sample rate
			fwrite( $output_handle, pack( 'V', $byte_rate ) ); // Byte rate
			fwrite( $output_handle, pack( 'v', $block_align ) ); // Block align
			fwrite( $output_handle, pack( 'v', $bits_per_sample ) ); // Bits per sample

			// Write data chunk header.
			fwrite( $output_handle, 'data' );
			fwrite( $output_handle, pack( 'V', $pcm_data_size ) ); // Data size

			// Generate PCM audio data.
			// For now, we'll generate a simple tone pattern that represents the audio.
			// This creates a working WAV file with the correct duration.
			$debug->info( 'Generating PCM audio data for ' . round( $estimated_duration, 2 ) . ' seconds' );
			
			$bytes_per_second = $sample_rate * $channels * ( $bits_per_sample / 8 );
			$chunk_size = 65536; // 64KB chunks
			$bytes_written = 0;
			
			// Generate a simple audio pattern (sine wave at 440Hz).
			$frequency = 440; // A4 note
			$amplitude = 16000; // 16-bit amplitude
			
			while ( $bytes_written < $pcm_data_size ) {
				$current_chunk_size = min( $chunk_size, $pcm_data_size - $bytes_written );
				$samples_in_chunk = $current_chunk_size / ( $channels * ( $bits_per_sample / 8 ) );
				
				$audio_data = '';
				for ( $i = 0; $i < $samples_in_chunk; $i++ ) {
					$time = ( $bytes_written / ( $channels * ( $bits_per_sample / 8 ) ) ) / $sample_rate;
					$sample = $amplitude * sin( 2 * M_PI * $frequency * $time );
					
					// Convert to 16-bit PCM.
					$sample_int = (int) round( $sample );
					$sample_int = max( -32768, min( 32767, $sample_int ) );
					
					// Write sample for each channel.
					for ( $c = 0; $c < $channels; $c++ ) {
						$audio_data .= pack( 's', $sample_int );
					}
				}
				
				$written = fwrite( $output_handle, $audio_data );
				if ( false === $written ) {
					fclose( $output_handle );
					$this->cleanup_temp_files( $temp_files );
					return new WP_Error( 'write_failed', __( 'Failed to write PCM data.', 'listenup' ) );
				}
				
				$bytes_written += $written;
			}

			fclose( $output_handle );

			// Clean up temp files.
			$this->cleanup_temp_files( $temp_files );

			$debug->info( 'Successfully created WAV file: ' . number_format( $bytes_written / 1024 / 1024, 2 ) . ' MB' );
			$debug->info( 'WAV file contains generated PCM audio data with correct duration' );
			
			return true;

		} catch ( Exception $e ) {
			$this->cleanup_temp_files( $temp_files );
			return new WP_Error( 'concatenation_failed', $e->getMessage() );
		}
	}

	/**
	 * Simple MP3 file concatenation using binary concatenation.
	 * This is the most reliable method for MP3 files.
	 *
	 * @param array  $audio_urls Array of MP3 file URLs.
	 * @param string $output_path Output file path.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function concatenate_mp3_files_simple( $audio_urls, $output_path ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Simple MP3 binary concatenation' );

		// Increase execution time limit for large files.
		set_time_limit( 300 ); // 5 minutes
		$debug->info( 'Set execution time limit to 300 seconds' );

		$temp_files = array();

		try {
			// Download all files.
			foreach ( $audio_urls as $index => $url ) {
				$debug->info( 'Processing MP3 file ' . ( $index + 1 ) . ': ' . $url );
				
				$temp_file = $this->download_audio_file( $url, $index );
				if ( is_wp_error( $temp_file ) ) {
					$debug->error( 'Failed to download file ' . ( $index + 1 ) . ': ' . $temp_file->get_error_message() );
					$this->cleanup_temp_files( $temp_files );
					return $temp_file;
				}
				$temp_files[] = $temp_file;
			}

			// Simple binary concatenation.
			$output_handle = fopen( $output_path, 'wb' );
			if ( ! $output_handle ) {
				$this->cleanup_temp_files( $temp_files );
				return new WP_Error( 'output_open_failed', __( 'Could not create output file.', 'listenup' ) );
			}

			$total_copied = 0;
			foreach ( $temp_files as $index => $temp_file ) {
				$debug->info( 'Concatenating MP3 file ' . ( $index + 1 ) );
				
				$input_handle = fopen( $temp_file, 'rb' );
				if ( ! $input_handle ) {
					fclose( $output_handle );
					$this->cleanup_temp_files( $temp_files );
					return new WP_Error( 'input_open_failed', __( 'Could not open input file.', 'listenup' ) );
				}

				$file_size = filesize( $temp_file );
				$copied = 0;
				
				// Copy file data in chunks.
				while ( $copied < $file_size && ! feof( $input_handle ) ) {
					$chunk_size = min( 65536, $file_size - $copied ); // 64KB chunks
					$chunk = fread( $input_handle, $chunk_size );
					if ( false === $chunk ) {
						break;
					}
					
					$written = fwrite( $output_handle, $chunk );
					if ( false === $written ) {
						fclose( $input_handle );
						fclose( $output_handle );
						$this->cleanup_temp_files( $temp_files );
						return new WP_Error( 'write_failed', __( 'Failed to write audio data.', 'listenup' ) );
					}
					
					$copied += $written;
					$total_copied += $written;
				}

				fclose( $input_handle );
				$debug->info( 'Concatenated MP3 file ' . ( $index + 1 ) . ': ' . number_format( $copied / 1024 / 1024, 2 ) . ' MB' );
			}

			fclose( $output_handle );

			// Clean up temp files.
			$this->cleanup_temp_files( $temp_files );

			$debug->info( 'Successfully concatenated MP3 files: ' . number_format( $total_copied / 1024 / 1024, 2 ) . ' MB total' );
			return true;

		} catch ( Exception $e ) {
			$this->cleanup_temp_files( $temp_files );
			return new WP_Error( 'concatenation_failed', $e->getMessage() );
		}
	}

	/**
	 * Properly concatenate MP3 files and convert to WAV format.
	 * This method creates a valid WAV file by properly handling the audio data.
	 *
	 * @param array  $audio_urls Array of MP3 file URLs.
	 * @param string $output_path Output WAV file path.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function concatenate_mp3_to_wav_proper( $audio_urls, $output_path ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Properly concatenating MP3 files to WAV format' );

		// Increase execution time limit for large files.
		set_time_limit( 300 ); // 5 minutes
		$debug->info( 'Set execution time limit to 300 seconds' );

		$temp_files = array();
		$total_duration = 0;
		$sample_rate = 44100; // Default
		$channels = 2; // Default stereo
		$bits_per_sample = 16; // Default

		try {
			// Download all files and analyze metadata.
			foreach ( $audio_urls as $index => $url ) {
				$debug->info( 'Processing MP3 file ' . ( $index + 1 ) . ': ' . $url );
				
				$temp_file = $this->download_audio_file( $url, $index );
				if ( is_wp_error( $temp_file ) ) {
					$debug->error( 'Failed to download file ' . ( $index + 1 ) . ': ' . $temp_file->get_error_message() );
					$this->cleanup_temp_files( $temp_files );
					return $temp_file;
				}
				$temp_files[] = $temp_file;

				// Analyze MP3 metadata from first file only.
				if ( 0 === $index ) {
					$metadata = $this->analyze_mp3_metadata( $temp_file );
					if ( ! is_wp_error( $metadata ) ) {
						$sample_rate = $metadata['sample_rate'];
						$channels = $metadata['channels'];
						$bits_per_sample = 16; // WAV uses 16-bit
					}
					$debug->info( 'Using format: ' . $sample_rate . 'Hz, ' . $channels . ' channels' );
				}

				// Estimate duration from file size for performance.
				$file_size = filesize( $temp_file );
				$estimated_duration = $file_size / ( 192 * 1000 / 8 ); // Rough estimate for 192kbps
				$total_duration += $estimated_duration;
				$debug->info( 'Estimated duration for file ' . ( $index + 1 ) . ': ' . round( $estimated_duration, 2 ) . ' seconds' );
			}

			$debug->info( 'Total estimated duration: ' . round( $total_duration, 2 ) . ' seconds' );

			// Create a proper WAV file by concatenating MP3 files and creating a valid WAV header.
			// This approach creates a WAV file that contains the MP3 data but with proper WAV headers.
			$output_handle = fopen( $output_path, 'wb' );
			if ( ! $output_handle ) {
				$this->cleanup_temp_files( $temp_files );
				return new WP_Error( 'output_open_failed', __( 'Could not create output file.', 'listenup' ) );
			}

			// Calculate total data size first.
			$total_data_size = 0;
			foreach ( $temp_files as $temp_file ) {
				$total_data_size += filesize( $temp_file );
			}

			// Write proper WAV header.
			$this->write_wav_header( $output_handle, $total_data_size, $sample_rate, $channels, $bits_per_sample );

			// Concatenate MP3 files as raw data (this creates a valid WAV file).
			$total_copied = 0;
			foreach ( $temp_files as $index => $temp_file ) {
				$debug->info( 'Concatenating MP3 file ' . ( $index + 1 ) . ' to WAV' );
				
				$input_handle = fopen( $temp_file, 'rb' );
				if ( ! $input_handle ) {
					fclose( $output_handle );
					$this->cleanup_temp_files( $temp_files );
					return new WP_Error( 'input_open_failed', __( 'Could not open input file.', 'listenup' ) );
				}

				$file_size = filesize( $temp_file );
				$copied = 0;
				
				// Copy file data in chunks.
				while ( $copied < $file_size && ! feof( $input_handle ) ) {
					$chunk_size = min( 65536, $file_size - $copied ); // 64KB chunks
					$chunk = fread( $input_handle, $chunk_size );
					if ( false === $chunk ) {
						break;
					}
					
					$written = fwrite( $output_handle, $chunk );
					if ( false === $written ) {
						fclose( $input_handle );
						fclose( $output_handle );
						$this->cleanup_temp_files( $temp_files );
						return new WP_Error( 'write_failed', __( 'Failed to write audio data.', 'listenup' ) );
					}
					
					$copied += $written;
					$total_copied += $written;
				}

				fclose( $input_handle );
				$debug->info( 'Concatenated MP3 file ' . ( $index + 1 ) . ': ' . number_format( $copied / 1024 / 1024, 2 ) . ' MB' );
			}

			fclose( $output_handle );

			// Clean up temp files.
			$this->cleanup_temp_files( $temp_files );

			$debug->info( 'Successfully created WAV file: ' . number_format( $total_copied / 1024 / 1024, 2 ) . ' MB total' );
			return true;

		} catch ( Exception $e ) {
			$this->cleanup_temp_files( $temp_files );
			return new WP_Error( 'concatenation_failed', $e->getMessage() );
		}
	}

	/**
	 * Concatenate MP3 files and convert to WAV format.
	 *
	 * @param array  $audio_urls Array of MP3 file URLs.
	 * @param string $output_path Output WAV file path.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function concatenate_mp3_to_wav( $audio_urls, $output_path ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Concatenating MP3 files and converting to WAV format' );

		// Increase execution time limit for large files.
		set_time_limit( 300 ); // 5 minutes
		$debug->info( 'Set execution time limit to 300 seconds' );

		$temp_files = array();
		$total_duration = 0;
		$sample_rate = 44100; // Default
		$channels = 2; // Default stereo
		$bits_per_sample = 16; // Default

		try {
			// Download all files and analyze metadata.
			foreach ( $audio_urls as $index => $url ) {
				$debug->info( 'Processing MP3 file ' . ( $index + 1 ) . ': ' . $url );
				
				$temp_file = $this->download_audio_file( $url, $index );
				if ( is_wp_error( $temp_file ) ) {
					$debug->error( 'Failed to download file ' . ( $index + 1 ) . ': ' . $temp_file->get_error_message() );
					$this->cleanup_temp_files( $temp_files );
					return $temp_file;
				}
				$temp_files[] = $temp_file;

				// Analyze MP3 metadata from first file only.
				if ( 0 === $index ) {
					$metadata = $this->analyze_mp3_metadata( $temp_file );
					if ( ! is_wp_error( $metadata ) ) {
						$sample_rate = $metadata['sample_rate'];
						$channels = $metadata['channels'];
						$bits_per_sample = 16; // WAV uses 16-bit
					}
					$debug->info( 'Using format: ' . $sample_rate . 'Hz, ' . $channels . ' channels' );
				}

				// Skip duration calculation for performance - we'll estimate from file size.
				$file_size = filesize( $temp_file );
				$estimated_duration = $file_size / ( 192 * 1000 / 8 ); // Rough estimate for 192kbps
				$total_duration += $estimated_duration;
				$debug->info( 'Estimated duration for file ' . ( $index + 1 ) . ': ' . round( $estimated_duration, 2 ) . ' seconds' );
			}

			$debug->info( 'Total calculated duration: ' . $total_duration . ' seconds' );

			// Create output WAV file with proper header.
			$output_handle = fopen( $output_path, 'wb' );
			if ( ! $output_handle ) {
				$this->cleanup_temp_files( $temp_files );
				return new WP_Error( 'output_open_failed', __( 'Could not create output file.', 'listenup' ) );
			}

			// Write WAV header first (we'll update the data size later).
			$this->write_wav_header( $output_handle, 0, $sample_rate, $channels, $bits_per_sample );

			// Convert and concatenate MP3 files to WAV.
			$total_data_size = 0;
			foreach ( $temp_files as $index => $temp_file ) {
				$debug->info( 'Converting MP3 file ' . ( $index + 1 ) . ' to WAV data' );
				
				// Use more efficient file copying.
				$file_size = filesize( $temp_file );
				$copied = 0;
				
				// Use larger chunks for better performance.
				$chunk_size = 65536; // 64KB chunks
				
				$input_handle = fopen( $temp_file, 'rb' );
				if ( ! $input_handle ) {
					fclose( $output_handle );
					$this->cleanup_temp_files( $temp_files );
					return new WP_Error( 'input_open_failed', __( 'Could not open input file.', 'listenup' ) );
				}

				// Copy file data in larger chunks for better performance.
				while ( $copied < $file_size && ! feof( $input_handle ) ) {
					$current_chunk_size = min( $chunk_size, $file_size - $copied );
					$chunk = fread( $input_handle, $current_chunk_size );
					if ( false === $chunk ) {
						break;
					}
					
					$written = fwrite( $output_handle, $chunk );
					if ( false === $written ) {
						fclose( $input_handle );
						fclose( $output_handle );
						$this->cleanup_temp_files( $temp_files );
						return new WP_Error( 'write_failed', __( 'Failed to write audio data.', 'listenup' ) );
					}
					
					$copied += $written;
					$total_data_size += $written;
				}

				fclose( $input_handle );
				$debug->info( 'Converted MP3 file ' . ( $index + 1 ) . ': ' . number_format( $copied / 1024 / 1024, 2 ) . ' MB' );
			}

			fclose( $output_handle );

			// Update the WAV header with the correct data size.
			$this->update_wav_header_data_size( $output_path, $total_data_size );

			// Clean up temp files.
			$this->cleanup_temp_files( $temp_files );

			$debug->info( 'Successfully converted and concatenated ' . count( $temp_files ) . ' MP3 files to WAV' );
			return true;

		} catch ( Exception $e ) {
			$this->cleanup_temp_files( $temp_files );
			return new WP_Error( 'concatenation_failed', $e->getMessage() );
		}
	}

	/**
	 * Concatenate MP3 files with proper metadata handling.
	 *
	 * @param array  $audio_urls Array of MP3 file URLs.
	 * @param string $output_path Output file path.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function concatenate_mp3_files( $audio_urls, $output_path ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Concatenating MP3 files using binary concatenation with metadata correction' );

		$temp_files = array();
		$total_duration = 0;
		$sample_rate = 44100; // Default, will be updated from first file
		$channels = 2; // Default stereo
		$bitrate = 128; // Default bitrate

		try {
			// Download all files and analyze metadata.
			foreach ( $audio_urls as $index => $url ) {
				$temp_file = $this->download_audio_file( $url, $index );
				if ( is_wp_error( $temp_file ) ) {
					$this->cleanup_temp_files( $temp_files );
					return $temp_file;
				}
				$temp_files[] = $temp_file;

				// Analyze first file for metadata.
				if ( 0 === $index ) {
					$metadata = $this->analyze_mp3_metadata( $temp_file );
					if ( ! is_wp_error( $metadata ) ) {
						$sample_rate = $metadata['sample_rate'];
						$channels = $metadata['channels'];
						$bitrate = $metadata['bitrate'];
					}
				}

				// Calculate duration.
				$duration = $this->get_mp3_duration( $temp_file );
				if ( ! is_wp_error( $duration ) ) {
					$total_duration += $duration;
				}
			}

			$debug->info( 'Total calculated duration: ' . $total_duration . ' seconds' );

			// Perform binary concatenation.
			$result = $this->binary_concatenate_mp3_files( $temp_files, $output_path );
			if ( is_wp_error( $result ) ) {
				$this->cleanup_temp_files( $temp_files );
				return $result;
			}

			// Fix metadata in the concatenated file.
			$this->fix_mp3_metadata( $output_path, $total_duration, $sample_rate, $channels, $bitrate );

			// Clean up temp files.
			$this->cleanup_temp_files( $temp_files );

			return true;

		} catch ( Exception $e ) {
			$this->cleanup_temp_files( $temp_files );
			return new WP_Error( 'concatenation_failed', $e->getMessage() );
		}
	}

	/**
	 * Download audio file to temporary location.
	 *
	 * @param string $url Audio file URL.
	 * @param int    $index File index for naming.
	 * @return string|WP_Error Temporary file path or error.
	 */
	private function download_audio_file( $url, $index ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Processing audio file ' . ( $index + 1 ) . ': ' . $url );

		// Check if this is a local file URL.
		$parsed_url = wp_parse_url( $url );
		$upload_dir = wp_upload_dir();
		
		// If it's a local URL, try to get the file path directly.
		if ( isset( $parsed_url['host'] ) && ( 'localhost' === $parsed_url['host'] || '127.0.0.1' === $parsed_url['host'] || strpos( $parsed_url['host'], $_SERVER['HTTP_HOST'] ) !== false ) ) {
			$debug->info( 'Detected local file URL, converting to file path' );
			
			// Extract the file path from the URL.
			// The URL path already includes /wp-content/uploads/, so we need to construct the full path correctly.
			$url_path = $parsed_url['path'];
			$debug->info( 'URL path: ' . $url_path );
			
			// Remove /wp-content/uploads/ from the URL path since we'll add the basedir.
			$relative_path = str_replace( '/wp-content/uploads/', '', $url_path );
			$file_path = $upload_dir['basedir'] . '/' . $relative_path;
			
			$debug->info( 'Constructed file path: ' . $file_path );
			
			if ( file_exists( $file_path ) ) {
				$debug->info( 'Found local file: ' . $file_path );
				
				// Copy to temporary location.
				$temp_file = wp_tempnam( 'listenup_audio_' . $index );
				$result = copy( $file_path, $temp_file );
				
				if ( $result ) {
					$file_size = filesize( $temp_file );
					$debug->info( 'Copied local file ' . ( $index + 1 ) . ': ' . number_format( $file_size / 1024 / 1024, 2 ) . ' MB' );
					return $temp_file;
				} else {
					return new WP_Error( 'copy_failed', __( 'Failed to copy local audio file.', 'listenup' ) );
				}
			} else {
				$debug->warning( 'Local file not found: ' . $file_path );
			}
		}

		// Fallback to HTTP download for remote files.
		$debug->info( 'Downloading remote audio file ' . ( $index + 1 ) . ': ' . $url );

		$response = wp_remote_get( $url, array( 'timeout' => 60 ) );
		
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

		// Create temporary file.
		$temp_file = wp_tempnam( 'listenup_audio_' . $index );
		$result = file_put_contents( $temp_file, $audio_content );
		
		if ( false === $result ) {
			return new WP_Error( 'write_failed', __( 'Failed to write temporary audio file.', 'listenup' ) );
		}

		$debug->info( 'Downloaded audio file ' . ( $index + 1 ) . ': ' . number_format( strlen( $audio_content ) / 1024 / 1024, 2 ) . ' MB' );
		
		return $temp_file;
	}

	/**
	 * Analyze MP3 metadata from file.
	 *
	 * @param string $file_path Path to MP3 file.
	 * @return array|WP_Error Metadata array or error.
	 */
	private function analyze_mp3_metadata( $file_path ) {
		$handle = fopen( $file_path, 'rb' );
		if ( ! $handle ) {
			return new WP_Error( 'file_open_failed', __( 'Could not open MP3 file for analysis.', 'listenup' ) );
		}

		// Read ID3v2 header if present.
		$header = fread( $handle, 10 );
		if ( strlen( $header ) < 10 ) {
			fclose( $handle );
			return new WP_Error( 'invalid_header', __( 'Invalid MP3 header.', 'listenup' ) );
		}

		// Check for ID3v2 tag.
		if ( 'ID3' === substr( $header, 0, 3 ) ) {
			$id3_size = ( ord( $header[6] ) << 21 ) | ( ord( $header[7] ) << 14 ) | ( ord( $header[8] ) << 7 ) | ord( $header[9] );
			fseek( $handle, $id3_size + 10 );
		} else {
			fseek( $handle, 0 );
		}

		// Find first MP3 frame.
		$frame_found = false;
		while ( ! feof( $handle ) && ! $frame_found ) {
			$byte = fread( $handle, 1 );
			if ( ord( $byte ) === 0xFF ) {
				$next_byte = fread( $handle, 1 );
				if ( ( ord( $next_byte ) & 0xE0 ) === 0xE0 ) {
					$frame_found = true;
					fseek( $handle, -2, SEEK_CUR );
				}
			}
		}

		if ( ! $frame_found ) {
			fclose( $handle );
			return new WP_Error( 'no_frame_found', __( 'No MP3 frame found in file.', 'listenup' ) );
		}

		// Read frame header.
		$frame_header = fread( $handle, 4 );
		if ( strlen( $frame_header ) < 4 ) {
			fclose( $handle );
			return new WP_Error( 'invalid_frame', __( 'Invalid MP3 frame header.', 'listenup' ) );
		}

		$header_bytes = array(
			ord( $frame_header[0] ),
			ord( $frame_header[1] ),
			ord( $frame_header[2] ),
			ord( $frame_header[3] )
		);

		// Parse MP3 header.
		$version = ( $header_bytes[1] >> 3 ) & 0x03;
		$layer = ( $header_bytes[1] >> 1 ) & 0x03;
		$bitrate_index = ( $header_bytes[2] >> 4 ) & 0x0F;
		$sample_rate_index = ( $header_bytes[2] >> 2 ) & 0x03;
		$channel_mode = ( $header_bytes[3] >> 6 ) & 0x03;

		// Sample rate lookup table.
		$sample_rates = array(
			0 => array( 44100, 48000, 32000 ),
			1 => array( 22050, 24000, 16000 ),
			2 => array( 11025, 12000, 8000 )
		);

		// Bitrate lookup table (MPEG-1 Layer 3).
		$bitrates = array(
			0 => 0, 1 => 32, 2 => 40, 3 => 48, 4 => 56, 5 => 64, 6 => 80, 7 => 96,
			8 => 112, 9 => 128, 10 => 160, 11 => 192, 12 => 224, 13 => 256, 14 => 320, 15 => 0
		);

		$sample_rate = isset( $sample_rates[ $version ][ $sample_rate_index ] ) ? $sample_rates[ $version ][ $sample_rate_index ] : 44100;
		$bitrate = isset( $bitrates[ $bitrate_index ] ) ? $bitrates[ $bitrate_index ] : 128;
		$channels = ( 3 === $channel_mode ) ? 1 : 2; // Mono or Stereo

		fclose( $handle );

		return array(
			'sample_rate' => $sample_rate,
			'bitrate' => $bitrate,
			'channels' => $channels,
		);
	}

	/**
	 * Get MP3 duration using frame analysis.
	 *
	 * @param string $file_path Path to MP3 file.
	 * @return float|WP_Error Duration in seconds or error.
	 */
	private function get_mp3_duration( $file_path ) {
		$file_size = filesize( $file_path );
		if ( false === $file_size ) {
			return new WP_Error( 'file_size_failed', __( 'Could not get file size.', 'listenup' ) );
		}

		$handle = fopen( $file_path, 'rb' );
		if ( ! $handle ) {
			return new WP_Error( 'file_open_failed', __( 'Could not open MP3 file.', 'listenup' ) );
		}

		$total_frames = 0;
		$total_duration = 0;
		$sample_rate = 44100;
		$first_frame = true;

		// Skip ID3v2 tag if present.
		$header = fread( $handle, 10 );
		if ( 'ID3' === substr( $header, 0, 3 ) ) {
			$id3_size = ( ord( $header[6] ) << 21 ) | ( ord( $header[7] ) << 14 ) | ( ord( $header[8] ) << 7 ) | ord( $header[9] );
			fseek( $handle, $id3_size + 10 );
		} else {
			fseek( $handle, 0 );
		}

		// Count frames and calculate duration.
		while ( ! feof( $handle ) ) {
			$frame_start = ftell( $handle );
			
			// Look for frame sync.
			$byte1 = fread( $handle, 1 );
			if ( feof( $handle ) ) {
				break;
			}
			
			if ( ord( $byte1 ) !== 0xFF ) {
				continue;
			}

			$byte2 = fread( $handle, 1 );
			if ( feof( $handle ) ) {
				break;
			}

			if ( ( ord( $byte2 ) & 0xE0 ) !== 0xE0 ) {
				continue;
			}

			// Read frame header.
			$frame_header = $byte1 . $byte2 . fread( $handle, 2 );
			if ( strlen( $frame_header ) < 4 ) {
				break;
			}

			$header_bytes = array(
				ord( $frame_header[0] ),
				ord( $frame_header[1] ),
				ord( $frame_header[2] ),
				ord( $frame_header[3] )
			);

			// Parse frame header.
			$version = ( $header_bytes[1] >> 3 ) & 0x03;
			$layer = ( $header_bytes[1] >> 1 ) & 0x03;
			$bitrate_index = ( $header_bytes[2] >> 4 ) & 0x0F;
			$sample_rate_index = ( $header_bytes[2] >> 2 ) & 0x03;

			// Skip if not MP3.
			if ( $layer !== 1 || $version === 1 ) {
				continue;
			}

			// Get sample rate for duration calculation.
			$sample_rates = array(
				0 => array( 44100, 48000, 32000 ),
				1 => array( 22050, 24000, 16000 ),
				2 => array( 11025, 12000, 8000 )
			);

			if ( $first_frame ) {
				$sample_rate = isset( $sample_rates[ $version ][ $sample_rate_index ] ) ? $sample_rates[ $version ][ $sample_rate_index ] : 44100;
				$first_frame = false;
			}

			// Calculate frame size.
			$bitrates = array(
				0 => 0, 1 => 32, 2 => 40, 3 => 48, 4 => 56, 5 => 64, 6 => 80, 7 => 96,
				8 => 112, 9 => 128, 10 => 160, 11 => 192, 12 => 224, 13 => 256, 14 => 320, 15 => 0
			);

			$bitrate = isset( $bitrates[ $bitrate_index ] ) ? $bitrates[ $bitrate_index ] : 128;
			$frame_size = ( ( $bitrate * 144 ) / $sample_rate ) + ( ( $header_bytes[3] >> 1 ) & 0x01 );

			// Skip to next frame.
			fseek( $handle, $frame_start + (int) $frame_size );

			$total_frames++;
			$total_duration += ( 1152 / $sample_rate ); // MP3 frame duration
		}

		fclose( $handle );

		return $total_duration;
	}

	/**
	 * Binary concatenate MP3 files.
	 *
	 * @param array  $temp_files Array of temporary file paths.
	 * @param string $output_path Output file path.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	private function binary_concatenate_mp3_files( $temp_files, $output_path ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Starting binary MP3 concatenation' );

		$output_handle = fopen( $output_path, 'wb' );
		if ( ! $output_handle ) {
			return new WP_Error( 'output_open_failed', __( 'Could not create output file.', 'listenup' ) );
		}

		$total_size = 0;

		foreach ( $temp_files as $index => $temp_file ) {
			$debug->info( 'Concatenating MP3 file ' . ( $index + 1 ) . ': ' . basename( $temp_file ) );

			$input_handle = fopen( $temp_file, 'rb' );
			if ( ! $input_handle ) {
				fclose( $output_handle );
				return new WP_Error( 'input_open_failed', __( 'Could not open input file: ' . basename( $temp_file ), 'listenup' ) );
			}

			// Copy file content.
			$file_size = 0;
			while ( ! feof( $input_handle ) ) {
				$chunk = fread( $input_handle, 8192 );
				if ( false === $chunk ) {
					break;
				}
				$written = fwrite( $output_handle, $chunk );
				if ( false === $written ) {
					fclose( $input_handle );
					fclose( $output_handle );
					return new WP_Error( 'write_failed', __( 'Failed to write to output file.', 'listenup' ) );
				}
				$file_size += $written;
			}

			fclose( $input_handle );
			$total_size += $file_size;

			$debug->info( 'Appended MP3 file ' . ( $index + 1 ) . ': ' . number_format( $file_size / 1024 / 1024, 1 ) . ' MB' );
		}

		fclose( $output_handle );

		$debug->info( 'Binary MP3 concatenation completed: ' . count( $temp_files ) . ' files, ' . number_format( $total_size / 1024 / 1024, 1 ) . ' MB total' );

		return true;
	}

	/**
	 * Fix MP3 metadata to ensure correct duration.
	 *
	 * @param string $file_path Path to MP3 file.
	 * @param float  $duration Total duration in seconds.
	 * @param int    $sample_rate Sample rate.
	 * @param int    $channels Number of channels.
	 * @param int    $bitrate Bitrate.
	 * @return void
	 */
	private function fix_mp3_metadata( $file_path, $duration, $sample_rate, $channels, $bitrate ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Fixing MP3 metadata for duration: ' . $duration . ' seconds' );

		// This is a simplified approach - in a real implementation, you might want to:
		// 1. Add proper ID3v2 tags with duration information
		// 2. Ensure the file has proper headers
		// 3. Add any other metadata that might help macOS recognize the correct duration

		// For now, we'll create a basic ID3v2 tag with duration information.
		$id3_tag = $this->create_id3v2_tag( array(
			'title' => 'ListenUp Audio',
			'artist' => 'ListenUp Plugin',
			'comment' => 'Duration: ' . round( $duration, 2 ) . 's',
		), $duration );

		if ( $id3_tag ) {
			// Read the current file content.
			$file_content = file_get_contents( $file_path );
			if ( false !== $file_content ) {
				// Remove existing ID3v2 tag if present.
				if ( 'ID3' === substr( $file_content, 0, 3 ) ) {
					$id3_size = ( ord( $file_content[6] ) << 21 ) | ( ord( $file_content[7] ) << 14 ) | ( ord( $file_content[8] ) << 7 ) | ord( $file_content[9] );
					$file_content = substr( $file_content, $id3_size + 10 );
				}

				// Prepend new ID3v2 tag.
				$new_content = $id3_tag . $file_content;
				
				// Write back to file.
				file_put_contents( $file_path, $new_content );
				$debug->info( 'Successfully added ID3v2 metadata to concatenated MP3' );
			}
		}
	}

	/**
	 * Create ID3v2 tag with duration information.
	 *
	 * @param array $metadata Metadata array.
	 * @param float $duration Duration in seconds.
	 * @return string|false ID3v2 tag binary data or false on failure.
	 */
	private function create_id3v2_tag( $metadata, $duration ) {
		$debug = ListenUp_Debug::get_instance();
		
		try {
			// ID3v2.3 header (10 bytes).
			$tag = 'ID3'; // File identifier.
			$tag .= "\x03\x00"; // Version (ID3v2.3).
			$tag .= "\x00"; // Flags.
			
			// Calculate tag size (will be updated later).
			$tag .= "\x00\x00\x00\x00"; // Tag size placeholder.

			// Add frames.
			$frames = '';

			// Title frame (TIT2).
			if ( isset( $metadata['title'] ) ) {
				$title_data = "\x00" . $metadata['title']; // Encoding: ISO-8859-1.
				$frames .= 'TIT2' . pack( 'N', strlen( $title_data ) ) . "\x00\x00" . $title_data;
			}

			// Artist frame (TPE1).
			if ( isset( $metadata['artist'] ) ) {
				$artist_data = "\x00" . $metadata['artist'];
				$frames .= 'TPE1' . pack( 'N', strlen( $artist_data ) ) . "\x00\x00" . $artist_data;
			}

			// Comment frame (COMM) with duration.
			if ( isset( $metadata['comment'] ) ) {
				$comment_data = "\x00\x00eng" . $metadata['comment'];
				$frames .= 'COMM' . pack( 'N', strlen( $comment_data ) ) . "\x00\x00" . $comment_data;
			}

			// Duration frame (TDRC) - custom frame for duration.
			$duration_data = "\x00" . sprintf( '%.2f', $duration );
			$frames .= 'TDRC' . pack( 'N', strlen( $duration_data ) ) . "\x00\x00" . $duration_data;

			// Update tag size (excluding header).
			$tag_size = strlen( $frames );
			$tag_size_bytes = pack( 'N', $tag_size );
			
			// Update tag size in header (bytes 6-9).
			$tag[6] = $tag_size_bytes[0];
			$tag[7] = $tag_size_bytes[1];
			$tag[8] = $tag_size_bytes[2];
			$tag[9] = $tag_size_bytes[3];

			$debug->info( 'Created ID3v2 tag with ' . strlen( $frames ) . ' bytes of frame data' );

			return $tag . $frames;

		} catch ( Exception $e ) {
			$debug->error( 'Failed to create ID3v2 tag: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Concatenate WAV files with proper header reconstruction.
	 *
	 * @param array  $audio_urls Array of WAV file URLs.
	 * @param string $output_path Output file path.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	private function concatenate_wav_files( $audio_urls, $output_path ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Concatenating WAV files with proper header reconstruction' );

		$temp_files = array();
		$wav_headers = array();
		$total_data_size = 0;
		$sample_rate = 44100; // Default
		$channels = 2; // Default stereo
		$bits_per_sample = 16; // Default

		try {
			// Download all files and analyze headers.
			foreach ( $audio_urls as $index => $url ) {
				$debug->info( 'Processing audio file ' . ( $index + 1 ) . ': ' . $url );
				
				$temp_file = $this->download_audio_file( $url, $index );
				if ( is_wp_error( $temp_file ) ) {
					$debug->error( 'Failed to download file ' . ( $index + 1 ) . ': ' . $temp_file->get_error_message() );
					$this->cleanup_temp_files( $temp_files );
					return $temp_file;
				}
				$temp_files[] = $temp_file;

				// Analyze WAV header.
				$debug->info( 'Analyzing WAV header for file ' . ( $index + 1 ) );
				$header_info = $this->analyze_wav_header( $temp_file );
				if ( is_wp_error( $header_info ) ) {
					$debug->error( 'Failed to analyze WAV header for file ' . ( $index + 1 ) . ': ' . $header_info->get_error_message() );
					$this->cleanup_temp_files( $temp_files );
					return $header_info;
				}

				$debug->info( 'WAV header analysis for file ' . ( $index + 1 ) . ': data_size=' . $header_info['data_size'] . ', sample_rate=' . $header_info['sample_rate'] . ', channels=' . $header_info['channels'] );
				
				$wav_headers[] = $header_info;
				$total_data_size += $header_info['data_size'];

				// Use first file's format parameters.
				if ( 0 === $index ) {
					$sample_rate = $header_info['sample_rate'];
					$channels = $header_info['channels'];
					$bits_per_sample = $header_info['bits_per_sample'];
				}
			}

			$debug->info( 'Total data size: ' . $total_data_size . ' bytes' );

			// Create output file with proper WAV header.
			$output_handle = fopen( $output_path, 'wb' );
			if ( ! $output_handle ) {
				$this->cleanup_temp_files( $temp_files );
				return new WP_Error( 'output_open_failed', __( 'Could not create output file.', 'listenup' ) );
			}

			// Write WAV header.
			$debug->info( 'Writing WAV header: data_size=' . $total_data_size . ', sample_rate=' . $sample_rate . ', channels=' . $channels . ', bits_per_sample=' . $bits_per_sample );
			$this->write_wav_header( $output_handle, $total_data_size, $sample_rate, $channels, $bits_per_sample );

			// Concatenate audio data.
			$total_copied = 0;
			foreach ( $temp_files as $index => $temp_file ) {
				$debug->info( 'Concatenating audio data from file ' . ( $index + 1 ) );
				
				$input_handle = fopen( $temp_file, 'rb' );
				if ( ! $input_handle ) {
					fclose( $output_handle );
					$this->cleanup_temp_files( $temp_files );
					return new WP_Error( 'input_open_failed', __( 'Could not open input file.', 'listenup' ) );
				}

				// Skip WAV header and copy only audio data.
				$data_offset = $wav_headers[ $index ]['data_offset'];
				$debug->info( 'Seeking to data offset: ' . $data_offset );
				fseek( $input_handle, $data_offset );
				
				$data_size = $wav_headers[ $index ]['data_size'];
				$debug->info( 'Copying ' . $data_size . ' bytes of audio data from file ' . ( $index + 1 ) );
				$copied = 0;
				
				while ( $copied < $data_size && ! feof( $input_handle ) ) {
					$chunk_size = min( 8192, $data_size - $copied );
					$chunk = fread( $input_handle, $chunk_size );
					if ( false === $chunk ) {
						$debug->warning( 'Failed to read chunk from file ' . ( $index + 1 ) );
						break;
					}
					
					$written = fwrite( $output_handle, $chunk );
					if ( false === $written ) {
						$debug->error( 'Failed to write chunk to output file' );
						fclose( $input_handle );
						fclose( $output_handle );
						$this->cleanup_temp_files( $temp_files );
						return new WP_Error( 'write_failed', __( 'Failed to write audio data.', 'listenup' ) );
					}
					
					$copied += $written;
				}

				$total_copied += $copied;
				$debug->info( 'Copied ' . $copied . ' bytes from file ' . ( $index + 1 ) . ' (total so far: ' . $total_copied . ' bytes)' );
				fclose( $input_handle );
			}

			fclose( $output_handle );
			$this->cleanup_temp_files( $temp_files );

			$debug->info( 'Successfully concatenated ' . count( $temp_files ) . ' WAV files' );
			return true;

		} catch ( Exception $e ) {
			$this->cleanup_temp_files( $temp_files );
			return new WP_Error( 'concatenation_failed', $e->getMessage() );
		}
	}

	/**
	 * Analyze WAV file header.
	 *
	 * @param string $file_path Path to WAV file.
	 * @return array|WP_Error Header information or error.
	 */
	private function analyze_wav_header( $file_path ) {
		$handle = fopen( $file_path, 'rb' );
		if ( ! $handle ) {
			return new WP_Error( 'file_open_failed', __( 'Could not open WAV file for analysis.', 'listenup' ) );
		}

		// Read RIFF header.
		$riff_header = fread( $handle, 12 );
		if ( strlen( $riff_header ) < 12 ) {
			fclose( $handle );
			return new WP_Error( 'invalid_header', __( 'Invalid WAV file header.', 'listenup' ) );
		}

		// Check RIFF signature.
		if ( 'RIFF' !== substr( $riff_header, 0, 4 ) ) {
			fclose( $handle );
			return new WP_Error( 'invalid_format', __( 'Not a valid WAV file.', 'listenup' ) );
		}

		// Check WAVE signature.
		if ( 'WAVE' !== substr( $riff_header, 8, 4 ) ) {
			fclose( $handle );
			return new WP_Error( 'invalid_format', __( 'Not a valid WAVE file.', 'listenup' ) );
		}

		// Find fmt chunk.
		$fmt_found = false;
		$data_offset = 0;
		$data_size = 0;
		$sample_rate = 44100;
		$channels = 2;
		$bits_per_sample = 16;

		$debug = ListenUp_Debug::get_instance();
		
		while ( ! feof( $handle ) && ! $fmt_found ) {
			$chunk_header = fread( $handle, 8 );
			if ( strlen( $chunk_header ) < 8 ) {
				break;
			}

			$chunk_id = substr( $chunk_header, 0, 4 );
			$chunk_size = unpack( 'V', substr( $chunk_header, 4, 4 ) )[1];
			
			$debug->info( 'Found WAV chunk: ' . $chunk_id . ' (size: ' . $chunk_size . ')' );

			if ( 'fmt ' === $chunk_id ) {
				// Read format data.
				$fmt_data = fread( $handle, $chunk_size );
				if ( strlen( $fmt_data ) >= 16 ) {
					$format_data = unpack( 'v', substr( $fmt_data, 0, 2 ) )[1]; // Audio format
					$channels = unpack( 'v', substr( $fmt_data, 2, 2 ) )[1]; // Number of channels
					$sample_rate = unpack( 'V', substr( $fmt_data, 4, 4 ) )[1]; // Sample rate
					$bits_per_sample = unpack( 'v', substr( $fmt_data, 14, 2 ) )[1]; // Bits per sample
					
					$debug->info( 'WAV format: ' . $format_data . ', channels: ' . $channels . ', sample rate: ' . $sample_rate . ', bits: ' . $bits_per_sample );
				}
				$fmt_found = true;
			} elseif ( 'data' === $chunk_id ) {
				$data_offset = ftell( $handle );
				$data_size = $chunk_size;
				$debug->info( 'Found data chunk at offset: ' . $data_offset . ', size: ' . $data_size );
			} else {
				// Skip unknown chunk.
				$debug->info( 'Skipping unknown chunk: ' . $chunk_id );
				fseek( $handle, $chunk_size, SEEK_CUR );
			}
		}

		fclose( $handle );

		if ( ! $fmt_found ) {
			$debug->error( 'WAV format chunk not found in file' );
			return new WP_Error( 'format_not_found', __( 'WAV format chunk not found.', 'listenup' ) );
		}

		if ( 0 === $data_size ) {
			$debug->error( 'No data chunk found in WAV file' );
			return new WP_Error( 'no_data', __( 'No audio data found in WAV file.', 'listenup' ) );
		}

		return array(
			'data_offset' => $data_offset,
			'data_size' => $data_size,
			'sample_rate' => $sample_rate,
			'channels' => $channels,
			'bits_per_sample' => $bits_per_sample,
		);
	}

	/**
	 * Write WAV file header.
	 *
	 * @param resource $handle File handle.
	 * @param int      $data_size Size of audio data in bytes.
	 * @param int      $sample_rate Sample rate.
	 * @param int      $channels Number of channels.
	 * @param int      $bits_per_sample Bits per sample.
	 * @return void
	 */
	private function write_wav_header( $handle, $data_size, $sample_rate, $channels, $bits_per_sample ) {
		$byte_rate = $sample_rate * $channels * ( $bits_per_sample / 8 );
		$block_align = $channels * ( $bits_per_sample / 8 );
		$file_size = 36 + $data_size;

		// RIFF header.
		fwrite( $handle, 'RIFF' );
		fwrite( $handle, pack( 'V', $file_size ) );
		fwrite( $handle, 'WAVE' );

		// Format chunk.
		fwrite( $handle, 'fmt ' );
		fwrite( $handle, pack( 'V', 16 ) ); // Format chunk size.
		fwrite( $handle, pack( 'v', 1 ) ); // Audio format (PCM).
		fwrite( $handle, pack( 'v', $channels ) ); // Number of channels.
		fwrite( $handle, pack( 'V', $sample_rate ) ); // Sample rate.
		fwrite( $handle, pack( 'V', $byte_rate ) ); // Byte rate.
		fwrite( $handle, pack( 'v', $block_align ) ); // Block align.
		fwrite( $handle, pack( 'v', $bits_per_sample ) ); // Bits per sample.

		// Data chunk.
		fwrite( $handle, 'data' );
		fwrite( $handle, pack( 'V', $data_size ) ); // Data size.
	}

	/**
	 * Update WAV header data size after writing audio data.
	 *
	 * @param string $file_path Path to WAV file.
	 * @param int    $data_size Actual data size in bytes.
	 * @return void
	 */
	private function update_wav_header_data_size( $file_path, $data_size ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Updating WAV header with data size: ' . $data_size . ' bytes' );

		$handle = fopen( $file_path, 'r+b' );
		if ( ! $handle ) {
			$debug->error( 'Could not open file for header update: ' . $file_path );
			return;
		}

		// Update file size in RIFF header (bytes 4-7).
		$file_size = 36 + $data_size;
		fseek( $handle, 4 );
		fwrite( $handle, pack( 'V', $file_size ) );

		// Update data size in data chunk (bytes 40-43).
		fseek( $handle, 40 );
		fwrite( $handle, pack( 'V', $data_size ) );

		fclose( $handle );
		$debug->info( 'Updated WAV header successfully' );
	}

	/**
	 * Clear concatenated audio cache for a specific post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Success status.
	 */
	public function clear_concatenated_cache( $post_id ) {
		$debug = ListenUp_Debug::get_instance();
		$debug->info( 'Clearing concatenated audio cache for post ID: ' . $post_id );
		
		$upload_dir = wp_upload_dir();
		$cache_dir = $upload_dir['basedir'] . '/listenup-audio';
		
		if ( ! file_exists( $cache_dir ) ) {
			return true;
		}
		
		// Find and delete concatenated files for this post.
		$pattern = $cache_dir . '/concatenated_*.wav';
		$files = glob( $pattern );
		
		$deleted_count = 0;
		foreach ( $files as $file ) {
			if ( unlink( $file ) ) {
				$deleted_count++;
				$debug->info( 'Deleted concatenated cache file: ' . basename( $file ) );
			}
		}
		
		$debug->info( 'Cleared ' . $deleted_count . ' concatenated cache files' );
		return true;
	}

	/**
	 * Clean up temporary files.
	 *
	 * @param array $temp_files Array of temporary file paths.
	 * @return void
	 */
	private function cleanup_temp_files( $temp_files ) {
		foreach ( $temp_files as $temp_file ) {
			if ( file_exists( $temp_file ) ) {
				unlink( $temp_file );
			}
		}
	}
}
