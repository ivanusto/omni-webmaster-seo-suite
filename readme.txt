=== Omni Webmaster & SEO Suite ===
Contributors: ivanusto
Tags: seo, performance, comments, thumbnails, translation
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

All-in-one performance & SEO suite: cleans HTML head, restricts RSS, disables comments/thumbnails, renames/resizes uploads, translates Asian slugs.

== Description ==

Omni Webmaster & SEO Suite is a lightweight, high-performance toolkit designed to improve your website's SEO, speed, and management. By consolidating several essential tools into one cohesive admin interface, this plugin helps you maintain a clean codebase and optimize server resources.

This plugin incorporates the following major components:

1. SEO & Optimization
   Advanced RSS Control: Disable non-essential RSS feeds (returning HTTP 410) to reduce crawler load. Only keeps homepage, category, and author feeds active.
   HTML Head Cleanup: Removes redundant feed links, RSD, WLManifest, shortlink, and REST API header markings.
   Robots Meta Customization: Automatically tags tags archive, date archive, internal search, and deep pagination (page 3+) as `noindex, follow` to focus search authority.
   Sitemap Sanitization: Excludes `post_tag` from WordPress native sitemaps to prevent indexing low-quality archive pages.
   XML-RPC Hardening: Optionally strips all WordPress XML-RPC methods (wp.*, metaWeblog.*, blogger.*, mt.*, pingback.*), keeping only three harmless system methods, to shut down xmlrpc.php brute-force and pingback abuse without touching .htaccess. Also removes the X-Pingback response header.

2. Comments Control
   Disable Comments Everywhere: Completely turn off comments, trackbacks, and pingbacks across all post types. Hides historical comments and removes comment menus and widgets from the WordPress dashboard.

3. Media & Thumbnail Optimization
   SEO-Friendly Upload File Renaming: Automatically transliterates accented characters to ASCII, converts spaces/underscores to hyphens, strips non-ASCII characters, and lowercases file names on upload, with an optional YYYY-MM-DD date prefix.
   Automatic Upload Image Resizing: Downscales oversized JPEG/PNG/GIF/WebP/AVIF images to configurable maximum dimensions (up to 2560px) at upload time, before thumbnails are generated, with adjustable compression quality and preserved transparency.
   Selective Thumbnail Disabling: Stop WordPress from generating specific sizes on upload to save storage space.
   AJAX Thumbnail Cleanup: A safe, batch-based AJAX cleanup tool (50 attachments per run) to recursively delete historical thumbnail files with a live progress bar.

4. Slug Translator
   Auto Asian Title to English Slug: Integrates with Google Cloud Translation API to translate Asian-language titles (Chinese, Japanese, Korean, Thai) into clean, lowercase English URL slugs, preventing duplicate URLs and character overflow. The source language is auto-detected.
   This module shares its core logic with the standalone plugin Chinese to English Slug Converter (zh-to-en-slug): https://github.com/ivanusto/zh-to-en-slug — use the standalone plugin if slug translation is the only feature you need.

5. Meta Pixel Tracking
   Meta (Facebook) Pixel integration with PageView, ViewContent, and Search event tracking. Site staff are excluded by default to keep ad audience data clean.

6. Post Data Export
   Preview and export monthly post data (including a configurable page-view meta key) as CSV from the admin panel.

7. Meta Tags & Structured Data
   Outputs Meta Description, Open Graph social sharing tags (og:title, og:description, og:image, twitter:card), and Schema.org WebSite/Organization JSON-LD on the homepage — a lightweight alternative when no full SEO plugin is installed. A separate switch (off by default) extends the same output to single posts and pages with og:type=article, image width/height/alt, article:published_time, article:modified_time, Twitter Card tags, and BlogPosting/WebPage JSON-LD; the share image falls back from featured image to the first image in the content to the site-wide default image. Automatically disables its output when a major SEO plugin (Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework) is detected to prevent duplicate tags.

= Origin Projects =

This suite grew out of six standalone plugins previously written by the author, consolidated and optimized into one toolkit:

* disable-all-thumbnails: https://github.com/ivanusto/disable-all-thumbnails
* disable-all-comments: https://github.com/ivanusto/disable-all-comments
* zh-to-en-slug: https://github.com/ivanusto/zh-to-en-slug
* smart-image-upload-resizer: https://github.com/ivanusto/smart-image-upload-resizer
* smart-file-renamer: https://github.com/ivanusto/smart-file-renamer
* modern-rss-image-feed: https://github.com/ivanusto/modern-rss-image-feed

= Sister Project =

* Omni Performance Hardening: https://github.com/ivanusto/omni-wp-perf-hardening - High-performance hardening toolkit for WordPress to reduce server load from search scans, archive queries, and low-value feeds.

== External Services ==

This plugin utilizes third-party and external services to provide specific functionalities:

1. Google Cloud Translation API & Google Translate Public Endpoint:
   * What it is: Used to translate Asian-language post titles into English lowercase slugs.
   * Data sent: The text of the post title is sent to Google's translation endpoints when a post whose title contains Asian-script characters (Chinese, Japanese, Korean, Thai) is created or updated. No personally identifiable information (PII) or user data is transmitted.
   * Terms and Privacy:
     * Google Terms of Service: https://policies.google.com/terms
     * Google Privacy Policy: https://policies.google.com/privacy

2. Meta (Facebook) Pixel:
   * What it is: Used for tracking website visitor interactions (PageView, ViewContent, and Search events) for analytics and advertising.
   * Data sent: Sends visitor interaction data (visited page URL, search queries) to Meta. By default, logged-in site staff (users with `edit_posts` capability) are excluded, and tracking is disabled on feeds, previews, and oEmbed pages.
   * Terms and Privacy:
     * Meta Business Tools Terms: https://www.facebook.com/legal/controller_addendum
     * Meta Privacy Policy: https://www.facebook.com/privacy/policy/

== Installation ==

1. Upload the `omni-webmaster-seo-suite` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure the settings under 'Settings > Omni Webmaster'.

== Screenshots ==

1. SEO & Site Optimization settings tab with toggles for RSS control, HTML head cleanup, robots meta, sitemap sanitization, and XML-RPC hardening.
2. Media & Thumbnails tab: SEO-friendly upload file renaming and automatic upload image resizing, with configurable maximum dimensions and quality.
3. Media & Thumbnails tab: selectively disable individual thumbnail sizes, including the custom sizes registered by your theme, to stop them from being generated on upload.
4. Batch thumbnail cleanup tool with safe-mode options, live progress bar, and per-batch log console.
5. The whole settings panel is fully translated, shown here in the bundled Traditional Chinese (zh_TW) locale.

== Frequently Asked Questions ==

= Does translation require an API Key? =
No, an API key is optional. With a Google Cloud Translation API key configured, the official Cloud API is used. If the key is left blank (or a Cloud API call fails), the plugin falls back to the key-less public Google Translate endpoint automatically.

= Does deleting thumbnails delete my original images? =
No. It only deletes resized sub-sizes. Your original uploaded images remain completely safe.

= Should I enable Open Graph tags on single posts? =
Only if your theme does not already output them. Open any post, view its page source, and search for `og:title`. If it is already there, leave the switch off — duplicate tags make Facebook, LINE, and X pick the wrong title or image. The homepage switch and the single-post switch are independent, so you can run one without the other.

= Will settings from separate legacy plugins be migrated? =
No. This plugin uses a clean, unified settings array (`omni_webmaster_settings`) to prevent database clutter. You will need to check the desired options in the new admin settings panel.

== Changelog ==

= 2.5.1 =
* Fixed: the thumbnail size cards showed no dimensions for the built-in 1536x1536 and 2048x2048 sizes. WordPress registers those two with add_image_size() and gives them no size options, but the plugin was reading the dimensions from those non-existent options. They are now read from the registered sub-sizes, with the fixed dimensions core names them after as a fallback for when the size is disabled and therefore no longer registered.
* An unconstrained dimension now reads as "auto" instead of 0, so the built-in Medium Large size shows as "768 x auto" rather than "768 x 0". That size is 768px wide at whatever height preserves the aspect ratio.
* Display only: which thumbnails get generated, and the batch cleanup, are unchanged.

= 2.5.0 =
* WordPress 7.1 compatibility for the new client-side media processing pipeline, where the browser uploads the original via REST, generates sub-sizes locally, and sideloads them through /wp/v2/media/{id}/sideload.
* Image Resizing: keeps media processing server-side while the module is enabled (via the wp_client_side_media_processing_enabled filter), because in the client-side flow a server-side resize of the original either fails core's dimension validation with a 400 error or is silently replaced by the client's own 2560px scaled copy. As defense in depth, the resizer also refuses to touch files arriving on the sideload endpoint or on a media create with generate_sub_sizes=false.
* File Renaming: no longer renames files uploaded to the sub-size sideload endpoint. Those names must derive from the attachment's existing (already renamed) file name, otherwise core's naming workaround stops matching and every sub-size lands on a numeric-suffix name. The optional date prefix is now idempotent, so a name already carrying one is never double-prefixed.
* Thumbnail Disabling: disabled sizes are now also removed from wp_get_missing_image_subsizes(), which WordPress 7.1 uses to tell the browser which sub-sizes to generate client-side (and which the post-upload recovery has always used). Previously, disabled built-in sizes could quietly come back through those paths.
* Thumbnail Cleanup: since WordPress 7.1 one physical file can be registered under several size names when sizes share dimensions. The batch cleanup now tracks which files are still referenced by kept sizes, the main file, and the new companion files (source_image, animated_video, animated_video_poster) and never deletes a file that a surviving entry still points to.
* SEO Cleanup: the emoji dns-prefetch removal no longer hardcodes the emoji CDN URL (flagged by Plugin Check) and now handles resource hint entries passed as arrays, which previously raised a PHP 8 TypeError. WordPress 7.1 itself no longer prints this hint; the filter remains for older supported versions.

= 2.4.1 =
* Fixed: the SEO-Friendly Upload File Renaming module hooked the global `sanitize_file_name` filter, so it rewrote every string WordPress, themes, and plugins sanitize as a file name — not just uploads. Generated cache files such as `trx_addons-layout-2728.css` or `style_dynamic_ann.css` came back with underscores turned into hyphens, and names containing non-Latin characters lost them entirely, so any code that writes a file under one name and reads it back under another silently failed. This was reported as a theme's header and main menu disappearing on a site using a ThemeREX theme with Elementor.
* Renaming now runs on `wp_handle_upload_prefilter` and `wp_handle_sideload_prefilter`, so only files that are genuinely being uploaded or sideloaded are touched. The renaming rules themselves, the date prefix option, and the standalone-plugin conflict detection are unchanged.
* If you had turned the file renaming module off to work around a broken theme, it is safe to turn it back on after updating.

= 2.4.0 =
* New: the Meta Tags module can now output Open Graph tags on single posts and pages, not just the homepage — og:type=article, og:title, og:description, og:url, og:image (with width, height, and alt text), article:published_time, article:modified_time, and the Twitter Card tags.
* The single-post share image falls back in order: featured image, first image in the post content, then the site-wide default image. Resolving a content image to its attachment is cached in a transient keyed by the post's modified time.
* New: optional BlogPosting / WebPage JSON-LD on single posts, carrying the headline, publication and modification dates, author, image, and a self-contained Organization publisher node.
* Descriptions use the manual excerpt when set, otherwise the first 160 characters of the content (filterable via omni_og_description_length); password-protected posts never expose their content.
* Single-post output is off by default and carries an in-panel warning, because most themes already print their own OG tags and duplicates break share previews.
* A static front page is always treated as the homepage, so it never receives article markup.

= 2.3.0 =
* New: SEO-Friendly Upload File Renaming module (from the standalone smart-file-renamer plugin) — normalizes uploaded file names to clean ASCII lowercase slugs, with an optional date prefix. Off by default; found in the Media & Thumbnails tab.
* New: Automatic Upload Image Resizing module (from the standalone smart-image-upload-resizer plugin) — downscales oversized JPEG/PNG/GIF/WebP/AVIF uploads to configurable maximum dimensions with adjustable quality. Off by default; found in the Media & Thumbnails tab.
* Both modules automatically yield (with a settings-page notice) when their standalone counterpart plugin is active, so files are never renamed or resized twice.
* Image resizing fails safe: if the server lacks GD or a resize step fails, the original image is uploaded unchanged instead of blocking the upload.
* Fixed readme license metadata still referencing Apache-2.0; the plugin is licensed GPLv2 or later.

= 2.2.0 =
* Slug Translator no longer appends the post ID to every generated slug (e.g. `-13663`); slug uniqueness is delegated to WordPress core, which only adds a numeric suffix on an actual collision. Existing published slugs are not modified.
* The full configured maximum length is now available for the slug itself (previously 12 characters were reserved for the ID suffix, leaving only 18 of the default 30).
* Smarter truncation: cuts at whole-word boundaries without discarding a word that fit exactly, and trims trailing function words (of, and, the, ...) so slugs end on a meaningful word.

= 2.1.2 =
* Plugin Check compliance: admin-page strings containing markup are now output through wp_kses_post() instead of _e(), and the two dynamic notices are escaped the same way (fixes 17 escaping errors).
* Added the missing translators comment for the custom image size label.
* GitHub release packages no longer bundle the zh-TW README; removed a legacy zip from the repository.

= 2.1.1 =
* Slug Translator settings now recommend using a Google Cloud Translation API key: the keyless fallback endpoint can produce lower-quality slugs (e.g. `how-was-it-able-9`), and the description links to Google's API key setup guide.
* Refined the zh_TW translation of the Slug Translator description to mention Chinese, Japanese, Korean, and Thai support.

= 2.1 =
* Interface is now English by default with full internationalization (i18n) support; Traditional Chinese (zh_TW) translation is bundled so existing Chinese sites keep their localized UI.
* Slug Translator generalized beyond Chinese: now detects Chinese, Japanese, Korean, and Thai titles, and lets Google Translate auto-detect the source language.
* Added directory screenshots and screenshot captions.

= 2.0 =
* Resolved official WordPress.org Plugin Directory review feedback: removed persistent database updates to core media size options (thumbnail_size_w/h) so core media settings remain intact.
* Replaced direct style tag outputs with wp_add_inline_style() calls using version tags for cleaner CSP compliance and caching.
* Added External Services section in readme.txt to comply with Guideline 6 regarding Google Translate and Meta Pixel.

= 1.9 =
* Added XML-RPC Security Hardening option (SEO tab, off by default): removes all WordPress XML-RPC methods (wp.*, metaWeblog.*, blogger.*, mt.*, pingback.*), keeping only system.multicall, system.listMethods, and system.getCapabilities.
* Blocks xmlrpc.php credential brute-force (e.g. via wp.getUsersBlogs) and pingback amplification abuse; works on any web server without .htaccess rules.
* Removes the X-Pingback response header when hardening is enabled.

= 1.8 =
* Added Homepage Meta Tags & Structured Data module: outputs Meta Description, Open Graph tags (og:type/site_name/title/description/url/image/locale, twitter:card), and Schema.org WebSite + Organization JSON-LD on the homepage.
* Output is limited to the first page of the homepage (paginated pages excluded) to avoid duplicate descriptions across URLs.
* Automatic conflict detection: output is suppressed when Yoast SEO, Rank Math, All in One SEO, SEOPress, or The SEO Framework is active, with a notice shown in the settings panel.
* Added og:image picker with WordPress media library integration and live preview in the admin panel.
* Added live character counter (90-160 recommended range) for the homepage meta description field.
* Organization logo uses the Site Icon when set, falling back to the configured share image.
* JSON-LD is printed via wp_print_inline_script_tag with wp_json_encode for safe, CSP-friendly output.
* Added omni_meta_tags_enabled filter so themes or consent plugins can conditionally disable output.

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
