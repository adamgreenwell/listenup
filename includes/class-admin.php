<?php
/**
 * Admin functionality for ListenUp plugin.
 *
 * @package ListenUp
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin class.
 */
class ListenUp_Admin {

	/**
	 * Single instance of the class.
	 *
	 * @var ListenUp_Admin
	 */
	private static $instance = null;

	/**
	 * Get single instance of the class.
	 *
	 * @return ListenUp_Admin
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
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'wp_ajax_listenup_clear_debug_log', array( $this, 'ajax_clear_debug_log' ) );
		add_action( 'wp_ajax_listenup_get_voices', array( $this, 'ajax_get_voices' ) );
		add_action( 'wp_ajax_listenup_preview_voice', array( $this, 'ajax_preview_voice' ) );
	}

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu() {
		add_options_page(
			/* translators: Admin page title */
			__( 'ListenUp Settings', 'listenup' ),
			/* translators: Admin menu item */
			__( 'ListenUp', 'listenup' ),
			'manage_options',
			'listenup-settings',
			array( $this, 'admin_page' )
		);
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings() {
		register_setting(
			'listenup_options',
			'listenup_options',
			array( $this, 'sanitize_options' )
		);

		add_settings_section(
			'listenup_api_section',
			/* translators: Settings section title */
			__( 'API Configuration', 'listenup' ),
			array( $this, 'api_section_callback' ),
			'listenup-settings'
		);

		add_settings_field(
			'murf_api_key',
			/* translators: API key field label */
			__( 'Murf.ai API Key', 'listenup' ),
			array( $this, 'api_key_field_callback' ),
			'listenup-settings',
			'listenup_api_section'
		);

		add_settings_field(
			'selected_voice',
			/* translators: Voice selection field label */
			__( 'Voice Selection', 'listenup' ),
			array( $this, 'voice_selection_field_callback' ),
			'listenup-settings',
			'listenup_api_section'
		);


		add_settings_section(
			'listenup_display_section',
			/* translators: Settings section title */
			__( 'Display Settings', 'listenup' ),
			array( $this, 'display_section_callback' ),
			'listenup-settings'
		);

		add_settings_field(
			'auto_placement',
			/* translators: Auto placement field label */
			__( 'Automatic Placement', 'listenup' ),
			array( $this, 'auto_placement_field_callback' ),
			'listenup-settings',
			'listenup_display_section'
		);

		add_settings_field(
			'placement_position',
			/* translators: Player position field label */
			__( 'Player Position', 'listenup' ),
			array( $this, 'placement_position_field_callback' ),
			'listenup-settings',
			'listenup_display_section'
		);

		add_settings_section(
			'listenup_preroll_section',
			/* translators: Settings section title */
			__( 'Pre-roll Audio Settings', 'listenup' ),
			array( $this, 'preroll_section_callback' ),
			'listenup-settings'
		);

		add_settings_field(
			'pre_roll_audio',
			/* translators: Pre-roll audio field label */
			__( 'Pre-roll Audio File', 'listenup' ),
			array( $this, 'preroll_audio_field_callback' ),
			'listenup-settings',
			'listenup_preroll_section'
		);

		add_settings_section(
			'listenup_debug_section',
			/* translators: Settings section title */
			__( 'Debug Settings', 'listenup' ),
			array( $this, 'debug_section_callback' ),
			'listenup-settings'
		);

		add_settings_field(
			'debug_enabled',
			/* translators: Debug logging field label */
			__( 'Enable Debug Logging', 'listenup' ),
			array( $this, 'debug_enabled_field_callback' ),
			'listenup-settings',
			'listenup_debug_section'
		);
	}

	/**
	 * Sanitize options.
	 *
	 * @param array $input Raw input data.
	 * @return array Sanitized data.
	 */
	public function sanitize_options( $input ) {
		$sanitized = array();

		if ( isset( $input['murf_api_key'] ) ) {
			$sanitized['murf_api_key'] = sanitize_text_field( $input['murf_api_key'] );
		}

		if ( isset( $input['selected_voice'] ) ) {
			$sanitized['selected_voice'] = sanitize_text_field( $input['selected_voice'] );
		}

		if ( isset( $input['selected_voice_style'] ) ) {
			$sanitized['selected_voice_style'] = sanitize_text_field( $input['selected_voice_style'] );
		}

		if ( isset( $input['auto_placement'] ) ) {
			$sanitized['auto_placement'] = sanitize_text_field( $input['auto_placement'] );
		}

		if ( isset( $input['placement_position'] ) ) {
			$sanitized['placement_position'] = sanitize_text_field( $input['placement_position'] );
		}

		if ( isset( $input['pre_roll_audio'] ) ) {
			$sanitized['pre_roll_audio'] = sanitize_text_field( $input['pre_roll_audio'] );
		}

		if ( isset( $input['debug_enabled'] ) ) {
			$sanitized['debug_enabled'] = (bool) $input['debug_enabled'];
		}

		return $sanitized;
	}

	/**
	 * API section callback.
	 */
	public function api_section_callback() {
		/* translators: Description for API configuration section */
		echo '<p>' . esc_html__( 'Configure your Murf.ai API settings below.', 'listenup' ) . '</p>';
	}

	/**
	 * API key field callback.
	 */
	public function api_key_field_callback() {
		$options = get_option( 'listenup_options' );
		$api_key = isset( $options['murf_api_key'] ) ? $options['murf_api_key'] : '';
		
		printf(
			'<input type="password" id="murf_api_key" name="listenup_options[murf_api_key]" value="%s" class="regular-text" />',
			esc_attr( $api_key )
		);
		
		?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: Murf.ai website URL */
				esc_html__( 'Enter your Murf.ai API key. You can get one from %s.', 'listenup' ),
				'<a href="https://murf.ai" target="_blank">murf.ai</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Voice selection field callback.
	 */
	public function voice_selection_field_callback() {
		$options = get_option( 'listenup_options' );
		$selected_voice = isset( $options['selected_voice'] ) ? $options['selected_voice'] : 'en-US-natalie';
		
		// Get available voices from API.
		$api = ListenUp_API::get_instance();
		$voices = $api->get_available_voices();
		
		if ( is_wp_error( $voices ) ) {
			?>
			<p class="description" style="color: #d63638;">
				<?php
				printf(
					/* translators: %s: Error message */
					esc_html__( 'Unable to load voices: %s', 'listenup' ),
					esc_html( $voices->get_error_message() )
				);
				?>
			</p>
			
			<?php
			printf(
				'<input type="text" id="selected_voice" name="listenup_options[selected_voice]" value="%s" class="regular-text" readonly />',
				esc_attr( $selected_voice )
			);
			?>
			
			<p class="description">
				<?php esc_html_e( 'Using default voice. Please check your API key and try again.', 'listenup' ); ?>
			</p>
			<?php
			return;
		}
		
		// Enhanced voice picker interface
		$this->render_voice_picker( $voices, $selected_voice );
		
		?>
		<p class="description">
			<?php esc_html_e( 'Choose the voice that will be used for audio generation. You can search, filter, and preview voices before selecting.', 'listenup' ); ?>
		</p>
		<?php
	}


	/**
	 * Display section callback.
	 */
	public function display_section_callback() {
		/* translators: Description for display settings section */
		echo '<p>' . esc_html__( 'Configure how the audio player appears on your posts.', 'listenup' ) . '</p>';
	}

	/**
	 * Auto placement field callback.
	 */
	public function auto_placement_field_callback() {
		$options = get_option( 'listenup_options' );
		$auto_placement = isset( $options['auto_placement'] ) ? $options['auto_placement'] : 'none';
		
		$choices = array(
			'none' => __( 'Manual placement only (use shortcode)', 'listenup' ),
			'posts' => __( 'Automatically add to posts', 'listenup' ),
			'pages' => __( 'Automatically add to pages', 'listenup' ),
			'both' => __( 'Automatically add to both posts and pages', 'listenup' ),
		);

		?>
		<select id="auto_placement" name="listenup_options[auto_placement]">
			<?php foreach ( $choices as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $auto_placement, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		
		<p class="description">
			<?php esc_html_e( 'Choose whether to automatically display the audio player on your content.', 'listenup' ); ?>
		</p>
		<?php
	}

	/**
	 * Placement position field callback.
	 */
	public function placement_position_field_callback() {
		$options = get_option( 'listenup_options' );
		$placement_position = isset( $options['placement_position'] ) ? $options['placement_position'] : 'after';
		
		$choices = array(
			'before' => __( 'Before content', 'listenup' ),
			'after' => __( 'After content', 'listenup' ),
		);

		?>
		<select id="placement_position" name="listenup_options[placement_position]">
			<?php foreach ( $choices as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $placement_position, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		
		<p class="description">
			<?php esc_html_e( 'Choose where to place the audio player when using automatic placement.', 'listenup' ); ?>
		</p>
		<?php
	}

	/**
	 * Debug section callback.
	 */
	public function debug_section_callback() {
		/* translators: Description for debug settings section */
		echo '<p>' . esc_html__( 'Debug settings for troubleshooting and development.', 'listenup' ) . '</p>';
	}

	/**
	 * Debug enabled field callback.
	 */
	public function debug_enabled_field_callback() {
		$options = get_option( 'listenup_options' );
		$debug_enabled = isset( $options['debug_enabled'] ) ? $options['debug_enabled'] : false;
		
		printf(
			'<input type="checkbox" id="debug_enabled" name="listenup_options[debug_enabled]" value="1" %s />',
			checked( $debug_enabled, true, false )
		);
		
		?>
		<label for="debug_enabled"><?php esc_html_e( 'Enable debug logging', 'listenup' ); ?></label>
		
		<p class="description">
			<?php esc_html_e( 'When enabled, detailed debug information will be logged to help troubleshoot issues.', 'listenup' ); ?>
		</p>
		<?php

		// Show debug log viewer if debug is enabled.
		if ( $debug_enabled ) {
			$this->show_debug_log_viewer();
		}
	}

	/**
	 * Show debug log viewer.
	 */
	private function show_debug_log_viewer() {
		$debug = ListenUp_Debug::get_instance();
		$stats = $debug->get_debug_stats();
		$log_contents = $debug->get_log_contents( 50 ); // Last 50 lines.
		
		// Load the debug viewer partial
		$partial_path = plugin_dir_path( dirname( __FILE__ ) ) . 'partials/debug-viewer.php';
		if ( file_exists( $partial_path ) ) {
			include $partial_path;
		}
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( 'settings_page_listenup-settings' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'listenup-admin',
			LISTENUP_PLUGIN_URL . 'admin-ui/assets/css/admin.css',
			array(),
			LISTENUP_VERSION
		);

		wp_enqueue_script(
			'listenup-admin',
			LISTENUP_PLUGIN_URL . 'admin-ui/assets/js/admin.js',
			array( 'jquery' ),
			LISTENUP_VERSION,
			true
		);

		wp_localize_script( 'listenup-admin', 'listenupAdmin', array(
			'nonce' => wp_create_nonce( 'listenup_admin_nonce' ),
			'clearDebugNonce' => wp_create_nonce( 'listenup_clear_debug_log' ),
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		) );
	}

	/**
	 * Render enhanced voice picker interface.
	 *
	 * @param array  $voices Array of voice data from API.
	 * @param string $selected_voice Currently selected voice ID.
	 */
	private function render_voice_picker( $voices, $selected_voice ) {
		// Group voices by language
		$grouped_voices = $this->group_voices_by_language( $voices );
		
		// Find selected voice details
		$selected_voice_data = $this->find_voice_by_id( $voices, $selected_voice );
		
		// Get current voice style
		$options = get_option( 'listenup_options' );
		$selected_voice_style = isset( $options['selected_voice_style'] ) ? $options['selected_voice_style'] : 'Narration';
		
		// Load the voice picker partial
		$partial_path = plugin_dir_path( dirname( __FILE__ ) ) . 'partials/voice-picker.php';
		if ( file_exists( $partial_path ) ) {
			include $partial_path;
		}
	}

	/**
	 * Group voices by language.
	 *
	 * @param array $voices Array of voice data.
	 * @return array Grouped voices.
	 */
	private function group_voices_by_language( $voices ) {
		$grouped = array();
		
		foreach ( $voices as $voice ) {
			$language = isset( $voice['displayLanguage'] ) ? $voice['displayLanguage'] : 'Unknown';
			if ( ! isset( $grouped[ $language ] ) ) {
				$grouped[ $language ] = array();
			}
			$grouped[ $language ][] = $voice;
		}
		
		// Sort languages alphabetically
		ksort( $grouped );
		
		// Sort voices within each language by display name
		foreach ( $grouped as $language => &$language_voices ) {
			usort( $language_voices, function( $a, $b ) {
				return strcmp( $a['displayName'], $b['displayName'] );
			});
		}
		
		return $grouped;
	}

	/**
	 * Find voice by ID.
	 *
	 * @param array  $voices Array of voice data.
	 * @param string $voice_id Voice ID to find.
	 * @return array|null Voice data or null if not found.
	 */
	private function find_voice_by_id( $voices, $voice_id ) {
		foreach ( $voices as $voice ) {
			if ( isset( $voice['voiceId'] ) && $voice['voiceId'] === $voice_id ) {
				return $voice;
			}
		}
		return null;
	}

	/**
	 * Get voice avatar HTML.
	 *
	 * @param array $voice Voice data.
	 * @return string Avatar HTML.
	 */
	private function get_voice_avatar( $voice ) {
		$gender = isset( $voice['gender'] ) ? strtolower( $voice['gender'] ) : 'unknown';
		$name = isset( $voice['displayName'] ) ? $voice['displayName'] : 'Voice';
		
		// Use initials as fallback
		$initials = '';
		if ( preg_match( '/^([A-Za-z]+)/', $name, $matches ) ) {
			$initials = strtoupper( substr( $matches[1], 0, 2 ) );
		}
		
		$avatar_class = 'voice-avatar-placeholder';
		if ( $gender === 'female' ) {
			$avatar_class .= ' voice-avatar-female';
		} elseif ( $gender === 'male' ) {
			$avatar_class .= ' voice-avatar-male';
		}
		
		return sprintf(
			'<div class="%s">%s</div>',
			esc_attr( $avatar_class ),
			esc_html( $initials )
		);
	}

	/**
	 * AJAX handler for getting voices.
	 */
	public function ajax_get_voices() {
		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		// Verify nonce
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'listenup_admin_nonce' ) ) {
			wp_die( 'Invalid nonce' );
		}

		$api = ListenUp_API::get_instance();
		$voices = $api->get_available_voices();

		if ( is_wp_error( $voices ) ) {
			wp_send_json_success( array(
				'success' => false,
				'message' => $voices->get_error_message()
			) );
		}

		wp_send_json_success( array(
			'voices' => $voices
		) );
	}

	/**
	 * AJAX handler for voice preview.
	 */
	public function ajax_preview_voice() {
		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		// Verify nonce
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'listenup_admin_nonce' ) ) {
			wp_die( 'Invalid nonce' );
		}

		$voice_id = sanitize_text_field( wp_unslash( $_POST['voice_id'] ?? '' ) );
		$voice_style = sanitize_text_field( wp_unslash( $_POST['voice_style'] ?? 'Narration' ) );
		$preview_text = sanitize_text_field( wp_unslash( $_POST['preview_text'] ?? 'Hello, this is a preview of this voice.' ) );

		if ( empty( $voice_id ) ) {
			wp_send_json_success( array(
				'success' => false,
				'message' => __( 'Voice ID is required.', 'listenup' )
			) );
		}

		// Generate preview audio with specific voice and style
		$api = ListenUp_API::get_instance();
		$result = $api->generate_audio( $preview_text, 0, $voice_id, $voice_style );

		if ( is_wp_error( $result ) ) {
			wp_send_json_success( array(
				'success' => false,
				'message' => $result->get_error_message()
			) );
		}

		wp_send_json_success( array(
			'audio_url' => $result['audio_url']
		) );
	}

	/**
	 * Admin page callback.
	 */
	public function admin_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ListenUp Settings', 'listenup' ); ?></h1>
			
			<form method="post" action="options.php">
				<?php
				settings_fields( 'listenup_options' );
				do_settings_sections( 'listenup-settings' );
				submit_button();
				?>
			</form>
			
			<div class="listenup-admin-info">
				<h2><?php esc_html_e( 'How to Use ListenUp', 'listenup' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'Get your API key from Murf.ai and enter it above.', 'listenup' ); ?></li>
					<li><?php esc_html_e( 'Configure your display preferences.', 'listenup' ); ?></li>
					<li><?php esc_html_e( 'Edit any post or page and use the "ListenUp Audio Generation" meta box to generate audio.', 'listenup' ); ?></li>
					<li><?php esc_html_e( 'Use the [listenup] shortcode to manually place the player anywhere in your content.', 'listenup' ); ?></li>
				</ol>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler for clearing debug log.
	 */
	public function ajax_clear_debug_log() {
		// Check user permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'listenup' ) );
		}

		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'listenup_clear_debug_log' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'listenup' ) );
		}

		$debug = ListenUp_Debug::get_instance();
		$log_file = $debug->get_log_file_path();
		
		// Check if log file exists
		if ( ! file_exists( $log_file ) ) {
			wp_send_json_success( array(
				'message' => __( 'Debug log is already empty.', 'listenup' ),
			) );
		}
		
		// Check if log file is writable using WP_Filesystem
		if ( ! WP_Filesystem() ) {
			wp_send_json_success( array(
				'success' => false,
				'message' => __( 'Unable to initialize filesystem access.', 'listenup' ),
			) );
		}
		
		global $wp_filesystem;
		if ( ! $wp_filesystem->is_writable( $log_file ) ) {
			wp_send_json_success( array(
				'success' => false,
				'message' => __( 'Debug log file is not writable. Please check file permissions.', 'listenup' ),
			) );
		}
		
		$result = $debug->clear_log();

		if ( $result ) {
			wp_send_json_success( array(
				'message' => __( 'Debug log cleared successfully.', 'listenup' ),
			) );
		} else {
			wp_send_json_success( array(
				'success' => false,
				'message' => __( 'Failed to clear debug log. Please check file permissions.', 'listenup' ),
			) );
		}
	}

	/**
	 * Pre-roll section callback.
	 */
	public function preroll_section_callback() {
		/* translators: Description for pre-roll audio section */
		echo '<p>' . esc_html__( 'Configure a pre-roll audio file that will be played before your content audio. This is useful for advertisements or announcements.', 'listenup' ) . '</p>';
	}

	/**
	 * Pre-roll audio field callback.
	 */
	public function preroll_audio_field_callback() {
		$options = get_option( 'listenup_options' );
		$pre_roll_audio = isset( $options['pre_roll_audio'] ) ? $options['pre_roll_audio'] : '';
		
		?>
		<input type="text" id="pre_roll_audio" name="listenup_options[pre_roll_audio]" value="<?php echo esc_attr( $pre_roll_audio ); ?>" class="regular-text" />
		<p class="description">
			<?php esc_html_e( 'Enter the full path to your pre-roll audio file. Supported formats: MP3, WAV, OGG, M4A. Maximum file size: 10MB.', 'listenup' ); ?>
		</p>
		<?php if ( ! empty( $pre_roll_audio ) ) : ?>
			<?php
			$pre_roll_manager = ListenUp_Pre_Roll_Manager::get_instance();
			$validation = $pre_roll_manager->validate_pre_roll_file( $pre_roll_audio );
			?>
			<?php if ( is_wp_error( $validation ) ) : ?>
				<p class="description" style="color: #d63638;">
					<strong><?php esc_html_e( 'Error:', 'listenup' ); ?></strong> <?php echo esc_html( $validation->get_error_message() ); ?>
				</p>
			<?php else : ?>
				<p class="description" style="color: #00a32a;">
					<strong><?php esc_html_e( 'Valid audio file:', 'listenup' ); ?></strong> <?php echo esc_html( $validation['file_size_formatted'] ); ?> (<?php echo esc_html( strtoupper( $validation['extension'] ) ); ?>)
				</p>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}
}
