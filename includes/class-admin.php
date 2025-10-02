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
		add_action( 'wp_ajax_listenup_generate_preroll', array( $this, 'ajax_generate_preroll' ) );
		add_action( 'wp_ajax_listenup_test_conversion_api', array( $this, 'ajax_test_conversion_api' ) );
		add_action( 'wp_ajax_listenup_convert_audio', array( $this, 'ajax_convert_audio' ) );
		add_action( 'wp_ajax_listenup_delete_audio', array( $this, 'ajax_delete_audio' ) );
	}

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu() {
		// Add top-level menu.
		add_menu_page(
			/* translators: Admin page title */
			__( 'ListenUp', 'listenup' ),
			/* translators: Admin menu item */
			__( 'ListenUp', 'listenup' ),
			'manage_options',
			'listenup',
			array( $this, 'admin_page' ),
			'dashicons-media-audio',
			30
		);

		// Add Settings submenu.
		add_submenu_page(
			'listenup',
			/* translators: Settings page title */
			__( 'ListenUp Settings', 'listenup' ),
			/* translators: Settings menu item */
			__( 'Settings', 'listenup' ),
			'manage_options',
			'listenup',
			array( $this, 'admin_page' )
		);

		// Add Audio Library submenu.
		add_submenu_page(
			'listenup',
			/* translators: Audio Library page title */
			__( 'Audio Library', 'listenup' ),
			/* translators: Audio Library menu item */
			__( 'Audio Library', 'listenup' ),
			'manage_options',
			'listenup-audio-library',
			array( $this, 'audio_library_page' )
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

		add_settings_field(
			'download_restriction',
			/* translators: Download restriction field label */
			__( 'Download Restrictions', 'listenup' ),
			array( $this, 'download_restriction_field_callback' ),
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
			'listenup_conversion_section',
			/* translators: Settings section title */
			__( 'Audio Conversion Settings', 'listenup' ),
			array( $this, 'conversion_section_callback' ),
			'listenup-settings'
		);

		add_settings_field(
			'conversion_api_endpoint',
			/* translators: Conversion API endpoint field label */
			__( 'Conversion API Endpoint', 'listenup' ),
			array( $this, 'conversion_api_endpoint_field_callback' ),
			'listenup-settings',
			'listenup_conversion_section'
		);

		add_settings_field(
			'conversion_api_key',
			/* translators: Conversion API key field label */
			__( 'Conversion API Key', 'listenup' ),
			array( $this, 'conversion_api_key_field_callback' ),
			'listenup-settings',
			'listenup_conversion_section'
		);

		add_settings_field(
			'auto_convert',
			/* translators: Auto convert field label */
			__( 'Automatic Conversion', 'listenup' ),
			array( $this, 'auto_convert_field_callback' ),
			'listenup-settings',
			'listenup_conversion_section'
		);

		add_settings_field(
			'delete_wav_after_conversion',
			/* translators: Delete WAV after conversion field label */
			__( 'WAV File Retention', 'listenup' ),
			array( $this, 'delete_wav_field_callback' ),
			'listenup-settings',
			'listenup_conversion_section'
		);

		add_settings_section(
			'listenup_cloud_storage_section',
			/* translators: Settings section title */
			__( 'Cloud Storage Settings', 'listenup' ),
			array( $this, 'cloud_storage_section_callback' ),
			'listenup-settings'
		);

		add_settings_field(
			'cloud_storage_provider',
			/* translators: Cloud storage provider field label */
			__( 'Storage Provider', 'listenup' ),
			array( $this, 'cloud_storage_provider_field_callback' ),
			'listenup-settings',
			'listenup_cloud_storage_section'
		);

		add_settings_field(
			'cloud_storage_settings',
			/* translators: Cloud storage settings field label */
			__( 'Cloud Storage Settings', 'listenup' ),
			array( $this, 'cloud_storage_settings_field_callback' ),
			'listenup-settings',
			'listenup_cloud_storage_section'
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

		if ( isset( $input['pre_roll_text'] ) ) {
			$sanitized['pre_roll_text'] = sanitize_textarea_field( $input['pre_roll_text'] );
		}

		if ( isset( $input['debug_enabled'] ) ) {
			$sanitized['debug_enabled'] = (bool) $input['debug_enabled'];
		}

		if ( isset( $input['download_restriction'] ) ) {
			$allowed_values = array( 'allow_all', 'logged_in_only', 'disable' );
			$sanitized['download_restriction'] = in_array( $input['download_restriction'], $allowed_values, true )
				? $input['download_restriction']
				: 'allow_all';
		}

		if ( isset( $input['conversion_api_endpoint'] ) ) {
			$sanitized['conversion_api_endpoint'] = esc_url_raw( $input['conversion_api_endpoint'] );
		}

		if ( isset( $input['conversion_api_key'] ) ) {
			$sanitized['conversion_api_key'] = sanitize_text_field( $input['conversion_api_key'] );
		}

		if ( isset( $input['auto_convert'] ) ) {
			$sanitized['auto_convert'] = (bool) $input['auto_convert'];
		}

		if ( isset( $input['delete_wav_after_conversion'] ) ) {
			$sanitized['delete_wav_after_conversion'] = (bool) $input['delete_wav_after_conversion'];
		}

		// Cloud storage settings.
		if ( isset( $input['cloud_storage_provider'] ) ) {
			$allowed_providers = array( '', 'aws_s3', 'cloudflare_r2', 'google_cloud' );
			$sanitized['cloud_storage_provider'] = in_array( $input['cloud_storage_provider'], $allowed_providers, true )
				? $input['cloud_storage_provider']
				: '';
		}

		// AWS S3 settings.
		if ( isset( $input['aws_access_key'] ) ) {
			$sanitized['aws_access_key'] = sanitize_text_field( $input['aws_access_key'] );
		}
		if ( isset( $input['aws_secret_key'] ) ) {
			$sanitized['aws_secret_key'] = sanitize_text_field( $input['aws_secret_key'] );
		}
		if ( isset( $input['aws_bucket'] ) ) {
			$sanitized['aws_bucket'] = sanitize_text_field( $input['aws_bucket'] );
		}
		if ( isset( $input['aws_region'] ) ) {
			$sanitized['aws_region'] = sanitize_text_field( $input['aws_region'] );
		}
		if ( isset( $input['aws_base_url'] ) ) {
			$sanitized['aws_base_url'] = esc_url_raw( $input['aws_base_url'] );
		}

		// Cloudflare R2 settings.
		if ( isset( $input['r2_access_key'] ) ) {
			$sanitized['r2_access_key'] = sanitize_text_field( $input['r2_access_key'] );
		}
		if ( isset( $input['r2_secret_key'] ) ) {
			$sanitized['r2_secret_key'] = sanitize_text_field( $input['r2_secret_key'] );
		}
		if ( isset( $input['r2_bucket'] ) ) {
			$sanitized['r2_bucket'] = sanitize_text_field( $input['r2_bucket'] );
		}
		if ( isset( $input['r2_endpoint'] ) ) {
			$sanitized['r2_endpoint'] = esc_url_raw( $input['r2_endpoint'] );
		}
		if ( isset( $input['r2_base_url'] ) ) {
			$sanitized['r2_base_url'] = esc_url_raw( $input['r2_base_url'] );
		}

		// Google Cloud Storage settings.
		if ( isset( $input['gcs_project_id'] ) ) {
			$sanitized['gcs_project_id'] = sanitize_text_field( $input['gcs_project_id'] );
		}
		if ( isset( $input['gcs_bucket'] ) ) {
			$sanitized['gcs_bucket'] = sanitize_text_field( $input['gcs_bucket'] );
		}
		if ( isset( $input['gcs_credentials_file'] ) ) {
			$sanitized['gcs_credentials_file'] = sanitize_text_field( $input['gcs_credentials_file'] );
		}
		if ( isset( $input['gcs_base_url'] ) ) {
			$sanitized['gcs_base_url'] = esc_url_raw( $input['gcs_base_url'] );
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
	 * Download restriction field callback.
	 */
	public function download_restriction_field_callback() {
		$options = get_option( 'listenup_options' );
		$download_restriction = isset( $options['download_restriction'] ) ? $options['download_restriction'] : 'allow_all';

		$choices = array(
			'allow_all' => array(
				'label' => __( 'Allow all users to download', 'listenup' ),
				'description' => __( 'Anyone can download audio files, including visitors who are not logged in.', 'listenup' ),
			),
			'logged_in_only' => array(
				'label' => __( 'Restrict downloads to logged-in users only', 'listenup' ),
				'description' => __( 'Only users who are logged in to your WordPress site can download audio files.', 'listenup' ),
			),
			'disable' => array(
				'label' => __( 'Disable downloads completely', 'listenup' ),
				'description' => __( 'Remove the download button entirely. Users can still listen to audio online.', 'listenup' ),
			),
		);

		?>
		<fieldset>
			<?php foreach ( $choices as $value => $choice ) : ?>
				<label style="display: block; margin-bottom: 12px;">
					<input
						type="radio"
						name="listenup_options[download_restriction]"
						value="<?php echo esc_attr( $value ); ?>"
						<?php checked( $download_restriction, $value ); ?>
					/>
					<strong><?php echo esc_html( $choice['label'] ); ?></strong>
					<br/>
					<span class="description" style="margin-left: 24px;">
						<?php echo esc_html( $choice['description'] ); ?>
					</span>
				</label>
			<?php endforeach; ?>
		</fieldset>
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
		// Enqueue on both settings and library pages.
		if ( 'toplevel_page_listenup' !== $hook && 'listenup_page_listenup-audio-library' !== $hook ) {
			return;
		}

		// Enqueue WordPress media uploader.
		wp_enqueue_media();

		wp_enqueue_style(
			'listenup-admin',
			LISTENUP_PLUGIN_URL . 'admin-ui/assets/css/admin.css',
			array(),
			LISTENUP_VERSION
		);

		wp_enqueue_script(
			'listenup-admin',
			LISTENUP_PLUGIN_URL . 'admin-ui/assets/js/admin.js',
			array( 'jquery', 'wp-util' ),
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
	 * Conversion section callback.
	 */
	public function conversion_section_callback() {
		?>
		<p><?php esc_html_e( 'Configure your cloud audio conversion service to automatically convert WAV files to MP3.', 'listenup' ); ?></p>
		<?php
	}

	/**
	 * Conversion API endpoint field callback.
	 */
	public function conversion_api_endpoint_field_callback() {
		$options = get_option( 'listenup_options' );
		$endpoint = isset( $options['conversion_api_endpoint'] ) 
			? $options['conversion_api_endpoint'] 
			: 'https://listenup-audio-converter-931597442473.us-central1.run.app';
		
		printf(
			'<input type="url" id="conversion_api_endpoint" name="listenup_options[conversion_api_endpoint]" value="%s" class="regular-text" placeholder="https://your-api-domain.com" />',
			esc_attr( $endpoint )
		);
		?>
		<p class="description">
			<?php esc_html_e( 'Enter the base URL of your audio conversion API (without specific endpoints).', 'listenup' ); ?>
		</p>
		<?php
	}

	/**
	 * Conversion API key field callback.
	 */
	public function conversion_api_key_field_callback() {
		$options = get_option( 'listenup_options' );
		$api_key = isset( $options['conversion_api_key'] ) ? $options['conversion_api_key'] : '';
		
		printf(
			'<input type="password" id="conversion_api_key" name="listenup_options[conversion_api_key]" value="%s" class="regular-text" />',
			esc_attr( $api_key )
		);
		?>
		<p class="description">
			<?php esc_html_e( 'Enter your API key for authentication with the conversion service.', 'listenup' ); ?>
		</p>
		
		<?php if ( ! empty( $api_key ) ) : ?>
			<button type="button" class="button button-secondary" id="listenup-test-conversion-api" style="margin-top: 10px;">
				<?php esc_html_e( 'Test Connection', 'listenup' ); ?>
			</button>
			<span class="spinner" id="listenup-test-api-spinner"></span>
			<div id="listenup-test-api-result" style="margin-top: 10px;"></div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Auto convert field callback.
	 */
	public function auto_convert_field_callback() {
		$options = get_option( 'listenup_options' );
		$auto_convert = isset( $options['auto_convert'] ) ? $options['auto_convert'] : false;
		
		printf(
			'<input type="checkbox" id="auto_convert" name="listenup_options[auto_convert]" value="1" %s />',
			checked( $auto_convert, true, false )
		);
		?>
		<label for="auto_convert">
			<?php esc_html_e( 'Automatically convert WAV files to MP3 after generation', 'listenup' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, audio files will be automatically sent to your conversion service after generation.', 'listenup' ); ?>
		</p>
		<?php
	}

	/**
	 * Delete WAV field callback.
	 */
	public function delete_wav_field_callback() {
		$options = get_option( 'listenup_options' );
		$delete_wav = isset( $options['delete_wav_after_conversion'] ) ? $options['delete_wav_after_conversion'] : false;
		
		printf(
			'<input type="checkbox" id="delete_wav_after_conversion" name="listenup_options[delete_wav_after_conversion]" value="1" %s />',
			checked( $delete_wav, true, false )
		);
		?>
		<label for="delete_wav_after_conversion">
			<?php esc_html_e( 'Delete WAV files after successful MP3 conversion', 'listenup' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'This will save storage space by removing the larger WAV files once MP3 conversion is complete.', 'listenup' ); ?>
		</p>
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
		$pre_roll_text = isset( $options['pre_roll_text'] ) ? $options['pre_roll_text'] : '';

		?>
		<div id="listenup-preroll-container">
			<!-- Tab Navigation -->
			<div class="listenup-preroll-tabs">
				<button type="button" class="listenup-preroll-tab listenup-preroll-tab-active" data-tab="upload">
					<?php esc_html_e( 'Upload Audio File', 'listenup' ); ?>
				</button>
				<button type="button" class="listenup-preroll-tab" data-tab="generate">
					<?php esc_html_e( 'Generate with Murf.ai', 'listenup' ); ?>
				</button>
			</div>

			<!-- Upload Tab -->
			<div id="listenup-preroll-upload-tab" class="listenup-preroll-tab-content listenup-preroll-tab-active">
				<div class="listenup-preroll-upload-section">
					<button type="button" class="button button-secondary" id="listenup-upload-preroll-btn">
						<?php esc_html_e( 'Upload Audio File', 'listenup' ); ?>
					</button>
					<input type="hidden" id="pre_roll_audio" name="listenup_options[pre_roll_audio]" value="<?php echo esc_attr( $pre_roll_audio ); ?>" />

					<p class="description">
						<?php esc_html_e( 'Upload a pre-roll audio file. Supported formats: MP3, WAV, OGG, M4A. Maximum file size: 10MB.', 'listenup' ); ?>
					</p>

					<?php if ( ! empty( $pre_roll_audio ) ) : ?>
						<div id="listenup-preroll-preview" class="listenup-preroll-preview">
							<?php
							$pre_roll_manager = ListenUp_Pre_Roll_Manager::get_instance();
							$validation = $pre_roll_manager->validate_pre_roll_file( $pre_roll_audio );
							?>
							<?php if ( is_wp_error( $validation ) ) : ?>
								<p class="description" style="color: #d63638;">
									<strong><?php esc_html_e( 'Error:', 'listenup' ); ?></strong> <?php echo esc_html( $validation->get_error_message() ); ?>
								</p>
							<?php else : ?>
								<div class="listenup-preroll-info">
									<p style="color: #00a32a;">
										<strong><?php esc_html_e( 'Current pre-roll audio:', 'listenup' ); ?></strong>
									</p>
									<p><?php echo esc_html( basename( $pre_roll_audio ) ); ?></p>
									<p class="description">
										<?php echo esc_html( $validation['file_size_formatted'] ); ?> (<?php echo esc_html( strtoupper( $validation['extension'] ) ); ?>)
									</p>
									<audio controls style="max-width: 100%; margin-top: 10px;">
										<source src="<?php echo esc_url( $pre_roll_manager->get_pre_roll_url() ); ?>" type="audio/<?php echo esc_attr( $validation['extension'] ); ?>">
									</audio>
									<br>
									<button type="button" class="button button-secondary" id="listenup-remove-preroll-btn" style="margin-top: 10px;">
										<?php esc_html_e( 'Remove Pre-roll', 'listenup' ); ?>
									</button>
								</div>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Generate Tab -->
			<div id="listenup-preroll-generate-tab" class="listenup-preroll-tab-content">
				<div class="listenup-preroll-generate-section">
					<?php
					// Get current voice settings to display.
					$selected_voice = isset( $options['selected_voice'] ) ? $options['selected_voice'] : '';
					$selected_voice_style = isset( $options['selected_voice_style'] ) ? $options['selected_voice_style'] : '';

					// Get voice display name if available.
					$voice_display_name = '';
					if ( ! empty( $selected_voice ) ) {
						// Try to get the voice name from the voices list.
						$api = ListenUp_API::get_instance();
						$voices = $api->get_available_voices();
						if ( ! is_wp_error( $voices ) && ! empty( $voices ) ) {
							foreach ( $voices as $voice ) {
								if ( isset( $voice['voiceId'] ) && $voice['voiceId'] === $selected_voice ) {
									$voice_display_name = isset( $voice['displayName'] ) ? $voice['displayName'] : $selected_voice;
									break;
								}
							}
						}
					}
					?>

					<?php if ( ! empty( $selected_voice ) ) : ?>
						<div class="listenup-preroll-voice-info" style="background: #f0f6fc; border-left: 4px solid #0073aa; padding: 12px; margin-bottom: 15px;">
							<p style="margin: 0;">
								<strong><?php esc_html_e( 'Voice Settings:', 'listenup' ); ?></strong>
								<?php
								if ( ! empty( $voice_display_name ) ) {
									/* translators: %1$s: Voice name, %2$s: Voice style */
									printf( esc_html__( 'Using %1$s with %2$s style', 'listenup' ), '<strong>' . esc_html( $voice_display_name ) . '</strong>', '<strong>' . esc_html( $selected_voice_style ) . '</strong>' );
								} else {
									/* translators: %1$s: Voice ID, %2$s: Voice style */
									printf( esc_html__( 'Using voice %1$s with %2$s style', 'listenup' ), '<code>' . esc_html( $selected_voice ) . '</code>', '<strong>' . esc_html( $selected_voice_style ) . '</strong>' );
								}
								?>
							</p>
							<p class="description" style="margin: 5px 0 0 0;">
								<?php esc_html_e( 'Pre-roll audio will use the same voice and style configured in your plugin settings above.', 'listenup' ); ?>
							</p>
						</div>
					<?php else : ?>
						<div class="notice notice-warning inline" style="margin-bottom: 15px;">
							<p>
								<?php esc_html_e( 'Please configure a voice in the Voice Settings section above before generating pre-roll audio.', 'listenup' ); ?>
							</p>
						</div>
					<?php endif; ?>

					<label for="pre_roll_text">
						<strong><?php esc_html_e( 'Pre-roll Text', 'listenup' ); ?></strong>
					</label>
					<textarea
						id="pre_roll_text"
						name="listenup_options[pre_roll_text]"
						rows="4"
						class="large-text"
						maxlength="500"
						placeholder="<?php esc_attr_e( 'Enter the text you want to convert to audio for your pre-roll...', 'listenup' ); ?>"
					><?php echo esc_textarea( $pre_roll_text ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Enter text to generate audio using Murf.ai. Maximum 500 characters.', 'listenup' ); ?>
						<span id="listenup-preroll-char-count">0/500</span>
					</p>

					<button type="button" class="button button-primary" id="listenup-generate-preroll-btn" <?php echo empty( $selected_voice ) ? 'disabled' : ''; ?>>
						<?php esc_html_e( 'Generate Pre-roll Audio', 'listenup' ); ?>
					</button>
					<span class="spinner" id="listenup-generate-preroll-spinner"></span>

					<div id="listenup-generate-preroll-status"></div>
				</div>
			</div>
		</div>

		<style>
			.listenup-preroll-tabs {
				display: flex;
				gap: 0;
				margin-bottom: 20px;
				border-bottom: 1px solid #ddd;
			}
			.listenup-preroll-tab {
				padding: 10px 20px;
				background: #f0f0f1;
				border: 1px solid #ddd;
				border-bottom: none;
				cursor: pointer;
				transition: background 0.2s;
			}
			.listenup-preroll-tab:hover {
				background: #e8e8e9;
			}
			.listenup-preroll-tab-active {
				background: #fff;
				font-weight: 600;
			}
			.listenup-preroll-tab-content {
				display: none;
				padding: 20px;
				border: 1px solid #ddd;
				border-top: none;
				background: #fff;
			}
			.listenup-preroll-tab-content.listenup-preroll-tab-active {
				display: block;
			}
			.listenup-preroll-preview {
				margin-top: 15px;
				padding: 15px;
				background: #f9f9f9;
				border-left: 4px solid #00a32a;
			}
			#listenup-preroll-char-count {
				font-weight: 600;
			}
			#listenup-generate-preroll-status {
				margin-top: 15px;
			}
		</style>
		<?php
	}

	/**
	 * AJAX handler for generating pre-roll audio.
	 */
	public function ajax_generate_preroll() {
		// Verify nonce.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'listenup_admin_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'listenup' ) ) );
		}

		// Check user capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'listenup' ) ) );
		}

		// Get text from request.
		$text = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';

		if ( empty( $text ) ) {
			wp_send_json_error( array( 'message' => __( 'Text is required.', 'listenup' ) ) );
		}

		// Generate pre-roll audio.
		$pre_roll_manager = ListenUp_Pre_Roll_Manager::get_instance();
		$result = $pre_roll_manager->generate_pre_roll_audio( $text );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Audio Library page callback.
	 */
	public function audio_library_page() {
		// Get all posts with audio files.
		$posts_with_audio = $this->get_posts_with_audio();
		
		// Load the audio library view.
		$view_path = plugin_dir_path( dirname( __FILE__ ) ) . 'admin/views/audio-library.php';
		if ( file_exists( $view_path ) ) {
			include $view_path;
		} else {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Audio Library', 'listenup' ); ?></h1>
				<p><?php esc_html_e( 'Audio library view coming soon...', 'listenup' ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Get all posts that have audio files.
	 *
	 * @return array Array of post objects with audio metadata.
	 */
	private function get_posts_with_audio() {
		global $wpdb;

		// Query all posts that have the _listenup_audio meta key.
		$query = $wpdb->prepare(
			"SELECT DISTINCT p.ID, p.post_title, p.post_type, p.post_status, p.post_modified, pm.meta_value
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE pm.meta_key = %s
			AND p.post_status != %s
			ORDER BY p.post_modified DESC",
			'_listenup_audio',
			'trash'
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above.
		$results = $wpdb->get_results( $query );

		if ( empty( $results ) ) {
			return array();
		}

		// Process results to include decoded metadata.
		$posts_with_audio = array();
		foreach ( $results as $result ) {
			$audio_meta = maybe_unserialize( $result->meta_value );
			
			// Get file sizes if files exist.
			$upload_dir = wp_upload_dir();
			$cache_dir = $upload_dir['basedir'] . '/listenup-audio';
			
			$file_info = array(
				'wav_exists' => false,
				'wav_size' => 0,
				'mp3_exists' => false,
				'mp3_size' => 0,
			);
			
			if ( isset( $audio_meta['file'] ) ) {
				$wav_file = $cache_dir . '/' . $audio_meta['file'];
				if ( file_exists( $wav_file ) ) {
					$file_info['wav_exists'] = true;
					$file_info['wav_size'] = filesize( $wav_file );
				}
			}
			
			// Check for local MP3 files first.
			if ( isset( $audio_meta['mp3_file'] ) ) {
				$mp3_file = $cache_dir . '/' . $audio_meta['mp3_file'];
				if ( file_exists( $mp3_file ) ) {
					$file_info['mp3_exists'] = true;
					$file_info['mp3_size'] = filesize( $mp3_file );
				}
			}
			
			// Check for cloud storage MP3 files if no local MP3 found.
			if ( ! $file_info['mp3_exists'] ) {
				$mp3_file_meta = get_post_meta( $result->ID, '_listenup_mp3_file', true );
				$mp3_url_meta = get_post_meta( $result->ID, '_listenup_mp3_url', true );
				$mp3_size_meta = get_post_meta( $result->ID, '_listenup_mp3_size', true );
				
				if ( ! empty( $mp3_file_meta ) && ! empty( $mp3_url_meta ) ) {
					$file_info['mp3_exists'] = true;
					$file_info['mp3_size'] = ! empty( $mp3_size_meta ) ? (int) $mp3_size_meta : 0;
					$file_info['mp3_cloud_url'] = $mp3_url_meta;
					$file_info['mp3_cloud_file'] = $mp3_file_meta;
				}
			}
			
			$posts_with_audio[] = array(
				'ID' => $result->ID,
				'post_title' => $result->post_title,
				'post_type' => $result->post_type,
				'post_status' => $result->post_status,
				'post_modified' => $result->post_modified,
				'audio_meta' => $audio_meta,
				'file_info' => $file_info,
			);
		}

		return $posts_with_audio;
	}

	/**
	 * AJAX handler for testing conversion API connection.
	 */
	public function ajax_test_conversion_api() {
		// Check user permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'listenup' ) ) );
		}

		// Verify nonce.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'listenup_admin_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'listenup' ) ) );
		}

		// Test the connection.
		$conversion_api = ListenUp_Conversion_API::get_instance();
		$result = $conversion_api->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler for converting audio to MP3.
	 */
	public function ajax_convert_audio() {
		// Check user permissions.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'listenup' ) ) );
		}

		// Verify nonce.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'listenup_admin_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'listenup' ) ) );
		}

		// Get post ID.
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'listenup' ) ) );
		}

		// Check if this post has audio (single or chunked).
		$audio_meta = get_post_meta( $post_id, '_listenup_audio', true );
		$chunked_meta = get_post_meta( $post_id, '_listenup_chunked_audio', true );
		
		$has_single_audio = ! empty( $audio_meta ) && isset( $audio_meta['file'] );
		$has_chunked_audio = ! empty( $chunked_meta ) && isset( $chunked_meta['chunks'] );
		
		if ( ! $has_single_audio && ! $has_chunked_audio ) {
			wp_send_json_error( array( 'message' => __( 'No audio files found for this post.', 'listenup' ) ) );
		}

		// Check if MP3 already exists.
		if ( isset( $audio_meta['mp3_file'] ) && ! empty( $audio_meta['mp3_file'] ) ) {
			$upload_dir = wp_upload_dir();
			$mp3_file = $upload_dir['basedir'] . '/listenup-audio/' . $audio_meta['mp3_file'];
			if ( file_exists( $mp3_file ) ) {
				wp_send_json_error( array( 'message' => __( 'MP3 file already exists for this post.', 'listenup' ) ) );
			}
		}

		// Perform conversion (handles both single and multi-segment).
		$conversion_api = ListenUp_Conversion_API::get_instance();
		
		if ( $has_single_audio ) {
			// Single file conversion.
			$upload_dir = wp_upload_dir();
			$wav_file_path = $upload_dir['basedir'] . '/listenup-audio/' . $audio_meta['file'];

			if ( ! file_exists( $wav_file_path ) ) {
				wp_send_json_error( array( 'message' => __( 'WAV file not found.', 'listenup' ) ) );
			}
			
			$result = $conversion_api->convert_wav_to_mp3( $post_id, $wav_file_path, $audio_meta['file'] );
		} else {
			// Multi-segment conversion (conversion API handles finding the files).
			$result = $conversion_api->convert_wav_to_mp3( $post_id );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Optionally delete WAV file if setting is enabled.
		$options = get_option( 'listenup_options' );
		if ( isset( $options['delete_wav_after_conversion'] ) && $options['delete_wav_after_conversion'] ) {
			wp_delete_file( $wav_file_path );
			
			// Update post meta to remove WAV file reference.
			$audio_meta = get_post_meta( $post_id, '_listenup_audio', true );
			unset( $audio_meta['file'] );
			unset( $audio_meta['url'] );
			update_post_meta( $post_id, '_listenup_audio', $audio_meta );
			
			$result['wav_deleted'] = true;
		}

		// Handle both local files and cloud storage URLs.
		$mp3_url = isset( $result['mp3_url'] ) ? $result['mp3_url'] : ( isset( $result['cloud_url'] ) ? $result['cloud_url'] : '' );
		$mp3_size = isset( $result['mp3_size'] ) ? $result['mp3_size'] : ( isset( $result['file_size'] ) ? $result['file_size'] : 0 );
		
		wp_send_json_success( array(
			'message' => __( 'Audio converted successfully!', 'listenup' ),
			'mp3_url' => $mp3_url,
			'mp3_size' => $mp3_size,
			'wav_deleted' => isset( $result['wav_deleted'] ) ? $result['wav_deleted'] : false,
		) );
	}

	/**
	 * AJAX handler for deleting audio files.
	 */
	public function ajax_delete_audio() {
		// Check user permissions.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'listenup' ) ) );
		}

		// Verify nonce.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'listenup_admin_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'listenup' ) ) );
		}

		// Get post ID.
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'listenup' ) ) );
		}

		// Get audio metadata.
		$audio_meta = get_post_meta( $post_id, '_listenup_audio', true );
		if ( empty( $audio_meta ) ) {
			wp_send_json_error( array( 'message' => __( 'No audio files found for this post.', 'listenup' ) ) );
		}

		$upload_dir = wp_upload_dir();
		$cache_dir = $upload_dir['basedir'] . '/listenup-audio';
		$deleted_files = array();

		// Delete WAV file if exists.
		if ( isset( $audio_meta['file'] ) ) {
			$wav_file = $cache_dir . '/' . $audio_meta['file'];
			if ( file_exists( $wav_file ) ) {
				wp_delete_file( $wav_file );
				$deleted_files[] = 'WAV';
			}
		}

		// Delete MP3 file if exists.
		if ( isset( $audio_meta['mp3_file'] ) ) {
			$mp3_file = $cache_dir . '/' . $audio_meta['mp3_file'];
			if ( file_exists( $mp3_file ) ) {
				wp_delete_file( $mp3_file );
				$deleted_files[] = 'MP3';
			}
		}

		// Delete post meta.
		delete_post_meta( $post_id, '_listenup_audio' );

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %s: List of deleted file types */
				__( 'Audio files deleted successfully (%s).', 'listenup' ),
				implode( ', ', $deleted_files )
			),
		) );
	}

	/**
	 * Cloud storage section callback.
	 */
	public function cloud_storage_section_callback() {
		?>
		<p><?php esc_html_e( 'Configure cloud storage for audio files. This helps reduce bandwidth usage and enables development with localhost.', 'listenup' ); ?></p>
		<?php
	}

	/**
	 * Cloud storage provider field callback.
	 */
	public function cloud_storage_provider_field_callback() {
		$options = get_option( 'listenup_options' );
		$provider = isset( $options['cloud_storage_provider'] ) ? $options['cloud_storage_provider'] : '';
		
		$providers = array(
			'' => __( 'None (Local Storage)', 'listenup' ),
			'aws_s3' => __( 'AWS S3', 'listenup' ),
			'cloudflare_r2' => __( 'Cloudflare R2', 'listenup' ),
			'google_cloud' => __( 'Google Cloud Storage', 'listenup' ),
		);
		?>
		<select id="cloud_storage_provider" name="listenup_options[cloud_storage_provider]">
			<?php foreach ( $providers as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $provider, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Select a cloud storage provider for audio files.', 'listenup' ); ?>
		</p>
		<?php
	}

	/**
	 * Cloud storage settings field callback.
	 */
	public function cloud_storage_settings_field_callback() {
		$options = get_option( 'listenup_options' );
		?>
		<div id="aws-s3-settings" class="cloud-storage-settings" style="display: none;">
			<h4><?php esc_html_e( 'AWS S3 Settings', 'listenup' ); ?></h4>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Access Key ID', 'listenup' ); ?></th>
					<td>
						<input type="text" name="listenup_options[aws_access_key]" 
							   value="<?php echo esc_attr( isset( $options['aws_access_key'] ) ? $options['aws_access_key'] : '' ); ?>" 
							   class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Secret Access Key', 'listenup' ); ?></th>
					<td>
						<input type="password" name="listenup_options[aws_secret_key]" 
							   value="<?php echo esc_attr( isset( $options['aws_secret_key'] ) ? $options['aws_secret_key'] : '' ); ?>" 
							   class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Bucket Name', 'listenup' ); ?></th>
					<td>
						<input type="text" name="listenup_options[aws_bucket]" 
							   value="<?php echo esc_attr( isset( $options['aws_bucket'] ) ? $options['aws_bucket'] : '' ); ?>" 
							   class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Region', 'listenup' ); ?></th>
					<td>
						<input type="text" name="listenup_options[aws_region]" 
							   value="<?php echo esc_attr( isset( $options['aws_region'] ) ? $options['aws_region'] : 'us-east-1' ); ?>" 
							   class="regular-text" placeholder="us-east-1" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Base URL', 'listenup' ); ?></th>
					<td>
						<input type="url" name="listenup_options[aws_base_url]" 
							   value="<?php echo esc_attr( isset( $options['aws_base_url'] ) ? $options['aws_base_url'] : '' ); ?>" 
							   class="regular-text" placeholder="https://your-bucket.s3.amazonaws.com" />
						<p class="description"><?php esc_html_e( 'Public URL for your S3 bucket (e.g., https://your-bucket.s3.amazonaws.com)', 'listenup' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div id="cloudflare-r2-settings" class="cloud-storage-settings" style="display: none;">
			<h4><?php esc_html_e( 'Cloudflare R2 Settings', 'listenup' ); ?></h4>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Access Key ID', 'listenup' ); ?></th>
					<td>
						<input type="text" name="listenup_options[r2_access_key]" 
							   value="<?php echo esc_attr( isset( $options['r2_access_key'] ) ? $options['r2_access_key'] : '' ); ?>" 
							   class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Secret Access Key', 'listenup' ); ?></th>
					<td>
						<input type="password" name="listenup_options[r2_secret_key]" 
							   value="<?php echo esc_attr( isset( $options['r2_secret_key'] ) ? $options['r2_secret_key'] : '' ); ?>" 
							   class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Bucket Name', 'listenup' ); ?></th>
					<td>
						<input type="text" name="listenup_options[r2_bucket]" 
							   value="<?php echo esc_attr( isset( $options['r2_bucket'] ) ? $options['r2_bucket'] : '' ); ?>" 
							   class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Endpoint', 'listenup' ); ?></th>
					<td>
						<input type="url" name="listenup_options[r2_endpoint]" 
							   value="<?php echo esc_attr( isset( $options['r2_endpoint'] ) ? $options['r2_endpoint'] : '' ); ?>" 
							   class="regular-text" placeholder="https://your-account-id.r2.cloudflarestorage.com" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Base URL', 'listenup' ); ?></th>
					<td>
						<input type="url" name="listenup_options[r2_base_url]" 
							   value="<?php echo esc_attr( isset( $options['r2_base_url'] ) ? $options['r2_base_url'] : '' ); ?>" 
							   class="regular-text" placeholder="https://your-domain.com" />
						<p class="description"><?php esc_html_e( 'Public URL for your R2 bucket (e.g., https://your-domain.com)', 'listenup' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div id="google-cloud-settings" class="cloud-storage-settings" style="display: none;">
			<h4><?php esc_html_e( 'Google Cloud Storage Settings', 'listenup' ); ?></h4>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Project ID', 'listenup' ); ?></th>
					<td>
						<input type="text" name="listenup_options[gcs_project_id]" 
							   value="<?php echo esc_attr( isset( $options['gcs_project_id'] ) ? $options['gcs_project_id'] : '' ); ?>" 
							   class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Bucket Name', 'listenup' ); ?></th>
					<td>
						<input type="text" name="listenup_options[gcs_bucket]" 
							   value="<?php echo esc_attr( isset( $options['gcs_bucket'] ) ? $options['gcs_bucket'] : '' ); ?>" 
							   class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Credentials File Path', 'listenup' ); ?></th>
					<td>
						<input type="text" name="listenup_options[gcs_credentials_file]" 
							   value="<?php echo esc_attr( isset( $options['gcs_credentials_file'] ) ? $options['gcs_credentials_file'] : '' ); ?>" 
							   class="regular-text" placeholder="/path/to/service-account-key.json" />
						<p class="description"><?php esc_html_e( 'Path to your Google Cloud service account JSON file.', 'listenup' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Base URL', 'listenup' ); ?></th>
					<td>
						<input type="url" name="listenup_options[gcs_base_url]" 
							   value="<?php echo esc_attr( isset( $options['gcs_base_url'] ) ? $options['gcs_base_url'] : '' ); ?>" 
							   class="regular-text" placeholder="https://storage.googleapis.com/your-bucket" />
						<p class="description"><?php esc_html_e( 'Public URL for your GCS bucket.', 'listenup' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}
}
