<?php
/**
 * Plugin Name: ListenUp
 * Description: Add "read this to me" functionality to your WordPress posts using Murf.ai text-to-speech technology.
 * Version: 1.1.1
 * Author: Adam Greenwell
 * Author URI: https://adamgreenwell.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: listenup
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 6.8
 * Requires PHP: 7.4
 *
 * @package ListenUp
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'LISTENUP_VERSION', '1.1.1' );
define( 'LISTENUP_PLUGIN_FILE', __FILE__ );
define( 'LISTENUP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LISTENUP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LISTENUP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class.
 */
class ListenUp {

	/**
	 * Single instance of the plugin.
	 *
	 * @var ListenUp
	 */
	private static $instance = null;

	/**
	 * Get single instance of the plugin.
	 *
	 * @return ListenUp
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
		$this->load_dependencies();
	}

	/**
	 * Initialize WordPress hooks.
	 */
	private function init_hooks() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
	}

	/**
	 * Load plugin dependencies.
	 */
	private function load_dependencies() {
		require_once LISTENUP_PLUGIN_DIR . 'includes/class-debug.php';
		require_once LISTENUP_PLUGIN_DIR . 'includes/class-admin.php';
		require_once LISTENUP_PLUGIN_DIR . 'includes/class-api.php';
		require_once LISTENUP_PLUGIN_DIR . 'includes/class-cache.php';
		require_once LISTENUP_PLUGIN_DIR . 'includes/class-frontend.php';
		require_once LISTENUP_PLUGIN_DIR . 'includes/class-meta-box.php';
		require_once LISTENUP_PLUGIN_DIR . 'includes/class-shortcode.php';
	}

	/**
	 * Initialize plugin.
	 */
	public function init() {
		// Initialize components.
		ListenUp_Admin::get_instance();
		ListenUp_API::get_instance();
		ListenUp_Cache::get_instance();
		ListenUp_Frontend::get_instance();
		ListenUp_Meta_Box::get_instance();
		ListenUp_Shortcode::get_instance();
	}

	/**
	 * Initialize admin functionality.
	 */
	public function admin_init() {
		// Admin-specific initialization.
	}

	/**
	 * Plugin activation.
	 */
	public function activate() {
		// Create audio cache directory.
		$upload_dir = wp_upload_dir();
		$cache_dir = $upload_dir['basedir'] . '/listenup-audio';
		
		if ( ! file_exists( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
		}

		// Add .htaccess to protect cache files while allowing audio access.
		$htaccess_content = $this->generate_htaccess_content();
		file_put_contents( $cache_dir . '/.htaccess', $htaccess_content );

		// Set default options.
		$default_options = array(
			'murf_api_key' => '',
			'selected_voice' => 'en-US-natalie',
			'selected_voice_style' => 'Narration',
			'auto_placement' => 'none',
			'placement_position' => 'after',
		);
		
		add_option( 'listenup_options', $default_options );
	}

	/**
	 * Plugin deactivation.
	 */
	public function deactivate() {
		// Clean up if needed.
	}

	/**
	 * Generate .htaccess content for audio cache directory.
	 *
	 * @return string .htaccess file content.
	 */
	private function generate_htaccess_content() {
		$lines = array(
			'Options -Indexes',
			'',
			'# Allow access to audio files',
			'<Files ~ "\\.(mp3|wav|ogg|m4a)$">',
			'    Order allow,deny',
			'    Allow from all',
			'</Files>',
			'',
			'# Deny access to other files (like .json cache files)',
			'<Files ~ "\\.(json|txt|log)$">',
			'    Order allow,deny',
			'    Deny from all',
			'</Files>',
		);

		return implode( "\n", $lines );
	}
}

// Initialize the plugin.
ListenUp::get_instance();
