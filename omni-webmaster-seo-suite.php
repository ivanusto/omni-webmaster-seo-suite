<?php
/**
 * Plugin Name: Omni Webmaster & SEO Suite
 * Plugin URI:  https://github.com/ivanusto/omni-webmaster-seo-suite
 * Description: All-in-one WordPress optimization & SEO toolkit: SEO markup cleanup, advanced RSS control, automatic Asian-title slug translation, complete comment disabling, and selective thumbnail disabling with one-click batch cleanup.
 * Version:     2.1
 * Author:      Ivan Lin
 * Text Domain: omni-webmaster-seo-suite
 * Domain Path: /languages
 * License:     Apache-2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct access
}

// Plugin constants
define( 'OMNI_WEBMASTER_VERSION', '2.1' );
define( 'OMNI_WEBMASTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'OMNI_WEBMASTER_URL', plugin_dir_url( __FILE__ ) );
define( 'OMNI_WEBMASTER_BASENAME', plugin_basename( __FILE__ ) );

// Load core modules
require_once OMNI_WEBMASTER_DIR . 'includes/class-omni-seo-cleanup.php';
require_once OMNI_WEBMASTER_DIR . 'includes/class-omni-disable-comments.php';
require_once OMNI_WEBMASTER_DIR . 'includes/class-omni-disable-thumbnails.php';
require_once OMNI_WEBMASTER_DIR . 'includes/class-omni-slug-converter.php';
require_once OMNI_WEBMASTER_DIR . 'includes/class-omni-meta-pixel.php';
require_once OMNI_WEBMASTER_DIR . 'includes/class-omni-meta-tags.php';
require_once OMNI_WEBMASTER_DIR . 'includes/class-omni-admin.php';

// Initialize the plugin
add_action( 'plugins_loaded', 'omni_webmaster_seo_suite_init' );

function omni_webmaster_seo_suite_init() {
    // Load bundled translations (WordPress.org also serves them automatically)
    load_plugin_textdomain( 'omni-webmaster-seo-suite', false, dirname( OMNI_WEBMASTER_BASENAME ) . '/languages' );

    // Instantiate each module
    $seo_cleanup        = new Omni_SEO_Cleanup();
    $disable_comments   = new Omni_Disable_Comments();
    $disable_thumbnails = new Omni_Disable_Thumbnails();
    $slug_converter     = new Omni_Slug_Converter();
    $meta_pixel         = new Omni_Meta_Pixel();
    $meta_tags          = new Omni_Meta_Tags();

    // Instantiate the admin UI, passing in the other modules for interaction
    // (reading image sizes, handling AJAX requests, etc.)
    new Omni_Admin( $seo_cleanup, $disable_comments, $disable_thumbnails, $slug_converter );
}
