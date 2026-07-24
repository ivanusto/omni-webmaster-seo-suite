<?php
/**
 * Omni_Admin Class
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Omni_Admin {

    private $seo_cleanup;
    private $disable_comments;
    private $disable_thumbnails;
    private $slug_converter;

    private $option_name = 'omni_webmaster_settings';

    public function __construct( $seo_cleanup, $disable_comments, $disable_thumbnails, $slug_converter ) {
        $this->seo_cleanup        = $seo_cleanup;
        $this->disable_comments   = $disable_comments;
        $this->disable_thumbnails = $disable_thumbnails;
        $this->slug_converter     = $slug_converter;

        // Register menu and settings
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // Add a "Settings" shortcut link on the plugins list page
        add_filter( 'plugin_action_links_' . OMNI_WEBMASTER_BASENAME, [ $this, 'add_settings_link' ] );

        // Register data export AJAX preview and CSV download actions
        add_action( 'wp_ajax_omni_preview_posts_data', [ $this, 'preview_posts_data' ] );
        add_action( 'admin_post_omni_export_csv', [ $this, 'export_posts_csv' ] );

        // Register the clear oEmbed cache AJAX action
        add_action( 'wp_ajax_omni_clear_oembed_cache', [ $this, 'clear_oembed_cache' ] );

        // When settings change (especially "Clean Up HTML Head" or embed styles), automatically purge
        // the failed oEmbed cache so embed cards that degraded into plain text links can be
        // re-fetched and restored without manually clicking the button.
        add_action( 'update_option_' . $this->option_name, [ $this, 'maybe_purge_oembed_on_change' ], 10, 2 );
    }

    /**
     * Add the menu page
     */
    public function add_admin_menu() {
        add_options_page(
            __( 'Omni Webmaster & SEO Suite Settings', 'omni-webmaster-seo-suite' ),
            __( 'Omni Webmaster', 'omni-webmaster-seo-suite' ),
            'manage_options',
            'omni-webmaster-seo',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Register plugin settings and the sanitize callback
     */
    public function register_settings() {
        register_setting(
            'omni_webmaster_settings_group',
            $this->option_name,
            [
                'sanitize_callback' => [ $this, 'sanitize_settings' ]
            ]
        );
    }

    /**
     * Sanitize settings and apply defaults
     */
    public function sanitize_settings( $input ) {
        $sanitized = [];

        // SEO page options
        $sanitized['disable_feeds'] = isset( $input['disable_feeds'] ) ? '1' : '0';
        $sanitized['cleanup_head']  = isset( $input['cleanup_head'] ) ? '1' : '0';
        $sanitized['robots_meta']   = isset( $input['robots_meta'] ) ? '1' : '0';
        $sanitized['clean_sitemap'] = isset( $input['clean_sitemap'] ) ? '1' : '0';
        $sanitized['embed_styles']  = isset( $input['embed_styles'] ) ? '1' : '0';
        $sanitized['gist_styles']   = isset( $input['gist_styles'] ) ? '1' : '0';
        $sanitized['xmlrpc_hardening'] = isset( $input['xmlrpc_hardening'] ) ? '1' : '0';

        // Disable comments option
        $sanitized['disable_comments'] = isset( $input['disable_comments'] ) ? '1' : '0';

        // Disabled thumbnail sizes list
        $sanitized['disabled_sizes'] = [];
        if ( isset( $input['disabled_sizes'] ) && is_array( $input['disabled_sizes'] ) ) {
            $all_sizes = array_keys( $this->disable_thumbnails->get_all_image_sizes() );
            foreach ( $input['disabled_sizes'] as $size => $val ) {
                if ( in_array( $size, $all_sizes, true ) && $val === '1' ) {
                    $sanitized['disabled_sizes'][] = $size;
                }
            }
        }

        // Slug translator options
        $sanitized['slug_api_key']    = isset( $input['slug_api_key'] ) ? sanitize_text_field( $input['slug_api_key'] ) : '';
        // Clamp to 20-200 so the slug length never drops to zero after reserving space for the post ID
        $sanitized['slug_max_length'] = isset( $input['slug_max_length'] ) ? max( 20, min( 200, absint( $input['slug_max_length'] ) ) ) : 30;

        // View count custom field (meta key)
        $sanitized['views_meta_key']  = ! empty( $input['views_meta_key'] ) ? sanitize_text_field( trim( $input['views_meta_key'] ) ) : 'views';

        // Meta Pixel options (the pixel ID is digits only, so strip any non-digit characters)
        $sanitized['meta_pixel_enable']         = isset( $input['meta_pixel_enable'] ) ? '1' : '0';
        $sanitized['meta_pixel_id']             = isset( $input['meta_pixel_id'] ) ? preg_replace( '/\D/', '', sanitize_text_field( trim( $input['meta_pixel_id'] ) ) ) : '';
        $sanitized['meta_pixel_advanced']       = isset( $input['meta_pixel_advanced'] ) ? '1' : '0';
        $sanitized['meta_pixel_exclude_admins'] = isset( $input['meta_pixel_exclude_admins'] ) ? '1' : '0';

        // Homepage meta tags and structured data options
        $sanitized['meta_tags_enable']      = isset( $input['meta_tags_enable'] ) ? '1' : '0';
        $sanitized['home_meta_description'] = isset( $input['home_meta_description'] ) ? sanitize_textarea_field( $input['home_meta_description'] ) : '';
        $sanitized['og_default_image']      = isset( $input['og_default_image'] ) ? esc_url_raw( trim( $input['og_default_image'] ) ) : '';
        $sanitized['site_alternate_name']   = isset( $input['site_alternate_name'] ) ? sanitize_text_field( trim( $input['site_alternate_name'] ) ) : '';
        $sanitized['schema_website_enable'] = isset( $input['schema_website_enable'] ) ? '1' : '0';

        return $sanitized;
    }

    /**
     * Enqueue admin assets and localized script variables
     */
    public function enqueue_admin_assets( $hook ) {
        if ( 'settings_page_omni-webmaster-seo' !== $hook ) {
            return;
        }

        // Media library picker (used to select the og:image share image)
        wp_enqueue_media();

        wp_enqueue_style(
            'omni-webmaster-admin-css',
            OMNI_WEBMASTER_URL . 'assets/admin.css',
            [],
            OMNI_WEBMASTER_VERSION
        );

        wp_enqueue_script(
            'omni-webmaster-admin-js',
            OMNI_WEBMASTER_URL . 'assets/admin.js',
            [ 'jquery' ],
            OMNI_WEBMASTER_VERSION,
            true
        );

        wp_localize_script(
            'omni-webmaster-admin-js',
            'omniWebmaster',
            [
                'ajax_url'     => admin_url( 'admin-ajax.php' ),
                'slug_nonce'   => wp_create_nonce( 'omni_slug_test_nonce' ),
                'thumb_nonce'  => wp_create_nonce( 'omni_delete_thumbnails_nonce' ),
                'export_nonce' => wp_create_nonce( 'omni_export_posts_nonce' ),
            ]
        );

        // Translatable UI strings used by admin.js
        wp_localize_script(
            'omni-webmaster-admin-js',
            'omniL10n',
            [
                'testing'               => __( 'Testing connection...', 'omni-webmaster-seo-suite' ),
                'enterApiKeyFirst'      => __( 'Please enter an API key first.', 'omni-webmaster-seo-suite' ),
                'requestError'          => __( 'An error occurred. Please try again.', 'omni-webmaster-seo-suite' ),
                'deleting'              => __( 'Batch processing in progress. Please do not close this window...', 'omni-webmaster-seo-suite' ),
                'confirmDeleteAll'      => __( '[Warning] Are you sure you want to delete ALL thumbnails? This removes every image size variant and may cause broken or slow-loading images on the front end. This action cannot be undone!', 'omni-webmaster-seo-suite' ),
                'confirmDeleteSelected' => __( 'Are you sure you want to delete the thumbnail sizes checked as disabled? Only the sizes selected for disabling will be deleted; all other sizes are safely kept. This action cannot be undone!', 'omni-webmaster-seo-suite' ),
                'cleanupComplete'       => __( 'Cleanup complete! All image files have been processed.', 'omni-webmaster-seo-suite' ),
                'loadingPreview'        => __( 'Loading post data, please wait...', 'omni-webmaster-seo-suite' ),
                'noPosts'               => __( 'No published posts found for this month.', 'omni-webmaster-seo-suite' ),
                'modeAllSizes'          => __( 'clean all sizes', 'omni-webmaster-seo-suite' ),
                'modeDisabledOnly'      => __( 'clean disabled sizes only', 'omni-webmaster-seo-suite' ),
                /* translators: %1$s: cleanup mode name. */
                'logScanStart'          => __( 'Scanning media library image attachments and starting cleanup (mode: %1$s)...', 'omni-webmaster-seo-suite' ),
                /* translators: 1: number of processed images, 2: total number of images. */
                'cleanupProgress'       => __( 'Cleaning up: %1$s / %2$s images', 'omni-webmaster-seo-suite' ),
                /* translators: 1: batch number, 2: number of scanned images, 3: number of deleted thumbnail files. */
                'logBatchDone'          => __( 'Batch %1$s complete: scanned %2$s images, deleted %3$s thumbnail files.', 'omni-webmaster-seo-suite' ),
                /* translators: %1$s: batch number. */
                'logBatchEmpty'         => __( 'Batch %1$s: no thumbnails needed deleting.', 'omni-webmaster-seo-suite' ),
                /* translators: %1$s: server error message. */
                'logServerError'        => __( 'Server returned an error: %1$s', 'omni-webmaster-seo-suite' ),
                'logNetworkError'       => __( 'Network request failed. Please check your server status.', 'omni-webmaster-seo-suite' ),
                /* translators: 1: total number of scanned image attachments, 2: total number of deleted thumbnail files. */
                'logAllComplete'        => __( 'All images processed! Scanned %1$s image attachments in total and removed %2$s thumbnail files.', 'omni-webmaster-seo-suite' ),
                'cleanupAborted'        => __( 'Cleanup process interrupted', 'omni-webmaster-seo-suite' ),
                'selectMonthFirst'      => __( 'Please select a month to export.', 'omni-webmaster-seo-suite' ),
                /* translators: %1$s: server error message. */
                'loadFailed'            => __( 'Failed to load: %1$s', 'omni-webmaster-seo-suite' ),
                'previewNetworkError'   => __( 'Network error. Unable to load preview data.', 'omni-webmaster-seo-suite' ),
                'viewPost'              => __( 'View Post', 'omni-webmaster-seo-suite' ),
                'colDate'               => __( 'Date', 'omni-webmaster-seo-suite' ),
                'colTitle'              => __( 'Post Title', 'omni-webmaster-seo-suite' ),
                'colTopics'             => __( 'Angle / Topics', 'omni-webmaster-seo-suite' ),
                'colLink'               => __( 'Link', 'omni-webmaster-seo-suite' ),
                'colViews'              => __( 'Views', 'omni-webmaster-seo-suite' ),
                'copiedToClipboard'     => __( 'Post data copied to clipboard! You can paste it directly into Google Sheets or Excel.', 'omni-webmaster-seo-suite' ),
                'copyFailed'            => __( 'Copy failed. Please select the table manually and copy it.', 'omni-webmaster-seo-suite' ),
                'confirmClearOembed'    => __( 'Are you sure you want to clear all oEmbed preview card caches for the entire site? This forces WordPress to re-fetch embed preview cards on page load (no posts are deleted; only the cache is reset).', 'omni-webmaster-seo-suite' ),
                'clearingCache'         => __( 'Clearing cache...', 'omni-webmaster-seo-suite' ),
                'connectionError'       => __( 'Connection error. Please try again.', 'omni-webmaster-seo-suite' ),
                'mediaLibraryError'     => __( 'Failed to load the media library. Please paste the image URL directly.', 'omni-webmaster-seo-suite' ),
                'mediaFrameTitle'       => __( 'Select a social share image (1200 × 630 recommended)', 'omni-webmaster-seo-suite' ),
                'mediaFrameButton'      => __( 'Use this image', 'omni-webmaster-seo-suite' ),
            ]
        );
    }

    /**
     * Add a "Settings" shortcut link on the plugins list page
     */
    public function add_settings_link( $links ) {
        $settings_link = '<a href="options-general.php?page=omni-webmaster-seo">' . __( 'Settings', 'omni-webmaster-seo-suite' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Render the admin settings page
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Get current settings and set defaults
        $defaults = [
            'disable_feeds'       => '1',
            'cleanup_head'        => '1',
            'robots_meta'         => '1',
            'clean_sitemap'       => '1',
            'embed_styles'        => '0',
            'gist_styles'         => '0',
            'xmlrpc_hardening'    => '0',
            'disable_comments'    => '0',
            'disabled_sizes'      => [],
            'slug_api_key'        => '',
            'slug_max_length'     => 30,
            'views_meta_key'      => 'views',
            'meta_pixel_enable'   => '0',
            'meta_pixel_id'       => '',
            'meta_pixel_advanced' => '0',
            'meta_pixel_exclude_admins' => '1',
            'meta_tags_enable'      => '0',
            'home_meta_description' => '',
            'og_default_image'      => '',
            'site_alternate_name'   => '',
            'schema_website_enable' => '1',
        ];
        $settings = wp_parse_args( get_option( $this->option_name, [] ), $defaults );

        // Tab switching
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'seo'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! in_array( $active_tab, [ 'seo', 'comments', 'thumbnails', 'slug', 'export', 'pixel' ], true ) ) {
            $active_tab = 'seo';
        }
        ?>
        <div class="wrap omni-settings-wrap">
            <header class="omni-header">
                <div class="omni-header-title">
                    <h1><?php esc_html_e( 'Omni Webmaster & SEO Suite', 'omni-webmaster-seo-suite' ); ?></h1>
                    <span class="omni-badge">Version <?php echo esc_html( OMNI_WEBMASTER_VERSION ); ?></span>
                </div>
                <p class="omni-header-desc"><?php esc_html_e( 'Manage your WordPress site optimization and SEO essentials in one place, including comment control, thumbnail optimization, and slug translation.', 'omni-webmaster-seo-suite' ); ?></p>
            </header>

            <h2 class="nav-tab-wrapper">
                <a href="?page=omni-webmaster-seo&tab=seo" class="nav-tab <?php echo $active_tab === 'seo' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-site-alt3"></span> <?php esc_html_e( 'SEO & Site Optimization', 'omni-webmaster-seo-suite' ); ?>
                </a>
                <a href="?page=omni-webmaster-seo&tab=comments" class="nav-tab <?php echo $active_tab === 'comments' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-comments"></span> <?php esc_html_e( 'Comments Control', 'omni-webmaster-seo-suite' ); ?>
                </a>
                <a href="?page=omni-webmaster-seo&tab=thumbnails" class="nav-tab <?php echo $active_tab === 'thumbnails' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-format-image"></span> <?php esc_html_e( 'Media & Thumbnails', 'omni-webmaster-seo-suite' ); ?>
                </a>
                <a href="?page=omni-webmaster-seo&tab=slug" class="nav-tab <?php echo $active_tab === 'slug' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-translation"></span> <?php esc_html_e( 'Slug Translator', 'omni-webmaster-seo-suite' ); ?>
                </a>
                <a href="?page=omni-webmaster-seo&tab=pixel" class="nav-tab <?php echo $active_tab === 'pixel' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-chart-line"></span> <?php esc_html_e( 'Meta Pixel Tracking', 'omni-webmaster-seo-suite' ); ?>
                </a>
                <a href="?page=omni-webmaster-seo&tab=export" class="nav-tab <?php echo $active_tab === 'export' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-database-export"></span> <?php esc_html_e( 'Post Data Export', 'omni-webmaster-seo-suite' ); ?>
                </a>
            </h2>

            <div class="omni-content-card">
                <form method="post" action="options.php">
                    <?php settings_fields( 'omni_webmaster_settings_group' ); ?>

                    <!-- Tab 1: SEO Optimization -->
                    <div class="omni-tab-panel <?php echo $active_tab === 'seo' ? 'is-active' : ''; ?>">
                        <div class="omni-tab-content">
                            <h3><?php esc_html_e( 'SEO Optimization & HTML Cleanup', 'omni-webmaster-seo-suite' ); ?></h3>
                            <p class="section-desc"><?php esc_html_e( 'Optimize HTML head tags, manage RSS feed sources, and clean up the sitemap to prevent unnecessary exposure of your site structure.', 'omni-webmaster-seo-suite' ); ?></p>

                            <table class="form-table omni-form-table">
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Advanced RSS Feed Control', 'omni-webmaster-seo-suite' ); ?></th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[disable_feeds]" value="1" <?php checked( '1', $settings['disable_feeds'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong><?php esc_html_e( 'Disable Non-Essential RSS Feeds', 'omni-webmaster-seo-suite' ); ?></strong>
                                                <p><?php esc_html_e( 'Only the homepage, category, and author RSS feeds are kept; all other unused feeds return a 410 response to stop crawlers from putting useless request load on your server.', 'omni-webmaster-seo-suite' ); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Clean Up HTML Head', 'omni-webmaster-seo-suite' ); ?></th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[cleanup_head]" value="1" <?php checked( '1', $settings['cleanup_head'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong><?php esc_html_e( 'Remove Redundant HTML Tags', 'omni-webmaster-seo-suite' ); ?></strong>
                                                <p><?php echo wp_kses_post( __( 'Removes redundant feed links and REST API markup from the `<head>` section, keeping your source code clean and improving privacy.', 'omni-webmaster-seo-suite' ) ); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Custom Robots Meta', 'omni-webmaster-seo-suite' ); ?></th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[robots_meta]" value="1" <?php checked( '1', $settings['robots_meta'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong><?php esc_html_e( 'Set noindex on Search, Tag, and Paginated Pages', 'omni-webmaster-seo-suite' ); ?></strong>
                                                <p><?php echo wp_kses_post( __( 'Marks tag archives, date archives, internal search pages, and deep post pagination (page 3 and beyond) as `noindex, follow` to focus crawl priority and keep the site from generating large amounts of low-quality or duplicate content.', 'omni-webmaster-seo-suite' ) ); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Clean WordPress Sitemap', 'omni-webmaster-seo-suite' ); ?></th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[clean_sitemap]" value="1" <?php checked( '1', $settings['clean_sitemap'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong><?php esc_html_e( 'Remove Tags from the Sitemap', 'omni-webmaster-seo-suite' ); ?></strong>
                                                <p><?php echo wp_kses_post( __( 'Completely removes the `post_tag` entries from the native WordPress sitemap while keeping the main post and author listings for a leaner sitemap.', 'omni-webmaster-seo-suite' ) ); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'XML-RPC Security Hardening', 'omni-webmaster-seo-suite' ); ?></th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[xmlrpc_hardening]" value="1" <?php checked( '1', $settings['xmlrpc_hardening'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong><?php esc_html_e( 'Remove All WordPress XML-RPC Methods', 'omni-webmaster-seo-suite' ); ?></strong>
                                                <p><?php echo wp_kses_post( __( 'Removes all WordPress methods from <code>xmlrpc.php</code> (<code>wp.*</code>, <code>metaWeblog.*</code>, <code>pingback.*</code>, and so on), keeping only three harmless system methods. This blocks the <strong>brute-force</strong> and pingback abuse attack surface that bypasses login page protection, and also removes the <code>X-Pingback</code> response header. It does not rely on .htaccess and is compatible with any server.', 'omni-webmaster-seo-suite' ) ); ?></p>
                                                <p style="color: #d97706;"><span class="dashicons dashicons-info-outline" style="font-size: 15px; width: 15px; height: 15px; vertical-align: text-top;"></span> <?php esc_html_e( 'If you still use Jetpack or a legacy offline editor that relies on XML-RPC, do not enable this option.', 'omni-webmaster-seo-suite' ); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'WP Embed Block Dark Mode Fix (Embed Card)', 'omni-webmaster-seo-suite' ); ?></th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[embed_styles]" value="1" <?php checked( '1', $settings['embed_styles'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong><?php esc_html_e( 'Apply Dark Background and Style Fixes', 'omni-webmaster-seo-suite' ); ?></strong>
                                                <p><?php esc_html_e( 'Applies dark styling to the iframe blocks of native WordPress post embeds, ideal for dark-background sites or dark themes.', 'omni-webmaster-seo-suite' ); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'GitHub Gist Dark Mode Fix', 'omni-webmaster-seo-suite' ); ?></th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[gist_styles]" value="1" <?php checked( '1', $settings['gist_styles'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong><?php esc_html_e( 'Force GitHub Gist Code Blocks into Dark Mode', 'omni-webmaster-seo-suite' ); ?></strong>
                                                <p><?php esc_html_e( 'Fixes the white background of JavaScript-rendered GitHub Gist table blocks so they blend into dark themes.', 'omni-webmaster-seo-suite' ); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'One-Click oEmbed Cache Clearing', 'omni-webmaster-seo-suite' ); ?></th>
                                    <td>
                                        <div class="omni-field-row" style="align-items: center;">
                                            <button type="button" id="omni-btn-clear-oembed" class="button button-secondary" style="margin-right: 15px; display: inline-flex; align-items: center; gap: 4px;">
                                                <span class="dashicons dashicons-update" style="font-size: 17px; width: 17px; height: 17px;"></span> <?php esc_html_e( 'Clear Cache Now', 'omni-webmaster-seo-suite' ); ?>
                                            </button>
                                            <div class="omni-field-desc">
                                                <strong><?php esc_html_e( 'Reset Site-Wide Embed Preview Cards', 'omni-webmaster-seo-suite' ); ?></strong>
                                                <p><?php echo wp_kses_post( __( 'If enabling "Clean Up HTML Head" previously turned your embed cards into plain links, then after <strong>turning "Clean Up HTML Head" off</strong> you need to click this button to clear the failed oEmbed cache from the database so post pages can re-fetch and restore the rich preview cards.', 'omni-webmaster-seo-suite' ) ); ?></p>
                                            </div>
                                        </div>
                                        <div id="omni-oembed-clear-result" style="margin-top: 10px; font-weight: 500; display: none;"></div>
                                    </td>
                                </tr>
                            </table>

                            <hr style="margin: 30px 0; border: 0; border-top: 1px solid #e5e7eb;" />

                            <h3><?php esc_html_e( 'Homepage Meta Tags & Structured Data', 'omni-webmaster-seo-suite' ); ?></h3>
                            <p class="section-desc"><?php esc_html_e( 'When no full-featured SEO plugin is installed, outputs a meta description, Open Graph social sharing tags, and Schema.org (WebSite / Organization) structured data on the homepage. OG tags for single posts are left to your theme; this section only affects the homepage.', 'omni-webmaster-seo-suite' ); ?></p>

                            <?php $seo_conflict = Omni_Meta_Tags::detect_seo_plugin(); ?>
                            <?php if ( '' !== $seo_conflict ) : ?>
                                <div class="omni-alert omni-alert-warning" style="margin-bottom: 15px;">
                                    <span class="dashicons dashicons-warning"></span> <?php
                                    echo wp_kses_post( sprintf(
                                        /* translators: %s: name of the detected SEO plugin. */
                                        __( 'The "%s" plugin was detected. To avoid conflicts from duplicate meta tag output, the settings in this section are kept but <strong>front-end output is automatically disabled</strong> until that plugin is deactivated.', 'omni-webmaster-seo-suite' ),
                                        esc_html( $seo_conflict )
                                    ) );
                                    ?>
                                </div>
                            <?php endif; ?>

                            <table class="form-table omni-form-table">
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Enable Homepage Meta Tags', 'omni-webmaster-seo-suite' ); ?></th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[meta_tags_enable]" value="1" <?php checked( '1', $settings['meta_tags_enable'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong><?php esc_html_e( 'Output Meta Description and Open Graph Tags on the Homepage', 'omni-webmaster-seo-suite' ); ?></strong>
                                                <p><?php echo wp_kses_post( __( 'Output only on the first page of the homepage (not repeated on paginated pages), including <code>description</code>, <code>og:title</code>, <code>og:description</code>, <code>og:url</code>, <code>og:image</code>, <code>og:locale</code>, and <code>twitter:card</code>.', 'omni-webmaster-seo-suite' ) ); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Homepage Meta Description', 'omni-webmaster-seo-suite' ); ?></th>
                                    <td>
                                        <textarea id="omni_home_meta_description"
                                                  name="<?php echo esc_attr( $this->option_name ); ?>[home_meta_description]"
                                                  rows="3"
                                                  class="large-text"
                                                  maxlength="300"
                                                  placeholder="<?php esc_attr_e( 'e.g. yBlog shares website building tutorials, software reviews, and everyday notes, offering practical guides and hands-on webmaster experience.', 'omni-webmaster-seo-suite' ); ?>"><?php echo esc_textarea( $settings['home_meta_description'] ); ?></textarea>
                                        <p class="description"><?php esc_html_e( 'Recommended length is 90 to 160 characters; longer descriptions get truncated in search results. Current count:', 'omni-webmaster-seo-suite' ); ?> <strong id="omni-meta-desc-count">0</strong></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Default Social Share Image (og:image)', 'omni-webmaster-seo-suite' ); ?></th>
                                    <td>
                                        <div style="display: flex; gap: 8px; align-items: flex-start; flex-wrap: wrap;">
                                            <input type="url"
                                                   id="omni_og_default_image"
                                                   name="<?php echo esc_attr( $this->option_name ); ?>[og_default_image]"
                                                   value="<?php echo esc_url( $settings['og_default_image'] ); ?>"
                                                   class="regular-text"
                                                   placeholder="https://example.com/og-image.jpg" />
                                            <button type="button" id="omni-btn-select-og-image" class="button">
                                                <span class="dashicons dashicons-format-image" style="vertical-align: text-bottom;"></span> <?php esc_html_e( 'Select from Media Library', 'omni-webmaster-seo-suite' ); ?>
                                            </button>
                                        </div>
                                        <p class="description"><?php echo wp_kses_post( __( 'The preview image shown when your homepage is shared on Facebook, LINE, or Telegram. Recommended size: <strong>1200 × 630</strong> pixels. If left empty, social platforms will pick an image on their own (possibly an author avatar or a random image).', 'omni-webmaster-seo-suite' ) ); ?></p>
                                        <?php if ( ! empty( $settings['og_default_image'] ) ) : ?>
                                            <img id="omni-og-image-preview" src="<?php echo esc_url( $settings['og_default_image'] ); ?>" alt="" style="margin-top: 10px; max-width: 300px; height: auto; border-radius: 8px; border: 1px solid #e5e7eb;" />
                                        <?php else : ?>
                                            <img id="omni-og-image-preview" src="" alt="" style="display: none; margin-top: 10px; max-width: 300px; height: auto; border-radius: 8px; border: 1px solid #e5e7eb;" />
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Schema.org Structured Data', 'omni-webmaster-seo-suite' ); ?></th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[schema_website_enable]" value="1" <?php checked( '1', $settings['schema_website_enable'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong><?php esc_html_e( 'Output WebSite and Organization JSON-LD', 'omni-webmaster-seo-suite' ); ?></strong>
                                                <p><?php echo wp_kses_post( __( 'Helps Google display the correct <strong>site name</strong> (instead of the domain name) and site logo in search results (the Site Icon is used first, falling back to the share image above when not set). Requires "Enable Homepage Meta Tags" above to be turned on as well.', 'omni-webmaster-seo-suite' ) ); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Site Name Abbreviation (alternateName)', 'omni-webmaster-seo-suite' ); ?></th>
                                    <td>
                                        <input type="text"
                                               name="<?php echo esc_attr( $this->option_name ); ?>[site_alternate_name]"
                                               value="<?php echo esc_attr( $settings['site_alternate_name'] ); ?>"
                                               class="regular-text"
                                               placeholder="<?php esc_attr_e( 'e.g. yBlog', 'omni-webmaster-seo-suite' ); ?>" />
                                        <p class="description"><?php esc_html_e( '(Optional) A common short name for your site, provided to Google as an alternate identifier for the site name.', 'omni-webmaster-seo-suite' ); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Tab 2: Comments Control -->
                    <div class="omni-tab-panel <?php echo $active_tab === 'comments' ? 'is-active' : ''; ?>">
                        <div class="omni-tab-content">
                            <h3><?php esc_html_e( 'Completely Disable Comments', 'omni-webmaster-seo-suite' ); ?></h3>
                            <p class="section-desc"><?php esc_html_e( 'Block every WordPress comment entry point with a single switch, simplifying maintenance for non-community sites while preventing comment spam and improving security.', 'omni-webmaster-seo-suite' ); ?></p>

                            <table class="form-table omni-form-table">
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Global Comment Blocking Switch', 'omni-webmaster-seo-suite' ); ?></th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[disable_comments]" value="1" <?php checked( '1', $settings['disable_comments'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong><?php esc_html_e( 'Completely Turn Off Site Comments', 'omni-webmaster-seo-suite' ); ?></strong>
                                                <p><?php esc_html_e( 'When enabled, this turns off comment and trackback support for all post types, hides existing comments, closes comments on new posts, and hides the admin comments menu and the dashboard comments widget.', 'omni-webmaster-seo-suite' ); ?></p>
                                            </div>
                                        </div>
                                        <?php if ( ! empty( $settings['disable_comments'] ) ) : ?>
                                            <div class="omni-alert omni-alert-success" style="margin-top: 15px;">
                                                <span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Comments are now fully locked down across the entire site.', 'omni-webmaster-seo-suite' ); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Tab 3: Media & Thumbnails -->
                    <div class="omni-tab-panel <?php echo $active_tab === 'thumbnails' ? 'is-active' : ''; ?>">
                        <div class="omni-tab-content">
                            <h3><?php esc_html_e( 'Media & Thumbnail Generation Optimization', 'omni-webmaster-seo-suite' ); ?></h3>
                            <p class="section-desc"><?php esc_html_e( 'Disable specific thumbnail sizes to keep a single upload from generating dozens of unused copies that eat up your hosting disk space.', 'omni-webmaster-seo-suite' ); ?></p>

                            <table class="form-table omni-form-table">
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Select Thumbnail Sizes to Disable', 'omni-webmaster-seo-suite' ); ?></th>
                                    <td>
                                        <p class="description" style="margin-bottom: 15px;"><?php echo wp_kses_post( __( 'Check a thumbnail size below and newly uploaded images will <strong>no longer</strong> generate physical thumbnails at that size:', 'omni-webmaster-seo-suite' ) ); ?></p>
                                        <div class="omni-sizes-grid">
                                            <?php
                                            $all_sizes = $this->disable_thumbnails->get_all_image_sizes();
                                            foreach ( $all_sizes as $size_key => $size_data ) :
                                                $is_disabled = in_array( $size_key, $settings['disabled_sizes'], true );
                                                ?>
                                                <div class="omni-size-card <?php echo $is_disabled ? 'is-checked' : ''; ?>">
                                                    <div class="omni-size-card-header">
                                                        <strong><?php echo esc_html( $size_data['name'] ); ?></strong>
                                                        <code class="omni-size-key"><?php echo esc_html( $size_key ); ?></code>
                                                    </div>
                                                    <div class="omni-size-card-body">
                                                        <?php if ( isset( $size_data['width'] ) && isset( $size_data['height'] ) ) : ?>
                                                            <span class="omni-dimensions">
                                                                <span class="dashicons dashicons-format-image"></span>
                                                                <?php echo esc_html( $size_data['width'] ) . ' &times; ' . esc_html( $size_data['height'] ); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <label class="omni-checkbox-label">
                                                            <input type="checkbox"
                                                                   name="<?php echo esc_attr( $this->option_name ); ?>[disabled_sizes][<?php echo esc_attr( $size_key ); ?>]"
                                                                   value="1"
                                                                   <?php checked( true, $is_disabled ); ?> />
                                                            <span><?php esc_html_e( 'Disable Generation', 'omni-webmaster-seo-suite' ); ?></span>
                                                        </label>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <hr style="margin: 30px 0; border: 0; border-top: 1px solid #e5e7eb;" />

                            <h3><?php esc_html_e( 'Batch Clean Existing Thumbnails', 'omni-webmaster-seo-suite' ); ?></h3>
                            <p class="section-desc"><?php esc_html_e( 'Disabled thumbnail settings only affect newly uploaded images. To free up disk space, use the tool below to batch delete thumbnail files already generated for existing images.', 'omni-webmaster-seo-suite' ); ?></p>

                            <div class="omni-cleanup-card">
                                <h4><?php esc_html_e( 'Choose Cleanup Scope and Mode', 'omni-webmaster-seo-suite' ); ?></h4>
                                <p><?php esc_html_e( 'This scans every image attachment in the media library and removes the corresponding thumbnail files (original full-size images are kept). This is a destructive action; back up your site first.', 'omni-webmaster-seo-suite' ); ?></p>

                                <div class="omni-delete-mode-selector" style="margin-bottom: 20px;">
                                    <label class="omni-radio-label" style="display: block; margin-bottom: 12px; font-weight: 500;">
                                        <input type="radio" name="omni_delete_mode" value="disabled_only" checked />
                                        <span class="omni-radio-text"><strong><?php esc_html_e( 'Only clean the thumbnail sizes checked as disabled (recommended)', 'omni-webmaster-seo-suite' ); ?></strong></span>
                                        <p class="description" style="margin-left: 24px; margin-top: 2px;"><?php esc_html_e( 'Safe mode: only deletes the sizes you checked as disabled above, keeping every other thumbnail size intact to avoid broken images on the front end.', 'omni-webmaster-seo-suite' ); ?></p>
                                    </label>
                                    <label class="omni-radio-label" style="display: block; font-weight: 500;">
                                        <input type="radio" name="omni_delete_mode" value="all" />
                                        <span class="omni-radio-text" style="color: #dc2626;"><strong><?php esc_html_e( 'Clean thumbnails of all sizes (not recommended)', 'omni-webmaster-seo-suite' ); ?></strong></span>
                                        <p class="description" style="margin-left: 24px; margin-top: 2px;"><?php esc_html_e( 'Full cleanup: deletes every thumbnail of each image (e.g. 150x150, 300x300), which may cause 404 broken images on the front end or slow pages that have to load the original image.', 'omni-webmaster-seo-suite' ); ?></p>
                                    </label>
                                </div>

                                <div class="omni-cleanup-actions">
                                    <button type="button" id="omni-btn-delete-thumbs" class="button button-secondary">
                                        <span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Start Batch Cleanup', 'omni-webmaster-seo-suite' ); ?>
                                    </button>
                                </div>

                                <div id="omni-cleanup-progress-wrapper" class="omni-progress-wrapper" style="display:none;">
                                    <div class="omni-progress-bar-container">
                                        <div id="omni-cleanup-progress-fill" class="omni-progress-bar-fill" style="width: 0%;"></div>
                                    </div>
                                    <div class="omni-progress-meta">
                                        <span id="omni-cleanup-status-text"><?php esc_html_e( 'Preparing cleanup...', 'omni-webmaster-seo-suite' ); ?></span>
                                        <span id="omni-cleanup-percentage">0%</span>
                                    </div>
                                    <div id="omni-cleanup-log" class="omni-log-console"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab 4: Slug Converter -->
                    <div class="omni-tab-panel <?php echo $active_tab === 'slug' ? 'is-active' : ''; ?>">
                        <div class="omni-tab-content">
                            <h3><?php esc_html_e( 'Automatic Slug Translation', 'omni-webmaster-seo-suite' ); ?></h3>
                            <p class="section-desc"><?php esc_html_e( 'When you publish or save a post with an Asian-language title (Chinese, Japanese, Korean, Thai, etc.), the title is automatically translated into a clean English URL slug via the Google Translate API, improving SEO friendliness and preventing garbled characters when non-Latin URLs are copied.', 'omni-webmaster-seo-suite' ); ?></p>

                            <table class="form-table omni-form-table">
                                <tr>
                                    <th scope="row">Google Cloud Translation API Key</th>
                                    <td>
                                        <input type="text"
                                               id="omni_slug_api_key"
                                               name="<?php echo esc_attr( $this->option_name ); ?>[slug_api_key]"
                                               value="<?php echo esc_attr( $settings['slug_api_key'] ); ?>"
                                               class="regular-text omni-input-key"
                                               placeholder="<?php esc_attr_e( '(Optional) AIzaSy...', 'omni-webmaster-seo-suite' ); ?>" />
                                        <p class="description"><?php
                                        echo wp_kses_post( sprintf(
                                            /* translators: %s: URL of the Google Cloud Translation API setup guide */
                                            __( 'Enter a Cloud Translation API key created in the Google Cloud console (<a href="%s" target="_blank" rel="noopener noreferrer">how to get an API key</a>).<br/><strong>Fallback (no key required)</strong>: leave this field empty and the plugin automatically falls back to the keyless public Google Translate endpoint.<br/><strong>An API key is recommended</strong>: the keyless endpoint often produces lower-quality slugs (e.g. <code>how-was-it-able-9</code>), while the official Cloud API returns noticeably more accurate translations and includes a monthly free quota.', 'omni-webmaster-seo-suite' ),
                                            esc_url( 'https://cloud.google.com/translate/docs/setup' )
                                        ) );
                                        ?></p>

                                        <div class="omni-api-tester" style="margin-top: 15px;">
                                            <button type="button" id="omni-btn-test-api" class="button">
                                                <?php esc_html_e( 'Test API Key Connection', 'omni-webmaster-seo-suite' ); ?>
                                            </button>
                                            <span id="omni-test-api-result" class="omni-test-result"></span>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Maximum Slug Length', 'omni-webmaster-seo-suite' ); ?></th>
                                    <td>
                                        <input type="number"
                                               name="<?php echo esc_attr( $this->option_name ); ?>[slug_max_length]"
                                               value="<?php echo esc_attr( $settings['slug_max_length'] ); ?>"
                                               class="small-text"
                                               min="20"
                                               max="200" />
                                        <p class="description"><?php esc_html_e( 'Maximum number of characters for generated English slugs (30 to 50 recommended). The system automatically reserves 12 characters for appending the post ID as duplicate protection.', 'omni-webmaster-seo-suite' ); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Tab: Meta Pixel -->
                    <div class="omni-tab-panel <?php echo $active_tab === 'pixel' ? 'is-active' : ''; ?>">
                        <div class="omni-tab-content">
                            <h3><?php esc_html_e( 'Meta Pixel Conversion Tracking', 'omni-webmaster-seo-suite' ); ?></h3>
                            <p class="section-desc"><?php esc_html_e( 'Integrates the Meta (Facebook) Pixel tracking code, automatically loading the base PageView event plus advanced marketing tracking events on the front end to improve ad conversion rates and audience optimization accuracy.', 'omni-webmaster-seo-suite' ); ?></p>

                            <table class="form-table omni-form-table">
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Enable Meta Pixel Tracking', 'omni-webmaster-seo-suite' ); ?></th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[meta_pixel_enable]" value="1" <?php checked( '1', $settings['meta_pixel_enable'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong><?php esc_html_e( 'Load the Meta Pixel Tracking Code on the Front End', 'omni-webmaster-seo-suite' ); ?></strong>
                                                <p><?php echo wp_kses_post( __( 'When enabled, the Meta Pixel tracking script is inserted into the `<head>` section of every public front-end page and the base <code>PageView</code> event fires automatically.', 'omni-webmaster-seo-suite' ) ); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Meta Pixel ID</th>
                                    <td>
                                        <input type="text"
                                               name="<?php echo esc_attr( $this->option_name ); ?>[meta_pixel_id]"
                                               value="<?php echo esc_attr( $settings['meta_pixel_id'] ); ?>"
                                               class="regular-text"
                                               placeholder="<?php esc_attr_e( 'e.g. 123456789012345', 'omni-webmaster-seo-suite' ); ?>"
                                               pattern="[0-9]*"
                                               inputmode="numeric"
                                               title="<?php esc_attr_e( 'Enter a numbers-only Meta Pixel ID', 'omni-webmaster-seo-suite' ); ?>" />
                                        <p class="description"><?php esc_html_e( 'Enter your Meta (Facebook) Pixel ID (numbers only). You can find it on the settings page of Facebook Events Manager.', 'omni-webmaster-seo-suite' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Enable Advanced Event Tracking', 'omni-webmaster-seo-suite' ); ?></th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[meta_pixel_advanced]" value="1" <?php checked( '1', $settings['meta_pixel_advanced'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong><?php esc_html_e( 'Automatically Track Single Content Views and Search Behavior', 'omni-webmaster-seo-suite' ); ?></strong>
                                                <p><?php echo wp_kses_post( __( 'In addition to the base <code>PageView</code>, the system automatically sends the following standard events on the appropriate pages for advanced ad audience optimization:', 'omni-webmaster-seo-suite' ) ); ?></p>
                                                <ul style="list-style-type: disc; margin-left: 20px; margin-top: 6px; color: #6b7280; line-height: 1.5;">
                                                    <li><?php echo wp_kses_post( __( '<strong>Single post/page (ViewContent)</strong>: sent when a visitor views a single post or page, including the post title, all category names, post ID, and post type.', 'omni-webmaster-seo-suite' ) ); ?></li>
                                                    <li><?php echo wp_kses_post( __( '<strong>Search results (Search)</strong>: sent when a visitor performs an internal site search, including the search keyword the visitor entered.', 'omni-webmaster-seo-suite' ) ); ?></li>
                                                </ul>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Exclude Site Staff', 'omni-webmaster-seo-suite' ); ?></th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[meta_pixel_exclude_admins]" value="1" <?php checked( '1', $settings['meta_pixel_exclude_admins'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong><?php esc_html_e( 'Do Not Track Logged-In Administrators and Editors', 'omni-webmaster-seo-suite' ); ?></strong>
                                                <p><?php echo wp_kses_post( __( 'When enabled, logged-in users with post editing capability (<code>edit_posts</code>) will not load the Pixel tracking code when browsing the front end, keeping staff browsing behavior from polluting your ad audiences and conversion data (recommended to keep on).', 'omni-webmaster-seo-suite' ) ); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Tab 5: Data Export -->
                    <div class="omni-tab-panel <?php echo $active_tab === 'export' ? 'is-active' : ''; ?>">
                        <div class="omni-tab-content">
                            <h3><?php esc_html_e( 'Monthly Post Data Export', 'omni-webmaster-seo-suite' ); ?></h3>
                            <p class="section-desc"><?php esc_html_e( 'Filter published posts by month and export their dates, titles, category and tag topics, permalinks, and view count statistics.', 'omni-webmaster-seo-suite' ); ?></p>

                            <div class="omni-export-panel-box" style="background: #f9fafb; border: 1.5px solid #f3f4f6; border-radius: 12px; padding: 24px; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.02);">
                                <div class="omni-export-form-row" style="display: flex; gap: 30px; flex-wrap: wrap; margin-bottom: 20px;">
                                    <div class="omni-export-field" style="display: flex; flex-direction: column; gap: 8px;">
                                        <label for="omni_export_month" style="font-weight: 600; color: #374151;"><strong><?php esc_html_e( 'Select Export Month', 'omni-webmaster-seo-suite' ); ?></strong></label>
                                        <select id="omni_export_month" style="padding: 6px 12px; border-radius: 6px; border: 1.5px solid #d1d5db; min-width: 180px; font-family: inherit;">
                                            <?php
                                            global $wpdb;
                                            // Query the years and months that have published posts, cached via the WordPress Object Cache
                                            $months_query = wp_cache_get( 'omni_export_months_query', 'omni-webmaster-seo' );
                                            if ( false === $months_query ) {
                                                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                                                $months_query = $wpdb->get_results( "
                                                    SELECT DISTINCT YEAR(post_date) AS year, MONTH(post_date) AS month
                                                    FROM $wpdb->posts
                                                    WHERE post_type = 'post' AND post_status = 'publish'
                                                    ORDER BY post_date DESC
                                                " );
                                                wp_cache_set( 'omni_export_months_query', $months_query, 'omni-webmaster-seo', HOUR_IN_SECONDS );
                                            }

                                            if ( ! empty( $months_query ) ) {
                                                foreach ( $months_query as $m ) {
                                                    $val = sprintf( '%04d/%02d', $m->year, $m->month );
                                                    $label = sprintf(
                                                        /* translators: 1: four-digit year, 2: zero-padded month number. */
                                                        __( '%1$d-%2$02d', 'omni-webmaster-seo-suite' ),
                                                        $m->year,
                                                        $m->month
                                                    );
                                                    echo '<option value="' . esc_attr( $val ) . '">' . esc_html( $label ) . '</option>';
                                                }
                                            } else {
                                                echo '<option value="">' . esc_html__( 'No published posts', 'omni-webmaster-seo-suite' ) . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>

                                    <div class="omni-export-field" style="display: flex; flex-direction: column; gap: 8px;">
                                        <label for="omni_export_views_meta" style="font-weight: 600; color: #374151;"><strong><?php esc_html_e( 'View Count Custom Field (Meta Key)', 'omni-webmaster-seo-suite' ); ?></strong></label>
                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                            <input type="text" id="omni_export_views_meta" name="<?php echo esc_attr( $this->option_name ); ?>[views_meta_key]" value="<?php echo esc_attr( $settings['views_meta_key'] ); ?>" class="regular-text" style="width: 150px; padding: 6px 12px; border-radius: 6px; border: 1.5px solid #d1d5db;" placeholder="views" />
                                            <span class="description" style="font-size: 11px; color: #6b7280;"><?php echo wp_kses_post( __( 'Defaults to <code>views</code> (used by WP-PostViews). Enter the view count custom field name (meta key) used on your site.', 'omni-webmaster-seo-suite' ) ); ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="omni-export-actions" style="display: flex; gap: 12px; border-top: 1px solid #e5e7eb; padding-top: 20px;">
                                    <button type="button" id="omni-btn-preview-export" class="button" style="background: #0f766e !important; border-color: #0d9488 !important; color: #fff !important; font-weight: 500; padding: 4px 16px 6px; height: auto; border-radius: 6px;">
                                        <span class="dashicons dashicons-visibility" style="vertical-align: middle; margin-right: 4px;"></span> <?php esc_html_e( 'Preview Data', 'omni-webmaster-seo-suite' ); ?>
                                    </button>
                                    <button type="button" id="omni-btn-download-csv" class="button button-secondary" style="font-weight: 500; padding: 4px 16px 6px; height: auto; border-radius: 6px;">
                                        <span class="dashicons dashicons-download" style="vertical-align: middle; margin-right: 4px;"></span> <?php esc_html_e( 'Download CSV File (Excel)', 'omni-webmaster-seo-suite' ); ?>
                                    </button>
                                    <button type="button" id="omni-btn-copy-clipboard" class="button button-secondary" style="font-weight: 500; padding: 4px 16px 6px; height: auto; border-radius: 6px;" disabled>
                                        <span class="dashicons dashicons-clipboard" style="vertical-align: middle; margin-right: 4px;"></span> <?php esc_html_e( 'Copy to Clipboard (Paste into Sheets/Excel)', 'omni-webmaster-seo-suite' ); ?>
                                    </button>
                                </div>
                            </div>

                            <!-- Preview table and status -->
                            <div class="omni-export-preview-container" style="margin-top: 30px; display: none;">
                                <h4 style="margin-bottom: 12px; font-weight: 600; color: #111827; font-size: 15px;"><?php esc_html_e( 'Data Preview', 'omni-webmaster-seo-suite' ); ?></h4>
                                <div class="omni-export-table-wrapper" style="overflow-x: auto; background: #ffffff; border: 1.5px solid #e5e7eb; border-radius: 10px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
                                    <table class="wp-list-table widefat fixed striped posts" id="omni-export-preview-table" style="width: 100%; border-collapse: collapse; text-align: left;">
                                        <thead>
                                            <tr style="background: #f9fafb; border-bottom: 1.5px solid #e5e7eb;">
                                                <th style="width: 130px; font-weight: 600; padding: 12px 16px; color: #374151;"><?php esc_html_e( 'Date', 'omni-webmaster-seo-suite' ); ?></th>
                                                <th style="font-weight: 600; padding: 12px 16px; color: #374151;"><?php esc_html_e( 'Post Title', 'omni-webmaster-seo-suite' ); ?></th>
                                                <th style="width: 220px; font-weight: 600; padding: 12px 16px; color: #374151;"><?php esc_html_e( 'Angle / Topics', 'omni-webmaster-seo-suite' ); ?></th>
                                                <th style="font-weight: 600; padding: 12px 16px; color: #374151;"><?php esc_html_e( 'Link', 'omni-webmaster-seo-suite' ); ?></th>
                                                <th style="width: 90px; font-weight: 600; padding: 12px 16px; color: #374151; text-align: right;"><?php esc_html_e( 'Views', 'omni-webmaster-seo-suite' ); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!-- Loaded via AJAX -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div id="omni-export-status-message" style="margin-top: 20px; display: none; padding: 12px 16px; border-radius: 8px;"></div>
                        </div>
                    </div>

                    <div class="omni-submit-section">
                        <?php submit_button( __( 'Save Changes', 'omni-webmaster-seo-suite' ), 'primary', 'submit', false, [ 'id' => 'omni-submit-btn' ] ); ?>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX preview of post data
     */
    public function preview_posts_data() {
        check_ajax_referer( 'omni_export_posts_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'omni-webmaster-seo-suite' ) );
        }

        $month_str = isset( $_POST['export_month'] ) ? sanitize_text_field( wp_unslash( $_POST['export_month'] ) ) : ''; // e.g. "2026/05"
        $views_meta_key = isset( $_POST['views_meta_key'] ) ? sanitize_key( wp_unslash( $_POST['views_meta_key'] ) ) : 'views';

        if ( empty( $month_str ) ) {
            wp_send_json_error( __( 'No month specified.', 'omni-webmaster-seo-suite' ) );
        }

        $parts = explode( '/', $month_str );
        if ( count( $parts ) !== 2 ) {
            wp_send_json_error( __( 'Invalid month format.', 'omni-webmaster-seo-suite' ) );
        }

        $year  = intval( $parts[0] );
        $month = intval( $parts[1] );

        $posts_data = $this->query_posts_by_month( $year, $month, $views_meta_key );

        wp_send_json_success( $posts_data );
    }

    /**
     * Download the CSV file (compatible with Windows Excel non-Latin encoding)
     */
    public function export_posts_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'omni-webmaster-seo-suite' ) );
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'omni_export_posts_nonce' ) ) {
            wp_die( esc_html__( 'Security check failed. Please try again.', 'omni-webmaster-seo-suite' ) );
        }

        $month_str = isset( $_GET['export_month'] ) ? sanitize_text_field( wp_unslash( $_GET['export_month'] ) ) : ''; // e.g. "2026/05"
        $views_meta_key = isset( $_GET['views_meta_key'] ) ? sanitize_key( wp_unslash( $_GET['views_meta_key'] ) ) : 'views';

        if ( empty( $month_str ) ) {
            wp_die( esc_html__( 'Please specify a month to export.', 'omni-webmaster-seo-suite' ) );
        }

        $parts = explode( '/', $month_str );
        if ( count( $parts ) !== 2 ) {
            wp_die( esc_html__( 'Invalid month format.', 'omni-webmaster-seo-suite' ) );
        }

        $year  = intval( $parts[0] );
        $month = intval( $parts[1] );

        $posts_data = $this->query_posts_by_month( $year, $month, $views_meta_key );

        // Set download headers
        $filename = 'posts-export-' . str_replace( '/', '-', $month_str ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . esc_attr( $filename ) . '"' );

        // Write a UTF-8 BOM so Excel does not garble non-Latin characters
        echo "\xEF\xBB\xBF";

        $output = fopen( 'php://output', 'w' );

        // Write the column headers
        fputcsv( $output, [
            __( 'Date', 'omni-webmaster-seo-suite' ),
            __( 'Post Title', 'omni-webmaster-seo-suite' ),
            __( 'Angle / Topics', 'omni-webmaster-seo-suite' ),
            __( 'Link', 'omni-webmaster-seo-suite' ),
            __( 'Views', 'omni-webmaster-seo-suite' ),
        ] );

        // Write the rows
        foreach ( $posts_data as $row ) {
            fputcsv( $output, [
                $row['date'],
                $row['title'],
                $row['topics'],
                $row['link'],
                $row['views'],
            ] );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $output );
        exit;
    }

    /**
     * Query posts with view counts and related data for a given year and month
     */
    private function query_posts_by_month( $year, $month, $views_meta_key = 'views' ) {
        $args = [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'date_query'     => [
                [
                    'year'  => $year,
                    'month' => $month,
                ],
            ],
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $query = new WP_Query( $args );
        $posts_data = [];

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $post_id = get_the_ID();

                // 1. Date in Y/m/d format
                $date = get_the_date( 'Y/m/d', $post_id );

                // 2. Title
                $title = get_the_title( $post_id );

                // 3. Angle/topics (categories and tags merged)
                $categories = get_the_category( $post_id );
                $tags = get_the_tags( $post_id );
                $topics = [];

                if ( ! empty( $categories ) ) {
                    foreach ( $categories as $cat ) {
                        if ( 'uncategorized' !== $cat->slug || count( $categories ) === 1 ) {
                            $topics[] = $cat->name;
                        }
                    }
                }
                if ( ! empty( $tags ) ) {
                    foreach ( $tags as $tag ) {
                        $topics[] = $tag->name;
                    }
                }
                $topics_str = implode(
                    /* translators: separator between topic/category names in the export. */
                    __( ', ', 'omni-webmaster-seo-suite' ),
                    $topics
                );

                // 4. Permalink
                $link = get_permalink( $post_id );

                // 5. View count
                $views = 0;
                $is_jnews_key = ( stripos( $views_meta_key, 'jnews' ) !== false );

                if ( $is_jnews_key ) {
                    if ( function_exists( 'jnews_get_views' ) ) {
                        $views = intval( jnews_get_views( $post_id, 'all', false ) );
                    } else {
                        // Fallback to direct DB query if JNews function is not loaded
                        global $wpdb;
                        $table_name = $wpdb->prefix . 'popularpostsdata';

                        // Cache the table-existence check for performance and Plugin Check compliance
                        $table_exists = wp_cache_get( 'omni_popularpostsdata_exists', 'omni-webmaster-seo' );
                        if ( false === $table_exists ) {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                            $show_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
                            $table_exists = ( $show_table === $table_name ) ? 1 : 0;
                            wp_cache_set( 'omni_popularpostsdata_exists', $table_exists, 'omni-webmaster-seo', HOUR_IN_SECONDS );
                        }

                        if ( 1 === $table_exists ) {
                            $cache_key = 'omni_post_views_' . $post_id;
                            $cached_views = wp_cache_get( $cache_key, 'omni-webmaster-seo' );
                            if ( false === $cached_views ) {
                                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                                $db_views = $wpdb->get_var(
                                    $wpdb->prepare(
                                        "SELECT pageviews FROM `{$table_name}` WHERE postid = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                                        $post_id
                                    )
                                );
                                $cached_views = is_null( $db_views ) ? 0 : intval( $db_views );
                                wp_cache_set( $cache_key, $cached_views, 'omni-webmaster-seo', MINUTE_IN_SECONDS * 10 );
                            }
                            $views = $cached_views;
                        }
                    }
                } else {
                    $views = get_post_meta( $post_id, $views_meta_key, true );
                    if ( '' === $views ) {
                        $views = 0;
                    } else {
                        $views = intval( $views );
                    }
                }

                $posts_data[] = [
                    'date'   => $date,
                    'title'  => $title,
                    'topics' => $topics_str,
                    'link'   => $link,
                    'views'  => $views,
                ];
            }
            wp_reset_postdata();
        }

        return $posts_data;
    }

    /**
     * Clear the site-wide oEmbed cache
     */
    public function clear_oembed_cache() {
        check_ajax_referer( 'omni_export_posts_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'omni-webmaster-seo-suite' ) );
        }

        $deleted = $this->purge_oembed_cache();

        if ( false === $deleted ) {
            wp_send_json_error( __( 'Cache clearing failed due to a database query error.', 'omni-webmaster-seo-suite' ) );
        } else {
            wp_send_json_success(
                sprintf(
                    /* translators: %d: number of deleted cache records. */
                    __( 'Site-wide oEmbed cache cleared successfully (%d cache records deleted). Refresh your pages to see the result.', 'omni-webmaster-seo-suite' ),
                    $deleted
                )
            );
        }
    }

    /**
     * When settings change, automatically purge the failed oEmbed cache if embed rendering
     * is affected (Clean Up HTML Head / embed styles)
     *
     * @param mixed $old_value Settings before the change
     * @param mixed $new_value Settings after the change
     */
    public function maybe_purge_oembed_on_change( $old_value, $new_value ) {
        $old_value = is_array( $old_value ) ? $old_value : [];
        $new_value = is_array( $new_value ) ? $new_value : [];

        $watch_keys = [ 'cleanup_head', 'embed_styles' ];
        foreach ( $watch_keys as $key ) {
            $old = isset( $old_value[ $key ] ) ? $old_value[ $key ] : null;
            $new = isset( $new_value[ $key ] ) ? $new_value[ $key ] : null;
            if ( $old !== $new ) {
                $this->purge_oembed_cache();
                return;
            }
        }
    }

    /**
     * Actually delete all site-wide _oembed_* postmeta cache entries and flush the object cache
     * to force re-fetching.
     *
     * @return int|false Number of deleted rows, or false on failure.
     */
    private function purge_oembed_cache() {
        global $wpdb;

        // esc_like escapes the underscore (_) in the meta_key prefix so the LIKE
        // single-character wildcard does not cause false matches.
        $like = $wpdb->esc_like( '_oembed_' ) . '%';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->query(
            $wpdb->prepare( "DELETE FROM $wpdb->postmeta WHERE meta_key LIKE %s", $like )
        );

        // A direct SQL delete does not update the persistent object cache, so flush it
        // to avoid reading stale {{unknown}} failure values.
        if ( false !== $deleted ) {
            wp_cache_flush();
        }

        return $deleted;
    }
}
