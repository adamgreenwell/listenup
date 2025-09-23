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
		
		echo '<p class="description">';
		printf(
			/* translators: %s: Murf.ai website URL */
			esc_html__( 'Enter your Murf.ai API key. You can get one from %s.', 'listenup' ),
			'<a href="https://murf.ai" target="_blank">murf.ai</a>'
		);
		echo '</p>';
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

		echo '<select id="auto_placement" name="listenup_options[auto_placement]">';
		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $auto_placement, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		
		echo '<p class="description">';
		/* translators: Description for auto placement setting */
		esc_html_e( 'Choose whether to automatically display the audio player on your content.', 'listenup' );
		echo '</p>';
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

		echo '<select id="placement_position" name="listenup_options[placement_position]">';
		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $placement_position, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		
		echo '<p class="description">';
		/* translators: Description for placement position setting */
		esc_html_e( 'Choose where to place the audio player when using automatic placement.', 'listenup' );
		echo '</p>';
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
		
		echo '<label for="debug_enabled">' . esc_html__( 'Enable debug logging', 'listenup' ) . '</label>';
		
		echo '<p class="description">';
		/* translators: Description for debug logging setting */
		esc_html_e( 'When enabled, detailed debug information will be logged to help troubleshoot issues.', 'listenup' );
		echo '</p>';

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
		
		echo '<div class="listenup-debug-viewer" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">';
		echo '<h4>' . esc_html__( 'Debug Log Viewer', 'listenup' ) . '</h4>';
		
		// Debug stats.
		echo '<div class="listenup-debug-stats" style="margin-bottom: 15px;">';
		echo '<p><strong>' . esc_html__( 'Log Statistics:', 'listenup' ) . '</strong></p>';
		echo '<ul>';
		printf( '<li>%s: %s</li>', esc_html__( 'Log Size', 'listenup' ), esc_html( $stats['log_size_formatted'] ) );
		printf( '<li>%s: %d</li>', esc_html__( 'Total Lines', 'listenup' ), esc_html( $stats['total_lines'] ) );
		printf( '<li>%s: %d</li>', esc_html__( 'Info Messages', 'listenup' ), esc_html( $stats['info_count'] ) );
		printf( '<li>%s: %d</li>', esc_html__( 'Warnings', 'listenup' ), esc_html( $stats['warning_count'] ) );
		printf( '<li>%s: %d</li>', esc_html__( 'Errors', 'listenup' ), esc_html( $stats['error_count'] ) );
		echo '</ul>';
		echo '</div>';

		// Log contents.
		$log_contents = $debug->get_log_contents( 50 ); // Last 50 lines.
		if ( ! empty( $log_contents ) ) {
			echo '<div class="listenup-debug-log">';
			echo '<p><strong>' . esc_html__( 'Recent Log Entries (Last 50 lines):', 'listenup' ) . '</strong></p>';
			echo '<textarea readonly style="width: 100%; height: 200px; font-family: monospace; font-size: 12px;">';
			echo esc_textarea( $log_contents );
			echo '</textarea>';
			echo '</div>';
		} else {
			echo '<p>' . esc_html__( 'No debug log entries found.', 'listenup' ) . '</p>';
		}

		// Clear log button.
		echo '<div class="listenup-debug-actions" style="margin-top: 15px;">';
		echo '<button type="button" id="clear-debug-log" class="button button-secondary">';
		esc_html_e( 'Clear Debug Log', 'listenup' );
		echo '</button>';
		echo '</div>';

		echo '</div>';
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
			'nonce' => wp_create_nonce( 'listenup_clear_debug_log' ),
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
		$result = $debug->clear_log();

		if ( $result ) {
			wp_send_json_success( array(
				'message' => __( 'Debug log cleared successfully.', 'listenup' ),
			) );
		} else {
			wp_send_json_error( array(
				'message' => __( 'Failed to clear debug log.', 'listenup' ),
			) );
		}
	}
}
