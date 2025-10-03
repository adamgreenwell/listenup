# ListenUp WordPress Plugin

Add "read this to me" functionality to your WordPress posts using Murf.ai text-to-speech technology.

## Features

- Convert post/page content to audio using Murf.ai API
- Automatic or manual audio player placement
- Pre-roll audio support
- Cloud storage integration (AWS S3, Cloudflare R2, Google Cloud Storage)
- Cloud-based audio conversion (WAV to MP3)
- Multi-segment audio support
- Download restrictions (all users, logged-in only, or disabled)
- Debug logging for troubleshooting

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- Murf.ai API key
- Composer (for cloud storage dependencies)

## Installation

### 1. Install Plugin Files

Upload the `listenup` folder to your `/wp-content/plugins/` directory.

### 2. Install Dependencies

The plugin requires external SDKs for cloud storage functionality. Run:

```bash
cd wp-content/plugins/listenup
composer install --no-dev
```

This will install:
- AWS SDK for PHP (for S3 support)
- Google Cloud Storage SDK (for GCS support)

**Note:** Cloudflare R2 uses the AWS SDK as it's S3-compatible.

### 3. Activate Plugin

Activate the plugin through the WordPress admin panel.

## Configuration

### Basic Setup

1. Go to **ListenUp → Settings**
2. Enter your **Murf.ai API Key**
3. Select your preferred **Voice**
4. Configure **Display Settings** (auto-placement, position, etc.)

### Cloud Storage Setup (Optional but Recommended)

Cloud storage is **highly recommended** for:
- Development with localhost (cloud URLs are publicly accessible)
- Reducing bandwidth costs (files served from cloud)
- Multi-site deployments (organize files by site)

#### AWS S3

1. Select **AWS S3** from the Cloud Storage Provider dropdown
2. Enter your:
   - Access Key ID
   - Secret Access Key
   - Bucket Name
   - Region (e.g., `us-east-1`)
   - Base URL (e.g., `https://your-bucket.s3.amazonaws.com`)

#### Cloudflare R2

1. Select **Cloudflare R2** from the Cloud Storage Provider dropdown
2. Enter your:
   - Access Key ID
   - Secret Access Key
   - Bucket Name
   - Endpoint (e.g., `https://your-account-id.r2.cloudflarestorage.com`)
   - Base URL (your public R2 domain)

#### Google Cloud Storage

1. Select **Google Cloud Storage** from the Cloud Storage Provider dropdown
2. Enter your:
   - Project ID
   - Bucket Name
   - Credentials File Path (path to service account JSON)
   - Base URL (e.g., `https://storage.googleapis.com/your-bucket`)

### Audio Conversion Setup (Optional)

If you have a cloud-based audio conversion service:

1. Go to **ListenUp → Settings → Audio Conversion Settings**
2. Enter your conversion API endpoint
3. Enter your API key
4. Optionally enable:
   - Automatic conversion after generation
   - Delete WAV files after successful MP3 conversion

## Cloud Storage File Organization

Files uploaded to cloud storage are automatically organized by site and date:

```
listenup-audio/
  └── {site-slug}/
      └── {year}/
          └── {month}/
              └── {day}/
                  └── {filename}_{unique-id}.{ext}
```

**Example:**
```
listenup-audio/my-blog/2025/10/02/16_abc123_9d3c_6bd5_xyz789.wav
```

This structure allows you to:
- Use the same bucket for multiple sites
- Easily browse files by site
- Organize files chronologically
- Avoid filename conflicts

## Usage

### Generating Audio

1. Create or edit a post/page
2. In the **ListenUp Audio Generation** meta box:
   - Click **Generate Audio** to create audio from post content
   - Or upload a pre-generated audio file

### Manual Placement

Use the `[listenup]` shortcode anywhere in your content to place the audio player.

### Audio Library

Go to **ListenUp → Audio Library** to:
- View all generated audio files
- Convert WAV files to MP3
- Delete audio files
- Download audio files

## Development

### Installing for Development

```bash
# Clone repository
git clone <repository-url>

# Install dependencies
cd wp-content/plugins/listenup
composer install

# For development with dev dependencies
composer install
```

### Dependencies

The plugin uses Composer to manage the following dependencies:

- **aws/aws-sdk-php**: AWS SDK for S3 integration
- **google/cloud-storage**: Google Cloud Storage SDK

These are **required** for cloud storage functionality.

## Troubleshooting

### "AWS SDK is not available" Error

This means Composer dependencies haven't been installed. Run:

```bash
cd wp-content/plugins/listenup
composer install --no-dev
```

### Cloud Storage Not Working

1. Verify your credentials are correct
2. Check bucket permissions (must allow public read for uploaded files)
3. Enable debug logging in **ListenUp → Settings → Debug Settings**
4. Check the debug log for specific errors

### Audio Not Playing

1. Check browser console for errors
2. Verify audio files exist in the uploads directory or cloud storage
3. Check file permissions

## Support

For issues, feature requests, or questions, please contact the plugin author.

## License

GPL v2 or later

## Changelog

### 1.3.22
* Fix autoplay feature in audio library

### 1.3.21
* Add ability to delete cloud audio as well as local audio files

### 1.3.2
* Improve frontend player user experience when pre-roll audio is present

### 1.3.11
* Add feature to generate pre-roll audio

### 1.3.1
* Add "autoplay" feature to audio library shortcode

### 1.3.0
* Restrict download to logged-in users or block all downloads completely
* Implemented leech protection (requires server configuration)

### 1.2.01
* Minor frontend player presentation improvements

### 1.2.0
* Added ability to generate audio for posts that do not fit in the Murf.ai API request length

### 1.1.1
* Improved debug logging

## 1.1.0
* Users can select a default voice and style
* Voices can now be previewed

### 1.0.0
* Initial release
* Murf.ai API integration
* Audio caching system
* Meta box for manual generation
* Automatic placement options
* Shortcode support
* Accessibility features
* Mobile responsive design

