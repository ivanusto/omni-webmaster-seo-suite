<?php
/**
 * Plugin Name: Omni Webmaster & SEO Suite
 * Plugin URI:  https://yblog.org/plugins/omni-webmaster-seo-suite
 * Description: 一站式 WordPress 網站優化與 SEO 站長工具。整合了 SEO 標記優化、進階 RSS 控制、中文標題自動翻譯、完全禁用留言以及選擇性停用圖片縮圖與一鍵批次清理功能。
 * Version:     1.5
 * Author:      Ivan Lin & Ashley Hsieh
 * Text Domain: omni-webmaster-seo-suite
 * License:     Apache-2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // 防止直接存取
}

// 定義外掛常數
define( 'OMNI_WEBMASTER_VERSION', '1.5' );
define( 'OMNI_WEBMASTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'OMNI_WEBMASTER_URL', plugin_dir_url( __FILE__ ) );
define( 'OMNI_WEBMASTER_BASENAME', plugin_basename( __FILE__ ) );

// 載入核心模組
require_once OMNI_WEBMASTER_DIR . 'includes/class-omni-seo-cleanup.php';
require_once OMNI_WEBMASTER_DIR . 'includes/class-omni-disable-comments.php';
require_once OMNI_WEBMASTER_DIR . 'includes/class-omni-disable-thumbnails.php';
require_once OMNI_WEBMASTER_DIR . 'includes/class-omni-slug-converter.php';
require_once OMNI_WEBMASTER_DIR . 'includes/class-omni-meta-pixel.php';
require_once OMNI_WEBMASTER_DIR . 'includes/class-omni-admin.php';

// 初始化外掛
add_action( 'plugins_loaded', 'omni_webmaster_seo_suite_init' );

function omni_webmaster_seo_suite_init() {
    // 實例化各模組
    $seo_cleanup        = new Omni_SEO_Cleanup();
    $disable_comments   = new Omni_Disable_Comments();
    $disable_thumbnails = new Omni_Disable_Thumbnails();
    $slug_converter     = new Omni_Slug_Converter();
    $meta_pixel         = new Omni_Meta_Pixel();
    
    // 實例化後台管理介面，傳入其他模組以做互動（如取得圖片尺寸、處理 AJAX 請求等）
    new Omni_Admin( $seo_cleanup, $disable_comments, $disable_thumbnails, $slug_converter );
}
