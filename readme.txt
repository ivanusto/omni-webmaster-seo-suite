=== Omni Webmaster & SEO Suite ===
Contributors: Ivan Lin
Tags: seo, performance, comments, thumbnails, translation
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.5
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

== Installation ==

1. Upload the `omni-webmaster-seo-suite` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure the settings under 'Settings > Omni Webmaster'.

== Frequently Asked Questions ==

= Does translation require an API Key? =
Yes, auto-translation utilizes Google Cloud Translation API. You need to create an API key in Google Cloud Console. If left blank, translation will be inactive while other optimizations continue to work.

= Does deleting thumbnails delete my original images? =
No. It only deletes resized sub-sizes. Your original uploaded images remain completely safe.

= Will settings from separate legacy plugins be migrated? =
No. This plugin uses a clean, unified settings array (`omni_webmaster_settings`) to prevent database clutter. You will need to check the desired options in the new admin settings panel.

== Changelog ==

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
