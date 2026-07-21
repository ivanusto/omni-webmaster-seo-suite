# Omni Webmaster & SEO Suite

[繁體中文說明](README.zh-TW.md)

An all-in-one WordPress performance & SEO suite for webmasters: cleans the HTML head, restricts RSS feeds, disables comments and thumbnails, translates Chinese URL slugs into English, and integrates Meta Pixel tracking — all from a single unified settings panel.

![Version](https://img.shields.io/badge/version-1.8-blue) ![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b) ![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4) ![License](https://img.shields.io/badge/license-Apache--2.0-green)

## Modules

### 1. SEO & Optimization
- **Advanced RSS Control**: Disable non-essential RSS feeds (returning HTTP 410) to reduce crawler load. Only homepage, category, and author feeds stay active.
- **HTML Head Cleanup**: Removes redundant feed links, RSD, WLManifest, shortlink, and REST API header markings.
- **Robots Meta Customization**: Automatically tags tag archives, date archives, internal search, and deep pagination (page 3+) as `noindex, follow` to focus search authority.
- **Sitemap Sanitization**: Excludes `post_tag` from WordPress native sitemaps to prevent indexing low-quality archive pages.

### 2. Comments Control
- **Disable Comments Everywhere**: Completely turn off comments, trackbacks, and pingbacks across all post types. Hides historical comments and removes comment menus and widgets from the dashboard.

### 3. Media & Thumbnail Optimization
- **Selective Thumbnail Disabling**: Stop WordPress from generating specific sizes on upload to save storage space.
- **AJAX Thumbnail Cleanup**: A safe, batch-based cleanup tool (50 attachments per run) to recursively delete historical thumbnail files with a live progress bar.

### 4. Slug Translator
- **Auto Chinese Title to English Slug**: Translates Chinese post titles into clean, lowercase English URL slugs via the Google Cloud Translation API, with an automatic key-less fallback endpoint when no API key is configured.
- This module shares its core logic with the standalone plugin [Chinese to English Slug Converter (zh-to-en-slug)](https://github.com/ivanusto/zh-to-en-slug) — use the standalone plugin if slug translation is the only feature you need.

### 5. Meta Pixel Tracking
- **Meta (Facebook) Pixel integration**: PageView, ViewContent (single posts/pages), and Search event tracking.
- **Clean audience data**: Site staff (logged-in users with `edit_posts`) are excluded by default; feeds, previews, and oEmbed pages are never tracked.

### 6. Post Data Export
- **Monthly CSV export**: Preview and export post data by month — including a configurable page-view meta key — straight from the admin panel.

### 7. Homepage Meta Tags & Structured Data
- **Homepage-only output**: Meta Description, Open Graph social sharing tags (`og:title`, `og:description`, `og:image`, `twitter:card`), and Schema.org WebSite/Organization JSON-LD — a lightweight alternative when no full SEO plugin is installed.
- **Conflict-safe**: Output is automatically suppressed when a major SEO plugin (Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework) is active, so tags are never duplicated.
- **Media library picker**: Choose the `og:image` share image (recommended 1200 × 630) directly from the media library, with live preview and a character counter for the description.

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- (Optional) A Google Cloud Translation API key for slug translation — without one, the key-less public endpoint is used automatically

## Installation

1. Download `omni-webmaster-seo-suite-X.Y.zip` from the [latest release](https://github.com/ivanusto/omni-webmaster-seo-suite/releases/latest) (do **not** use the auto-generated Source code zip)
2. Upload it through **Plugins > Add New > Upload Plugin** in WordPress, or unzip it into `/wp-content/plugins/`
3. Activate the plugin through the **Plugins** screen
4. Configure the settings under **Settings > Omni Webmaster**

## FAQ

**Does translation require an API key?**
No, an API key is optional. With a Google Cloud Translation API key configured, the official Cloud API is used. If the key is left blank (or a Cloud API call fails), the plugin falls back to the key-less public Google Translate endpoint automatically.

**Does deleting thumbnails delete my original images?**
No. It only deletes resized sub-sizes. Your original uploaded images remain completely safe.

**Will settings from separate legacy plugins be migrated?**
No. This plugin uses a clean, unified settings array (`omni_webmaster_settings`) to prevent database clutter. You will need to check the desired options in the new admin settings panel.

## Changelog

See the Changelog section in [readme.txt](readme.txt) for the full version history.

## Origin Projects

This suite grew out of six standalone plugins previously written by the author. Their functionality was consolidated and optimized into one cohesive toolkit, with Meta Pixel tracking and monthly post data export added on top:

- [disable-all-thumbnails](https://github.com/ivanusto/disable-all-thumbnails) — prevent the generation of specific thumbnail formats in WordPress
- [disable-all-comments](https://github.com/ivanusto/disable-all-comments) — completely disable all comment features in WordPress
- [zh-to-en-slug](https://github.com/ivanusto/zh-to-en-slug) — automatically translate Chinese post titles to English slugs (still maintained in sync with this suite's Slug Translator module)
- [smart-image-upload-resizer](https://github.com/ivanusto/smart-image-upload-resizer) — automatically resize uploaded images and support to WebP /AVIF
- [smart-file-renamer](https://github.com/ivanusto/smart-file-renamer) — rename files with accents and special characters during upload for better SEO
- [modern-rss-image-feed](https://github.com/ivanusto/modern-rss-image-feed) — add modern image formats (WebP, AVIF) support to RSS feeds

If you only need a single feature, the standalone plugins remain available.

## License

[Apache-2.0](LICENSE)
