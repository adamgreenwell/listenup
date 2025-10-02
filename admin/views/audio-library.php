<?php
/**
 * Audio Library view template.
 *
 * @package ListenUp
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Calculate storage statistics.
$total_wav_size = 0;
$total_mp3_size = 0;
$total_posts = count( $posts_with_audio );
$wav_only_count = 0;
$mp3_ready_count = 0;
$pending_count = 0;
$failed_count = 0;

foreach ( $posts_with_audio as $post_data ) {
	$file_info = $post_data['file_info'];
	$audio_meta = $post_data['audio_meta'];
	
	if ( $file_info['wav_exists'] ) {
		$total_wav_size += $file_info['wav_size'];
	}
	
	if ( $file_info['mp3_exists'] ) {
		$total_mp3_size += $file_info['mp3_size'];
		$mp3_ready_count++;
	}
	
	$conversion_status = isset( $audio_meta['conversion_status'] ) ? $audio_meta['conversion_status'] : '';
	
	if ( ! $file_info['mp3_exists'] && $file_info['wav_exists'] ) {
		if ( 'pending' === $conversion_status || 'converting' === $conversion_status ) {
			$pending_count++;
		} elseif ( 'failed' === $conversion_status ) {
			$failed_count++;
		} else {
			$wav_only_count++;
		}
	}
}

$total_storage = $total_wav_size + $total_mp3_size;

/**
 * Format bytes to human-readable size.
 *
 * @param int $bytes File size in bytes.
 * @return string Formatted size string.
 */
function listenup_format_bytes( $bytes ) {
	if ( $bytes >= 1073741824 ) {
		return number_format( $bytes / 1073741824, 2 ) . ' GB';
	} elseif ( $bytes >= 1048576 ) {
		return number_format( $bytes / 1048576, 2 ) . ' MB';
	} elseif ( $bytes >= 1024 ) {
		return number_format( $bytes / 1024, 2 ) . ' KB';
	} else {
		return $bytes . ' bytes';
	}
}
?>

<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Audio Library', 'listenup' ); ?></h1>
	<hr class="wp-header-end">

	<?php if ( ! empty( $posts_with_audio ) ) : ?>
		<!-- Statistics Dashboard -->
		<div class="listenup-stats-dashboard" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
			<div class="listenup-stat-card" style="background: #fff; border-left: 4px solid #2271b1; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
				<div style="font-size: 28px; font-weight: 600; color: #2271b1;"><?php echo esc_html( $total_posts ); ?></div>
				<div style="color: #646970; margin-top: 5px;"><?php esc_html_e( 'Total Audio Files', 'listenup' ); ?></div>
			</div>
			
			<div class="listenup-stat-card" style="background: #fff; border-left: 4px solid #00a32a; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
				<div style="font-size: 28px; font-weight: 600; color: #00a32a;"><?php echo esc_html( listenup_format_bytes( $total_storage ) ); ?></div>
				<div style="color: #646970; margin-top: 5px;"><?php esc_html_e( 'Total Storage', 'listenup' ); ?></div>
			</div>
			
			<div class="listenup-stat-card" style="background: #fff; border-left: 4px solid #72aee6; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
				<div style="font-size: 28px; font-weight: 600; color: #72aee6;"><?php echo esc_html( $mp3_ready_count ); ?></div>
				<div style="color: #646970; margin-top: 5px;"><?php esc_html_e( 'MP3 Ready', 'listenup' ); ?></div>
			</div>
			
			<div class="listenup-stat-card" style="background: #fff; border-left: 4px solid #dba617; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
				<div style="font-size: 28px; font-weight: 600; color: #dba617;"><?php echo esc_html( $wav_only_count ); ?></div>
				<div style="color: #646970; margin-top: 5px;"><?php esc_html_e( 'WAV Only', 'listenup' ); ?></div>
			</div>
		</div>

		<!-- Filter and Actions Bar -->
		<div style="background: #fff; padding: 15px; margin: 20px 0; border: 1px solid #c3c4c7;">
			<form method="get" style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
				<input type="hidden" name="page" value="listenup-audio-library">
				
				<label for="filter-status" style="margin: 0;">
					<?php esc_html_e( 'Filter:', 'listenup' ); ?>
				</label>
				<select name="status" id="filter-status">
					<option value=""><?php esc_html_e( 'All Files', 'listenup' ); ?></option>
					<option value="mp3_ready"><?php esc_html_e( 'MP3 Ready', 'listenup' ); ?></option>
					<option value="wav_only"><?php esc_html_e( 'WAV Only', 'listenup' ); ?></option>
					<option value="converting"><?php esc_html_e( 'Converting', 'listenup' ); ?></option>
					<option value="failed"><?php esc_html_e( 'Failed', 'listenup' ); ?></option>
				</select>
				
				<input type="submit" class="button" value="<?php esc_attr_e( 'Apply Filter', 'listenup' ); ?>">
				
				<div style="flex: 1;"></div>
				
				<input type="search" name="s" placeholder="<?php esc_attr_e( 'Search posts...', 'listenup' ); ?>" style="width: 250px;">
				<input type="submit" class="button" value="<?php esc_attr_e( 'Search', 'listenup' ); ?>">
			</form>
		</div>

		<!-- Audio Files Table -->
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width: 3%;"><input type="checkbox" id="select-all"></th>
					<th style="width: 35%;"><?php esc_html_e( 'Post Title', 'listenup' ); ?></th>
					<th style="width: 10%;"><?php esc_html_e( 'Type', 'listenup' ); ?></th>
					<th style="width: 15%;"><?php esc_html_e( 'Status', 'listenup' ); ?></th>
					<th style="width: 15%;"><?php esc_html_e( 'File Size', 'listenup' ); ?></th>
					<th style="width: 12%;"><?php esc_html_e( 'Last Modified', 'listenup' ); ?></th>
					<th style="width: 10%;"><?php esc_html_e( 'Actions', 'listenup' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $posts_with_audio as $post_data ) : ?>
					<?php
					$post_id = $post_data['ID'];
					$audio_meta = $post_data['audio_meta'];
					$file_info = $post_data['file_info'];
					$edit_url = get_edit_post_link( $post_id );
					$view_url = get_permalink( $post_id );
					
					// Determine status.
					$status_class = '';
					$status_text = '';
					
					if ( $file_info['mp3_exists'] ) {
						$status_class = 'mp3-ready';
						$status_text = __( 'MP3 Ready', 'listenup' );
						$status_color = '#00a32a';
					} elseif ( isset( $audio_meta['conversion_status'] ) && 'converting' === $audio_meta['conversion_status'] ) {
						$status_class = 'converting';
						$status_text = __( 'Converting...', 'listenup' );
						$status_color = '#2271b1';
					} elseif ( isset( $audio_meta['conversion_status'] ) && 'pending' === $audio_meta['conversion_status'] ) {
						$status_class = 'pending';
						$status_text = __( 'Pending', 'listenup' );
						$status_color = '#dba617';
					} elseif ( isset( $audio_meta['conversion_status'] ) && 'failed' === $audio_meta['conversion_status'] ) {
						$status_class = 'failed';
						$status_text = __( 'Failed', 'listenup' );
						$status_color = '#d63638';
					} elseif ( $file_info['wav_exists'] ) {
						$status_class = 'wav-only';
						$status_text = __( 'WAV Only', 'listenup' );
						$status_color = '#dba617';
					} else {
						$status_class = 'no-file';
						$status_text = __( 'No File', 'listenup' );
						$status_color = '#d63638';
					}
					
					// Format file sizes.
					$size_text = '';
					if ( $file_info['wav_exists'] && $file_info['mp3_exists'] ) {
						$mp3_location = isset( $file_info['mp3_cloud_url'] ) ? __( ' (Cloud)', 'listenup' ) : '';
						$size_text = sprintf(
							/* translators: 1: WAV file size, 2: MP3 file size, 3: Cloud indicator */
							__( 'WAV: %1$s | MP3: %2$s%3$s', 'listenup' ),
							listenup_format_bytes( $file_info['wav_size'] ),
							listenup_format_bytes( $file_info['mp3_size'] ),
							$mp3_location
						);
					} elseif ( $file_info['wav_exists'] ) {
						$size_text = sprintf(
							/* translators: %s: WAV file size */
							__( 'WAV: %s', 'listenup' ),
							listenup_format_bytes( $file_info['wav_size'] )
						);
					} elseif ( $file_info['mp3_exists'] ) {
						$mp3_location = isset( $file_info['mp3_cloud_url'] ) ? __( ' (Cloud)', 'listenup' ) : '';
						$size_text = sprintf(
							/* translators: 1: MP3 file size, 2: Cloud indicator */
							__( 'MP3: %1$s%2$s', 'listenup' ),
							listenup_format_bytes( $file_info['mp3_size'] ),
							$mp3_location
						);
					} else {
						$size_text = '—';
					}
					?>
					<tr data-post-id="<?php echo esc_attr( $post_id ); ?>">
						<td><input type="checkbox" name="audio_ids[]" value="<?php echo esc_attr( $post_id ); ?>"></td>
						<td class="title column-title">
							<strong>
								<a href="<?php echo esc_url( $edit_url ); ?>">
									<?php echo esc_html( $post_data['post_title'] ? $post_data['post_title'] : __( '(no title)', 'listenup' ) ); ?>
								</a>
							</strong>
							<div class="row-actions">
								<span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'listenup' ); ?></a> | </span>
								<span class="view"><a href="<?php echo esc_url( $view_url ); ?>" target="_blank"><?php esc_html_e( 'View', 'listenup' ); ?></a></span>
							</div>
						</td>
						<td><?php echo esc_html( ucfirst( $post_data['post_type'] ) ); ?></td>
						<td>
							<span style="display: inline-block; padding: 4px 8px; border-radius: 3px; background: <?php echo esc_attr( $status_color ); ?>; color: #fff; font-size: 12px; font-weight: 500;">
								<?php echo esc_html( $status_text ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $size_text ); ?></td>
						<td><?php echo esc_html( human_time_diff( strtotime( $post_data['post_modified'] ), current_time( 'timestamp' ) ) . ' ago' ); ?></td>
						<td>
							<div class="button-group" style="display: flex; gap: 5px;">
								<?php if ( $file_info['wav_exists'] && ! $file_info['mp3_exists'] ) : ?>
									<button class="button button-small listenup-convert-btn" data-post-id="<?php echo esc_attr( $post_id ); ?>" title="<?php esc_attr_e( 'Convert to MP3', 'listenup' ); ?>">
										<?php esc_html_e( 'Convert', 'listenup' ); ?>
									</button>
								<?php endif; ?>
								
								<?php if ( $file_info['wav_exists'] || $file_info['mp3_exists'] ) : ?>
									<button class="button button-small listenup-delete-btn" data-post-id="<?php echo esc_attr( $post_id ); ?>" title="<?php esc_attr_e( 'Delete Audio', 'listenup' ); ?>">
										<?php esc_html_e( 'Delete', 'listenup' ); ?>
									</button>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th><input type="checkbox"></th>
					<th><?php esc_html_e( 'Post Title', 'listenup' ); ?></th>
					<th><?php esc_html_e( 'Type', 'listenup' ); ?></th>
					<th><?php esc_html_e( 'Status', 'listenup' ); ?></th>
					<th><?php esc_html_e( 'File Size', 'listenup' ); ?></th>
					<th><?php esc_html_e( 'Last Modified', 'listenup' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'listenup' ); ?></th>
				</tr>
			</tfoot>
		</table>

		<!-- Bulk Actions (for future implementation) -->
		<div style="margin-top: 20px; padding: 15px; background: #f0f0f1; border: 1px solid #c3c4c7;">
			<label for="bulk-action-selector"><?php esc_html_e( 'Bulk Actions:', 'listenup' ); ?></label>
			<select id="bulk-action-selector" disabled>
				<option value=""><?php esc_html_e( 'Select an action', 'listenup' ); ?></option>
				<option value="convert"><?php esc_html_e( 'Convert to MP3', 'listenup' ); ?></option>
				<option value="delete"><?php esc_html_e( 'Delete Audio Files', 'listenup' ); ?></option>
				<option value="regenerate"><?php esc_html_e( 'Regenerate Audio', 'listenup' ); ?></option>
			</select>
			<button class="button" disabled><?php esc_html_e( 'Apply', 'listenup' ); ?></button>
			<p class="description"><?php esc_html_e( 'Bulk actions coming soon...', 'listenup' ); ?></p>
		</div>

	<?php else : ?>
		<!-- Empty State -->
		<div style="text-align: center; padding: 60px 20px; background: #fff; border: 1px solid #c3c4c7; margin-top: 20px;">
			<div style="font-size: 48px; color: #c3c4c7; margin-bottom: 20px;">
				<span class="dashicons dashicons-media-audio" style="font-size: 80px; width: 80px; height: 80px;"></span>
			</div>
			<h2><?php esc_html_e( 'No audio files found', 'listenup' ); ?></h2>
			<p style="color: #646970; max-width: 500px; margin: 20px auto;">
				<?php esc_html_e( 'You haven\'t generated any audio files yet. Go to any post or page and use the ListenUp meta box to generate audio.', 'listenup' ); ?>
			</p>
			<a href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'View Posts', 'listenup' ); ?>
			</a>
		</div>
	<?php endif; ?>
</div>

<style>
	.listenup-stats-dashboard {
		animation: fadeIn 0.3s ease-in;
	}
	
	@keyframes fadeIn {
		from { opacity: 0; transform: translateY(10px); }
		to { opacity: 1; transform: translateY(0); }
	}
	
	.listenup-stat-card {
		transition: transform 0.2s, box-shadow 0.2s;
	}
	
	.listenup-stat-card:hover {
		transform: translateY(-2px);
		box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important;
	}
	
	.wp-list-table .button-small {
		padding: 0 8px;
		height: 26px;
		font-size: 12px;
		line-height: 24px;
	}
	
	#select-all {
		cursor: pointer;
	}
</style>

