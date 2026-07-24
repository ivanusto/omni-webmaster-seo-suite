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

        // Only run when the option is enabled
        if ( ! empty( $settings['disable_comments'] ) ) {
            // Run on WordPress init
            add_action( 'init', [ $this, 'disable_comments_support' ] );
            
            // Close comments on existing content
            add_filter( 'comments_open', '__return_false', 20, 2 );
            add_filter( 'pings_open', '__return_false', 20, 2 );
            
            // Hide existing comments
            add_filter( 'comments_array', '__return_empty_array', 10, 2 );
            
            // Remove the Comments item from the admin menu
            add_action( 'admin_menu', [ $this, 'remove_comments_menu' ] );
            
            // Remove the Comments item from the admin bar
            add_action( 'admin_bar_menu', [ $this, 'remove_comments_admin_bar' ], 999 );
            
            // Remove the comments widget from the dashboard
            add_action( 'wp_dashboard_setup', [ $this, 'remove_comments_dashboard_widget' ] );
            
            // Adjust the admin post list tables
            add_filter( 'manage_posts_columns', [ $this, 'remove_comments_column' ] );
            add_filter( 'manage_pages_columns', [ $this, 'remove_comments_column' ] );

            // Remove the front-end comment-reply JavaScript (improves load performance)
            add_action( 'wp_print_scripts', [ $this, 'dequeue_comment_reply_script' ], 100 );

            // Disable the REST API comment endpoints
            add_filter( 'rest_endpoints', [ $this, 'disable_comments_rest_endpoints' ] );

            // Disable the XML-RPC pingback methods
            add_filter( 'xmlrpc_methods', [ $this, 'disable_pingback_xmlrpc_methods' ] );

            // Remove the X-Pingback HTTP header
            add_filter( 'wp_headers', [ $this, 'remove_pingback_header' ] );
        }
    }

    /**
     * Disable comment support for all post types
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
     * Remove the Comments item from the admin menu
     */
    public function remove_comments_menu() {
        remove_menu_page( 'edit-comments.php' );
    }

    /**
     * Remove the Comments item from the admin bar
     */
    public function remove_comments_admin_bar( $wp_admin_bar ) {
        $wp_admin_bar->remove_node( 'comments' );
    }

    /**
     * Remove the comments widget from the dashboard
     */
    public function remove_comments_dashboard_widget() {
        remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
    }

    /**
     * Remove the comments column from post list tables
     */
    public function remove_comments_column( $columns ) {
        unset( $columns['comments'] );
        return $columns;
    }

    /**
     * Dequeue the front-end comment-reply script
     */
    public function dequeue_comment_reply_script() {
        wp_dequeue_script( 'comment-reply' );
    }

    /**
     * Disable the REST API comment endpoints (prevents spam comments that bypass the front end)
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
     * Remove the pingback methods from XML-RPC (reduces the chance of API abuse)
     */
    public function disable_pingback_xmlrpc_methods( $methods ) {
        unset( $methods['pingback.ping'] );
        unset( $methods['pingback.extensions.getPingbacks'] );
        return $methods;
    }

    /**
     * Remove X-Pingback from the HTTP response headers (cleaner headers)
     */
    public function remove_pingback_header( $headers ) {
        unset( $headers['X-Pingback'] );
        return $headers;
    }
}
