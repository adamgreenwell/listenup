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
            console.log('ListenUp: Creating player for container', container);
            
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
            
            console.log('ListenUp: Audio element found:', this.audio);
            console.log('ListenUp: Play button found:', this.playButton.length);
            
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
            console.log('ListenUp: Handling chunked audio with', this.audioChunks.length, 'chunks');
            
            try {
                // Show loading state
                this.showLoadingState();
                
                // Concatenate audio chunks
                const result = await this.concatenator.concatenateAudioChunks(this.audioChunks);
                
                if (result.success) {
                    // Update audio source to use concatenated audio
                    this.concatenatedBlobUrl = result.blobUrl;
                    this.audio.src = result.blobUrl;
                    
                    console.log('ListenUp: Chunked audio concatenated successfully, duration:', result.duration);
                    
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
                console.log('ListenUp: Audio metadata loaded');
                this.updateDuration();
            });
            this.audio.addEventListener('timeupdate', () => this.updateProgress());
            this.audio.addEventListener('ended', () => {
                console.log('ListenUp: Audio ended');
                this.onEnded();
            });
            this.audio.addEventListener('error', (e) => {
                console.log('ListenUp: Audio error', e);
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
            let downloadUrl;
            let filename;
            
            if (this.concatenatedBlobUrl) {
                // Use concatenated audio for chunked content
                downloadUrl = this.concatenatedBlobUrl;
                filename = 'audio-concatenated-' + Date.now() + '.wav';
                console.log('ListenUp: Downloading concatenated audio');
            } else {
                // Use original audio source for single file
                downloadUrl = this.audio.querySelector('source').src;
                filename = 'audio-' + Date.now() + '.mp3';
                console.log('ListenUp: Downloading single audio file');
            }
            
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
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
        console.log('ListenUp: Found', players.length, 'audio players to initialize');
        
        players.each(function() {
            console.log('ListenUp: Initializing player for', this);
            new ListenUpPlayer(this);
        });
    }
    
    // Initialize when document is ready
    $(document).ready(function() {
        console.log('ListenUp: Document ready, initializing audio players');
        initAudioPlayers();
    });
    
    // Re-initialize if content is loaded dynamically
    $(document).on('listenup:contentLoaded', function() {
        initAudioPlayers();
    });
    
})(jQuery);
