<?php
/**
 * Omni_SEO_Cleanup Class
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Omni_SEO_Cleanup {

    public function __construct() {
        $settings = $this->get_settings();

        // 1. Advanced RSS feed control
        if ( ! empty( $settings['disable_feeds'] ) ) {
            $feed_types = [
                'do_feed', 'do_feed_rdf', 'do_feed_rss',
                'do_feed_rss2', 'do_feed_atom',
                'do_feed_rss2_comment', 'do_feed_atom_comment'
            ];
            foreach ( $feed_types as $feed ) {
                add_action( $feed, [ $this, 'advanced_selective_disable_feeds' ], 1 );
            }
        }

        // 2. Clean up HTML head
        if ( ! empty( $settings['cleanup_head'] ) ) {
            add_action( 'init', [ $this, 'cleanup_head_tags' ] );
        }

        // 3. Custom robots meta
        if ( ! empty( $settings['robots_meta'] ) ) {
            add_filter( 'wp_robots', [ $this, 'custom_seo_robots_meta' ] );
        }

        // 4. Clean up Sitemap
        if ( ! empty( $settings['clean_sitemap'] ) ) {
            add_filter( 'wp_sitemaps_taxonomies', [ $this, 'clean_sitemap_taxonomies' ] );
        }

        // 5. WP embed block styles (Embed Card)
        if ( ! empty( $settings['embed_styles'] ) ) {
            add_action( 'enqueue_embed_scripts', [ $this, 'enqueue_embed_styles' ] );
        }

        // 6. GitHub Gist style fixes
        if ( ! empty( $settings['gist_styles'] ) ) {
            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_gist_styles' ] );
        }

        // 7. XML-RPC security hardening
        if ( ! empty( $settings['xmlrpc_hardening'] ) ) {
            // priority 999: run after other plugins register so the filtered result is the final list
            add_filter( 'xmlrpc_methods', [ $this, 'harden_xmlrpc_methods' ], 999 );
            add_filter( 'wp_headers', [ $this, 'remove_x_pingback_header' ] );
        }
    }

    /**
     * Get settings (safe SEO features enabled by default)
     */
    private function get_settings() {
        $defaults = [
            'disable_feeds' => '1',
            'cleanup_head'  => '1',
            'robots_meta'   => '1',
            'clean_sitemap' => '1',
            'embed_styles'     => '0',
            'gist_styles'      => '0',
            'xmlrpc_hardening' => '0',
        ];
        return wp_parse_args( get_option( 'omni_webmaster_settings', [] ), $defaults );
    }

    /**
     * Block non-essential feeds (only the main post, category, and author feeds are allowed; everything else returns HTTP 410)
     */
    public function advanced_selective_disable_feeds() {
        if ( is_feed() ) {
            // 1. Allow category feeds and author feeds
            if ( is_category() || is_author() ) {
                return;
            }

            // 2. Allow the homepage/main post feed
            if ( is_home() || is_front_page() ) {
                return;
            }

            // 3. Keep the standard feed (e.g. /feed/) working:
            // If it is not a comment feed, not a single post/page feed, not a tag feed, not a search feed, not a custom taxonomy feed, not a custom post type archive feed, not an attachment feed, and not a date archive feed,
            // treat it as the site's main feed and allow it through.
            if ( ! is_comment_feed() && ! is_singular() && ! is_tag() && ! is_search() && ! is_tax() && ! is_post_type_archive() && ! is_attachment() && ! is_date() ) {
                return;
            }
        }

        status_header( 410 );
        header( 'Content-Type: text/plain; charset=UTF-8' );
        echo 'This specific RSS feed has been permanently disabled to streamline SEO structure.';
        exit;
    }

    /**
     * Remove redundant HTML head tags and unnecessary resource loading (e.g. Emoji)
     */
    public function cleanup_head_tags() {
        $settings = $this->get_settings();

        // Remove feed links (when feeds are disabled)
        if ( ! empty( $settings['disable_feeds'] ) ) {
            remove_action( 'wp_head', 'feed_links', 2 );
            remove_action( 'wp_head', 'feed_links_extra', 3 );
        }

        // Remove RSD (Really Simple Discovery)
        remove_action( 'wp_head', 'rsd_link' );

        // Remove Windows Live Writer link
        remove_action( 'wp_head', 'wlwmanifest_link' );

        // Remove WordPress version info
        remove_action( 'wp_head', 'wp_generator' );

        // Remove shortlink and adjacent post links
        remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
        remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );

        // Remove REST API links
        remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
        remove_action( 'template_redirect', 'rest_output_link_header', 11 );

        // Disable Emoji loading (front-end performance optimization)
        remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
        remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
        remove_action( 'wp_print_styles', 'print_emoji_styles' );
        remove_action( 'admin_print_styles', 'print_emoji_styles' );
        remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
        remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
        remove_filter( 'wp_mail', 'wp_staticize_emoji' );
        add_filter( 'tiny_mce_plugins', [ $this, 'disable_emojis_tinymce' ] );
        add_filter( 'wp_resource_hints', [ $this, 'disable_emojis_dns_prefetch' ], 10, 2 );
    }

    /**
     * Disable the Emoji plugin in TinyMCE
     */
    public function disable_emojis_tinymce( $plugins ) {
        if ( is_array( $plugins ) ) {
            return array_diff( $plugins, [ 'wpemoji' ] );
        }
        return [];
    }

    /**
     * Remove Emoji-related DNS prefetch hints
     */
    public function disable_emojis_dns_prefetch( $urls, $relation_type ) {
        if ( 'dns-prefetch' === $relation_type ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
            $emoji_svg_url = apply_filters( 'emoji_svg_url', 'https://s.w.org/images/core/emoji/' );
            foreach ( $urls as $key => $url ) {
                if ( false !== strpos( $url, $emoji_svg_url ) ) {
                    unset( $urls[ $key ] );
                }
            }
        }
        return $urls;
    }

    /**
     * Set tag pages, date archives, search pages, and deep pagination to noindex
     */
    public function custom_seo_robots_meta( $robots ) {
        if ( is_tag() || is_date() || is_search() ) {
            $robots['noindex'] = true;
            $robots['follow']  = true;
        } elseif ( is_author() && is_paged() ) {
            $robots['noindex'] = true;
            $robots['follow']  = true;
        } elseif ( is_paged() ) {
            $paged = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
            if ( $paged >= 3 ) {
                $robots['noindex'] = true;
                $robots['follow']  = true;
            }
        }
        return $robots;
    }

    /**
     * XML-RPC security hardening: remove all WordPress methods (wp.*, metaWeblog.*,
     * blogger.*, mt.*, pingback.*, etc.), keeping only three harmless system methods.
     *
     * Authenticated methods (such as wp.getUsersBlogs) are the primary target for
     * brute-force attacks that bypass wp-login.php protections by hitting xmlrpc.php
     * directly; once removed, even if system.multicall remains, there is no attack
     * surface left to bundle into calls. No .htaccess dependency, works on any server.
     */
    public function harden_xmlrpc_methods( $methods ) {
        $allowed = [ 'system.multicall', 'system.listMethods', 'system.getCapabilities' ];
        return array_intersect_key( $methods, array_flip( $allowed ) );
    }

    /**
     * Remove the X-Pingback HTTP response header so the XML-RPC endpoint location is no longer advertised
     */
    public function remove_x_pingback_header( $headers ) {
        unset( $headers['X-Pingback'] );
        return $headers;
    }

    /**
     * Remove the tag taxonomy from the sitemap
     */
    public function clean_sitemap_taxonomies( $taxonomies ) {
        if ( isset( $taxonomies['post_tag'] ) ) {
            unset( $taxonomies['post_tag'] );
        }
        return $taxonomies;
    }

    /**
     * WP embed block styles (Embed Card)
     */
    public function enqueue_embed_styles() {
        $css = '
            body, .wp-embed {
                background-color: #1a1a1a !important;
                color: #ffffff !important;
            }
            .wp-embed-heading a {
                color: #ffffff !important;
                text-decoration: none;
                font-weight: bold;
            }
            .wp-embed-excerpt {
                color: #dddddd !important;
            }
            .wp-embed-site-title a,
            .wp-embed-meta {
                color: #bbbbbb !important;
            }
            .wp-embed {
                border: 1px solid #333333 !important;
                box-shadow: none !important;
                border-radius: 4px;
            }
        ';
        wp_register_style( 'omni-embed-styles', false, [], OMNI_WEBMASTER_VERSION );
        wp_enqueue_style( 'omni-embed-styles' );
        wp_add_inline_style( 'omni-embed-styles', $css );
    }

    /**
     * GitHub Gist style fixes
     */
    public function enqueue_gist_styles() {
        $css = '
            /* 1. Global container and code area background */
            .gist .gist-file, .gist div.gist-data {
                background-color: #1a1a1a !important;
                border: 1px solid #333333 !important;
                color: #e6e6e6 !important;
            }

            /* 2. Line-number gutter background and border */
            .gist div.gist-data .blob-num {
                background-color: #2a2a2a !important;
                border-right: 1px solid #333333 !important;
                color: #888888 !important;
            }

            /* 3. Footer meta bar background and text */
            .gist div.gist-meta {
                background-color: #2a2a2a !important;
                color: #aaaaaa !important;
                border-top: 1px solid #333333 !important;
            }

            /* 4. Footer link color */
            .gist div.gist-meta a {
                color: #ffffff !important;
            }

            /* 5. Fix the stubborn white background of GitHub Gist syntax-highlight blocks (.blob-code) */
            .gist .blob-code,
            .gist .blob-wrapper,
            .gist .highlight,
            .gist table.highlight,
            .gist table.highlight tr,
            .gist table.highlight td {
                background-color: #1a1a1a !important;
                border: none !important;
            }

            .gist .blob-code-inner {
                color: #e6e6e6 !important;
            }
        ';
        wp_register_style( 'omni-gist-styles', false, [], OMNI_WEBMASTER_VERSION );
        wp_enqueue_style( 'omni-gist-styles' );
        wp_add_inline_style( 'omni-gist-styles', $css );
    }
}
