/**
 * Library functionality for ListenUp plugin.
 *
 * @package ListenUp
 */

(function($) {
    'use strict';

    /**
     * Library Autoplay Controller
     */
    class ListenUpLibraryAutoplay {
        constructor(libraryContainer) {
            this.library = $(libraryContainer);
            this.autoplayAvailable = this.library.data('autoplay-available') === true;
            this.autoplayCheckbox = this.library.find('.listenup-autoplay-checkbox');
            this.autoplayEnabled = false;

            if (this.autoplayAvailable) {
                this.init();
            }
        }

        init() {
            this.loadAutoplayPreference();
            this.bindEvents();
        }

        loadAutoplayPreference() {
            // Check if user has a saved preference in localStorage
            const savedPreference = localStorage.getItem('listenup_autoplay_enabled');

            if (savedPreference !== null) {
                this.autoplayEnabled = savedPreference === 'true';
                this.autoplayCheckbox.prop('checked', this.autoplayEnabled);
            }
        }

        bindEvents() {
            // Listen for checkbox changes
            this.autoplayCheckbox.on('change', () => {
                this.autoplayEnabled = this.autoplayCheckbox.is(':checked');
                // Save preference to localStorage
                localStorage.setItem('listenup_autoplay_enabled', this.autoplayEnabled);
            });

            // Listen for custom audio ended events on all players in this library
            this.library.on('listenup:audioEnded', '.listenup-audio-element', (e) => {
                if (this.autoplayEnabled) {
                    this.handleAudioEnded(e.target);
                }
            });
        }

        handleAudioEnded(audioElement) {
            const $audio = $(audioElement);
            const $player = $audio.closest('.listenup-audio-player');
            const $libraryItem = $player.closest('.listenup-library-item');
            const nextPostId = $libraryItem.data('next-post-id');

            if (!nextPostId) {
                // No next item, we've reached the end of the library
                return;
            }

            // Find the next library item
            const $nextLibraryItem = this.library.find('.listenup-library-item[data-post-id="' + nextPostId + '"]');

            if ($nextLibraryItem.length === 0) {
                return;
            }

            // Find the player in the next item
            const $nextPlayer = $nextLibraryItem.find('.listenup-audio-player');

            if ($nextPlayer.length === 0) {
                return;
            }

            // Get the next audio element
            const $nextAudio = $nextPlayer.find('.listenup-audio-element');

            if ($nextAudio.length === 0) {
                return;
            }

            // Scroll to next item (smooth scroll)
            this.scrollToItem($nextLibraryItem);

            // Small delay to allow scroll, then play
            setTimeout(() => {
                const nextAudioElement = $nextAudio[0];

                // Trigger play on the next audio element
                nextAudioElement.play().then(() => {
                    // Also update the play button state
                    const $playButton = $nextPlayer.find('.listenup-play-button');
                    const $playIcon = $nextPlayer.find('.listenup-play-icon');
                    const $pauseIcon = $nextPlayer.find('.listenup-pause-icon');

                    $playIcon.hide();
                    $pauseIcon.show();
                    $playButton.attr('aria-label', 'Pause audio');
                }).catch((error) => {
                    console.error('ListenUp: Error auto-playing next audio:', error);
                });
            }, 500);
        }

        scrollToItem($item) {
            if ($item.length === 0) {
                return;
            }

            const offset = $item.offset().top - 100; // 100px from top for better visibility

            $('html, body').animate({
                scrollTop: offset
            }, 500, 'swing');
        }
    }

    /**
     * Initialize library autoplay functionality
     */
    function initLibraryAutoplay() {
        const libraries = $('.listenup-library[data-autoplay-available="true"]');

        libraries.each(function() {
            new ListenUpLibraryAutoplay(this);
        });
    }

    // Initialize when document is ready
    $(document).ready(function() {
        initLibraryAutoplay();
    });

    // Re-initialize if content is loaded dynamically
    $(document).on('listenup:contentLoaded', function() {
        initLibraryAutoplay();
    });

})(jQuery);
