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

        // 1. 進階 RSS Feed 控制
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

        // 2. 清理 HTML Head
        if ( ! empty( $settings['cleanup_head'] ) ) {
            add_action( 'init', [ $this, 'cleanup_head_tags' ] );
        }

        // 3. 自訂 Robots Meta
        if ( ! empty( $settings['robots_meta'] ) ) {
            add_filter( 'wp_robots', [ $this, 'custom_seo_robots_meta' ] );
        }

        // 4. 清洗 Sitemap
        if ( ! empty( $settings['clean_sitemap'] ) ) {
            add_filter( 'wp_sitemaps_taxonomies', [ $this, 'clean_sitemap_taxonomies' ] );
        }

        // 5. WP 嵌入區塊樣式 (Embed Card)
        if ( ! empty( $settings['embed_styles'] ) ) {
            add_action( 'embed_head', [ $this, 'custom_embed_styles' ], 999 );
        }

        // 6. GitHub Gist 樣式修正
        if ( ! empty( $settings['gist_styles'] ) ) {
            add_action( 'wp_footer', [ $this, 'custom_gist_styles' ], 999 );
        }
    }

    /**
     * 取得設定值（預設開啟安全 SEO 功能）
     */
    private function get_settings() {
        $defaults = [
            'disable_feeds' => '1',
            'cleanup_head'  => '1',
            'robots_meta'   => '1',
            'clean_sitemap' => '1',
            'embed_styles'  => '0',
            'gist_styles'   => '0',
        ];
        return wp_parse_args( get_option( 'omni_webmaster_settings', [] ), $defaults );
    }

    /**
     * 阻斷非必要 Feed (僅放行主文章、分類與作者 Feed，其餘回傳 410)
     */
    public function advanced_selective_disable_feeds() {
        if ( is_feed() ) {
            if ( is_category() || is_author() || is_home() ) {
                return; // 放行
            }
        }
        
        status_header( 410 );
        header( 'Content-Type: text/plain; charset=UTF-8' );
        echo 'This specific RSS feed has been permanently disabled to streamline SEO structure.';
        exit;
    }

    /**
     * 移除多餘的 HTML Head 標籤與不必要的資源加載 (如 Emoji)
     */
    public function cleanup_head_tags() {
        $settings = $this->get_settings();
        
        // 移除 Feed 連結 (若已停用 Feed)
        if ( ! empty( $settings['disable_feeds'] ) ) {
            remove_action( 'wp_head', 'feed_links', 2 );
            remove_action( 'wp_head', 'feed_links_extra', 3 );
        }

        // 移除 RSD (Really Simple Discovery)
        remove_action( 'wp_head', 'rsd_link' );
        
        // 移除 Windows Live Writer 連結
        remove_action( 'wp_head', 'wlwmanifest_link' );
        
        // 移除 WordPress 版本資訊
        remove_action( 'wp_head', 'wp_generator' );
        
        // 移除短網址連結與相鄰文章連結
        remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
        remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );

        // 移除 REST API 連結
        remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
        remove_action( 'template_redirect', 'rest_output_link_header', 11 );

        // 停用 Emoji 載入 (優化前端效能)
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
     * TinyMCE 停用 Emoji 插件
     */
    public function disable_emojis_tinymce( $plugins ) {
        if ( is_array( $plugins ) ) {
            return array_diff( $plugins, [ 'wpemoji' ] );
        }
        return [];
    }

    /**
     * 移除 Emoji 相關 DNS 預先獲取
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
     * 將標籤頁、日期頁、搜尋頁、深層分頁設定為 noindex
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
     * 從 sitemap 中移除標籤分類
     */
    public function clean_sitemap_taxonomies( $taxonomies ) {
        if ( isset( $taxonomies['post_tag'] ) ) {
            unset( $taxonomies['post_tag'] );
        }
        return $taxonomies;
    }

    /**
     * WP 嵌入區塊樣式 (Embed Card)
     */
    public function custom_embed_styles() {
        echo '<style>
            /* 核心修正：同時針對 body 和 .wp-embed 設定背景 */
            body, .wp-embed {
                background-color: #1a1a1a !important; /* 深色背景 */
                color: #ffffff !important; /* 預設文字改為白色 */
            }
            /* 確保標題是白色且清楚 */
            .wp-embed-heading a {
                color: #ffffff !important;
                text-decoration: none;
                font-weight: bold;
            }
            /* 摘要文字顏色 - 稍微調亮一點以利閱讀 */
            .wp-embed-excerpt {
                color: #dddddd !important; 
            }
            /* 底部 Meta 資訊 */
            .wp-embed-site-title a,
            .wp-embed-meta {
                color: #bbbbbb !important;
            }
            /* 去除預設的白色邊框與陰影，改用深色邊框 */
            .wp-embed {
                border: 1px solid #333333 !important;
                box-shadow: none !important;
                border-radius: 4px; /* 加一點圓角比較現代 */
            }
        </style>';
    }

    /**
     * GitHub Gist 樣式修正
     */
    public function custom_gist_styles() {
        echo '<style>
            /* =========================================================================
               針對 GitHub Gist 嵌入樣式 (非 Iframe，而是 Javascript 生成) 的暗色修正
               ========================================================================= */
            
            /* 1. 全域容器與程式碼區域背景 */
            .gist .gist-file, .gist div.gist-data {
                background-color: #1a1a1a !important;
                border: 1px solid #333333 !important;
                color: #e6e6e6 !important;
            }
            
            /* 2. 行數邊欄背景與邊框 */
            .gist div.gist-data .blob-num {
                background-color: #2a2a2a !important;
                border-right: 1px solid #333333 !important;
                color: #888888 !important;
            }
            
            /* 3. 底部資訊列背景與文字 */
            .gist div.gist-meta {
                background-color: #2a2a2a !important;
                color: #aaaaaa !important;
                border-top: 1px solid #333333 !important;
            }
            
            /* 4. 底部連結顏色 */
            .gist div.gist-meta a {
                color: #ffffff !important;
            }
            
            /* =========================================================================
               補強：修正 GitHub Gist 語法高亮區塊 (.blob-code) 的頑固白色背景
               ========================================================================= */
            
            /* 強制將內部表格、語法高亮區塊與每一行程式碼的背景改為深色 */
            .gist .blob-code,
            .gist .blob-wrapper,
            .gist .highlight,
            .gist table.highlight,
            .gist table.highlight tr,
            .gist table.highlight td {
                background-color: #1a1a1a !important;
                border: none !important; /* 移除可能的內部細線 */
            }
            
            /* 確保預設的純文字（如註解或沒有高亮的變數）在黑底上是清晰的淺色 */
            .gist .blob-code-inner {
                color: #e6e6e6 !important;
            }
        </style>';
    }
}
