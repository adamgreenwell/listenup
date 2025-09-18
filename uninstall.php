<?php
/**
 * Uninstall script for ListenUp plugin.
 *
 * @package ListenUp
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove plugin options.
delete_option( 'listenup_options' );

// Remove cached audio files.
$upload_dir = wp_upload_dir();
$cache_dir = $upload_dir['basedir'] . '/listenup-audio';

if ( file_exists( $cache_dir ) ) {
	$files = glob( $cache_dir . '/*' );
	foreach ( $files as $file ) {
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
	// Use WP_Filesystem for directory removal.
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();
	global $wp_filesystem;
	if ( $wp_filesystem ) {
		$wp_filesystem->rmdir( $cache_dir, true );
	} else {
		// Fallback to rmdir if WP_Filesystem is not available.
		rmdir( $cache_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

