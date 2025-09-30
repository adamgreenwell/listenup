=== ListenUp ===
Contributors: adamgreenwell
Tags: audio, text-to-speech, accessibility, tts
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add "read this to me" functionality to your WordPress posts using Murf.ai text-to-speech technology.

== Description ==

ListenUp is a powerful WordPress plugin that adds text-to-speech functionality to your posts and pages. With just a few clicks, you can generate high-quality audio versions of your content using Murf.ai's advanced AI voices.

= Key Features =

* **Easy Audio Generation**: Generate audio for any post or page with a simple click
* **Murf.ai Integration**: Uses professional AI voices for natural-sounding audio
* **No FFmpeg Required**: Ideal for hosting environments where FFmpeg is not available or restricted
* **Smart Caching**: Audio files are cached locally to save API credits
* **Intelligent Chunking**: Long content is automatically broken into manageable chunks
* **Seamless Playback**: Multiple audio chunks play continuously without interruption
* **Flexible Placement**: Choose where to display the audio player (before/after content)
* **Shortcode Support**: Use [listenup] shortcode to place players anywhere
* **Accessibility First**: WCAG-compliant audio player with keyboard navigation
* **Mobile Responsive**: Works perfectly on all devices
* **Admin-Friendly**: Simple settings page with clear instructions

= How It Works =

1. **Setup**: Enter your Murf.ai API key in the plugin settings
2. **Generate**: Use the meta box on any post/page to generate audio
3. **Display**: Audio players appear automatically or via shortcode
4. **Listen**: Visitors can play, pause, and download audio content

= Advanced Features =

**Content Chunking**: For posts that exceed Murf.ai's API limits, content is automatically broken into smaller chunks. Each chunk generates a separate audio file, but the frontend player seamlessly plays all chunks in sequence without interruption.

**Audio Concatenation**: When downloading audio content that has been chunked, the plugin automatically concatenates all audio files into a single WAV file, ensuring compatibility across all platforms and devices.

**No FFmpeg Dependency**: Unlike many audio plugins, ListenUp doesn't require FFmpeg, making it perfect for shared hosting environments, managed WordPress hosts, or any situation where FFmpeg is not available or restricted.

= Perfect For =

* Bloggers who want to offer audio versions of their posts
* Content creators looking to improve accessibility
* Websites targeting mobile users who prefer audio content
* Educational sites with long-form content
* News sites wanting to offer audio news

= Accessibility Features =

* Full keyboard navigation support
* Screen reader compatible
* High contrast mode support
* Reduced motion support
* Proper ARIA labels and descriptions

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/listenup` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Settings > ListenUp to configure your Murf.ai API key
4. Start generating audio for your posts and pages!

== Frequently Asked Questions ==

= Do I need a Murf.ai account? =

Yes, you'll need a Murf.ai account and API key to use this plugin. You can sign up at murf.ai and get your API key from your dashboard.

= How much does Murf.ai cost? =

Murf.ai offers various pricing plans. Check their website for current pricing information.

= Can I use this on multiple sites? =

Yes, but you'll need separate API keys for each site or ensure your Murf.ai plan supports multiple domains.

= What audio formats are supported? =

The plugin generates WAV audio files, which provide high-quality audio output and are compatible with all modern browsers and devices. WAV format ensures maximum compatibility across all platforms and devices.

= Can I customize the audio player appearance? =

Yes, the plugin includes CSS classes that you can customize in your theme's stylesheet.

= What happens with very long posts? =

For posts that exceed Murf.ai's API character limits, the plugin automatically breaks the content into smaller chunks. Each chunk is processed separately and saved as individual audio files. The frontend player seamlessly plays all chunks in sequence, and when users download the audio, all chunks are automatically concatenated into a single WAV file for maximum compatibility.

== Changelog ==

- 1.2.01 =
* Minor frontend player presentation improvements

= 1.2.0 =
* Added ability to generate audio for posts that do not fit in the Murf.ai API request length

= 1.1.1 =
* Improved debug logging

= 1.1.0 =
* Users can select a default voice and style
* Voices can now be previewed

= 1.0.0 =
* Initial release
* Murf.ai API integration
* Audio caching system
* Meta box for manual generation
* Automatic placement options
* Shortcode support
* Accessibility features
* Mobile responsive design

== Upgrade Notice ==

= 1.0.0 =
Initial release of ListenUp plugin.

== Support ==

For support, please visit the plugin's support forum or contact the developer.

== Privacy Policy ==

This plugin does not collect or store any personal data. Audio files are cached locally on your server. The plugin only communicates with Murf.ai's API to generate audio content.
