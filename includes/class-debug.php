<?php
/**
 * Debug functionality for ListenUp plugin.
 *
 * @package ListenUp
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Debug class for centralized logging and troubleshooting.
 */
class ListenUp_Debug {

	/**
	 * Single instance of the class.
	 *
	 * @var ListenUp_Debug
	 */
	private static $instance = null;

	/**
	 * Debug log file path.
	 *
	 * @var string
	 */
	private $log_file;

	/**
	 * Maximum log file size in bytes (1MB).
	 *
	 * @var int
	 */
	private $max_log_size = 1048576;

	/**
	 * Get single instance of the class.
	 *
	 * @return ListenUp_Debug
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
		$this->log_file = $upload_dir['basedir'] . '/listenup-debug.log';
	}

	/**
	 * Check if debug logging is enabled.
	 *
	 * @return bool True if debug is enabled.
	 */
	public function is_debug_enabled() {
		$options = get_option( 'listenup_options' );
		return isset( $options['debug_enabled'] ) && $options['debug_enabled'];
	}

	/**
	 * Log a debug message.
	 *
	 * @param string $message Debug message.
	 * @param string $level Log level (info, warning, error).
	 * @param array  $context Additional context data.
	 */
	public function log( $message, $level = 'info', $context = array() ) {
		if ( ! $this->is_debug_enabled() ) {
			return;
		}

		$timestamp = current_time( 'Y-m-d H:i:s' );
		$context_str = ! empty( $context ) ? ' | Context: ' . wp_json_encode( $context ) : '';
		$log_entry = "[{$timestamp}] [{$level}] {$message}{$context_str}" . PHP_EOL;

		// Check if log file needs rotation.
		if ( file_exists( $this->log_file ) && filesize( $this->log_file ) > $this->max_log_size ) {
			$this->rotate_log();
		}

		// Write to log file.
		file_put_contents( $this->log_file, $log_entry, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Log an info message.
	 *
	 * @param string $message Info message.
	 * @param array  $context Additional context data.
	 */
	public function info( $message, $context = array() ) {
		$this->log( $message, 'info', $context );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $message Warning message.
	 * @param array  $context Additional context data.
	 */
	public function warning( $message, $context = array() ) {
		$this->log( $message, 'warning', $context );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message Error message.
	 * @param array  $context Additional context data.
	 */
	public function error( $message, $context = array() ) {
		$this->log( $message, 'error', $context );
	}

	/**
	 * Log API request details.
	 *
	 * @param string $endpoint API endpoint.
	 * @param array  $request_data Request data.
	 * @param array  $response_data Response data.
	 * @param int    $response_code HTTP response code.
	 */
	public function log_api_request( $endpoint, $request_data, $response_data, $response_code ) {
		$this->info( 'API Request', array(
			'endpoint' => $endpoint,
			'request_data' => $request_data,
			'response_code' => $response_code,
			'response_data' => $response_data,
		) );
	}

	/**
	 * Log cache operations.
	 *
	 * @param string $operation Cache operation (get, set, delete).
	 * @param string $key Cache key.
	 * @param mixed  $data Cache data (for set operations).
	 * @param bool   $success Operation success status.
	 */
	public function log_cache_operation( $operation, $key, $data = null, $success = true ) {
		$context = array(
			'operation' => $operation,
			'key' => $key,
			'success' => $success,
		);

		if ( $data !== null ) {
			$context['data'] = $data;
		}

		$level = $success ? 'info' : 'error';
		$this->log( "Cache {$operation}", $level, $context );
	}

	/**
	 * Log post meta operations.
	 *
	 * @param string $operation Meta operation (get, set, delete).
	 * @param int    $post_id Post ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $meta_value Meta value (for set operations).
	 * @param bool   $success Operation success status.
	 */
	public function log_post_meta_operation( $operation, $post_id, $meta_key, $meta_value = null, $success = true ) {
		$context = array(
			'operation' => $operation,
			'post_id' => $post_id,
			'meta_key' => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'success' => $success,
		);

		if ( $meta_value !== null ) {
			$context['meta_value'] = $meta_value; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}

		$level = $success ? 'info' : 'error';
		$this->log( "Post Meta {$operation}", $level, $context );
	}

	/**
	 * Get debug log contents.
	 *
	 * @param int $lines Number of lines to retrieve (0 for all).
	 * @return string Log contents.
	 */
	public function get_log_contents( $lines = 0 ) {
		if ( ! file_exists( $this->log_file ) ) {
			return '';
		}

		if ( $lines > 0 ) {
			$log_contents = file_get_contents( $this->log_file );
			$log_lines = explode( PHP_EOL, $log_contents );
			$log_lines = array_slice( $log_lines, -$lines );
			return implode( PHP_EOL, $log_lines );
		}

		return file_get_contents( $this->log_file );
	}

	/**
	 * Clear debug log.
	 *
	 * @return bool Success status.
	 */
	public function clear_log() {
		if ( file_exists( $this->log_file ) ) {
			return wp_delete_file( $this->log_file );
		}
		return true;
	}

	/**
	 * Get log file size.
	 *
	 * @return int Log file size in bytes.
	 */
	public function get_log_size() {
		if ( file_exists( $this->log_file ) ) {
			return filesize( $this->log_file );
		}
		return 0;
	}

	/**
	 * Get log file size formatted.
	 *
	 * @return string Formatted log file size.
	 */
	public function get_log_size_formatted() {
		return size_format( $this->get_log_size() );
	}

	/**
	 * Rotate log file.
	 */
	private function rotate_log() {
		if ( file_exists( $this->log_file ) ) {
			$backup_file = $this->log_file . '.backup';
			if ( file_exists( $backup_file ) ) {
				wp_delete_file( $backup_file );
			}
			// Use WP_Filesystem for file operations.
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();
			global $wp_filesystem;
			if ( $wp_filesystem ) {
				$wp_filesystem->move( $this->log_file, $backup_file );
			} else {
				// Fallback to rename if WP_Filesystem is not available.
				rename( $this->log_file, $backup_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			}
		}
	}

	/**
	 * Get debug statistics.
	 *
	 * @return array Debug statistics.
	 */
	public function get_debug_stats() {
		$stats = array(
			'debug_enabled' => $this->is_debug_enabled(),
			'log_file_exists' => file_exists( $this->log_file ),
			'log_size' => $this->get_log_size(),
			'log_size_formatted' => $this->get_log_size_formatted(),
		);

		if ( file_exists( $this->log_file ) ) {
			$log_contents = $this->get_log_contents();
			$lines = explode( PHP_EOL, $log_contents );
			$stats['total_lines'] = count( array_filter( $lines ) );
			
			// Count log levels.
			$stats['info_count'] = substr_count( $log_contents, '[info]' );
			$stats['warning_count'] = substr_count( $log_contents, '[warning]' );
			$stats['error_count'] = substr_count( $log_contents, '[error]' );
		} else {
			$stats['total_lines'] = 0;
			$stats['info_count'] = 0;
			$stats['warning_count'] = 0;
			$stats['error_count'] = 0;
		}

		return $stats;
	}
}
