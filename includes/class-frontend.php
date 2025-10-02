<?php
/**
 * Frontend functionality for ListenUp plugin.
 *
 * @package ListenUp
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend class for audio player display.
 */
class ListenUp_Frontend {

	/**
	 * Single instance of the class.
	 *
	 * @var ListenUp_Frontend
	 */
	private static $instance = null;

	/**
	 * Get single instance of the class.
	 *
	 * @return ListenUp_Frontend
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
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks.
	 */
	private function init_hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'the_content', array( $this, 'maybe_add_audio_player' ) );
		add_action( 'wp_ajax_listenup_download_wav', array( $this, 'ajax_download_wav' ) );
		add_action( 'wp_ajax_nopriv_listenup_download_wav', array( $this, 'ajax_download_wav' ) );
		add_action( 'wp_ajax_listenup_serve_audio', array( $this, 'ajax_serve_audio' ) );
		add_action( 'wp_ajax_nopriv_listenup_serve_audio', array( $this, 'ajax_serve_audio' ) );
	}

	/**
	 * Enqueue frontend scripts and styles.
	 */
	public function enqueue_scripts() {
		// Load on all pages since shortcode can be used anywhere.
		// The JavaScript will only initialize if audio players are present.

		wp_enqueue_style(
			'listenup-frontend',
			LISTENUP_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			LISTENUP_VERSION
		);

		wp_enqueue_script(
			'listenup-audio-concatenator',
			LISTENUP_PLUGIN_URL . 'assets/js/audio-concatenator.js',
			array(),
			LISTENUP_VERSION,
			true
		);

		wp_enqueue_script(
			'listenup-frontend',
			LISTENUP_PLUGIN_URL . 'assets/js/frontend.js',
			array( 'jquery', 'listenup-audio-concatenator' ),
			LISTENUP_VERSION,
			true
		);

		// Get download restriction setting.
		$options = get_option( 'listenup_options' );
		$download_restriction = isset( $options['download_restriction'] ) ? $options['download_restriction'] : 'allow_all';

		// Localize script with AJAX data.
		wp_localize_script(
			'listenup-frontend',
			'listenupAjax',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'listenup_download_wav' ),
				'downloadRestriction' => $download_restriction,
				'isUserLoggedIn' => is_user_logged_in(),
			)
		);
	}

	/**
	 * Maybe add audio player to content.
	 *
	 * @param string $content Post content.
	 * @return string Modified content.
	 */
	public function maybe_add_audio_player( $content ) {
		// Only on single posts/pages.
		if ( ! is_singular() ) {
			return $content;
		}

		global $post;
		if ( ! $post ) {
			return $content;
		}

		// Check if auto-placement is enabled.
		$options = get_option( 'listenup_options' );
		$auto_placement = isset( $options['auto_placement'] ) ? $options['auto_placement'] : 'none';
		$placement_position = isset( $options['placement_position'] ) ? $options['placement_position'] : 'after';

		// Determine if we should show the player.
		$should_show = false;
		switch ( $auto_placement ) {
			case 'posts':
				$should_show = 'post' === $post->post_type;
				break;
			case 'pages':
				$should_show = 'page' === $post->post_type;
				break;
			case 'both':
				$should_show = in_array( $post->post_type, array( 'post', 'page' ), true );
				break;
		}

		if ( ! $should_show ) {
			return $content;
		}

		// Check if audio exists for this post.
		$cache = ListenUp_Cache::get_instance();
		$cached_audio = $cache->get_cached_audio( $post->ID );

		if ( ! $cached_audio ) {
			return $content;
		}

		// Generate audio player HTML.
		$audio_player = $this->generate_audio_player( $cached_audio, $post->ID );

		// Add player to content based on position.
		if ( 'before' === $placement_position ) {
			$content = $audio_player . $content;
		} else {
			$content = $content . $audio_player;
		}

		return $content;
	}

	/**
	 * Generate audio player HTML.
	 *
	 * @param string|array $audio_data Audio URL or array with audio data.
	 * @param int          $post_id Post ID.
	 * @return string Audio player HTML.
	 */
	public function generate_audio_player( $audio_data, $post_id = 0 ) {
		$player_id = 'listenup-player-' . $post_id;
		
		// Handle both single URL and chunked audio data
		$audio_url = '';
		$audio_chunks = null;
		$cloud_url = null;
		$is_cloud_storage = false;
		
		if ( is_array( $audio_data ) ) {
			// Check if this is cloud storage audio
			if ( isset( $audio_data['cloud_storage'] ) && $audio_data['cloud_storage'] ) {
				$is_cloud_storage = true;
				$cloud_url = isset( $audio_data['cloud_url'] ) ? $audio_data['cloud_url'] : $audio_data['url'];
				$audio_url = $cloud_url; // Use cloud URL as primary
				
				// For chunked audio, keep local chunks as fallback
				if ( isset( $audio_data['chunks'] ) ) {
					$audio_chunks = $audio_data['chunks'];
				}
			} elseif ( isset( $audio_data['chunks'] ) ) {
				$audio_chunks = $audio_data['chunks'];
				$audio_url = isset( $audio_data['chunks'][0] ) ? $audio_data['chunks'][0] : ''; // Fallback URL
			} else {
				// Check if array has numeric keys (chunked audio) or is a single URL array
				if ( isset( $audio_data[0] ) ) {
					$audio_url = $audio_data[0]; // First chunk as fallback
					$audio_chunks = $audio_data;
				} else {
					// Single audio file stored as array with non-numeric keys
					$audio_url = reset( $audio_data ); // Get first value
					$audio_chunks = null;
				}
			}
		} else {
			$audio_url = $audio_data;
		}

		// Convert URLs to secure URLs
		if ( $is_cloud_storage ) {
			// Convert cloud storage URLs to HTTPS
			$audio_url = $this->convert_to_https( $audio_url );
			if ( $audio_chunks ) {
				$audio_chunks = array_map( array( $this, 'convert_to_https' ), $audio_chunks );
			}
		} else {
			// Convert local URLs to secure proxy URLs
			$audio_url = $this->get_secure_audio_url( $audio_url );
			if ( $audio_chunks ) {
				$audio_chunks = array_map( array( $this, 'get_secure_audio_url' ), $audio_chunks );
			}
		}
		
		ob_start();
		?>
		<div class="listenup-audio-player" id="<?php echo esc_attr( $player_id ); ?>" data-post-id="<?php echo esc_attr( $post_id ); ?>" <?php echo $audio_chunks ? 'data-audio-chunks="' . esc_attr( wp_json_encode( $audio_chunks ) ) . '"' : ''; ?> <?php echo $is_cloud_storage ? 'data-cloud-storage="true" data-cloud-url="' . esc_attr( $this->convert_to_https( $cloud_url ) ) . '"' : ''; ?>>
			<div class="listenup-player-header">
				<h3 class="listenup-player-title">
					<?php /* translators: Audio player title */ esc_html_e( 'Listen to this content', 'listenup' ); ?>
				</h3>
			</div>
			
			<div class="listenup-player-controls">
				<button type="button" class="listenup-play-button" aria-label="<?php /* translators: Play button aria label */ esc_attr_e( 'Play audio', 'listenup' ); ?>">
					<span class="listenup-play-icon" aria-hidden="true">
						<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" width="64px" height="64px" viewBox="0 0 64 64"><g transform="translate(0, 0)"><path d="M54.56,31.171l-40-27A1,1,0,0,0,13,5V59a1,1,0,0,0,1.56.829l40-27a1,1,0,0,0,0-1.658Z" fill="#444444"></path></g></svg>
					</span>
					<span class="listenup-pause-icon" aria-hidden="true" style="display: none;">
						<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" width="64px" height="64px" viewBox="0 0 64 64"><g transform="translate(0, 0)"><rect x="4" y="4" width="17" height="56" rx="2" fill="#444444"></rect><rect data-color="color-2" x="43" y="4" width="17" height="56" rx="2" fill="#444444"></rect></g></svg>
					</span>
				</button>
				
				<div class="listenup-progress-container">
					<div class="listenup-progress-bar">
						<div class="listenup-progress-fill"></div>
					</div>
					<div class="listenup-time-display">
						<span class="listenup-current-time">0:00</span>
						<span class="listenup-duration">0:00</span>
					</div>
				</div>
				
				<button type="button" class="listenup-download-button" aria-label="<?php /* translators: Download button aria label */ esc_attr_e( 'Download audio', 'listenup' ); ?>">
					<span class="listenup-download-icon" aria-hidden="true">
						<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" width="64px" height="64px" viewBox="0 0 64 64"><g transform="translate(0, 0)"><path data-color="color-2" d="M31.206,44.607a1,1,0,0,0,1.588,0l13-17A1,1,0,0,0,45,26H35V6a3,3,0,0,0-6,0V26H19a1,1,0,0,0-.794,1.607Z" fill="#444444"></path><path d="M60,41a1,1,0,0,0-1,1V53a4,4,0,0,1-4,4H9a4,4,0,0,1-4-4V42a1,1,0,0,0-2,0V53a6.006,6.006,0,0,0,6,6H55a6.006,6.006,0,0,0,6-6V42A1,1,0,0,0,60,41Z" fill="#444444"></path></g></svg>
					</span>
				</button>
			</div>
			
			<audio 
				class="listenup-audio-element" 
				preload="metadata"
				aria-label="<?php /* translators: Audio element aria label */ esc_attr_e( 'Audio player for post content', 'listenup' ); ?>"
			>
				<source src="<?php echo esc_url( $audio_url ); ?>" type="audio/mpeg">
				<?php if ( $is_cloud_storage && $audio_chunks ) : ?>
					<!-- Fallback to local chunks if cloud fails -->
					<?php foreach ( $audio_chunks as $chunk_url ) : ?>
						<source src="<?php echo esc_url( $this->get_secure_audio_url( $chunk_url ) ); ?>" type="audio/wav">
					<?php endforeach; ?>
				<?php endif; ?>
				<?php /* translators: Fallback text for unsupported browsers */ esc_html_e( 'Your browser does not support the audio element.', 'listenup' ); ?>
			</audio>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get audio player for shortcode.
	 *
	 * @param int $post_id Post ID (optional, defaults to current post).
	 * @return string Audio player HTML or empty string.
	 */
	public function get_audio_player_for_shortcode( $post_id = 0 ) {
		if ( ! $post_id ) {
			global $post;
			$post_id = $post ? $post->ID : 0;
		}

		if ( ! $post_id ) {
			return '';
		}

		// Check if audio exists for this post.
		$cache = ListenUp_Cache::get_instance();
		$cached_audio = $cache->get_cached_audio( $post_id );

		if ( ! $cached_audio ) {
			/* translators: Message when no audio is available for content */
			return '<p class="listenup-no-audio">' . esc_html__( 'No audio available for this content.', 'listenup' ) . '</p>';
		}

		return $this->generate_audio_player( $cached_audio, $post_id );
	}

	/**
	 * AJAX handler for WAV download.
	 */
	public function ajax_download_wav() {
		// Verify nonce for security.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'listenup_download_wav' ) ) {
			wp_die( 'Security check failed' );
		}

		// Check download restrictions.
		$options = get_option( 'listenup_options' );
		$download_restriction = isset( $options['download_restriction'] ) ? $options['download_restriction'] : 'allow_all';

		if ( 'disable' === $download_restriction ) {
			wp_die( esc_html__( 'Downloads are currently disabled.', 'listenup' ) );
		}

		if ( 'logged_in_only' === $download_restriction && ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in to download audio files.', 'listenup' ) );
		}

		$post_id = intval( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_die( 'Invalid post ID' );
		}

		// Check if audio exists for this post.
		$cache = ListenUp_Cache::get_instance();
		$cached_audio = $cache->get_cached_audio( $post_id );

		if ( ! $cached_audio ) {
			wp_die( 'No audio available for this post' );
		}

		// Get direct audio URLs (not secure proxy URLs).
		// The concatenator needs direct URLs to access files from the filesystem.
		$audio_urls = $this->get_direct_audio_urls( $cached_audio );

		if ( empty( $audio_urls ) || count( $audio_urls ) <= 1 ) {
			wp_die( 'No chunked audio available for concatenation' );
		}

		// Use server-side concatenator to create WAV file.
		$concatenator = ListenUp_Audio_Concatenator::get_instance();
		$result = $concatenator->get_concatenated_audio_url( $audio_urls, $post_id, 'wav' );

		if ( is_wp_error( $result ) ) {
			wp_die( 'Failed to concatenate audio: ' . esc_html( $result->get_error_message() ) );
		}

		// Verify the file exists and get its size.
		if ( ! file_exists( $result['file_path'] ) ) {
			wp_die( 'Concatenated audio file not found' );
		}

		$file_size = filesize( $result['file_path'] );
		if ( false === $file_size || $file_size === 0 ) {
			wp_die( 'Concatenated audio file is empty or corrupted' );
		}

		// Set headers for file download.
		$filename = 'listenup-audio-' . $post_id . '-' . gmdate( 'Y-m-d-H-i-s' ) . '.wav';

		// Clear any previous output.
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		header( 'Content-Type: audio/wav' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . $file_size );
		header( 'Cache-Control: no-cache, must-revalidate' );
		header( 'Expires: Sat, 26 Jul 1997 05:00:00 GMT' );
		header( 'Accept-Ranges: bytes' );

		// Stream the file directly to avoid memory issues with large files.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Required for streaming large binary files
		$handle = fopen( $result['file_path'], 'rb' );
		if ( false === $handle ) {
			wp_die( 'Could not read concatenated audio file' );
		}

		// Output file in 64KB chunks to avoid memory issues.
		while ( ! feof( $handle ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file output
			echo fread( $handle, 65536 );
			flush();
		}

		fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		exit;
	}

	/**
	 * AJAX handler for secure audio file serving.
	 * Serves audio files through PHP with permission checks and range request support.
	 */
	public function ajax_serve_audio() {
		// Get and validate parameters.
		$file = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';

		if ( empty( $file ) || empty( $nonce ) ) {
			status_header( 400 );
			wp_die( 'Invalid request' );
		}

		// Verify nonce.
		if ( ! wp_verify_nonce( $nonce, 'listenup_serve_audio_' . $file ) ) {
			status_header( 403 );
			wp_die( 'Security check failed' );
		}

		// Check download restrictions (for access control).
		$options = get_option( 'listenup_options' );
		$download_restriction = isset( $options['download_restriction'] ) ? $options['download_restriction'] : 'allow_all';

		// Note: We allow playback even with restrictions, only downloads are blocked.

		/*
		if ( 'disable' === $download_restriction ) {
			status_header( 403 );
			wp_die( esc_html__( 'Audio access is currently disabled.', 'listenup' ) );
		}

		if ( 'logged_in_only' === $download_restriction && ! is_user_logged_in() ) {
			status_header( 403 );
			wp_die( esc_html__( 'You must be logged in to access audio files.', 'listenup' ) );
		}
		*/

		// Construct file path.
		$upload_dir = wp_upload_dir();
		$cache_dir = $upload_dir['basedir'] . '/listenup-audio';
		$file_path = $cache_dir . '/' . basename( $file );

		// Validate file exists and is within the cache directory (security check).
		if ( ! file_exists( $file_path ) ) {
			status_header( 404 );
			wp_die( 'File not found' );
		}

		// Ensure file is within our cache directory (prevent directory traversal).
		$real_cache_dir = realpath( $cache_dir );
		$real_file_path = realpath( $file_path );

		if ( false === $real_file_path || 0 !== strpos( $real_file_path, $real_cache_dir ) ) {
			status_header( 403 );
			wp_die( 'Access denied' );
		}

		// Get file info.
		$file_size = filesize( $file_path );
		$mime_type = $this->get_mime_type( $file_path );

		// Handle range requests for seeking support.
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';

		if ( ! empty( $range ) ) {
			$this->serve_file_with_range( $file_path, $file_size, $mime_type, $range );
		} else {
			$this->serve_file_complete( $file_path, $file_size, $mime_type );
		}

		exit;
	}

	/**
	 * Serve complete file without range support.
	 *
	 * @param string $file_path Path to file.
	 * @param int    $file_size File size in bytes.
	 * @param string $mime_type MIME type.
	 */
	private function serve_file_complete( $file_path, $file_size, $mime_type ) {
		// Clear any previous output.
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		// Set headers.
		status_header( 200 );
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Length: ' . $file_size );
		header( 'Accept-Ranges: bytes' );
		header( 'Cache-Control: public, max-age=31536000' ); // Cache for 1 year.
		header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + 31536000 ) . ' GMT' );

		// Output file in chunks to avoid memory issues.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Required for streaming large binary files
		$handle = fopen( $file_path, 'rb' );
		if ( false === $handle ) {
			wp_die( 'Could not read file' );
		}

		while ( ! feof( $handle ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file output
			echo fread( $handle, 8192 );
			flush();
		}

		fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Serve file with HTTP range support for seeking.
	 *
	 * @param string $file_path Path to file.
	 * @param int    $file_size File size in bytes.
	 * @param string $mime_type MIME type.
	 * @param string $range Range header value.
	 */
	private function serve_file_with_range( $file_path, $file_size, $mime_type, $range ) {
		// Parse range header.
		$range = str_replace( 'bytes=', '', $range );
		$range_parts = explode( '-', $range );
		$start = intval( $range_parts[0] );
		$end = isset( $range_parts[1] ) && ! empty( $range_parts[1] ) ? intval( $range_parts[1] ) : $file_size - 1;

		// Validate range.
		if ( $start > $end || $start < 0 || $end >= $file_size ) {
			status_header( 416 ); // Range Not Satisfiable.
			header( 'Content-Range: bytes */' . $file_size );
			exit;
		}

		$length = $end - $start + 1;

		// Clear any previous output.
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		// Set headers for partial content.
		status_header( 206 ); // Partial Content.
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Length: ' . $length );
		header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $file_size );
		header( 'Accept-Ranges: bytes' );
		header( 'Cache-Control: public, max-age=31536000' );
		header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + 31536000 ) . ' GMT' );

		// Output file range in chunks.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Required for streaming large binary files with range support
		$handle = fopen( $file_path, 'rb' );
		if ( false === $handle ) {
			wp_die( 'Could not read file' );
		}

		// Seek to start position.
		fseek( $handle, $start );

		// Output in chunks.
		$remaining = $length;
		while ( $remaining > 0 && ! feof( $handle ) ) {
			$chunk_size = min( 8192, $remaining );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file output
			echo fread( $handle, $chunk_size );
			flush();
			$remaining -= $chunk_size;
		}

		fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Get MIME type for file.
	 *
	 * @param string $file_path Path to file.
	 * @return string MIME type.
	 */
	private function get_mime_type( $file_path ) {
		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		$mime_types = array(
			'mp3' => 'audio/mpeg',
			'wav' => 'audio/wav',
			'ogg' => 'audio/ogg',
			'm4a' => 'audio/mp4',
		);

		return isset( $mime_types[ $extension ] ) ? $mime_types[ $extension ] : 'application/octet-stream';
	}

	/**
	 * Generate secure audio URL that goes through our proxy.
	 *
	 * @param string $file_url Original file URL.
	 * @return string Secure URL.
	 */
	public function get_secure_audio_url( $file_url ) {
		// If already a secure URL, return as-is.
		if ( strpos( $file_url, 'admin-ajax.php' ) !== false && strpos( $file_url, 'listenup_serve_audio' ) !== false ) {
			return $file_url;
		}

		// Extract filename from URL.
		$parsed_url = wp_parse_url( $file_url );
		$file_path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';
		$filename = basename( $file_path );

		// Generate nonce for this specific file.
		$nonce = wp_create_nonce( 'listenup_serve_audio_' . $filename );

		// Build secure URL.
		$secure_url = add_query_arg(
			array(
				'action' => 'listenup_serve_audio',
				'file' => $filename,
				'nonce' => $nonce,
			),
			admin_url( 'admin-ajax.php' )
		);

		return $secure_url;
	}

	/**
	 * Convert HTTP URLs to HTTPS for cloud storage.
	 *
	 * @param string $url Original URL.
	 * @return string HTTPS URL.
	 */
	private function convert_to_https( $url ) {
		if ( empty( $url ) ) {
			return $url;
		}

		// Convert HTTP to HTTPS
		$url = str_replace( 'http://', 'https://', $url );
		
		// Convert S3 website endpoints to regular S3 endpoints
		// s3-website.us-east-2.amazonaws.com -> s3.us-east-2.amazonaws.com
		$url = str_replace( 's3-website.', 's3.', $url );
		
		return $url;
	}

	/**
	 * Get original (direct) audio URLs from cached data.
	 * Used for server-side operations like concatenation.
	 *
	 * @param array $cached_audio Cached audio data.
	 * @return array Array of direct URLs.
	 */
	private function get_direct_audio_urls( $cached_audio ) {
		$urls = array();

		if ( is_array( $cached_audio ) && isset( $cached_audio['chunks'] ) ) {
			$urls = $cached_audio['chunks'];
		} elseif ( is_array( $cached_audio ) && isset( $cached_audio[0] ) ) {
			$urls = $cached_audio;
		} elseif ( is_array( $cached_audio ) && isset( $cached_audio['url'] ) ) {
			$urls = array( $cached_audio['url'] );
		} elseif ( is_string( $cached_audio ) ) {
			$urls = array( $cached_audio );
		}

		return $urls;
	}
}
