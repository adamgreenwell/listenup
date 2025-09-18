/**
 * Meta box JavaScript for ListenUp plugin.
 *
 * @package ListenUp
 */

(function($) {
    'use strict';

    /**
     * Meta Box Controller
     */
    class ListenUpMetaBox {
        constructor() {
            this.init();
        }
        
        init() {
            this.bindEvents();
        }
        
        bindEvents() {
            // Generate audio button
            $(document).on('click', '#listenup-generate', () => this.generateAudio());
            
            // Regenerate audio button
            $(document).on('click', '#listenup-regenerate', () => this.regenerateAudio());
            
            // Delete audio button
            $(document).on('click', '#listenup-delete', () => this.deleteAudio());
        }
        
        generateAudio() {
            this.performAction('generate');
        }
        
        regenerateAudio() {
            this.performAction('regenerate');
        }
        
        deleteAudio() {
            if (!confirm('Are you sure you want to delete the audio for this post?')) {
                return;
            }
            
            this.performAction('delete');
        }
        
        performAction(action) {
            const $status = $('#listenup-status');
            const $messages = $('#listenup-messages');
            const $metaBox = $('#listenup-meta-box');
            
            // Clear previous messages
            $messages.empty();
            
            // Show loading state
            $status.show();
            
            // Disable buttons
            $metaBox.find('button').prop('disabled', true);
            
            // Make AJAX request
            $.ajax({
                url: listenupMetaBox.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'listenup_generate_audio',
                    nonce: listenupMetaBox.nonce,
                    post_id: listenupMetaBox.postId,
                    action_type: action
                },
                success: (response) => {
                    this.handleSuccess(response, action);
                },
                error: (xhr, status, error) => {
                    this.handleError(error);
                },
                complete: () => {
                    // Hide loading state
                    $status.hide();
                    
                    // Re-enable buttons
                    $metaBox.find('button').prop('disabled', false);
                }
            });
        }
        
        handleSuccess(response, action) {
            const $messages = $('#listenup-messages');
            const $metaBox = $('#listenup-meta-box');
            
            if (response.success) {
                // Show success message
                this.showMessage('success', response.data.message);
                
                // Update the meta box content without reloading
                if (action === 'generate' || action === 'regenerate') {
                    setTimeout(() => {
                        this.updateMetaBoxForAudioExists();
                    }, 1500);
                } else if (action === 'delete') {
                    // Update the meta box to show "no audio" state
                    this.updateMetaBoxForNoAudio();
                }
            } else {
                this.showMessage('error', response.data || 'An error occurred.');
            }
        }
        
        handleError(error) {
            this.showMessage('error', 'Network error: ' + error);
        }
        
        showMessage(type, message) {
            const $messages = $('#listenup-messages');
            const $message = $(`<div class="listenup-message ${type}">${message}</div>`);
            
            $messages.append($message);
            
            // Auto-hide success messages after 5 seconds
            if (type === 'success') {
                setTimeout(() => {
                    $message.fadeOut(() => {
                        $message.remove();
                    });
                }, 5000);
            }
        }
        
        updateMetaBoxForNoAudio() {
            const $metaBox = $('#listenup-meta-box');
            
            $metaBox.html(`
                <div class="listenup-no-audio">
                    <p>No audio has been generated for this content yet.</p>
                    <button type="button" id="listenup-generate" class="button button-primary">
                        Generate Audio
                    </button>
                </div>
                
                <div id="listenup-status" class="listenup-status" style="display: none;">
                    <p class="listenup-loading">
                        <span class="spinner is-active"></span>
                        Generating audio...
                    </p>
                </div>
                
                <div id="listenup-messages" class="listenup-messages"></div>
            `);
        }
        
        updateMetaBoxForAudioExists() {
            const $metaBox = $('#listenup-meta-box');
            
            $metaBox.html(`
                <div class="listenup-audio-exists">
                    <p><strong>Audio Available</strong></p>
                    <p>Audio has been generated for this content.</p>
                    <button type="button" id="listenup-regenerate" class="button button-secondary">
                        Regenerate Audio
                    </button>
                    <button type="button" id="listenup-delete" class="button button-link-delete">
                        Delete Audio
                    </button>
                </div>
                
                <div id="listenup-status" class="listenup-status" style="display: none;">
                    <p class="listenup-loading">
                        <span class="spinner is-active"></span>
                        Generating audio...
                    </p>
                </div>
                
                <div id="listenup-messages" class="listenup-messages"></div>
            `);
        }
    }
    
    // Initialize when document is ready
    $(document).ready(function() {
        new ListenUpMetaBox();
    });
    
})(jQuery);
