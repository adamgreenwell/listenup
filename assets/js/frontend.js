/**
 * Frontend JavaScript for ListenUp plugin.
 *
 * @package ListenUp
 */

(function($) {
    'use strict';

    /**
     * Audio Player Controller
     */
    class ListenUpPlayer {
        constructor(container) {
            this.container = $(container);
            this.audio = this.container.find('.listenup-audio-element')[0];
            this.playButton = this.container.find('.listenup-play-button');
            this.playIcon = this.container.find('.listenup-play-icon');
            this.pauseIcon = this.container.find('.listenup-pause-icon');
            this.progressBar = this.container.find('.listenup-progress-bar');
            this.progressFill = this.container.find('.listenup-progress-fill');
            this.currentTimeDisplay = this.container.find('.listenup-current-time');
            this.durationDisplay = this.container.find('.listenup-duration');
            this.downloadButton = this.container.find('.listenup-download-button');
            this.isPlaying = false;
            this.isDragging = false;
            this.audioChunks = this.container.data('audio-chunks');
            this.concatenatedBlobUrl = null;
            this.concatenator = new ListenUpAudioConcatenator();
            
            this.init();
        }
        
        async init() {
            this.bindEvents();
            this.setupDownload();
            
            // Handle chunked audio if present
            if (this.audioChunks && this.audioChunks.length > 1) {
                this.disableDownloadButton();
                await this.handleChunkedAudio();
                this.enableDownloadButton();
            }
        }
        
        /**
         * Handle chunked audio concatenation
         */
        async handleChunkedAudio() {
            try {
                // Show loading state
                this.showLoadingState();
                
                // Concatenate audio chunks
                const result = await this.concatenator.concatenateAudioChunks(this.audioChunks);
                
                if (result.success) {
                    // Update audio source to use concatenated audio
                    this.concatenatedBlobUrl = result.blobUrl;
                    this.audio.src = result.blobUrl;
                    
                    // Hide loading state
                    this.hideLoadingState();
                } else {
                    throw new Error('Audio concatenation failed');
                }
                
            } catch (error) {
                console.error('ListenUp: Error handling chunked audio:', error);
                this.hideLoadingState();
                this.showErrorState('Failed to process audio. Please try again.');
            }
        }

        /**
         * Show loading state
         */
        showLoadingState() {
            this.playButton.prop('disabled', true);
            this.playButton.addClass('loading');
            this.container.find('.listenup-player-title').text('Processing audio...');
        }

        /**
         * Hide loading state
         */
        hideLoadingState() {
            this.playButton.prop('disabled', false);
            this.playButton.removeClass('loading');
            this.container.find('.listenup-player-title').text('Listen to this content');
        }

        /**
         * Show error state
         */
        showErrorState(message) {
            this.container.find('.listenup-player-title').text(message);
            this.playButton.prop('disabled', true);
        }

        bindEvents() {
            // Play/pause button
            this.playButton.on('click', () => this.togglePlayPause());
            
            // Progress bar click
            this.progressBar.on('click', (e) => this.seekTo(e));
            
            // Progress bar drag
            this.progressBar.on('mousedown', (e) => this.startDragging(e));
            $(document).on('mousemove', (e) => this.drag(e));
            $(document).on('mouseup', () => this.stopDragging());
            
            // Audio events
            this.audio.addEventListener('loadedmetadata', () => {
                this.updateDuration();
            });
            this.audio.addEventListener('timeupdate', () => this.updateProgress());
            this.audio.addEventListener('ended', () => {
                this.onEnded();
            });
            this.audio.addEventListener('error', (e) => {
                this.onError(e);
            });
            
            // Keyboard accessibility
            this.playButton.on('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.togglePlayPause();
                }
            });
            
            this.progressBar.on('keydown', (e) => {
                if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                    e.preventDefault();
                    const currentTime = this.audio.currentTime;
                    const duration = this.audio.duration;
                    const newTime = e.key === 'ArrowLeft' 
                        ? Math.max(0, currentTime - 5)
                        : Math.min(duration, currentTime + 5);
                    this.audio.currentTime = newTime;
                }
            });
        }
        
        togglePlayPause() {
            if (this.isPlaying) {
                this.pause();
            } else {
                this.play();
            }
        }
        
        play() {
            this.audio.play().then(() => {
                this.isPlaying = true;
                this.updatePlayButton();
            }).catch((error) => {
                console.error('Error playing audio:', error);
                this.onError(error);
            });
        }
        
        pause() {
            this.audio.pause();
            this.isPlaying = false;
            this.updatePlayButton();
        }
        
        updatePlayButton() {
            if (this.isPlaying) {
                this.playIcon.hide();
                this.pauseIcon.show();
                this.playButton.attr('aria-label', 'Pause audio');
            } else {
                this.playIcon.show();
                this.pauseIcon.hide();
                this.playButton.attr('aria-label', 'Play audio');
            }
        }
        
        seekTo(event) {
            if (this.isDragging) return;
            
            const rect = this.progressBar[0].getBoundingClientRect();
            const clickX = event.clientX - rect.left;
            const percentage = clickX / rect.width;
            const newTime = percentage * this.audio.duration;
            
            this.audio.currentTime = newTime;
        }
        
        startDragging(event) {
            this.isDragging = true;
            this.seekTo(event);
        }
        
        drag(event) {
            if (!this.isDragging) return;
            this.seekTo(event);
        }
        
        stopDragging() {
            this.isDragging = false;
        }
        
        updateProgress() {
            if (this.isDragging) return;
            
            const currentTime = this.audio.currentTime;
            const duration = this.audio.duration;
            
            if (duration > 0) {
                const percentage = (currentTime / duration) * 100;
                this.progressFill.css('width', percentage + '%');
                this.currentTimeDisplay.text(this.formatTime(currentTime));
            }
        }
        
        updateDuration() {
            this.durationDisplay.text(this.formatTime(this.audio.duration));
        }
        
        onEnded() {
            this.isPlaying = false;
            this.updatePlayButton();
            this.progressFill.css('width', '0%');
            this.currentTimeDisplay.text('0:00');
        }
        
        onError(error) {
            console.error('Audio error:', error);
            this.container.append('<p class="listenup-error">Error loading audio. Please try again.</p>');
        }
        
        formatTime(seconds) {
            if (isNaN(seconds)) return '0:00';
            
            const minutes = Math.floor(seconds / 60);
            const remainingSeconds = Math.floor(seconds % 60);
            return minutes + ':' + (remainingSeconds < 10 ? '0' : '') + remainingSeconds;
        }
        
        setupDownload() {
            this.downloadButton.on('click', () => {
                this.handleDownload();
            });
        }
        
        /**
         * Handle download for both single and chunked audio
         */
        handleDownload() {
            // For chunked audio, use server-side generation for better macOS compatibility
            if (this.audioChunks && this.audioChunks.length > 1) {
                this.downloadWavServerSide();
                return;
            }
            
            // For single WAV files, download directly
            let downloadUrl;
            let filename;
            
            if (this.concatenatedBlobUrl) {
                // Use concatenated audio for chunked content
                downloadUrl = this.concatenatedBlobUrl;
                filename = 'audio-concatenated-' + Date.now() + '.wav';
            } else {
                // For single files, download the WAV file directly
                downloadUrl = this.audio.querySelector('source').src;
                filename = 'audio-' + Date.now() + '.wav';
            }
            
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }


        /**
         * Download WAV using server-side generation (fallback method)
         */
        async downloadWavServerSide() {
            try {
                // Get post ID from the player container
                const postId = this.container.data('post-id') || 
                              this.container.closest('article').data('post-id') ||
                              document.body.dataset.postId;
                
                if (!postId) {
                    throw new Error('Could not determine post ID');
                }

                // Create form data
                const formData = new FormData();
                formData.append('action', 'listenup_download_wav');
                formData.append('post_id', postId);
                formData.append('nonce', listenupAjax.nonce);

                // Make AJAX request
                const response = await fetch(listenupAjax.ajaxurl, {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`Server error: ${response.status}`);
                }

                // Get filename from Content-Disposition header
                const contentDisposition = response.headers.get('Content-Disposition');
                let filename = 'listenup-audio.wav';
                if (contentDisposition) {
                    const filenameMatch = contentDisposition.match(/filename="(.+)"/);
                    if (filenameMatch) {
                        filename = filenameMatch[1];
                    }
                }

                // Create blob and download
                const blob = await response.blob();
                const url = URL.createObjectURL(blob);
                
                const link = document.createElement('a');
                link.href = url;
                link.download = filename;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                // Clean up
                URL.revokeObjectURL(url);
                
            } catch (error) {
                console.error('ListenUp: Error downloading server-generated WAV:', error);
                this.showErrorState('Failed to download audio. Please try again.');
            }
        }

        /**
         * Disable download button (for chunked audio processing)
         */
        disableDownloadButton() {
            this.downloadButton.prop('disabled', true);
            this.downloadButton.addClass('listenup-download-disabled');
            this.downloadButton.attr('title', 'Processing audio...');
        }
        
        /**
         * Enable download button (after chunked audio is ready)
         */
        enableDownloadButton() {
            this.downloadButton.prop('disabled', false);
            this.downloadButton.removeClass('listenup-download-disabled');
            this.downloadButton.attr('title', 'Download audio');
        }

        /**
         * Cleanup method to prevent memory leaks
         */
        cleanup() {
            if (this.concatenatedBlobUrl) {
                this.concatenator.cleanupBlobUrl(this.concatenatedBlobUrl);
            }
        }
    }
    
    /**
     * Initialize all audio players on the page
     */
    function initAudioPlayers() {
        const players = $('.listenup-audio-player');
        
        players.each(function() {
            new ListenUpPlayer(this);
        });
    }
    
    // Initialize when document is ready
    $(document).ready(function() {
        initAudioPlayers();
    });
    
    // Re-initialize if content is loaded dynamically
    $(document).on('listenup:contentLoaded', function() {
        initAudioPlayers();
    });
    
})(jQuery);
