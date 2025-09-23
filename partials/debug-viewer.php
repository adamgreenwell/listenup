<?php
/**
 * Debug Viewer Partial
 *
 * @package ListenUp
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Extract variables for use in template
$stats = $stats ?? array();
$log_contents = $log_contents ?? '';
?>

<div class="listenup-debug-viewer" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
	<h4><?php esc_html_e( 'Debug Log Viewer', 'listenup' ); ?></h4>
	<p class="description"><?php esc_html_e( 'ListenUp debug entries are written to the standard WordPress debug.log file with [ListenUp] prefix for easy identification.', 'listenup' ); ?></p>
	
	<!-- Debug stats -->
	<div class="listenup-debug-stats" style="margin-bottom: 15px;">
		<p><strong><?php esc_html_e( 'Log Statistics:', 'listenup' ); ?></strong></p>
		<ul>
			<?php printf( '<li>%s: %s</li>', esc_html__( 'Log Size', 'listenup' ), esc_html( $stats['log_size_formatted'] ) ); ?>
			<?php printf( '<li>%s: %d</li>', esc_html__( 'Total Lines', 'listenup' ), esc_html( $stats['total_lines'] ) ); ?>
			<?php printf( '<li>%s: %d</li>', esc_html__( 'Info Messages', 'listenup' ), esc_html( $stats['info_count'] ) ); ?>
			<?php printf( '<li>%s: %d</li>', esc_html__( 'Warnings', 'listenup' ), esc_html( $stats['warning_count'] ) ); ?>
			<?php printf( '<li>%s: %d</li>', esc_html__( 'Errors', 'listenup' ), esc_html( $stats['error_count'] ) ); ?>
		</ul>
	</div>

	<!-- Log contents -->
	<?php if ( ! empty( $log_contents ) ) : ?>
		<div class="listenup-debug-log">
			<p><strong><?php esc_html_e( 'Recent Log Entries (Last 50 lines):', 'listenup' ); ?></strong></p>
			<textarea readonly style="width: 100%; height: 200px; font-family: monospace; font-size: 12px;"><?php echo esc_textarea( $log_contents ); ?></textarea>
		</div>
	<?php else : ?>
		<p><?php esc_html_e( 'No debug log entries found.', 'listenup' ); ?></p>
	<?php endif; ?>

	<!-- Clear log button -->
	<div class="listenup-debug-actions" style="margin-top: 15px;">
		<button type="button" id="clear-debug-log" class="button button-secondary">
			<?php esc_html_e( 'Clear ListenUp Debug Entries', 'listenup' ); ?>
		</button>
	</div>
</div>
