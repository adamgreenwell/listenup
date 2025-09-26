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

		// Localize script with AJAX data.
		wp_localize_script(
			'listenup-frontend',
			'listenupAjax',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'listenup_download_wav' ),
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
		
		if ( is_array( $audio_data ) ) {
			if ( isset( $audio_data['chunks'] ) ) {
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
		
		ob_start();
		?>
		<div class="listenup-audio-player" id="<?php echo esc_attr( $player_id ); ?>" data-post-id="<?php echo esc_attr( $post_id ); ?>" <?php echo $audio_chunks ? 'data-audio-chunks="' . esc_attr( wp_json_encode( $audio_chunks ) ) . '"' : ''; ?>>
			<div class="listenup-player-header">
				<h3 class="listenup-player-title">
					<?php /* translators: Audio player title */ esc_html_e( 'Listen to this content', 'listenup' ); ?>
				</h3>
			</div>
			
			<div class="listenup-player-controls">
				<button type="button" class="listenup-play-button" aria-label="<?php /* translators: Play button aria label */ esc_attr_e( 'Play audio', 'listenup' ); ?>">
					<span class="listenup-play-icon" aria-hidden="true">▶</span>
					<span class="listenup-pause-icon" aria-hidden="true" style="display: none;">⏸</span>
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
					<span class="listenup-download-icon" aria-hidden="true">⬇</span>
				</button>
			</div>
			
			<audio 
				class="listenup-audio-element" 
				preload="metadata"
				aria-label="<?php /* translators: Audio element aria label */ esc_attr_e( 'Audio player for post content', 'listenup' ); ?>"
			>
				<source src="<?php echo esc_url( $audio_url ); ?>" type="audio/mpeg">
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
		if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'listenup_download_wav' ) ) {
			wp_die( 'Security check failed' );
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

		// Get audio chunks.
		$audio_chunks = null;
		if ( is_array( $cached_audio ) && isset( $cached_audio['chunks'] ) ) {
			$audio_chunks = $cached_audio['chunks'];
		} elseif ( is_array( $cached_audio ) ) {
			$audio_chunks = $cached_audio;
		}

		if ( ! $audio_chunks || count( $audio_chunks ) <= 1 ) {
			wp_die( 'No chunked audio available for concatenation' );
		}

		// Use server-side concatenator to create WAV file.
		$concatenator = ListenUp_Audio_Concatenator::get_instance();
		$result = $concatenator->get_concatenated_audio_url( $audio_chunks, $post_id, 'wav' );

		if ( is_wp_error( $result ) ) {
			wp_die( 'Failed to concatenate audio: ' . $result->get_error_message() );
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
		$filename = 'listenup-audio-' . $post_id . '-' . date( 'Y-m-d-H-i-s' ) . '.wav';
		
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

		// Output the file in chunks to avoid memory issues.
		$handle = fopen( $result['file_path'], 'rb' );
		if ( $handle ) {
			while ( ! feof( $handle ) ) {
				echo fread( $handle, 8192 );
				flush();
			}
			fclose( $handle );
		} else {
			wp_die( 'Could not read concatenated audio file' );
		}
		
		exit;
	}
}
