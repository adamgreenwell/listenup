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

            // Initialize char count on page load
            const initialLength = $('#pre_roll_text').val().length;
            $('#listenup-preroll-char-count').text(`${initialLength}/500`);

            // Upload pre-roll audio
            $(document).on('click', '#listenup-upload-preroll-btn', this.handleUploadPreRoll.bind(this));

            // Remove pre-roll audio
            $(document).on('click', '#listenup-remove-preroll-btn', this.handleRemovePreRoll.bind(this));

            // Generate pre-roll audio
            $(document).on('click', '#listenup-generate-preroll-btn', this.handleGeneratePreRoll.bind(this));
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
                        // Update hidden field with file path
                        $('#pre_roll_audio').val(response.data.file_path);

                        // Show success message
                        $status.html(`
                            <div class="listenup-preroll-preview" style="margin-top: 15px; padding: 15px; background: #f9f9f9; border-left: 4px solid #00a32a;">
                                <p style="color: #00a32a;"><strong>Pre-roll audio generated successfully!</strong></p>
                                <p>${response.data.filename}</p>
                                <audio controls style="max-width: 100%; margin-top: 10px;">
                                    <source src="${response.data.file_url}" type="audio/mpeg">
                                </audio>
                                <p class="description" style="margin-top: 10px;">Remember to save your settings to apply this pre-roll!</p>
                            </div>
                        `);

                        this.showMessage('Pre-roll audio generated successfully!', 'success');
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
    }
    
    // Initialize when document is ready
    $(document).ready(function() {
        new ListenUpAdmin();
    });
    
})(jQuery);
