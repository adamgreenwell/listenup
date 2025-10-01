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
		// Use WordPress standard debug.log file
		$this->log_file = WP_CONTENT_DIR . '/debug.log';
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
		$log_entry = "[ListenUp] [{$timestamp}] [{$level}] {$message}{$context_str}";

		// Use WordPress debug logging system
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging for plugin troubleshooting
			error_log( $log_entry );
		}
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

		$log_contents = file_get_contents( $this->log_file );
		$all_lines = explode( PHP_EOL, $log_contents );
		
		// Filter for ListenUp entries only
		$listenup_lines = array_filter( $all_lines, function( $line ) {
			return strpos( $line, '[ListenUp]' ) !== false;
		});
		
		// Re-index the array
		$listenup_lines = array_values( $listenup_lines );
		
		if ( $lines > 0 && count( $listenup_lines ) > $lines ) {
			$listenup_lines = array_slice( $listenup_lines, -$lines );
		}

		return implode( PHP_EOL, $listenup_lines );
	}

	/**
	 * Clear debug log.
	 *
	 * @return bool Success status.
	 */
	public function clear_log() {
		if ( ! file_exists( $this->log_file ) ) {
			return true;
		}

		$log_contents = file_get_contents( $this->log_file );
		$all_lines = explode( PHP_EOL, $log_contents );
		
		// Filter out ListenUp entries
		$filtered_lines = array_filter( $all_lines, function( $line ) {
			return strpos( $line, '[ListenUp]' ) === false;
		});
		
		// Re-index the array
		$filtered_lines = array_values( $filtered_lines );
		
		// Write back the filtered content
		$new_content = implode( PHP_EOL, $filtered_lines );
		return file_put_contents( $this->log_file, $new_content, LOCK_EX ) !== false;
	}

	/**
	 * Get log file size.
	 *
	 * @return int Log file size in bytes.
	 */
	public function get_log_size() {
		$log_contents = $this->get_log_contents();
		return strlen( $log_contents );
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
	 * Get log file path.
	 *
	 * @return string Log file path.
	 */
	public function get_log_file_path() {
		return $this->log_file;
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
