<?php
/**
 * Plugin Name: ListenUp
 * Description: Add "read this to me" functionality to your WordPress posts using Murf.ai text-to-speech technology.
 * Version: 1.3.0
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
define( 'LISTENUP_VERSION', '1.3.0' );
define( 'LISTENUP_PLUGIN_FILE', __FILE__ );
define( 'LISTENUP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LISTENUP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LISTENUP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader for ListenUp classes.
 *
 * @param string $class_name Class name to load.
 */
function listenup_autoloader( $class_name ) {
	// Only autoload ListenUp classes
	if ( 0 !== strpos( $class_name, 'ListenUp_' ) ) {
		return;
	}

	// Convert class name to file name
	$file_name = 'class-' . strtolower( str_replace( array( 'ListenUp_', '_' ), array( '', '-' ), $class_name ) ) . '.php';
	
	// Try includes directory first
	$file_path = LISTENUP_PLUGIN_DIR . 'includes/' . $file_name;
	
	if ( file_exists( $file_path ) ) {
		require_once $file_path;
		return;
	}
	
	// Try subdirectories if needed (for future organization)
	$directories = array( 'admin', 'frontend', 'api', 'integrations' );
	foreach ( $directories as $dir ) {
		$file_path = LISTENUP_PLUGIN_DIR . 'includes/' . $dir . '/' . $file_name;
		if ( file_exists( $file_path ) ) {
			require_once $file_path;
			return;
		}
	}
}

// Register the autoloader
spl_autoload_register( 'listenup_autoloader' );

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
	 * 
	 * Note: Classes are now automatically loaded via the autoloader.
	 * This method is kept for any non-class dependencies that might be added in the future.
	 */
	private function load_dependencies() {
		// Non-class dependencies can be loaded here if needed in the future.
		// Class files are automatically loaded via the autoloader when instantiated.
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
		ListenUp_Library_Shortcode::get_instance();
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
			'pre_roll_audio' => '',
			'download_restriction' => 'allow_all',
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
