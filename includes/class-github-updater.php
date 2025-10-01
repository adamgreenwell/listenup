<?php

/**
 * GitHub Updater Class for Public Repositories
 *
 * Simplified GitHub updater for public repositories using releases.
 * No authentication required for public repos.
 *
 * @package ListenUp
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class ListenUp_GitHub_Updater {
	/**
	 * Plugin slug
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Plugin data from get_plugin_data()
	 *
	 * @var array
	 */
	private $plugin_data;

	/**
	 * Full path to plugin file
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * GitHub repository information
	 *
	 * @var array
	 */
	private $repository;

	/**
	 * Constructor
	 *
	 * @param string $plugin_file Full path to the main plugin file
	 * @param string $username GitHub username
	 * @param string $repository GitHub repository name
	 */
	public function __construct( $plugin_file, $username = 'adamgreenwell', $repository = 'listenup' ) {
		$this->plugin_file = $plugin_file;
		$this->slug        = plugin_basename( $plugin_file );
		$this->repository  = array(
			'username'   => $username,
			'repository' => $repository
		);

		$this->init();
	}

	/**
	 * Initialize updater hooks and functionality
	 */
	private function init() {
		// WordPress update system hooks
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );

		// Schedule update checks
		add_action( 'wp', array( $this, 'schedule_update_check' ) );
		add_action( 'listenup_check_github_updates', array( $this, 'scheduled_update_check' ) );

		// Admin notices
		add_action( 'admin_notices', array( $this, 'show_update_notices' ) );
	}

	/**
	 * Check for plugin updates
	 *
	 * @param object $transient WordPress update transient
	 *
	 * @return object Modified transient
	 */
	public function check_for_update( $transient ) {
		if ( ! isset( $transient->checked ) ) {
			return $transient;
		}

		// Get plugin data
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$this->plugin_data = get_plugin_data( $this->plugin_file );

		// Get GitHub data
		$github_data = $this->fetch_github_data();
		if ( ! $github_data ) {
			return $transient;
		}

		// Extract version from GitHub data
		$github_version = $this->extract_version( $github_data );
		if ( ! $github_version ) {
			return $transient;
		}

		// Compare versions
		if ( version_compare( $github_version, $this->plugin_data['Version'], '>' ) ) {
			$package_url = $this->get_download_url( $github_data );

			$update_data = array(
				'slug'          => $this->slug,
				'plugin'        => $this->slug,
				'new_version'   => $github_version,
				'url'           => $this->plugin_data['PluginURI'],
				'package'       => $package_url,
				'icons'         => array(),
				'banners'       => array(),
				'banners_rtl'   => array(),
				'tested'        => get_bloginfo( 'version' ),
				'requires_php'  => '7.4',
				'compatibility' => new stdClass(),
			);

			$transient->response[ $this->slug ] = (object) $update_data;

			error_log( "ListenUp GitHub update available: {$this->plugin_data['Version']} -> $github_version" );
		}

		return $transient;
	}

	/**
	 * Fetch data from GitHub API
	 *
	 * @return array|false GitHub data or false on failure
	 */
	private function fetch_github_data() {
		$endpoint = "https://api.github.com/repos/{$this->repository['username']}/{$this->repository['repository']}/releases/latest";

		// Prepare request arguments
		$args = array(
			'timeout' => 30,
			'headers' => array(
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
				'Accept'     => 'application/vnd.github.v3+json'
			)
		);

		// Make the request
		$response = wp_remote_get( $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			error_log( 'ListenUp GitHub API request failed: ' . $response->get_error_message() );
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = wp_remote_retrieve_body( $response );

		if ( $response_code !== 200 ) {
			error_log( "ListenUp GitHub API returned error: $response_code - $body" );
			return false;
		}

		$data = json_decode( $body, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			error_log( 'ListenUp Failed to decode GitHub API response: ' . json_last_error_msg() );
			return false;
		}

		return $data;
	}

	/**
	 * Extract version from GitHub response
	 *
	 * @param array $github_data GitHub API response
	 *
	 * @return string|false Version string or false
	 */
	private function extract_version( $github_data ) {
		if ( isset( $github_data['tag_name'] ) ) {
			return ltrim( $github_data['tag_name'], 'v' );
		}

		return false;
	}

	/**
	 * Get download URL from GitHub response
	 *
	 * @param array $github_data GitHub API response
	 *
	 * @return string Download URL
	 */
	private function get_download_url( $github_data ) {
		if ( isset( $github_data['zipball_url'] ) ) {
			return $github_data['zipball_url'];
		}

		// Fallback: construct zipball URL
		return sprintf( 'https://api.github.com/repos/%s/%s/zipball/%s', 
			$this->repository['username'], 
			$this->repository['repository'], 
			$github_data['tag_name'] ?? 'main' 
		);
	}

	/**
	 * Provide plugin information for update popup
	 *
	 * @param false|object|array $result
	 * @param string $action
	 * @param object $args
	 *
	 * @return object|false
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( $action !== 'plugin_information' || $args->slug !== $this->slug ) {
			return $result;
		}

		$github_data = $this->fetch_github_data();
		if ( ! $github_data ) {
			return $result;
		}

		if ( ! isset( $this->plugin_data ) ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$this->plugin_data = get_plugin_data( $this->plugin_file );
		}

		$version     = $this->extract_version( $github_data );
		$description = isset( $github_data['body'] ) ? $github_data['body'] : 'No release notes available.';

		return (object) array(
			'name'              => $this->plugin_data['Name'],
			'slug'              => $this->slug,
			'version'           => $version,
			'author'            => $this->plugin_data['AuthorName'],
			'author_profile'    => $this->plugin_data['AuthorURI'],
			'last_updated'      => isset( $github_data['published_at'] ) ? $github_data['published_at'] : date( 'Y-m-d' ),
			'homepage'          => $this->plugin_data['PluginURI'],
			'short_description' => $this->plugin_data['Description'],
			'sections'          => array(
				'Description' => $this->plugin_data['Description'],
				'Changelog'   => $description,
			),
			'download_link'     => $this->get_download_url( $github_data )
		);
	}

	/**
	 * Actions after plugin installation
	 *
	 * @param bool $response
	 * @param array $hook_extra
	 * @param array $result
	 *
	 * @return array
	 */
	public function after_install( $response, $hook_extra, $result ) {
		global $wp_filesystem;

		// Only handle our plugin
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->slug ) {
			return $result;
		}

		$plugin_folder = WP_PLUGIN_DIR . '/' . dirname( $this->slug );

		// Move the new plugin files
		$wp_filesystem->move( $result['destination'], $plugin_folder );
		$result['destination'] = $plugin_folder;

		error_log( "ListenUp GitHub update installed successfully: $plugin_folder" );

		return $result;
	}

	/**
	 * Schedule automatic update checks
	 */
	public function schedule_update_check() {
		if ( ! wp_next_scheduled( 'listenup_check_github_updates' ) ) {
			wp_schedule_event( time(), 'daily', 'listenup_check_github_updates' );
		}
	}

	/**
	 * Scheduled update check via wp-cron
	 */
	public function scheduled_update_check() {
		$github_data = $this->fetch_github_data();
		if ( ! $github_data ) {
			return;
		}

		$github_version = $this->extract_version( $github_data );
		if ( ! $github_version ) {
			return;
		}

		if ( ! isset( $this->plugin_data ) ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$this->plugin_data = get_plugin_data( $this->plugin_file );
		}

		// Check if update is available
		if ( version_compare( $github_version, $this->plugin_data['Version'], '>' ) ) {
			// Store update information
			set_transient( 'listenup_github_update_available', array(
				'current_version' => $this->plugin_data['Version'],
				'new_version'     => $github_version,
				'checked_at'      => time()
			), DAY_IN_SECONDS );
		}
	}

	/**
	 * Show admin notices for updates
	 */
	public function show_update_notices() {
		$update_info = get_transient( 'listenup_github_update_available' );

		if ( $update_info && current_user_can( 'update_plugins' ) ) {
			echo '<div class="notice notice-info is-dismissible">';
			echo '<p><strong>ListenUp Update Available:</strong> ';
			echo sprintf( 'Version %s is available (current: %s). <a href="%s">Update now</a>', 
				esc_html( $update_info['new_version'] ), 
				esc_html( $update_info['current_version'] ), 
				admin_url( 'plugins.php' ) 
			);
			echo '</p></div>';
		}
	}

	/**
	 * AJAX handler: Manual update check
	 */
	public function manual_update_check() {
		check_ajax_referer( 'listenup_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// Clear update transients to force fresh check
		delete_site_transient( 'update_plugins' );
		delete_transient( 'listenup_github_update_available' );

		$github_data = $this->fetch_github_data();

		if ( $github_data ) {
			$github_version = $this->extract_version( $github_data );

			if ( ! isset( $this->plugin_data ) ) {
				if ( ! function_exists( 'get_plugin_data' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$this->plugin_data = get_plugin_data( $this->plugin_file );
			}

			$update_available = version_compare( $github_version, $this->plugin_data['Version'], '>' );

			wp_send_json_success( array(
				'message'          => $update_available ? 'Update available!' : 'Plugin is up to date.',
				'current_version'  => $this->plugin_data['Version'],
				'latest_version'   => $github_version,
				'update_available' => $update_available
			) );
		} else {
			wp_send_json_error( 'Failed to check for updates' );
		}
	}

	/**
	 * Cleanup - remove scheduled events
	 */
	public function cleanup() {
		wp_clear_scheduled_hook( 'listenup_check_github_updates' );
	}
}