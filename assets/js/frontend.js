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
            
            // Cloud storage properties
            this.isCloudStorage = this.container.data('cloud-storage') === true;
            this.cloudUrl = this.container.data('cloud-url');
            this.hasTriedCloud = false;
            this.cloudFailed = false;
            
            // Pre-roll detection
            this.hasPreroll = this.container.data('has-preroll') === true;
            
            this.init();
        }
        
        async init() {
            this.bindEvents();
            this.checkDownloadPermission();
            this.setupDownload();

            // Handle cloud storage or chunked audio
            if (this.isCloudStorage) {
                // For cloud storage with pre-roll, use client-side concatenation
                if (this.hasPreroll) {
                    await this.handleCloudStorageWithPreroll();
                } else {
                    // Try cloud storage first
                    await this.handleCloudStorage();
                }
            } else if (this.audioChunks && this.audioChunks.length > 1) {
                // Handle local chunked audio (including pre-roll)
                this.disableDownloadButton();
                await this.handleChunkedAudio();
                this.enableDownloadButton();
            }
        }

        /**
         * Check download permission and hide button if necessary
         */
        checkDownloadPermission() {
            if (!window.listenupAjax) {
                return;
            }

            const restriction = window.listenupAjax.downloadRestriction;
            const isLoggedIn = window.listenupAjax.isUserLoggedIn;

            // Hide button completely if downloads are disabled
            if (restriction === 'disable') {
                this.downloadButton.hide();
                return;
            }

            // Hide button for non-logged-in users if restriction is set
            if (restriction === 'logged_in_only' && !isLoggedIn) {
                this.downloadButton.hide();
                return;
            }

            // Show button for all other cases
            this.downloadButton.show();
        }
        
        /**
         * Handle cloud storage audio with fallback to local
         */
        async handleCloudStorage() {
            try {
                // Show loading state
                this.showLoadingState();
                
                // Try to load cloud audio first
                await this.tryCloudAudio();
                
                // If cloud failed and we have local chunks, fallback to local
                if (this.cloudFailed && this.audioChunks && this.audioChunks.length > 1) {
                    console.log('Cloud audio failed, falling back to local chunks');
                    this.disableDownloadButton();
                    await this.handleChunkedAudio();
                    this.enableDownloadButton();
                } else if (this.cloudFailed) {
                    throw new Error('Cloud audio failed and no local fallback available');
                }
                
                // Hide loading state
                this.hideLoadingState();
                
            } catch (error) {
                console.error('ListenUp: Error handling cloud storage audio:', error);
                this.hideLoadingState();
                this.showErrorState('Failed to load audio. Please try again.');
            }
        }

        /**
         * Try to load cloud audio
         */
        async tryCloudAudio() {
            return new Promise((resolve, reject) => {
                if (!this.cloudUrl) {
                    reject(new Error('No cloud URL available'));
                    return;
                }

                // Set up error handler for cloud audio
                const handleCloudError = () => {
                    this.cloudFailed = true;
                    this.hasTriedCloud = true;
                    reject(new Error('Cloud audio failed to load'));
                };

                // Set up success handler
                const handleCloudSuccess = () => {
                    console.log('Cloud audio loaded successfully');
                    resolve();
                };

                // Add event listeners
                this.audio.addEventListener('error', handleCloudError, { once: true });
                this.audio.addEventListener('canplaythrough', handleCloudSuccess, { once: true });

                // Set cloud URL as source
                this.audio.src = this.cloudUrl;
                this.audio.load();

                // Timeout after 10 seconds
                setTimeout(() => {
                    if (!this.hasTriedCloud) {
                        handleCloudError();
                    }
                }, 10000);
            });
        }

        /**
         * Handle chunked audio concatenation
         */
        async handleChunkedAudio() {
            try {
                // Show loading state
                this.showLoadingState();
                
                // Use sequential playback for chunked audio (pre-roll + content)
                this.setupSequentialPlaybackForChunks();
                
                // Hide loading state
                this.hideLoadingState();
                
            } catch (error) {
                console.error('ListenUp: Error handling chunked audio:', error);
                this.hideLoadingState();
                this.showErrorState('Failed to process audio. Please try again.');
            }
        }

        /**
         * Handle cloud storage audio with pre-roll using sequential playback
         */
        async handleCloudStorageWithPreroll() {
            try {
                // Show loading state
                this.showLoadingState();
                
                // Get pre-roll URL for sequential playback
                const postId = this.container.data('post-id');
                const preRollUrl = await this.getPreRollUrl(postId);
                
                if (preRollUrl) {
                    // Set up sequential playback: pre-roll first, then main content
                    this.setupSequentialPlayback(preRollUrl, this.cloudUrl);
                    this.hideLoadingState();
                } else {
                    // No pre-roll, just play the main content
                    this.audio.src = this.cloudUrl;
                    this.hideLoadingState();
                }
                
            } catch (error) {
                console.error('ListenUp: Error handling cloud storage with pre-roll:', error);
                this.hideLoadingState();
                this.showErrorState('Failed to process audio. Please try again.');
            }
        }

        /**
         * Set up sequential playback: pre-roll first, then main content
         */
        setupSequentialPlayback(preRollUrl, mainContentUrl) {
            // Store URLs for sequential playback
            this.preRollUrl = preRollUrl;
            this.mainContentUrl = mainContentUrl;
            this.isSequentialPlayback = true;
            
            // Pre-load main content to get its duration for display
            const tempAudio = new Audio(mainContentUrl);
            tempAudio.addEventListener('loadedmetadata', () => {
                // Set the duration display to show main content duration (never show pre-roll duration)
                this.durationDisplay.text(this.formatTime(tempAudio.duration));
            });
            tempAudio.load();
            
            // Set up the audio source (pre-roll) but don't auto-play
            this.audio.src = preRollUrl;
            this.audio.load();
            
            // Listen for pre-roll to finish, then switch to main content
            this.audio.addEventListener('ended', () => {
                console.log('ListenUp: Pre-roll finished, switching to main content');
                this.audio.src = mainContentUrl;
                this.audio.load();
                
                // Wait for the new audio to load, then continue playing
                this.audio.addEventListener('loadedmetadata', () => {
                    // Continue playing since user already gave consent
                    this.audio.play().then(() => {
                        // Maintain playing state during sequential playback
                        this.isPlaying = true;
                        this.updatePlayButton();
                    }).catch((error) => {
                        console.error('ListenUp: Error playing main content after pre-roll:', error);
                    });
                }, { once: true });
            }, { once: true });
        }

        /**
         * Set up sequential playback for chunked audio (pre-roll + content chunks)
         */
        setupSequentialPlaybackForChunks() {
            if (!this.audioChunks || this.audioChunks.length === 0) {
                console.error('ListenUp: No audio chunks available for sequential playback');
                return;
            }

            // First chunk is pre-roll, rest are content
            const preRollUrl = this.audioChunks[0];
            const contentChunks = this.audioChunks.slice(1);
            
            // Store for sequential playback
            this.contentChunks = contentChunks;
            this.isSequentialPlayback = true;
            
            // Calculate total duration of content chunks (excluding pre-roll) for display
            this.calculateTotalContentDuration(contentChunks);
            
            // Set up pre-roll source but don't auto-play
            this.audio.src = preRollUrl;
            this.audio.load();
            
            // Listen for pre-roll to finish, then play content chunks sequentially
            this.audio.addEventListener('ended', () => {
                console.log('ListenUp: Pre-roll finished, playing content chunks');
                this.playContentChunksSequentially(contentChunks, 0);
            }, { once: true });
        }

        /**
         * Calculate total duration of content chunks
         */
        calculateTotalContentDuration(contentChunks) {
            let totalDuration = 0;
            let loadedCount = 0;
            
            contentChunks.forEach((chunkUrl, index) => {
                const tempAudio = new Audio(chunkUrl);
                tempAudio.addEventListener('loadedmetadata', () => {
                    totalDuration += tempAudio.duration;
                    loadedCount++;
                    
                    // When all chunks are loaded, update the duration display
                    if (loadedCount === contentChunks.length) {
                        this.durationDisplay.text(this.formatTime(totalDuration));
                    }
                });
                tempAudio.load();
            });
        }

        /**
         * Play content chunks sequentially
         */
        playContentChunksSequentially(chunks, index) {
            if (index >= chunks.length) {
                console.log('ListenUp: All content chunks finished');
                return;
            }

            // Set up current chunk
            this.audio.src = chunks[index];
            this.audio.load();
            
            // Listen for current chunk to finish, then play next
            this.audio.addEventListener('ended', () => {
                console.log(`ListenUp: Content chunk ${index + 1} finished, playing next`);
                this.playContentChunksSequentially(chunks, index + 1);
            }, { once: true });
            
            // Wait for the new audio to load, then continue playing
            this.audio.addEventListener('loadedmetadata', () => {
                // Continue playing since user already gave consent
                this.audio.play().then(() => {
                    // Maintain playing state during sequential playback
                    this.isPlaying = true;
                    this.updatePlayButton();
                }).catch((error) => {
                    console.error('ListenUp: Error playing content chunk:', error);
                });
            }, { once: true });
        }

        /**
         * Get pre-roll URL for sequential playback
         */
        async getPreRollUrl(postId) {
            try {
                const response = await fetch(listenupAjax.ajaxurl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'listenup_get_preroll_url',
                        post_id: postId,
                        nonce: listenupAjax.frontend_nonce
                    })
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();
                
                if (result.success && result.data.url) {
                    return result.data.url;
                } else {
                    return null; // No pre-roll available
                }
                
            } catch (error) {
                console.error('ListenUp: Error getting pre-roll URL:', error);
                return null; // No pre-roll available
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
            
            // Dispatch custom event for library autoplay functionality
            const endedEvent = new CustomEvent('listenup:audioEnded', {
                detail: {
                    audioElement: this.audio,
                    playerContainer: this.container[0]
                },
                bubbles: true
            });
            this.audio.dispatchEvent(endedEvent);
        }
        
        onError(error) {
            console.error('Audio error:', error);

            // Get more details about the error
            const audioElement = error.target;
            const audioSrc = audioElement ? audioElement.src : 'unknown';
            const errorCode = audioElement && audioElement.error ? audioElement.error.code : 'unknown';
            const errorMessage = audioElement && audioElement.error ? audioElement.error.message : 'Unknown error';

            console.error('Audio error details:', {
                src: audioSrc,
                errorCode: errorCode,
                errorMessage: errorMessage,
                networkState: audioElement ? audioElement.networkState : 'unknown',
                readyState: audioElement ? audioElement.readyState : 'unknown'
            });

            // Only show error message if one doesn't already exist
            if (this.container.find('.listenup-error').length === 0) {
                this.container.append('<p class="listenup-error">Error loading audio. Please try again.</p>');
            }
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
            // For cloud storage, try to download directly from cloud URL first
            if (this.isCloudStorage && this.cloudUrl && !this.cloudFailed) {
                this.downloadCloudAudio();
                return;
            }
            
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
         * Download cloud audio directly
         */
        downloadCloudAudio() {
            try {
                const filename = 'audio-cloud-' + Date.now() + '.mp3';
                const link = document.createElement('a');
                link.href = this.cloudUrl;
                link.download = filename;
                link.target = '_blank'; // Open in new tab as fallback
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            } catch (error) {
                console.error('ListenUp: Error downloading cloud audio:', error);
                this.showErrorState('Failed to download cloud audio. Please try again.');
            }
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
