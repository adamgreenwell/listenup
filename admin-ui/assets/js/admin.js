/**
 * Admin JavaScript for ListenUp plugin.
 *
 * @package ListenUp
 */

(function($) {
    'use strict';

    /**
     * Admin functionality
     */
    class ListenUpAdmin {
        constructor() {
            this.init();
        }
        
        init() {
            this.bindEvents();
        }
        
        bindEvents() {
            // API key visibility toggle
            this.toggleApiKeyVisibility();
            
            // Auto-placement change handler
            this.handleAutoPlacementChange();
            
            // Debug log clear handler
            this.handleDebugLogClear();
            
            // Voice picker functionality
            this.initVoicePicker();

            // Pre-roll functionality
            this.initPreRollManager();
            
            // Cloud storage provider change handler
            this.handleCloudStorageProviderChange();
            
            // Delete modal functionality
            this.initDeleteModal();
        }
        
        toggleApiKeyVisibility() {
            const apiKeyField = $('#murf_api_key');
            if (apiKeyField.length) {
                // Add toggle button after the field
                const toggleButton = $('<button type="button" class="button button-secondary" style="margin-left: 5px;">Show</button>');
                apiKeyField.after(toggleButton);
                
                toggleButton.on('click', function() {
                    const field = apiKeyField;
                    const button = $(this);
                    
                    if (field.attr('type') === 'password') {
                        field.attr('type', 'text');
                        button.text('Hide');
                    } else {
                        field.attr('type', 'password');
                        button.text('Show');
                    }
                });
            }
        }
        
        handleAutoPlacementChange() {
            const autoPlacementField = $('#auto_placement');
            const placementPositionField = $('#placement_position');
            const placementPositionRow = placementPositionField.closest('tr');
            
            function togglePlacementPosition() {
                const value = autoPlacementField.val();
                if (value === 'none') {
                    placementPositionRow.hide();
                } else {
                    placementPositionRow.show();
                }
            }
            
            // Initial state
            togglePlacementPosition();
            
            // Change handler
            autoPlacementField.on('change', togglePlacementPosition);
        }
        
        handleDebugLogClear() {
            $(document).on('click', '#clear-debug-log', function(e) {
                e.preventDefault();
                
                if (!confirm('Are you sure you want to clear all ListenUp debug entries from the debug log?')) {
                    return;
                }
                
                const button = $(this);
                const originalText = button.text();
                
                button.prop('disabled', true).text('Clearing...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'listenup_clear_debug_log',
                        nonce: listenupAdmin.clearDebugNonce
                    },
                    success: function(response) {
                        if (response.success && response.data.success !== false) {
                            alert('ListenUp debug entries cleared successfully!');
                            location.reload(); // Reload to refresh the log viewer
                        } else {
                            const errorMessage = response.data?.message || 'Unknown error';
                            alert('Failed to clear ListenUp debug entries: ' + errorMessage);
                        }
                    },
                    error: function() {
                        alert('Network error occurred while clearing ListenUp debug entries.');
                    },
                    complete: function() {
                        button.prop('disabled', false).text(originalText);
                    }
                });
            });
        }

        initVoicePicker() {
            // Use event delegation since elements might not exist yet
            $(document).on('click', '#voice-picker-trigger', this.openVoicePicker.bind(this));
            $(document).on('click', '#voice-picker-close', this.closeVoicePicker.bind(this));
            $(document).on('click', '#voice-picker-modal', this.handleModalClick.bind(this));
            $(document).on('keydown', this.handleKeydown.bind(this));
            $(document).on('input', '#voice-search', this.filterVoices.bind(this));
            $(document).on('change', '#language-filter', this.filterVoices.bind(this));
            $(document).on('change', '#gender-filter', this.filterVoices.bind(this));
            $(document).on('change', '#style-filter', this.filterVoices.bind(this));
            $(document).on('click', '.voice-select-btn', this.selectVoice.bind(this));
            $(document).on('click', '.voice-preview-btn', this.previewVoice.bind(this));
            $(document).on('change', '.voice-style-select', this.handleStyleChange.bind(this));
            
            // Simple test click handler
            $(document).on('click', '#voice-picker-trigger', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $('#voice-picker-modal').show();
            });
        }

        openVoicePicker(e) {
            e.preventDefault();
            const $trigger = $(e.currentTarget);
            const $modal = $('#voice-picker-modal');
            const $search = $('#voice-search');
            
            $modal.show();
            $trigger.addClass('active');
            $search.focus();
        }

        handleModalClick(e) {
            if (e.target.id === 'voice-picker-modal') {
                this.closeVoicePicker();
            }
        }

        handleKeydown(e) {
            if (e.key === 'Escape' && $('#voice-picker-modal').is(':visible')) {
                this.closeVoicePicker();
            }
        }

        closeVoicePicker() {
            $('#voice-picker-modal').hide();
            $('#voice-picker-trigger').removeClass('active');
        }

        filterVoices() {
            const searchTerm = $('#voice-search').val().toLowerCase();
            const languageFilter = $('#language-filter').val();
            const genderFilter = $('#gender-filter').val();
            const styleFilter = $('#style-filter').val();

            $('.voice-item').each(function() {
                const $item = $(this);
                const voiceName = $item.data('display-name').toLowerCase();
                const voiceLanguage = $item.data('language');
                const voiceGender = $item.data('gender');
                const availableStyles = $item.data('available-styles') ? $item.data('available-styles').split(',') : [];

                let show = true;

                // Search filter
                if (searchTerm && !voiceName.includes(searchTerm)) {
                    show = false;
                }

                // Language filter
                if (languageFilter && voiceLanguage !== languageFilter) {
                    show = false;
                }

                // Gender filter
                if (genderFilter && voiceGender !== genderFilter) {
                    show = false;
                }

                // Style filter
                if (styleFilter && !availableStyles.includes(styleFilter)) {
                    show = false;
                }

                if (show) {
                    $item.removeClass('hidden').show();
                } else {
                    $item.addClass('hidden').hide();
                }
            });

            // Hide/show language groups based on visible voices
            $('.voice-language-group').each(function() {
                const $group = $(this);
                const visibleVoices = $group.find('.voice-item:not(.hidden)').length;
                
                if (visibleVoices === 0) {
                    $group.hide();
                } else {
                    $group.show();
                }
            });
        }

        selectVoice(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const voiceId = $btn.data('voice-id');
            const $voiceItem = $btn.closest('.voice-item');
            const voiceName = $voiceItem.find('.voice-name').text();
            const voiceDetails = $voiceItem.find('.voice-details').text();
            const selectedStyle = $voiceItem.find('.voice-style-select').val() || 'Narration';

            // Update hidden inputs
            $('#selected_voice').val(voiceId);
            $('#selected_voice_style').val(selectedStyle);

            // Update trigger display
            const $trigger = $('#voice-picker-trigger');
            $trigger.find('.voice-name').text(voiceName);
            $trigger.find('.voice-details').text(voiceDetails);
            $trigger.find('.voice-style').text(selectedStyle);

            // Update avatar (you might want to get this from the selected item)
            const $avatar = $voiceItem.find('.voice-avatar').clone();
            $trigger.find('.voice-avatar').replaceWith($avatar);

            // Close modal
            this.closeVoicePicker();

            // Show success message
            this.showMessage('Voice and style selected successfully!', 'success');
        }

        previewVoice(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const voiceId = $btn.data('voice-id');
            const $voiceItem = $btn.closest('.voice-item');
            const selectedStyle = $voiceItem.find('.voice-style-select').val() || 'Narration';
            
            if ($btn.hasClass('loading')) {
                return;
            }

            $btn.addClass('loading');
            $btn.find('.preview-icon').text('...');

            const previewText = 'Hello, this is a preview of this voice.';

            $.ajax({
                url: listenupAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'listenup_preview_voice',
                    voice_id: voiceId,
                    voice_style: selectedStyle,
                    preview_text: previewText,
                    nonce: listenupAdmin.nonce
                },
                success: (response) => {
                    if (response.success && response.data.success !== false) {
                        // Create and play audio
                        const audio = new Audio(response.data.audio_url);
                        audio.play();
                        
                        // Update button state
                        $btn.find('.preview-icon').text('OK');
                        setTimeout(() => {
                            $btn.find('.preview-icon').text('Play');
                        }, 2000);
                    } else {
                        const errorMessage = response.data?.message || 'Unknown error';
                        this.showMessage('Preview failed: ' + errorMessage, 'error');
                        $btn.find('.preview-icon').text('Play');
                    }
                },
                error: () => {
                    this.showMessage('Network error occurred while generating preview.', 'error');
                    $btn.find('.preview-icon').text('Play');
                },
                complete: () => {
                    $btn.removeClass('loading');
                }
            });
        }

        handleStyleChange(e) {
            const $select = $(e.currentTarget);
            const voiceId = $select.data('voice-id');
            const selectedStyle = $select.val();
            
            // Update the global voice style field if this is the currently selected voice
            const currentVoiceId = $('#selected_voice').val();
            if (voiceId === currentVoiceId) {
                $('#selected_voice_style').val(selectedStyle);
            }
        }

        showMessage(message, type = 'info') {
            // Create a simple notification
            const $notification = $(`
                <div class="notice notice-${type} is-dismissible" style="margin: 5px 0;">
                    <p>${message}</p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">Dismiss this notice.</span>
                    </button>
                </div>
            `);

            // Insert after the voice picker
            $('.listenup-voice-picker').after($notification);

            // Auto-dismiss after 3 seconds
            setTimeout(() => {
                $notification.fadeOut(() => {
                    $notification.remove();
                });
            }, 3000);

            // Handle manual dismiss
            $notification.find('.notice-dismiss').on('click', function() {
                $notification.fadeOut(() => {
                    $notification.remove();
                });
            });
        }

        initPreRollManager() {
            // Tab switching
            $(document).on('click', '.listenup-preroll-tab', function() {
                const tab = $(this).data('tab');

                // Update tab buttons
                $('.listenup-preroll-tab').removeClass('listenup-preroll-tab-active');
                $(this).addClass('listenup-preroll-tab-active');

                // Update tab content
                $('.listenup-preroll-tab-content').removeClass('listenup-preroll-tab-active');
                $(`#listenup-preroll-${tab}-tab`).addClass('listenup-preroll-tab-active');
            });

            // Character count for pre-roll text
            $(document).on('input', '#pre_roll_text', function() {
                const length = $(this).val().length;
                $('#listenup-preroll-char-count').text(`${length}/500`);
            });

            // Initialize char count on page load (only if element exists)
            const $preRollText = $('#pre_roll_text');
            if ($preRollText.length) {
                const initialLength = $preRollText.val().length;
                $('#listenup-preroll-char-count').text(`${initialLength}/500`);
            }

            // Upload pre-roll audio
            $(document).on('click', '#listenup-upload-preroll-btn', this.handleUploadPreRoll.bind(this));

            // Remove pre-roll audio
            $(document).on('click', '#listenup-remove-preroll-btn', this.handleRemovePreRoll.bind(this));

            // Generate pre-roll audio
            $(document).on('click', '#listenup-generate-preroll-btn', this.handleGeneratePreRoll.bind(this));

            // Conversion API test
            $(document).on('click', '#listenup-test-conversion-api', this.handleTestConversionAPI.bind(this));

            // Audio library actions
            $(document).on('click', '.listenup-convert-btn', this.handleConvertAudio.bind(this));
            $(document).on('click', '.listenup-delete-btn', this.handleDeleteAudio.bind(this));
        }

        handleUploadPreRoll(e) {
            e.preventDefault();

            // Use WordPress media uploader
            if (typeof wp !== 'undefined' && wp.media) {
                const mediaUploader = wp.media({
                    title: 'Select Pre-roll Audio File',
                    button: {
                        text: 'Use this file'
                    },
                    library: {
                        type: ['audio']
                    },
                    multiple: false
                });

                mediaUploader.on('select', () => {
                    const attachment = mediaUploader.state().get('selection').first().toJSON();

                    // Validate file type
                    const validTypes = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg', 'audio/mp4', 'audio/m4a'];
                    if (!validTypes.includes(attachment.mime)) {
                        alert('Please select a valid audio file (MP3, WAV, OGG, or M4A).');
                        return;
                    }

                    // Validate file size (10MB max)
                    if (attachment.filesize > 10 * 1024 * 1024) {
                        alert('File is too large. Maximum size is 10MB.');
                        return;
                    }

                    // Get the file path from URL
                    // For WordPress media library files, we'll use the URL and convert it server-side
                    $('#pre_roll_audio').val(attachment.url);

                    // Show preview
                    this.updatePreRollPreview(attachment);

                    this.showMessage('Pre-roll audio file selected. Remember to save your settings!', 'success');
                });

                mediaUploader.open();
            } else {
                alert('WordPress media uploader is not available.');
            }
        }

        handleRemovePreRoll(e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to remove the pre-roll audio?')) {
                return;
            }

            // Clear the hidden input
            $('#pre_roll_audio').val('');

            // Remove preview
            $('#listenup-preroll-preview').remove();

            this.showMessage('Pre-roll audio removed. Remember to save your settings!', 'success');
        }

        handleGeneratePreRoll(e) {
            e.preventDefault();

            const $btn = $(e.currentTarget);
            const $spinner = $('#listenup-generate-preroll-spinner');
            const $status = $('#listenup-generate-preroll-status');
            const text = $('#pre_roll_text').val().trim();

            // Validate text
            if (!text) {
                $status.html('<p class="description" style="color: #d63638;">Please enter text for the pre-roll.</p>');
                return;
            }

            if (text.length > 500) {
                $status.html('<p class="description" style="color: #d63638;">Text must be 500 characters or less.</p>');
                return;
            }

            // Disable button and show spinner
            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $status.html('<p class="description">Generating audio with Murf.ai...</p>');

            // Make AJAX request
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'listenup_generate_preroll',
                    text: text,
                    nonce: listenupAdmin.nonce
                },
                success: (response) => {
                    if (response.success && response.data.success !== false) {
                        // Handle dual-format response structure
                        const wavFile = response.data.wav_file_path || response.data.file_path;
                        const wavFilename = response.data.wav_filename || response.data.filename;
                        const wavUrl = response.data.wav_file_url || response.data.file_url;
                        const mp3File = response.data.mp3_file_path;
                        const mp3Filename = response.data.mp3_filename;
                        const mp3Url = response.data.mp3_file_url;
                        const hasMp3 = response.data.has_mp3;

                        // Update hidden field with WAV file path (primary format)
                        $('#pre_roll_audio').val(wavFile);
                        
                        // Automatically save the pre-roll to options
                        this.savePrerollToOptions(wavFile);

                        // Show success message with dual-format info
                        let audioHtml = '';
                        if (wavUrl) {
                            audioHtml += `<source src="${wavUrl}" type="audio/wav">`;
                        }
                        if (mp3Url && hasMp3) {
                            audioHtml += `<source src="${mp3Url}" type="audio/mpeg">`;
                        }

                        $status.html(`
                            <div class="listenup-preroll-preview" style="margin-top: 15px; padding: 15px; background: #f9f9f9; border-left: 4px solid #00a32a;">
                                <p style="color: #00a32a;"><strong>Pre-roll audio generated successfully!</strong></p>
                                <p><strong>WAV:</strong> ${wavFilename}</p>
                                ${hasMp3 ? `<p><strong>MP3:</strong> ${mp3Filename}</p>` : ''}
                                <audio controls style="max-width: 100%; margin-top: 10px;">
                                    ${audioHtml}
                                </audio>
                                <p class="description" style="margin-top: 10px;">Remember to save your settings to apply this pre-roll!</p>
                            </div>
                        `);

                        this.showMessage('Pre-roll audio generated successfully!', 'success');
                        
                        // Refresh the upload tab preview to show the new pre-roll
                        this.refreshPrerollPreview();
                    } else {
                        const errorMessage = response.data?.message || 'Unknown error';
                        $status.html(`<p class="description" style="color: #d63638;">Failed to generate pre-roll: ${errorMessage}</p>`);
                    }
                },
                error: () => {
                    $status.html('<p class="description" style="color: #d63638;">Network error occurred while generating pre-roll.</p>');
                },
                complete: () => {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        }

        updatePreRollPreview(attachment) {
            const previewHtml = `
                <div id="listenup-preroll-preview" class="listenup-preroll-preview">
                    <div class="listenup-preroll-info">
                        <p style="color: #00a32a;">
                            <strong>Current pre-roll audio:</strong>
                        </p>
                        <p>${attachment.filename}</p>
                        <p class="description">
                            ${attachment.filesizeHumanReadable} (${attachment.subtype.toUpperCase()})
                        </p>
                        <audio controls style="max-width: 100%; margin-top: 10px;">
                            <source src="${attachment.url}" type="${attachment.mime}">
                        </audio>
                        <br>
                        <button type="button" class="button button-secondary" id="listenup-remove-preroll-btn" style="margin-top: 10px;">
                            Remove Pre-roll
                        </button>
                    </div>
                </div>
            `;

            // Remove existing preview and add new one
            $('#listenup-preroll-preview').remove();
            $('.listenup-preroll-upload-section .description').after(previewHtml);
        }

        handleTestConversionAPI(e) {
            e.preventDefault();

            const $btn = $(e.currentTarget);
            const $spinner = $('#listenup-test-api-spinner');
            const $result = $('#listenup-test-api-result');

            // Disable button and show spinner
            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $result.html('<p class="description">Testing connection...</p>');

            // Make AJAX request
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'listenup_test_conversion_api',
                    nonce: listenupAdmin.nonce
                },
                success: (response) => {
                    if (response.success) {
                        $result.html('<p class="description" style="color: #00a32a;"><strong>✓ Connection successful!</strong> API is reachable and responding.</p>');
                    } else {
                        const errorMessage = response.data?.message || 'Unknown error';
                        $result.html(`<p class="description" style="color: #d63638;"><strong>✗ Connection failed:</strong> ${errorMessage}</p>`);
                    }
                },
                error: () => {
                    $result.html('<p class="description" style="color: #d63638;"><strong>✗ Network error:</strong> Could not reach the server.</p>');
                },
                complete: () => {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        }

        handleConvertAudio(e) {
            e.preventDefault();

            const $btn = $(e.currentTarget);
            const postId = $btn.data('post-id');
            const $row = $btn.closest('tr');

            if (!confirm('Convert this WAV file to MP3? This may take a few minutes.')) {
                return;
            }

            // Disable button and show loading state
            const originalText = $btn.text();
            $btn.prop('disabled', true).text('Converting...');

            // Make AJAX request
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'listenup_convert_audio',
                    post_id: postId,
                    nonce: listenupAdmin.nonce
                },
                success: (response) => {
                    if (response.success) {
                        // Show success message
                        this.showMessage(response.data.message, 'success');
                        
                        // Reload the page to show updated status
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    } else {
                        const errorMessage = response.data?.message || 'Unknown error';
                        this.showMessage('Conversion failed: ' + errorMessage, 'error');
                        $btn.prop('disabled', false).text(originalText);
                    }
                },
                error: () => {
                    this.showMessage('Network error occurred during conversion.', 'error');
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        }

        handleDeleteAudio(e) {
            e.preventDefault();

            const $btn = $(e.currentTarget);
            const postId = $btn.data('post-id');
            const wavExists = $btn.data('wav-exists') === '1';
            const mp3Exists = $btn.data('mp3-exists') === '1';
            const mp3CloudUrl = $btn.data('mp3-cloud-url') || '';
            const mp3CloudPath = $btn.data('mp3-cloud-path') || '';

            // Store current button and row for later use
            this.currentDeleteBtn = $btn;
            this.currentDeleteRow = $btn.closest('tr');
            this.currentPostId = postId;

            // Show the delete modal
            this.showDeleteModal(wavExists, mp3Exists, mp3CloudUrl, mp3CloudPath);
        }

        showDeleteModal(wavExists, mp3Exists, mp3CloudUrl, mp3CloudPath) {
            const $modal = $('#listenup-delete-modal');
            
            // Clear previous selections
            $modal.find('input[name="delete-type"]').prop('checked', false);
            $modal.find('.listenup-delete-option').removeClass('selected');
            
            // Show/hide options based on available files
            const $localOption = $modal.find('[data-delete-type="local"]');
            const $cloudOption = $modal.find('[data-delete-type="cloud"]');
            const $bothOption = $modal.find('[data-delete-type="both"]');
            
            // Reset all options to visible first
            $localOption.css('display', 'block');
            $cloudOption.css('display', 'block');
            $bothOption.css('display', 'block');
            
            // Show local option only if there are local files
            if (wavExists || (mp3Exists && !mp3CloudUrl)) {
                $localOption.css('display', 'block');
            } else {
                $localOption.css('display', 'none');
            }
            
            // Show cloud option only if there are cloud files
            if (mp3Exists && mp3CloudUrl) {
                $cloudOption.css('display', 'block');
            } else {
                $cloudOption.css('display', 'none');
            }
            
            // Show both option only if there are both local and cloud files
            if ((wavExists || (mp3Exists && !mp3CloudUrl)) && (mp3Exists && mp3CloudUrl)) {
                $bothOption.css('display', 'block');
            } else {
                $bothOption.css('display', 'none');
            }
            
            // Disable confirm button initially
            $modal.find('.listenup-modal-confirm').prop('disabled', true);
            
            // Show modal
            $modal.show();
        }

        hideDeleteModal() {
            const $modal = $('#listenup-delete-modal');
            $modal.hide();
            this.currentDeleteBtn = null;
            this.currentDeleteRow = null;
            this.currentPostId = null;
        }

        performDeleteAudio(deleteType) {
            if (!this.currentPostId || !deleteType) {
                return;
            }

            const $btn = this.currentDeleteBtn;
            const $row = this.currentDeleteRow;
            const postId = this.currentPostId;

            // Disable button and show loading state
            const originalText = $btn.text();
            $btn.prop('disabled', true).text('Deleting...');

            // Hide modal
            this.hideDeleteModal();

            // Make AJAX request
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'listenup_delete_audio',
                    post_id: postId,
                    delete_type: deleteType,
                    nonce: listenupAdmin.nonce
                },
                success: (response) => {
                    if (response.success) {
                        // Show success message
                        this.showMessage(response.data.message, 'success');

                        // Reload the page to show updated state
                        // This ensures the row only disappears if no audio files remain
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        const errorMessage = response.data?.message || 'Unknown error';
                        this.showMessage('Delete failed: ' + errorMessage, 'error');
                        $btn.prop('disabled', false).text(originalText);
                    }
                },
                error: () => {
                    this.showMessage('Network error occurred during deletion.', 'error');
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        }

        initDeleteModal() {
            const self = this;
            
            // Handle delete option clicks
            $(document).on('click', '.listenup-delete-option', function(e) {
                e.preventDefault();
                
                // Remove selection from all options
                $('.listenup-delete-option').removeClass('selected');
                
                // Add selection to clicked option
                $(this).addClass('selected');
                
                // Check the radio button
                $(this).find('input[type="radio"]').prop('checked', true);
                
                // Enable confirm button
                $('.listenup-modal-confirm').prop('disabled', false);
            });
            
            // Handle radio button changes
            $(document).on('change', 'input[name="delete-type"]', function() {
                const $option = $(this).closest('.listenup-delete-option');
                
                // Remove selection from all options
                $('.listenup-delete-option').removeClass('selected');
                
                // Add selection to current option
                $option.addClass('selected');
                
                // Enable confirm button
                $('.listenup-modal-confirm').prop('disabled', false);
            });
            
            // Handle modal cancel
            $(document).on('click', '.listenup-modal-cancel', function(e) {
                e.preventDefault();
                self.hideDeleteModal();
            });
            
            // Handle modal backdrop click
            $(document).on('click', '.listenup-modal-backdrop', function(e) {
                e.preventDefault();
                self.hideDeleteModal();
            });
            
            // Handle modal confirm
            $(document).on('click', '.listenup-modal-confirm', function(e) {
                e.preventDefault();
                
                const deleteType = $('input[name="delete-type"]:checked').val();
                if (deleteType) {
                    self.performDeleteAudio(deleteType);
                }
            });
            
            // Prevent modal content clicks from closing modal
            $(document).on('click', '.listenup-modal-content', function(e) {
                e.stopPropagation();
            });
        }
        
        handleCloudStorageProviderChange() {
            const $providerSelect = $('#cloud_storage_provider');
            if (!$providerSelect.length) {
                return;
            }
            
            // Show/hide settings based on selected provider
            const toggleSettings = () => {
                const selectedProvider = $providerSelect.val();
                
                // Hide all settings
                $('.cloud-storage-settings').hide();
                
                // Show relevant settings
                if (selectedProvider === 'aws_s3') {
                    $('#aws-s3-settings').css('display', 'block');
                } else if (selectedProvider === 'cloudflare_r2') {
                    $('#cloudflare-r2-settings').css('display', 'block');
                } else if (selectedProvider === 'google_cloud') {
                    $('#google-cloud-settings').css('display', 'block');
                }
            };
            
            // Initial toggle
            toggleSettings();
            
            // Bind change event
            $providerSelect.on('change', toggleSettings);
        }
        
        savePrerollToOptions(filePath) {
            // Save the pre-roll file path to WordPress options
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'listenup_save_preroll',
                    file_path: filePath,
                    nonce: listenupAdmin.nonce
                },
                success: (response) => {
                    if (response.success) {
                        // Refresh the upload tab preview
                        this.refreshPrerollPreview();
                    }
                },
                error: () => {
                    console.error('Failed to save pre-roll to options');
                }
            });
        }
        
        refreshPrerollPreview() {
            // Update the upload tab preview without reloading the page
            const $uploadTab = $('#listenup-preroll-upload-tab');
            const $preview = $uploadTab.find('#listenup-preroll-preview');
            const filePath = $('#pre_roll_audio').val();
            
            if (filePath) {
                // Show a simple preview with the file path
                $preview.html(`
                    <div class="listenup-preroll-info">
                        <p style="color: #00a32a;">
                            <strong>Current pre-roll audio:</strong>
                        </p>
                        <p>${filePath.split('/').pop()}</p>
                        <p class="description">Pre-roll audio is ready. Remember to save your settings!</p>
                    </div>
                `).show();
            }
        }
    }
    
    // Initialize when document is ready
    $(document).ready(function() {
        new ListenUpAdmin();
    });
    
})(jQuery);
