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
        
        // 註冊選單與設定
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        
        // 在外掛列表頁加入「設定」快捷連結
        add_filter( 'plugin_action_links_' . OMNI_WEBMASTER_BASENAME, [ $this, 'add_settings_link' ] );

        // 註冊數據匯出 AJAX 預覽與 CSV 下載動作
        add_action( 'wp_ajax_omni_preview_posts_data', [ $this, 'preview_posts_data' ] );
        add_action( 'admin_post_omni_export_csv', [ $this, 'export_posts_csv' ] );
        
        // 註冊清除 oEmbed 快取 AJAX 動作
        add_action( 'wp_ajax_omni_clear_oembed_cache', [ $this, 'clear_oembed_cache' ] );

        // 當設定變更（特別是「清理 HTML Head」或嵌入樣式）時，自動清除 oEmbed 失敗快取，
        // 讓退化成純文字連結的嵌入卡片能自動重新抓取還原，無需手動點擊按鈕。
        add_action( 'update_option_' . $this->option_name, [ $this, 'maybe_purge_oembed_on_change' ], 10, 2 );
    }

    /**
     * 加入選單頁面
     */
    public function add_admin_menu() {
        add_options_page(
            'Omni 站長工具與 SEO 設定',
            'Omni 站長工具',
            'manage_options',
            'omni-webmaster-seo',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * 註冊外掛設定與淨化回呼
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
     * 設定淨化與預設處理
     */
    public function sanitize_settings( $input ) {
        $sanitized = [];
        
        // SEO 頁面選項
        $sanitized['disable_feeds'] = isset( $input['disable_feeds'] ) ? '1' : '0';
        $sanitized['cleanup_head']  = isset( $input['cleanup_head'] ) ? '1' : '0';
        $sanitized['robots_meta']   = isset( $input['robots_meta'] ) ? '1' : '0';
        $sanitized['clean_sitemap'] = isset( $input['clean_sitemap'] ) ? '1' : '0';
        $sanitized['embed_styles']  = isset( $input['embed_styles'] ) ? '1' : '0';
        $sanitized['gist_styles']   = isset( $input['gist_styles'] ) ? '1' : '0';
        
        // 留言禁用選項
        $sanitized['disable_comments'] = isset( $input['disable_comments'] ) ? '1' : '0';
        
        // 停用縮圖清單
        $sanitized['disabled_sizes'] = [];
        if ( isset( $input['disabled_sizes'] ) && is_array( $input['disabled_sizes'] ) ) {
            $all_sizes = array_keys( $this->disable_thumbnails->get_all_image_sizes() );
            foreach ( $input['disabled_sizes'] as $size => $val ) {
                if ( in_array( $size, $all_sizes, true ) && $val === '1' ) {
                    $sanitized['disabled_sizes'][] = $size;
                }
            }
        }
        
        // 中英 Slug 選項
        $sanitized['slug_api_key']    = isset( $input['slug_api_key'] ) ? sanitize_text_field( $input['slug_api_key'] ) : '';
        // 夾在 20-200 之間，避免扣除 ID 保留空間後 slug 長度歸零
        $sanitized['slug_max_length'] = isset( $input['slug_max_length'] ) ? max( 20, min( 200, absint( $input['slug_max_length'] ) ) ) : 30;

        // 瀏覽量自訂欄位 (Meta Key)
        $sanitized['views_meta_key']  = ! empty( $input['views_meta_key'] ) ? sanitize_text_field( trim( $input['views_meta_key'] ) ) : 'views';
        
        // Meta Pixel 選項（像素編號僅由數字組成，直接過濾非數字字元）
        $sanitized['meta_pixel_enable']         = isset( $input['meta_pixel_enable'] ) ? '1' : '0';
        $sanitized['meta_pixel_id']             = isset( $input['meta_pixel_id'] ) ? preg_replace( '/\D/', '', sanitize_text_field( trim( $input['meta_pixel_id'] ) ) ) : '';
        $sanitized['meta_pixel_advanced']       = isset( $input['meta_pixel_advanced'] ) ? '1' : '0';
        $sanitized['meta_pixel_exclude_admins'] = isset( $input['meta_pixel_exclude_admins'] ) ? '1' : '0';
        
        return $sanitized;
    }

    /**
     * 載入後台資源與在地化腳本變數
     */
    public function enqueue_admin_assets( $hook ) {
        if ( 'settings_page_omni-webmaster-seo' !== $hook ) {
            return;
        }
        
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
                'ajax_url'            => admin_url( 'admin-ajax.php' ),
                'slug_nonce'          => wp_create_nonce( 'omni_slug_test_nonce' ),
                'thumb_nonce'         => wp_create_nonce( 'omni_delete_thumbnails_nonce' ),
                'export_nonce'        => wp_create_nonce( 'omni_export_posts_nonce' ),
                'txt_testing'         => '測試連線中...',
                'txt_deleting'        => '正在批次處理中，請勿關閉視窗...',
                'txt_confirm_delete_all' => '【警告】您確定要刪除所有的縮圖嗎？這會清除所有圖片版本，可能會導致前端頁面圖片損壞或載入緩慢。此動作無法復原！',
                'txt_confirm_delete_selected' => '您確定要刪除已勾選停用的縮圖尺寸嗎？這只會刪除被選定停用的縮圖規格，其餘規格會被安全保留。此動作無法復原！',
                'txt_success'         => '測試成功！',
                'txt_error'           => '發生錯誤，請重試。',
                'txt_complete'        => '清理完成！已清理全部圖片檔案。',
                'txt_loading_preview' => '正在讀取文章數據，請稍後...',
                'txt_no_posts'        => '此月份查無任何已發布文章。'
            ]
        );
    }

    /**
     * 在外掛列表頁加入「設定」快捷連結
     */
    public function add_settings_link( $links ) {
        $settings_link = '<a href="options-general.php?page=omni-webmaster-seo">' . __( '設定', 'omni-webmaster-seo-suite' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * 渲染後台設定頁面
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // 取得目前設定，設定預設值
        $defaults = [
            'disable_feeds'       => '1',
            'cleanup_head'        => '1',
            'robots_meta'         => '1',
            'clean_sitemap'       => '1',
            'embed_styles'        => '0',
            'gist_styles'         => '0',
            'disable_comments'    => '0',
            'disabled_sizes'      => [],
            'slug_api_key'        => '',
            'slug_max_length'     => 30,
            'views_meta_key'      => 'views',
            'meta_pixel_enable'   => '0',
            'meta_pixel_id'       => '',
            'meta_pixel_advanced' => '0',
            'meta_pixel_exclude_admins' => '1',
        ];
        $settings = wp_parse_args( get_option( $this->option_name, [] ), $defaults );

        // 頁籤切換
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'seo'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! in_array( $active_tab, [ 'seo', 'comments', 'thumbnails', 'slug', 'export', 'pixel' ], true ) ) {
            $active_tab = 'seo';
        }
        ?>
        <div class="wrap omni-settings-wrap">
            <header class="omni-header">
                <div class="omni-header-title">
                    <h1>Omni 站長工具與 SEO 整合套件</h1>
                    <span class="omni-badge">Version <?php echo esc_html( OMNI_WEBMASTER_VERSION ); ?></span>
                </div>
                <p class="omni-header-desc">一站式管理您的 WordPress 網站優化與 SEO 基礎配置，整合留言防制、縮圖最佳化與網址翻譯。</p>
            </header>

            <h2 class="nav-tab-wrapper">
                <a href="?page=omni-webmaster-seo&tab=seo" class="nav-tab <?php echo $active_tab === 'seo' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-site-alt3"></span> SEO 與網站優化
                </a>
                <a href="?page=omni-webmaster-seo&tab=comments" class="nav-tab <?php echo $active_tab === 'comments' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-comments"></span> 留言功能控制
                </a>
                <a href="?page=omni-webmaster-seo&tab=thumbnails" class="nav-tab <?php echo $active_tab === 'thumbnails' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-format-image"></span> 媒體與縮圖優化
                </a>
                <a href="?page=omni-webmaster-seo&tab=slug" class="nav-tab <?php echo $active_tab === 'slug' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-translation"></span> 中英網址翻譯
                </a>
                <a href="?page=omni-webmaster-seo&tab=pixel" class="nav-tab <?php echo $active_tab === 'pixel' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-chart-line"></span> Meta Pixel 追蹤
                </a>
                <a href="?page=omni-webmaster-seo&tab=export" class="nav-tab <?php echo $active_tab === 'export' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-database-export"></span> 文章數據匯出
                </a>
            </h2>

            <div class="omni-content-card">
                <form method="post" action="options.php">
                    <?php settings_fields( 'omni_webmaster_settings_group' ); ?>

                    <!-- Tab 1: SEO Optimization -->
                    <div class="omni-tab-panel <?php echo $active_tab === 'seo' ? 'is-active' : ''; ?>">
                        <div class="omni-tab-content">
                            <h3>SEO 優化與 HTML 清理</h3>
                            <p class="section-desc">優化 HTML 頭部標籤，管理 RSS 訂閱來源並清洗 Sitemap 以防網站結構不必要地暴露。</p>
                            
                            <table class="form-table omni-form-table">
                                <tr>
                                    <th scope="row">進階 RSS Feed 控制</th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[disable_feeds]" value="1" <?php checked( '1', $settings['disable_feeds'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong>停用非必要 RSS 訂閱源</strong>
                                                <p>僅放行首頁、文章分類與作者的 RSS Feed，阻斷其他無用 RSS 訂閱（回傳 410）以防止爬蟲對伺服器造成無效請求負荷。</p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">清理 HTML Head</th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[cleanup_head]" value="1" <?php checked( '1', $settings['cleanup_head'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong>移除 HTML 多餘標籤</strong>
                                                <p>移除 `<head>` 中冗餘的 Feed 連結與 REST API 頭部標記，保持源碼乾淨度並增強隱私。</p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">自訂 Robots Meta</th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[robots_meta]" value="1" <?php checked( '1', $settings['robots_meta'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong>搜尋、標籤與分頁設定 noindex</strong>
                                                <p>將標籤存檔頁、日期封存頁、內部搜尋頁與第 3 頁以上的深層文章分頁，標記為 `noindex, follow`，聚焦爬蟲權重，避免網站產生大量低質量或重複內容。</p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">清洗 WordPress Sitemap</th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[clean_sitemap]" value="1" <?php checked( '1', $settings['clean_sitemap'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong>從網站地圖中移除標籤 (Tags)</strong>
                                                <p>從 WP 原生 Sitemap 結構中徹底拔除 `post_tag` 項目，保留主要文章與作者目錄，精簡 Sitemap 指向。</p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">WP 嵌入區塊深色修正 (Embed Card)</th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[embed_styles]" value="1" <?php checked( '1', $settings['embed_styles'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong>套用深色背景與樣式修正</strong>
                                                <p>針對 WordPress 原生嵌入文章的 iframe 區塊進行暗色化，適合黑底網站或深色主題。</p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">GitHub Gist 程式碼深色修正</th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[gist_styles]" value="1" <?php checked( '1', $settings['gist_styles'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong>強制將 GitHub Gist 程式碼區塊轉換為深色模式</strong>
                                                <p>解決 JavaScript 產生的 GitHub Gist 表格區塊出現白色背景的問題，使其融入深色主題。</p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">一鍵清除 oEmbed 快取</th>
                                    <td>
                                        <div class="omni-field-row" style="align-items: center;">
                                            <button type="button" id="omni-btn-clear-oembed" class="button button-secondary" style="margin-right: 15px; display: inline-flex; align-items: center; gap: 4px;">
                                                <span class="dashicons dashicons-update" style="font-size: 17px; width: 17px; height: 17px;"></span> 立即清除快取
                                            </button>
                                            <div class="omni-field-desc">
                                                <strong>重設全站嵌入預覽卡片</strong>
                                                <p>若您先前啟用過「清理 HTML Head」導致嵌入卡片變成普通連結，在<strong>關閉「清理 HTML Head」</strong>後，您需要點擊此按鈕清除資料庫中的 oEmbed 失敗快取，文章頁面才會重新抓取並還原精美的預覽卡片。</p>
                                            </div>
                                        </div>
                                        <div id="omni-oembed-clear-result" style="margin-top: 10px; font-weight: 500; display: none;"></div>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Tab 2: Comments Control -->
                    <div class="omni-tab-panel <?php echo $active_tab === 'comments' ? 'is-active' : ''; ?>">
                        <div class="omni-tab-content">
                            <h3>完全禁用留言功能</h3>
                            <p class="section-desc">一鍵完全阻斷 WordPress 留言入口，極簡化非社群互動型網站運維，防止垃圾留言侵擾並提升安全。</p>
                            
                            <table class="form-table omni-form-table">
                                <tr>
                                    <th scope="row">留言全局封鎖開關</th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[disable_comments]" value="1" <?php checked( '1', $settings['disable_comments'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong>完全關閉網站留言功能</strong>
                                                <p>啟用後會關閉所有文章類型的留言及跟蹤支援、隱藏現有留言、關閉新文章留言入口、並隱藏後台留言選單與儀表板上的留言小工具。</p>
                                            </div>
                                        </div>
                                        <?php if ( ! empty( $settings['disable_comments'] ) ) : ?>
                                            <div class="omni-alert omni-alert-success" style="margin-top: 15px;">
                                                <span class="dashicons dashicons-yes-alt"></span> 目前全站留言功能已被徹底鎖定。
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
                            <h3>媒體與縮圖生成優化</h3>
                            <p class="section-desc">自訂停用特定寬高的圖片縮圖規格，防止上傳一張圖片生成數十個無用分身佔用虛擬主機硬碟容量。</p>
                            
                            <table class="form-table omni-form-table">
                                <tr>
                                    <th scope="row">選擇要停用的縮圖尺寸</th>
                                    <td>
                                        <p class="description" style="margin-bottom: 15px;">勾選下方縮圖規格後，未來上傳的新圖片將<strong>不再</strong>自動生成該尺寸的實體縮圖：</p>
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
                                                            <span>停用生成</span>
                                                        </label>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <hr style="margin: 30px 0; border: 0; border-top: 1px solid #e5e7eb;" />

                            <h3>批次清理現有縮圖</h3>
                            <p class="section-desc">已停用的縮圖設定僅對「新上傳」圖片有效。若要釋放硬碟空間，可使用下方工具批次刪除歷史圖片已產生的縮圖檔案。</p>
                            
                            <div class="omni-cleanup-card">
                                <h4>選擇清理範圍與模式</h4>
                                <p>這將掃描媒體庫中所有的圖片附件，並移除對應的縮圖實體檔案（保留原始大圖）。此為破壞性動作，建議先進行網站備份。</p>
                                
                                <div class="omni-delete-mode-selector" style="margin-bottom: 20px;">
                                    <label class="omni-radio-label" style="display: block; margin-bottom: 12px; font-weight: 500;">
                                        <input type="radio" name="omni_delete_mode" value="disabled_only" checked />
                                        <span class="omni-radio-text"><strong>僅清理已勾選停用的縮圖尺寸 (推薦)</strong></span>
                                        <p class="description" style="margin-left: 24px; margin-top: 2px;">安全模式：只會刪除您上方勾選停用的尺寸，保留其他正常的縮圖尺寸，避免前端文章破圖。</p>
                                    </label>
                                    <label class="omni-radio-label" style="display: block; font-weight: 500;">
                                        <input type="radio" name="omni_delete_mode" value="all" />
                                        <span class="omni-radio-text" style="color: #dc2626;"><strong>清理所有尺寸的縮圖 (不推薦)</strong></span>
                                        <p class="description" style="margin-left: 24px; margin-top: 2px;">完整清除：將刪除該圖片的所有縮圖（例如 150x150, 300x300 等），前端頁面可能會因找不到圖片而回傳 404 破圖或需加載原圖而變慢。</p>
                                    </label>
                                </div>

                                <div class="omni-cleanup-actions">
                                    <button type="button" id="omni-btn-delete-thumbs" class="button button-secondary">
                                        <span class="dashicons dashicons-trash"></span> 開始進行批次清理
                                    </button>
                                </div>
                                
                                <div id="omni-cleanup-progress-wrapper" class="omni-progress-wrapper" style="display:none;">
                                    <div class="omni-progress-bar-container">
                                        <div id="omni-cleanup-progress-fill" class="omni-progress-bar-fill" style="width: 0%;"></div>
                                    </div>
                                    <div class="omni-progress-meta">
                                        <span id="omni-cleanup-status-text">準備進行清理...</span>
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
                            <h3>中英網址自動翻譯</h3>
                            <p class="section-desc">當發布或儲存含中文的文章標題時，自動呼叫 Google 翻譯 API 翻譯為英文 URL Slug，增強 SEO 搜尋友好度與中文網址複製時產生亂碼的問題。</p>
                            
                            <table class="form-table omni-form-table">
                                <tr>
                                    <th scope="row">Google Cloud Translation API Key</th>
                                    <td>
                                        <input type="text" 
                                               id="omni_slug_api_key" 
                                               name="<?php echo esc_attr( $this->option_name ); ?>[slug_api_key]" 
                                               value="<?php echo esc_attr( $settings['slug_api_key'] ); ?>" 
                                               class="regular-text omni-input-key" 
                                               placeholder="（選填）AIzaSy..." />
                                        <p class="description">請填寫 Google Cloud 主控台申請的 Cloud Translation API Key。<br/><strong>備用方案 (免金鑰)</strong>：若欄位留空，系統將自動啟用免金鑰的 Google Translate 公開端點，無痛進行網址翻譯！</p>
                                        
                                        <div class="omni-api-tester" style="margin-top: 15px;">
                                            <button type="button" id="omni-btn-test-api" class="button">
                                                測試 API 金鑰連線
                                            </button>
                                            <span id="omni-test-api-result" class="omni-test-result"></span>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">最大字元長度</th>
                                    <td>
                                        <input type="number" 
                                               name="<?php echo esc_attr( $this->option_name ); ?>[slug_max_length]" 
                                               value="<?php echo esc_attr( $settings['slug_max_length'] ); ?>" 
                                               class="small-text"
                                               min="20"
                                               max="200" />
                                        <p class="description">產生的英文網址最大字元數（建議為 30 至 50 之間）。系統會自動預留 12 個字元供後續追加文章 ID 做防重複保護。</p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Tab: Meta Pixel -->
                    <div class="omni-tab-panel <?php echo $active_tab === 'pixel' ? 'is-active' : ''; ?>">
                        <div class="omni-tab-content">
                            <h3>Meta Pixel 廣告轉換追蹤</h3>
                            <p class="section-desc">整合 Meta (Facebook) Pixel 追蹤代碼，在網站前端自動載入基礎 PageView 及進階行銷追蹤事件，提升廣告轉換率與受眾優化精準度。</p>
                            
                            <table class="form-table omni-form-table">
                                <tr>
                                    <th scope="row">啟用 Meta Pixel 追蹤</th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[meta_pixel_enable]" value="1" <?php checked( '1', $settings['meta_pixel_enable'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong>開啟前端 Meta Pixel 追蹤碼載入</strong>
                                                <p>啟用後會在網站所有公開前端頁面的 `<head>` 區域插入 Meta Pixel 追蹤腳本，並自動觸發基礎的 <code>PageView</code> 事件。</p>
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
                                               placeholder="例如：123456789012345"
                                               pattern="[0-9]*"
                                               inputmode="numeric"
                                               title="請輸入純數字的 Meta Pixel ID" />
                                        <p class="description">請輸入您的 Meta (Facebook) 像素編號（純數字）。可在 Facebook 事件管理工具的設定頁中找到。</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">啟用進階事件追蹤</th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[meta_pixel_advanced]" value="1" <?php checked( '1', $settings['meta_pixel_advanced'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong>自動追蹤單一內容瀏覽與搜尋行為</strong>
                                                <p>除了基礎的 <code>PageView</code> 外，系統會自動在適當的頁面發送以下標準事件以做進階廣告受眾優化：</p>
                                                <ul style="list-style-type: disc; margin-left: 20px; margin-top: 6px; color: #6b7280; line-height: 1.5;">
                                                    <li><strong>單一文章/頁面 (ViewContent)</strong>：當訪客瀏覽單篇文章或頁面時發送，包含文章標題、全部分類名稱、文章 ID 與文章類型。</li>
                                                    <li><strong>搜尋結果 (Search)</strong>：當訪客在網站進行內部搜尋時發送，包含訪客輸入的搜尋關鍵字。</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">排除網站管理人員</th>
                                    <td>
                                        <div class="omni-field-row">
                                            <label class="omni-switch">
                                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[meta_pixel_exclude_admins]" value="1" <?php checked( '1', $settings['meta_pixel_exclude_admins'] ); ?> />
                                                <span class="omni-slider"></span>
                                            </label>
                                            <div class="omni-field-desc">
                                                <strong>不追蹤登入中的管理員與編輯</strong>
                                                <p>啟用後，具備文章編輯權限（<code>edit_posts</code>）的登入使用者瀏覽前台時將不會載入 Pixel 追蹤碼，避免站方人員自身的瀏覽行為污染廣告受眾與轉換數據（建議保持開啟）。</p>
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
                            <h3>文章數據月報匯出</h3>
                            <p class="section-desc">篩選指定月份的發布文章，匯出包含日期、標題、分類與標籤主題、網址連結、以及瀏覽量統計數據。</p>
                            
                            <div class="omni-export-panel-box" style="background: #f9fafb; border: 1.5px solid #f3f4f6; border-radius: 12px; padding: 24px; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.02);">
                                <div class="omni-export-form-row" style="display: flex; gap: 30px; flex-wrap: wrap; margin-bottom: 20px;">
                                    <div class="omni-export-field" style="display: flex; flex-direction: column; gap: 8px;">
                                        <label for="omni_export_month" style="font-weight: 600; color: #374151;"><strong>選擇匯出月份</strong></label>
                                        <select id="omni_export_month" style="padding: 6px 12px; border-radius: 6px; border: 1.5px solid #d1d5db; min-width: 180px; font-family: inherit;">
                                            <?php
                                            global $wpdb;
                                            // 查詢有文章發布的年份和月份，並使用 WordPress Object Cache 進行快取
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
                                                    $label = sprintf( '%d 年 %02d 月', $m->year, $m->month );
                                                    echo '<option value="' . esc_attr( $val ) . '">' . esc_html( $label ) . '</option>';
                                                }
                                            } else {
                                                echo '<option value="">無已發布文章</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    
                                    <div class="omni-export-field" style="display: flex; flex-direction: column; gap: 8px;">
                                        <label for="omni_export_views_meta" style="font-weight: 600; color: #374151;"><strong>瀏覽量自訂欄位 (Meta Key)</strong></label>
                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                            <input type="text" id="omni_export_views_meta" name="<?php echo esc_attr( $this->option_name ); ?>[views_meta_key]" value="<?php echo esc_attr( $settings['views_meta_key'] ); ?>" class="regular-text" style="width: 150px; padding: 6px 12px; border-radius: 6px; border: 1.5px solid #d1d5db;" placeholder="views" />
                                            <span class="description" style="font-size: 11px; color: #6b7280;">預設為 <code>views</code> (WP-PostViews 用)。請輸入您網站使用的瀏覽量自訂欄位名稱 (Meta Key)。</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="omni-export-actions" style="display: flex; gap: 12px; border-top: 1px solid #e5e7eb; padding-top: 20px;">
                                    <button type="button" id="omni-btn-preview-export" class="button" style="background: #0f766e !important; border-color: #0d9488 !important; color: #fff !important; font-weight: 500; padding: 4px 16px 6px; height: auto; border-radius: 6px;">
                                        <span class="dashicons dashicons-visibility" style="vertical-align: middle; margin-right: 4px;"></span> 即時預覽數據
                                    </button>
                                    <button type="button" id="omni-btn-download-csv" class="button button-secondary" style="font-weight: 500; padding: 4px 16px 6px; height: auto; border-radius: 6px;">
                                        <span class="dashicons dashicons-download" style="vertical-align: middle; margin-right: 4px;"></span> 下載 CSV 檔案 (Excel)
                                    </button>
                                    <button type="button" id="omni-btn-copy-clipboard" class="button button-secondary" style="font-weight: 500; padding: 4px 16px 6px; height: auto; border-radius: 6px;" disabled>
                                        <span class="dashicons dashicons-clipboard" style="vertical-align: middle; margin-right: 4px;"></span> 一鍵複製到剪貼簿 (貼上 Sheets/Excel)
                                    </button>
                                </div>
                            </div>
                            
                            <!-- 預覽表格與狀態 -->
                            <div class="omni-export-preview-container" style="margin-top: 30px; display: none;">
                                <h4 style="margin-bottom: 12px; font-weight: 600; color: #111827; font-size: 15px;">數據預覽</h4>
                                <div class="omni-export-table-wrapper" style="overflow-x: auto; background: #ffffff; border: 1.5px solid #e5e7eb; border-radius: 10px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
                                    <table class="wp-list-table widefat fixed striped posts" id="omni-export-preview-table" style="width: 100%; border-collapse: collapse; text-align: left;">
                                        <thead>
                                            <tr style="background: #f9fafb; border-bottom: 1.5px solid #e5e7eb;">
                                                <th style="width: 130px; font-weight: 600; padding: 12px 16px; color: #374151;">日期</th>
                                                <th style="font-weight: 600; padding: 12px 16px; color: #374151;">文章標題</th>
                                                <th style="width: 220px; font-weight: 600; padding: 12px 16px; color: #374151;">切角/主題</th>
                                                <th style="font-weight: 600; padding: 12px 16px; color: #374151;">連結</th>
                                                <th style="width: 90px; font-weight: 600; padding: 12px 16px; color: #374151; text-align: right;">瀏覽量</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!-- AJAX 載入 -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            
                            <div id="omni-export-status-message" style="margin-top: 20px; display: none; padding: 12px 16px; border-radius: 8px;"></div>
                        </div>
                    </div>

                    <div class="omni-submit-section">
                        <?php submit_button( '儲存變更', 'primary', 'submit', false, [ 'id' => 'omni-submit-btn' ] ); ?>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX 預覽文章資料
     */
    public function preview_posts_data() {
        check_ajax_referer( 'omni_export_posts_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '權限不足' );
        }

        $month_str = isset( $_POST['export_month'] ) ? sanitize_text_field( wp_unslash( $_POST['export_month'] ) ) : ''; // e.g. "2026/05"
        $views_meta_key = isset( $_POST['views_meta_key'] ) ? sanitize_key( wp_unslash( $_POST['views_meta_key'] ) ) : 'views';

        if ( empty( $month_str ) ) {
            wp_send_json_error( '未指定月份' );
        }

        $parts = explode( '/', $month_str );
        if ( count( $parts ) !== 2 ) {
            wp_send_json_error( '月份格式錯誤' );
        }

        $year  = intval( $parts[0] );
        $month = intval( $parts[1] );

        $posts_data = $this->query_posts_by_month( $year, $month, $views_meta_key );

        wp_send_json_success( $posts_data );
    }

    /**
     * 下載 CSV 檔案 (相容 Windows Excel 中文編碼)
     */
    public function export_posts_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '權限不足' );
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'omni_export_posts_nonce' ) ) {
            wp_die( '安全性檢查失敗，請重新操作。' );
        }

        $month_str = isset( $_GET['export_month'] ) ? sanitize_text_field( wp_unslash( $_GET['export_month'] ) ) : ''; // e.g. "2026/05"
        $views_meta_key = isset( $_GET['views_meta_key'] ) ? sanitize_key( wp_unslash( $_GET['views_meta_key'] ) ) : 'views';

        if ( empty( $month_str ) ) {
            wp_die( '請指定要匯出的月份。' );
        }

        $parts = explode( '/', $month_str );
        if ( count( $parts ) !== 2 ) {
            wp_die( '月份格式錯誤。' );
        }

        $year  = intval( $parts[0] );
        $month = intval( $parts[1] );

        $posts_data = $this->query_posts_by_month( $year, $month, $views_meta_key );

        // 設置下載 Header
        $filename = 'posts-export-' . str_replace( '/', '-', $month_str ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . esc_attr( $filename ) . '"' );
        
        // 寫入 UTF-8 BOM 避免 Excel 開啟中文時亂碼
        echo "\xEF\xBB\xBF";

        $output = fopen( 'php://output', 'w' );
        
        // 寫入欄位名稱
        fputcsv( $output, [ '日期', '文章標題', '切角/主題', '連結', '瀏覽量' ] );

        // 寫入內容
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
     * 依年月查詢文章與瀏覽量等資料
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

                // 1. 日期格式 Y/m/d
                $date = get_the_date( 'Y/m/d', $post_id );

                // 2. 標題
                $title = get_the_title( $post_id );

                // 3. 切角/主題 (合併分類與標籤)
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
                $topics_str = implode( '、', $topics );

                // 4. 連結
                $link = get_permalink( $post_id );

                // 5. 瀏覽量
                $views = 0;
                $is_jnews_key = ( stripos( $views_meta_key, 'jnews' ) !== false );

                if ( $is_jnews_key ) {
                    if ( function_exists( 'jnews_get_views' ) ) {
                        $views = intval( jnews_get_views( $post_id, 'all', false ) );
                    } else {
                        // Fallback to direct DB query if JNews function is not loaded
                        global $wpdb;
                        $table_name = $wpdb->prefix . 'popularpostsdata';
                        
                        // 快取資料表是否存在檢查以提升效能與符合 Plugin Check 標準
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
     * 清除全站 oEmbed 快取
     */
    public function clear_oembed_cache() {
        check_ajax_referer( 'omni_export_posts_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '權限不足' );
        }

        $deleted = $this->purge_oembed_cache();

        if ( false === $deleted ) {
            wp_send_json_error( '清除失敗，資料庫查詢錯誤。' );
        } else {
            wp_send_json_success( sprintf( '成功清除全站 oEmbed 快取（共刪除 %d 筆快取紀錄）。請重新整理網頁查看效果。', $deleted ) );
        }
    }

    /**
     * 設定變更時，若影響嵌入呈現（清理 Head / 嵌入樣式）則自動清除 oEmbed 失敗快取
     *
     * @param mixed $old_value 變更前設定
     * @param mixed $new_value 變更後設定
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
     * 實際執行刪除全站 _oembed_* postmeta 快取，並清空物件快取以強制重新抓取。
     *
     * @return int|false 刪除筆數，失敗時回傳 false。
     */
    private function purge_oembed_cache() {
        global $wpdb;

        // esc_like 會跳脫 meta_key 前綴的底線（_），避免 LIKE 單字元萬用字元誤判。
        $like = $wpdb->esc_like( '_oembed_' ) . '%';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->query(
            $wpdb->prepare( "DELETE FROM $wpdb->postmeta WHERE meta_key LIKE %s", $like )
        );

        // 直接 SQL 刪除不會同步更新持久化物件快取，清空以避免讀到舊的 {{unknown}} 失敗值。
        if ( false !== $deleted ) {
            wp_cache_flush();
        }

        return $deleted;
    }
}
