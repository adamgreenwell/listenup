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
                
                if (!confirm('Are you sure you want to clear the debug log?')) {
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
                        nonce: listenupAdmin.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Debug log cleared successfully!');
                            location.reload(); // Reload to refresh the log viewer
                        } else {
                            alert('Failed to clear debug log: ' + (response.data.message || 'Unknown error'));
                        }
                    },
                    error: function() {
                        alert('Network error occurred while clearing debug log.');
                    },
                    complete: function() {
                        button.prop('disabled', false).text(originalText);
                    }
                });
            });
        }
    }
    
    // Initialize when document is ready
    $(document).ready(function() {
        new ListenUpAdmin();
    });
    
})(jQuery);
