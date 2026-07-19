=== Omni Webmaster & SEO Suite ===
Contributors: Ivan Lin
Tags: seo, performance, comments, thumbnails, translation
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.7
License: Apache-2.0
License URI: https://opensource.org/license/apache-2-0

An all-in-one performance & SEO suite: cleans HTML head, restricts RSS, disables comments/thumbnails, and translates Chinese URL slugs.

== Description ==

Omni Webmaster & SEO Suite is a lightweight, high-performance toolkit designed to improve your website's SEO, speed, and management. By consolidating several essential tools into one cohesive admin interface, this plugin helps you maintain a clean codebase and optimize server resources.

This plugin incorporates the following major components:

1. SEO & Optimization
   Advanced RSS Control: Disable non-essential RSS feeds (returning HTTP 410) to reduce crawler load. Only keeps homepage, category, and author feeds active.
   HTML Head Cleanup: Removes redundant feed links, RSD, WLManifest, shortlink, and REST API header markings.
   Robots Meta Customization: Automatically tags tags archive, date archive, internal search, and deep pagination (page 3+) as `noindex, follow` to focus search authority.
   Sitemap Sanitization: Excludes `post_tag` from WordPress native sitemaps to prevent indexing low-quality archive pages.

2. Comments Control
   Disable Comments Everywhere: Completely turn off comments, trackbacks, and pingbacks across all post types. Hides historical comments and removes comment menus and widgets from the WordPress dashboard.

3. Media & Thumbnail Optimization
   Selective Thumbnail Disabling: Stop WordPress from generating specific sizes on upload to save storage space.
   AJAX Thumbnail Cleanup: A safe, batch-based AJAX cleanup tool (50 attachments per run) to recursively delete historical thumbnail files with a live progress bar.

4. Slug Translator
   Auto Chinese Title to English Slug: Integrates with Google Cloud Translation API to translate Chinese titles into clean, lowercase English URL slugs, preventing duplicate URLs and character overflow.
   This module shares its core logic with the standalone plugin Chinese to English Slug Converter (zh-to-en-slug): https://github.com/ivanusto/zh-to-en-slug — use the standalone plugin if slug translation is the only feature you need.

5. Meta Pixel Tracking
   Meta (Facebook) Pixel integration with PageView, ViewContent, and Search event tracking. Site staff are excluded by default to keep ad audience data clean.

6. Post Data Export
   Preview and export monthly post data (including a configurable page-view meta key) as CSV from the admin panel.

= Origin Projects =

This suite grew out of six standalone plugins previously written by the author, consolidated and optimized into one toolkit:

* disable-all-thumbnails: https://github.com/ivanusto/disable-all-thumbnails
* disable-all-comments: https://github.com/ivanusto/disable-all-comments
* zh-to-en-slug: https://github.com/ivanusto/zh-to-en-slug
* smart-image-upload-resizer: https://github.com/ivanusto/smart-image-upload-resizer
* smart-file-renamer: https://github.com/ivanusto/smart-file-renamer
* modern-rss-image-feed: https://github.com/ivanusto/modern-rss-image-feed

== Installation ==

1. Upload the `omni-webmaster-seo-suite` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure the settings under 'Settings > Omni Webmaster'.

== Frequently Asked Questions ==

= Does translation require an API Key? =
No, an API key is optional. With a Google Cloud Translation API key configured, the official Cloud API is used. If the key is left blank (or a Cloud API call fails), the plugin falls back to the key-less public Google Translate endpoint automatically.

= Does deleting thumbnails delete my original images? =
No. It only deletes resized sub-sizes. Your original uploaded images remain completely safe.

= Will settings from separate legacy plugins be migrated? =
No. This plugin uses a clean, unified settings array (`omni_webmaster_settings`) to prevent database clutter. You will need to check the desired options in the new admin settings panel.

== Changelog ==

= 1.7 =
* Rewrote the Slug Translator module to align with the standalone zh-to-en-slug plugin (v1.2.2) implementation, with shared API-call helpers for the save-time translation and the AJAX key test.
* Cloud Translation API requests now ask for plain-text responses (format=text) so HTML entities can no longer pollute generated slugs.
* Translation now only runs for an allow-list of post statuses (draft, publish, future, pending, private), customizable via the new omni_slug_allowed_statuses filter.
* Plugin settings are now loaded lazily on first use instead of on every page load.
* Hardened the AJAX API test endpoint: added a manage_options capability check and escaped all dynamic output.
* Generated slugs now keep a minimum length of 8 characters, and the max-length setting is clamped to 20-200 so the ID reserve can no longer truncate slugs to an empty string.
* Fixed the OMNI_WEBMASTER_VERSION constant lagging behind the actual plugin version.
* Documentation: corrected the API-key FAQ (key-less fallback) and cross-linked the standalone zh-to-en-slug project.

= 1.6 =
* Optimized Meta Pixel module: settings are now cached per request instead of being re-read on every output hook.
* Added "Exclude site staff" option (enabled by default) so logged-in users with edit_posts capability are no longer tracked, keeping ad audience data clean.
* Pixel tracking is now skipped on feeds, post previews, customizer previews, and oEmbed pages.
* Pixel ID is strictly sanitized to digits only on save and on output.
* Added preconnect/dns-prefetch resource hints for connect.facebook.net to speed up fbevents.js loading.
* Advanced event parameters (ViewContent/Search) are now encoded with wp_json_encode for safer output.
* Inline pixel script is now printed via wp_print_inline_script_tag() for CSP-nonce compatibility.
* Fixed unescaped ampersand in the noscript fallback image URL.
* Added omni_meta_pixel_enabled filter so themes or consent plugins can conditionally disable tracking.

= 1.5 =
* Added Meta Pixel tracking integration.
* Added PageView, ViewContent (on single posts/pages), and Search tracking.
* Added Meta Pixel tracking settings tab and options in admin console.

= 1.4 =
* Fixed Google Translate API double URL-encoding issue that caused translation failures.
* Fixed post slug getting stuck as 'auto-draft' when saving Chinese-titled posts.
* Excluded 'auto-draft' and 'inherit' statuses from trigger translation workflow to optimize performance.

= 1.3 =
* Hardened the oEmbed cache cleanup query (escaped LIKE wildcard, prepared statement) and now flush the object cache after purging so stale embed cards regenerate reliably.
* Auto-purge oEmbed failure cache when the "HTML Head Cleanup" or embed-style options change, so degraded embed cards recover without a manual reset.

= 1.2 =
* Updated version and Tested up to compatibility with WordPress 7.0.
* Resolved various official Plugin Check report warnings including database caching, parameter escaping, input sanitization/unslashing, hook prefixing, and WP_Filesystem usage.

= 1.0.1 =
Fixed settings tab panel display issue where settings were overwritten.
Fixed coding standards, text domain mismatches, output escaping, and database query caching.
Renamed global initialization function for prefix safety.

= 1.0.0 =
Initial release.
Integrated SEO cleanup, comment block, thumbnail control with AJAX cleanup, and slug translation.
