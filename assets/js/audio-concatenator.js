/**
 * Client-side audio concatenation for ListenUp plugin.
 *
 * @package ListenUp
 */

(function($) {
    'use strict';

    /**
     * Audio Concatenator using Web Audio API
     */
    class ListenUpAudioConcatenator {
        constructor() {
            this.audioContext = null;
            this.isSupported = this.checkSupport();
        }

        /**
         * Check if Web Audio API is supported
         */
        checkSupport() {
            return !!(window.AudioContext || window.webkitAudioContext);
        }

        /**
         * Initialize audio context
         */
        initAudioContext() {
            if (!this.audioContext) {
                const AudioContextClass = window.AudioContext || window.webkitAudioContext;
                this.audioContext = new AudioContextClass();
            }
            return this.audioContext;
        }

        /**
         * Download and decode audio file
         */
        async downloadAndDecodeAudio(url) {
            try {
                const response = await fetch(url);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const arrayBuffer = await response.arrayBuffer();
                const audioContext = this.initAudioContext();
                const audioBuffer = await audioContext.decodeAudioData(arrayBuffer);
                
                return audioBuffer;
            } catch (error) {
                console.error('ListenUp: Error downloading/decoding audio:', error);
                throw error;
            }
        }

        /**
         * Concatenate multiple audio buffers
         */
        concatenateAudioBuffers(audioBuffers) {
            if (!audioBuffers || audioBuffers.length === 0) {
                throw new Error('No audio buffers provided');
            }

            if (audioBuffers.length === 1) {
                return audioBuffers[0];
            }

            const audioContext = this.initAudioContext();
            const firstBuffer = audioBuffers[0];
            const totalLength = audioBuffers.reduce((sum, buffer) => sum + buffer.length, 0);
            const numberOfChannels = firstBuffer.numberOfChannels;
            const sampleRate = firstBuffer.sampleRate;

            // Create new buffer with total length
            const concatenatedBuffer = audioContext.createBuffer(
                numberOfChannels,
                totalLength,
                sampleRate
            );

            // Copy each buffer to the concatenated buffer
            let offset = 0;
            audioBuffers.forEach(buffer => {
                for (let channel = 0; channel < numberOfChannels; channel++) {
                    const sourceData = buffer.getChannelData(channel);
                    const destData = concatenatedBuffer.getChannelData(channel);
                    destData.set(sourceData, offset);
                }
                offset += buffer.length;
            });

            return concatenatedBuffer;
        }

        /**
         * Convert audio buffer to blob URL
         */
        async audioBufferToBlob(audioBuffer) {
            const audioContext = this.initAudioContext();
            const numberOfChannels = audioBuffer.numberOfChannels;
            const sampleRate = audioBuffer.sampleRate;
            const length = audioBuffer.length;

            // Create WAV file
            const arrayBuffer = this.createWavFile(audioBuffer);
            const blob = new Blob([arrayBuffer], { type: 'audio/wav' });
            return URL.createObjectURL(blob);
        }

        /**
         * Create WAV file from audio buffer
         */
        createWavFile(audioBuffer) {
            const numberOfChannels = audioBuffer.numberOfChannels;
            const sampleRate = audioBuffer.sampleRate;
            const length = audioBuffer.length;
            const bytesPerSample = 2; // 16-bit
            const blockAlign = numberOfChannels * bytesPerSample;
            const byteRate = sampleRate * blockAlign;
            const dataSize = length * blockAlign;
            const bufferSize = 44 + dataSize;

            const buffer = new ArrayBuffer(bufferSize);
            const view = new DataView(buffer);

            // WAV header
            const writeString = (offset, string) => {
                for (let i = 0; i < string.length; i++) {
                    view.setUint8(offset + i, string.charCodeAt(i));
                }
            };

            writeString(0, 'RIFF');
            view.setUint32(4, bufferSize - 8, true);
            writeString(8, 'WAVE');
            writeString(12, 'fmt ');
            view.setUint32(16, 16, true);
            view.setUint16(20, 1, true);
            view.setUint16(22, numberOfChannels, true);
            view.setUint32(24, sampleRate, true);
            view.setUint32(28, byteRate, true);
            view.setUint16(32, blockAlign, true);
            view.setUint16(34, 16, true);
            writeString(36, 'data');
            view.setUint32(40, dataSize, true);

            // Convert float samples to 16-bit PCM
            let offset = 44;
            for (let i = 0; i < length; i++) {
                for (let channel = 0; channel < numberOfChannels; channel++) {
                    const sample = Math.max(-1, Math.min(1, audioBuffer.getChannelData(channel)[i]));
                    view.setInt16(offset, sample < 0 ? sample * 0x8000 : sample * 0x7FFF, true);
                    offset += 2;
                }
            }

            return buffer;
        }

        /**
         * Main concatenation method
         */
        async concatenateAudioChunks(audioUrls) {
            if (!this.isSupported) {
                throw new Error('Web Audio API is not supported in this browser');
            }

            console.log('ListenUp: Starting audio concatenation for', audioUrls.length, 'chunks');

            try {
                // Download and decode all audio chunks
                const downloadPromises = audioUrls.map(url => this.downloadAndDecodeAudio(url));
                const audioBuffers = await Promise.all(downloadPromises);

                console.log('ListenUp: Downloaded and decoded', audioBuffers.length, 'audio chunks');

                // Concatenate the buffers
                const concatenatedBuffer = this.concatenateAudioBuffers(audioBuffers);

                console.log('ListenUp: Concatenated audio buffer created, length:', concatenatedBuffer.duration, 'seconds');

                // Convert to blob URL
                const blobUrl = await this.audioBufferToBlob(concatenatedBuffer);

                console.log('ListenUp: Audio concatenation completed, blob URL created');

                return {
                    success: true,
                    blobUrl: blobUrl,
                    duration: concatenatedBuffer.duration,
                    sampleRate: concatenatedBuffer.sampleRate,
                    numberOfChannels: concatenatedBuffer.numberOfChannels
                };

            } catch (error) {
                console.error('ListenUp: Audio concatenation failed:', error);
                throw error;
            }
        }

        /**
         * Clean up blob URLs to prevent memory leaks
         */
        cleanupBlobUrl(blobUrl) {
            if (blobUrl && blobUrl.startsWith('blob:')) {
                URL.revokeObjectURL(blobUrl);
            }
        }
    }

    // Make the concatenator available globally
    window.ListenUpAudioConcatenator = ListenUpAudioConcatenator;

})(jQuery);
