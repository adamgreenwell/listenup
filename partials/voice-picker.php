<?php
/**
 * Voice Picker Partial
 *
 * @package ListenUp
 * @since 1.1.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Extract variables for use in template
$grouped_voices = $grouped_voices ?? array();
$selected_voice_data = $selected_voice_data ?? null;
$selected_voice_style = $selected_voice_style ?? 'Narration';
$selected_voice = $selected_voice ?? '';
$voices = $voices ?? array();
?>

<div class="listenup-voice-picker">
	<!-- Selected Voice Display -->
	<div class="voice-picker-selected">
		<button type="button" class="voice-picker-trigger" id="voice-picker-trigger">
			<?php if ( $selected_voice_data ) : ?>
				<div class="voice-avatar">
					<?php echo wp_kses_post( $this->get_voice_avatar( $selected_voice_data ) ); ?>
				</div>
				<div class="voice-info">
					<span class="voice-name"><?php echo esc_html( $selected_voice_data['displayName'] ); ?></span>
					<span class="voice-details"><?php echo esc_html( $selected_voice_data['displayLanguage'] . ' (' . $selected_voice_data['accent'] . ')' ); ?></span>
					<span class="voice-style"><?php echo esc_html( $selected_voice_style ); ?></span>
				</div>
			<?php else : ?>
				<div class="voice-info">
					<span class="voice-name"><?php esc_html_e( 'Select a voice...', 'listenup' ); ?></span>
				</div>
			<?php endif; ?>
			<span class="voice-picker-arrow"></span>
		</button>
		
		<!-- Hidden input for form submission -->
		<input type="hidden" id="selected_voice" name="listenup_options[selected_voice]" value="<?php echo esc_attr( $selected_voice ); ?>" />
	</div>
	
	<!-- Voice Picker Modal -->
	<div class="voice-picker-modal" id="voice-picker-modal" style="display: none;">
		<div class="voice-picker-content">
			<div class="voice-picker-header">
				<h3><?php esc_html_e( 'Select Voice', 'listenup' ); ?></h3>
				<button type="button" class="voice-picker-close" id="voice-picker-close">&times;</button>
			</div>
			
			<div class="voice-picker-filters">
				<div class="filter-group">
					<input type="text" id="voice-search" placeholder="<?php esc_attr_e( 'Search voices...', 'listenup' ); ?>" class="voice-search-input" />
				</div>
				<div class="filter-group">
					<select id="language-filter" class="language-filter">
						<option value=""><?php esc_html_e( 'All Languages', 'listenup' ); ?></option>
						<?php foreach ( array_keys( $grouped_voices ) as $language ) : ?>
							<option value="<?php echo esc_attr( $language ); ?>"><?php echo esc_html( $language ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="filter-group">
					<select id="gender-filter" class="gender-filter">
						<option value=""><?php esc_html_e( 'All Genders', 'listenup' ); ?></option>
						<option value="Male"><?php esc_html_e( 'Male', 'listenup' ); ?></option>
						<option value="Female"><?php esc_html_e( 'Female', 'listenup' ); ?></option>
					</select>
				</div>
				<div class="filter-group">
					<select id="style-filter" class="style-filter">
						<option value=""><?php esc_html_e( 'All Styles', 'listenup' ); ?></option>
						<?php
						// Get all unique styles from all voices
						$all_styles = array();
						foreach ( $voices as $voice ) {
							if ( isset( $voice['availableStyles'] ) ) {
								$all_styles = array_merge( $all_styles, $voice['availableStyles'] );
							}
						}
						$all_styles = array_unique( $all_styles );
						sort( $all_styles );
						foreach ( $all_styles as $style ) :
						?>
							<option value="<?php echo esc_attr( $style ); ?>"><?php echo esc_html( $style ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
			
			<div class="voice-picker-list" id="voice-picker-list">
				<?php foreach ( $grouped_voices as $language => $language_voices ) : ?>
					<div class="voice-language-group" data-language="<?php echo esc_attr( $language ); ?>">
						<h4 class="language-header"><?php echo esc_html( $language ); ?></h4>
						<div class="voice-grid">
							<?php foreach ( $language_voices as $voice ) : ?>
								<div class="voice-item" 
									 data-voice-id="<?php echo esc_attr( $voice['voiceId'] ); ?>"
									 data-gender="<?php echo esc_attr( $voice['gender'] ); ?>"
									 data-language="<?php echo esc_attr( $language ); ?>"
									 data-display-name="<?php echo esc_attr( $voice['displayName'] ); ?>"
									 data-available-styles="<?php echo esc_attr( implode( ',', isset( $voice['availableStyles'] ) ? $voice['availableStyles'] : array() ) ); ?>">
									<div class="voice-avatar">
										<?php echo wp_kses_post( $this->get_voice_avatar( $voice ) ); ?>
									</div>
									<div class="voice-info">
										<div class="voice-name"><?php echo esc_html( $voice['displayName'] ); ?></div>
										<div class="voice-details">
											<span class="voice-accent"><?php echo esc_html( $voice['accent'] ); ?></span>
											<span class="voice-gender"><?php echo esc_html( $voice['gender'] ); ?></span>
										</div>
										<div class="voice-description"><?php echo esc_html( $voice['description'] ); ?></div>
										<?php if ( isset( $voice['availableStyles'] ) && ! empty( $voice['availableStyles'] ) ) : ?>
											<div class="voice-styles">
												<label for="style-<?php echo esc_attr( $voice['voiceId'] ); ?>"><?php esc_html_e( 'Style:', 'listenup' ); ?></label>
												<select class="voice-style-select" id="style-<?php echo esc_attr( $voice['voiceId'] ); ?>" data-voice-id="<?php echo esc_attr( $voice['voiceId'] ); ?>">
													<?php foreach ( $voice['availableStyles'] as $style ) : ?>
														<option value="<?php echo esc_attr( $style ); ?>" <?php selected( $selected_voice_style, $style ); ?>>
															<?php echo esc_html( $style ); ?>
														</option>
													<?php endforeach; ?>
												</select>
											</div>
										<?php endif; ?>
									</div>
									<div class="voice-actions">
										<button type="button" class="voice-preview-btn" data-voice-id="<?php echo esc_attr( $voice['voiceId'] ); ?>">
											<span class="preview-icon">Play</span>
										</button>
										<button type="button" class="voice-select-btn" data-voice-id="<?php echo esc_attr( $voice['voiceId'] ); ?>">
											<?php esc_html_e( 'Select', 'listenup' ); ?>
										</button>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
</div>
