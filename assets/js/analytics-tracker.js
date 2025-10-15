/**
 * ListenUp Analytics Tracker
 *
 * Tracks audio playback events and pushes them to the dataLayer for Google Tag Manager/Google Analytics.
 *
 * @package ListenUp
 */

(function() {
	'use strict';

	// Ensure window.dataLayer exists
	window.dataLayer = window.dataLayer || [];

	/**
	 * Push event to dataLayer
	 *
	 * @param {string} event Event name
	 * @param {object} data Event data
	 */
	function pushToDataLayer(event, data) {
		window.dataLayer.push({
			event: event,
			...data
		});

		// Debug logging if enabled
		if (window.listenupAnalytics && window.listenupAnalytics.debug) {
			console.log('[ListenUp Analytics]', event, data);
		}
	}

	/**
	 * Track audio play event
	 *
	 * @param {HTMLAudioElement} audioElement The audio element
	 * @param {number} postId Post ID
	 * @param {string} postTitle Post title
	 */
	function trackPlay(audioElement, postId, postTitle) {
		if (!window.listenupAnalytics || !window.listenupAnalytics.trackPlays) {
			return;
		}

		pushToDataLayer('listenup_audio_play', {
			audio_post_id: postId,
			audio_post_title: postTitle,
			audio_url: audioElement.currentSrc,
			audio_duration: audioElement.duration
		});
	}

	/**
	 * Track audio completion percentage
	 *
	 * @param {HTMLAudioElement} audioElement The audio element
	 * @param {number} postId Post ID
	 * @param {string} postTitle Post title
	 * @param {number} percentComplete Percentage of audio completed (0-100)
	 */
	function trackDuration(audioElement, postId, postTitle, percentComplete) {
		if (!window.listenupAnalytics || !window.listenupAnalytics.trackDuration) {
			return;
		}

		pushToDataLayer('listenup_audio_progress', {
			audio_post_id: postId,
			audio_post_title: postTitle,
			audio_url: audioElement.currentSrc,
			audio_duration: audioElement.duration,
			audio_current_time: audioElement.currentTime,
			audio_percent_complete: Math.round(percentComplete)
		});
	}

	/**
	 * Track audio download event
	 *
	 * @param {string} downloadUrl Download URL
	 * @param {number} postId Post ID
	 * @param {string} postTitle Post title
	 */
	function trackDownload(downloadUrl, postId, postTitle) {
		if (!window.listenupAnalytics || !window.listenupAnalytics.trackDownloads) {
			return;
		}

		pushToDataLayer('listenup_audio_download', {
			audio_post_id: postId,
			audio_post_title: postTitle,
			audio_download_url: downloadUrl
		});
	}

	/**
	 * Initialize tracking for an audio player
	 *
	 * @param {HTMLElement} playerElement The player container element
	 */
	function initializePlayerTracking(playerElement) {
		const audioElement = playerElement.querySelector('audio');
		if (!audioElement) {
			return;
		}

		// Get post metadata from data attributes
		const postId = playerElement.dataset.postId || '';
		const postTitle = playerElement.dataset.postTitle || '';

		// Track if play event has been fired
		let playTracked = false;

		// Track progress milestones (25%, 50%, 75%, 100%)
		const milestones = [25, 50, 75, 100];
		const trackedMilestones = new Set();

		// Play event
		audioElement.addEventListener('play', function() {
			if (!playTracked) {
				trackPlay(audioElement, postId, postTitle);
				playTracked = true;
			}
		});

		// Time update event for tracking progress
		audioElement.addEventListener('timeupdate', function() {
			if (!audioElement.duration || audioElement.duration === 0) {
				return;
			}

			const percentComplete = (audioElement.currentTime / audioElement.duration) * 100;

			// Track milestone events
			milestones.forEach(function(milestone) {
				if (percentComplete >= milestone && !trackedMilestones.has(milestone)) {
					trackedMilestones.add(milestone);
					trackDuration(audioElement, postId, postTitle, milestone);
				}
			});
		});

		// Ended event (tracks 100% completion)
		audioElement.addEventListener('ended', function() {
			if (!trackedMilestones.has(100)) {
				trackedMilestones.add(100);
				trackDuration(audioElement, postId, postTitle, 100);
			}
		});

		// Download button tracking
		const downloadButton = playerElement.querySelector('.listenup-download-btn, [data-listenup-download]');
		if (downloadButton) {
			downloadButton.addEventListener('click', function(e) {
				// Get download URL from href or data attribute
				const downloadUrl = this.href || this.dataset.listenupDownload || audioElement.currentSrc;
				trackDownload(downloadUrl, postId, postTitle);
			});
		}
	}

	/**
	 * Initialize tracking for all players on the page
	 */
	function initializeTracking() {
		// Wait for DOM to be ready
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', initializeTracking);
			return;
		}

		// Find all ListenUp audio players
		const players = document.querySelectorAll('.listenup-audio-player, [data-listenup-player]');
		players.forEach(function(player) {
			initializePlayerTracking(player);
		});

		// Watch for dynamically added players
		if (window.MutationObserver) {
			const observer = new MutationObserver(function(mutations) {
				mutations.forEach(function(mutation) {
					mutation.addedNodes.forEach(function(node) {
						if (node.nodeType === 1) { // Element node
							// Check if the node itself is a player
							if (node.classList && (node.classList.contains('listenup-audio-player') || node.hasAttribute('data-listenup-player'))) {
								initializePlayerTracking(node);
							}
							// Check if the node contains players
							const childPlayers = node.querySelectorAll ? node.querySelectorAll('.listenup-audio-player, [data-listenup-player]') : [];
							childPlayers.forEach(function(player) {
								initializePlayerTracking(player);
							});
						}
					});
				});
			});

			observer.observe(document.body, {
				childList: true,
				subtree: true
			});
		}
	}

	// Initialize tracking when script loads
	initializeTracking();

})();