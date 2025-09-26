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
    }
    
    // Initialize when document is ready
    $(document).ready(function() {
        new ListenUpAdmin();
    });
    
})(jQuery);
