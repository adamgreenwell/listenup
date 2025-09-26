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
         * Create high-quality WAV file from audio buffer with enhanced metadata for macOS compatibility
         */
        createWavFile(audioBuffer) {
            const numberOfChannels = audioBuffer.numberOfChannels;
            const sampleRate = audioBuffer.sampleRate;
            const length = audioBuffer.length;
            const bytesPerSample = 2; // 16-bit for compatibility
            const blockAlign = numberOfChannels * bytesPerSample;
            const byteRate = sampleRate * blockAlign;
            const dataSize = length * blockAlign;
            
            // Calculate duration in seconds for metadata
            const duration = length / sampleRate;
            
            // Enhanced WAV with LIST chunk for metadata
            const listChunkSize = this.createListChunkSize(duration);
            const bufferSize = 44 + listChunkSize + dataSize;

            const buffer = new ArrayBuffer(bufferSize);
            const view = new DataView(buffer);

            // WAV header
            const writeString = (offset, string) => {
                for (let i = 0; i < string.length; i++) {
                    view.setUint8(offset + i, string.charCodeAt(i));
                }
            };

            // RIFF header
            writeString(0, 'RIFF');
            view.setUint32(4, bufferSize - 8, true);
            writeString(8, 'WAVE');
            
            // fmt chunk - Enhanced with proper PCM format specification
            writeString(12, 'fmt ');
            view.setUint32(16, 16, true); // fmt chunk size
            view.setUint16(20, 1, true); // PCM format (uncompressed)
            view.setUint16(22, numberOfChannels, true);
            view.setUint32(24, sampleRate, true);
            view.setUint32(28, byteRate, true);
            view.setUint16(32, blockAlign, true);
            view.setUint16(34, 16, true); // bits per sample
            
            // LIST chunk with metadata for macOS compatibility
            const listOffset = this.writeListChunk(view, 36, duration, sampleRate, length);
            
            // data chunk
            writeString(listOffset, 'data');
            view.setUint32(listOffset + 4, dataSize, true);

            // Convert float samples to 16-bit PCM with proper dithering
            let offset = listOffset + 8;
            for (let i = 0; i < length; i++) {
                for (let channel = 0; channel < numberOfChannels; channel++) {
                    let sample = audioBuffer.getChannelData(channel)[i];
                    
                    // Clamp sample to valid range
                    sample = Math.max(-1, Math.min(1, sample));
                    
                    // Convert to 16-bit with proper rounding
                    let pcmSample;
                    if (sample < 0) {
                        pcmSample = Math.round(sample * 0x8000);
                        // Ensure we don't exceed 16-bit signed range
                        pcmSample = Math.max(pcmSample, -0x8000);
                    } else {
                        pcmSample = Math.round(sample * 0x7FFF);
                        // Ensure we don't exceed 16-bit signed range
                        pcmSample = Math.min(pcmSample, 0x7FFF);
                    }
                    
                    view.setInt16(offset, pcmSample, true); // little-endian
                    offset += 2;
                }
            }

            console.log(`ListenUp: Created WAV file - Duration: ${duration.toFixed(2)}s, Sample Rate: ${sampleRate}Hz, Channels: ${numberOfChannels}, Size: ${(bufferSize / 1024 / 1024).toFixed(2)}MB`);
            
            return buffer;
        }

        /**
         * Calculate size needed for LIST chunk with metadata
         */
        createListChunkSize(duration) {
            // INFO chunk with comprehensive metadata for macOS compatibility
            const infoEntries = [
                'INAM', 'ListenUp Audio', // Title
                'IART', 'ListenUp Plugin', // Artist
                'ICMT', `Duration: ${duration.toFixed(2)}s | Concatenated Audio`, // Comment with duration
                'ISFT', 'ListenUp Audio Concatenator v1.0', // Software
                'ISBJ', 'Text-to-Speech Audio', // Subject
                'IGNR', 'Speech', // Genre
                'ICRD', new Date().toISOString().split('T')[0], // Creation date
                'ITRK', '1/1', // Track number
                'IKEY', 'ListenUp,Audio,Speech,TTS' // Keywords
            ];
            
            let size = 4; // 'LIST' header
            size += 4; // chunk size
            size += 4; // 'INFO' type
            
            infoEntries.forEach(entry => {
                size += 4; // entry type
                size += 4; // entry size
                size += entry.length; // entry data
                size += entry.length % 2; // padding for even alignment
            });
            
            return size;
        }

        /**
         * Write LIST chunk with comprehensive metadata
         */
        writeListChunk(view, offset, duration, sampleRate, length) {
            const writeString = (pos, string) => {
                for (let i = 0; i < string.length; i++) {
                    view.setUint8(pos + i, string.charCodeAt(i));
                }
                return pos + string.length;
            };

            let pos = offset;
            
            // LIST header
            pos = writeString(pos, 'LIST');
            
            // Calculate chunk size
            const infoEntries = [
                'INAM', 'ListenUp Audio',
                'IART', 'ListenUp Plugin', 
                'ICMT', `Duration: ${duration.toFixed(2)}s | Concatenated Audio`,
                'ISFT', 'ListenUp Audio Concatenator v1.0',
                'ISBJ', 'Text-to-Speech Audio',
                'IGNR', 'Speech',
                'ICRD', new Date().toISOString().split('T')[0],
                'ITRK', '1/1',
                'IKEY', 'ListenUp,Audio,Speech,TTS'
            ];
            
            let chunkSize = 4; // 'INFO' type
            infoEntries.forEach(entry => {
                chunkSize += 4 + 4 + entry.length + (entry.length % 2);
            });
            
            view.setUint32(pos, chunkSize, true);
            pos += 4;
            
            // INFO type
            pos = writeString(pos, 'INFO');
            
            // Write INFO entries
            infoEntries.forEach(entry => {
                // Write entry type (4-byte ASCII)
                const typeBytes = entry.split('').map(char => char.charCodeAt(0));
                for (let i = 0; i < 4; i++) {
                    view.setUint8(pos + i, typeBytes[i] || 0);
                }
                pos += 4;
                
                // Write entry size
                view.setUint32(pos, entry.length, true);
                pos += 4;
                
                // Write entry data
                pos = writeString(pos, entry);
                
                // Add padding for even alignment
                if (entry.length % 2) {
                    view.setUint8(pos, 0);
                    pos += 1;
                }
            });
            
            return pos;
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
