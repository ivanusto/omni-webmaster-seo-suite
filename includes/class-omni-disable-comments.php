<?php
/**
 * Omni_Disable_Comments Class
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Omni_Disable_Comments {

    public function __construct() {
        $settings = get_option( 'omni_webmaster_settings', [] );

        // 僅在勾選啟用時執行
        if ( ! empty( $settings['disable_comments'] ) ) {
            // 在 WordPress 初始化時執行
            add_action( 'init', [ $this, 'disable_comments_support' ] );
            
            // 關閉現有文章的留言功能
            add_filter( 'comments_open', '__return_false', 20, 2 );
            add_filter( 'pings_open', '__return_false', 20, 2 );
            
            // 隱藏現有留言
            add_filter( 'comments_array', '__return_empty_array', 10, 2 );
            
            // 移除管理選單中的留言選項
            add_action( 'admin_menu', [ $this, 'remove_comments_menu' ] );
            
            // 移除管理工具欄中的留言選項
            add_action( 'admin_bar_menu', [ $this, 'remove_comments_admin_bar' ], 999 );
            
            // 移除儀表板上的留言小工具
            add_action( 'wp_dashboard_setup', [ $this, 'remove_comments_dashboard_widget' ] );
            
            // 修改後台文章管理頁面
            add_filter( 'manage_posts_columns', [ $this, 'remove_comments_column' ] );
            add_filter( 'manage_pages_columns', [ $this, 'remove_comments_column' ] );

            // 移除前端留言回覆 JavaScript (優化加載效能)
            add_action( 'wp_print_scripts', [ $this, 'dequeue_comment_reply_script' ], 100 );

            // 禁用 REST API 留言端點
            add_filter( 'rest_endpoints', [ $this, 'disable_comments_rest_endpoints' ] );

            // 禁用 XML-RPC pingback 方法
            add_filter( 'xmlrpc_methods', [ $this, 'disable_pingback_xmlrpc_methods' ] );

            // 移除 X-Pingback HTTP 標頭
            add_filter( 'wp_headers', [ $this, 'remove_pingback_header' ] );
        }
    }

    /**
     * 為所有文章類型禁用留言支援
     */
    public function disable_comments_support() {
        $post_types = get_post_types();
        foreach ( $post_types as $post_type ) {
            if ( post_type_supports( $post_type, 'comments' ) ) {
                remove_post_type_support( $post_type, 'comments' );
                remove_post_type_support( $post_type, 'trackbacks' );
            }
        }
    }

    /**
     * 移除管理選單中的留言選項
     */
    public function remove_comments_menu() {
        remove_menu_page( 'edit-comments.php' );
    }

    /**
     * 移除管理工具欄中的留言選項
     */
    public function remove_comments_admin_bar( $wp_admin_bar ) {
        $wp_admin_bar->remove_node( 'comments' );
    }

    /**
     * 移除儀表板上的留言小工具
     */
    public function remove_comments_dashboard_widget() {
        remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
    }

    /**
     * 移除文章管理頁面中的留言列
     */
    public function remove_comments_column( $columns ) {
        unset( $columns['comments'] );
        return $columns;
    }

    /**
     * 移除前端 comment-reply 腳本
     */
    public function dequeue_comment_reply_script() {
        wp_dequeue_script( 'comment-reply' );
    }

    /**
     * 禁用 REST API 留言端點 (阻止繞過前端直接發送垃圾留言)
     */
    public function disable_comments_rest_endpoints( $endpoints ) {
        if ( isset( $endpoints['/wp/v2/comments'] ) ) {
            unset( $endpoints['/wp/v2/comments'] );
        }
        if ( isset( $endpoints['/wp/v2/comments/(?P<id>[\d]+)'] ) ) {
            unset( $endpoints['/wp/v2/comments/(?P<id>[\d]+)'] );
        }
        return $endpoints;
    }

    /**
     * 移除 XML-RPC 中的 Pingback 方法 (減少 API 被惡意利用機會)
     */
    public function disable_pingback_xmlrpc_methods( $methods ) {
        unset( $methods['pingback.ping'] );
        unset( $methods['pingback.extensions.getPingbacks'] );
        return $methods;
    }

    /**
     * 移除 HTTP 回應標頭中的 X-Pingback (乾淨的 Header)
     */
    public function remove_pingback_header( $headers ) {
        unset( $headers['X-Pingback'] );
        return $headers;
    }
}
