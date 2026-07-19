<?php
/**
 * Omni_Slug_Converter Class
 *
 * 核心邏輯與獨立外掛 zh-to-en-slug 同步維護：
 * https://github.com/ivanusto/zh-to-en-slug
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Omni_Slug_Converter {

    private $options = null;

    public function __construct() {
        // 修改 slug 生成的時機
        add_filter( 'wp_insert_post_data', [ $this, 'process_post_data' ], 10, 2 );

        // AJAX API 測試端點
        if ( is_admin() ) {
            add_action( 'wp_ajax_omni_test_translation_api', [ $this, 'test_translation_api' ] );
        }
    }

    /**
     * 延遲載入設定並合併預設值，避免舊資料缺少個別 key
     */
    private function get_options() {
        if ( null === $this->options ) {
            $settings      = get_option( 'omni_webmaster_settings', [] );
            $this->options = [
                'api_key'    => isset( $settings['slug_api_key'] ) ? $settings['slug_api_key'] : '',
                'max_length' => isset( $settings['slug_max_length'] ) ? absint( $settings['slug_max_length'] ) : 30,
            ];
        }
        return $this->options;
    }

    /**
     * 當文章儲存時，如果標題含中文，進行翻譯並轉成英文 Slug
     */
    public function process_post_data( $data, $postarr ) {
        // 自動儲存或修訂版不處理
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return $data;
        }

        if ( empty( $data['post_title'] ) ) {
            return $data;
        }

        // 允許處理的文章狀態白名單，可透過 filter 自訂
        $allowed_statuses = apply_filters( 'omni_slug_allowed_statuses', [ 'draft', 'publish', 'future', 'pending', 'private' ] );
        $current_status   = isset( $postarr['post_status'] ) ? $postarr['post_status'] : '';

        if ( ! in_array( $current_status, $allowed_statuses, true ) ) {
            return $data;
        }

        // 檢查是否包含中文（含 CJK Extension A 與基本區全範圍）
        if ( ! preg_match( '/[\x{3400}-\x{4DBF}\x{4e00}-\x{9FFF}]/u', $data['post_title'] ) ) {
            return $data;
        }

        // 如果已經有手動輸入的客製化 slug（不是預設的 auto-draft 且不是純中文 sanitize 後的結果），不重複翻譯
        if ( ! empty( $postarr['post_name'] ) &&
             ! preg_match( '/^auto-draft/', $postarr['post_name'] ) &&
             $postarr['post_name'] !== sanitize_title( $data['post_title'] ) &&
             ! empty( $postarr['ID'] ) ) {
            return $data;
        }

        $translated = $this->translate_title( $data['post_title'] );
        if ( $translated ) {
            $base_slug = $this->create_slug( $translated );
            $post_id   = isset( $postarr['ID'] ) ? $postarr['ID'] : 0;

            // 已存在 ID 的文章，尾部附加 ID 避免重複衝突
            if ( $post_id > 0 ) {
                $data['post_name'] = $base_slug . '-' . $post_id;
            } else {
                // 新文章尚無 ID，直接使用翻譯後的 slug；
                // 唯一性由 WordPress 核心的 wp_unique_post_slug 保證
                $data['post_name'] = $base_slug;
            }
        }

        return $data;
    }

    /**
     * 呼叫官方 Google Cloud Translation API
     */
    private function call_cloud_api( $api_key, $text ) {
        $url = add_query_arg(
            [ 'key' => $api_key ],
            'https://translation.googleapis.com/language/translate/v2'
        );

        $args = [
            'body'    => wp_json_encode( [
                'q'      => $text,
                'source' => 'zh-TW',
                'target' => 'en',
                // 要求純文字回應，避免回傳 HTML entities（如 &#39;）污染 slug
                'format' => 'text',
            ] ),
            'headers' => [
                'Content-Type'     => 'application/json; charset=utf-8',
                'Referer'          => home_url(),
                'X-Requested-With' => 'XMLHttpRequest',
            ],
            // 翻譯失敗會回退到免金鑰端點或 WP 預設 slug，寧可快速失敗也不要卡住存檔流程
            'timeout' => 8,
        ];

        return wp_remote_post( $url, $args );
    }

    /**
     * 呼叫免 Key 的 Google Translate 公開端點（Fallback）
     */
    private function call_free_api( $text ) {
        $url = add_query_arg(
            [
                'client' => 'gtx',
                'sl'     => 'zh-TW',
                'tl'     => 'en',
                'dt'     => 't',
                'q'      => $text,
            ],
            'https://translate.googleapis.com/translate_a/single'
        );

        $args = [
            'headers' => [
                'Referer' => home_url(),
            ],
            'timeout' => 5,
        ];

        return wp_remote_get( $url, $args );
    }

    /**
     * 從 Cloud API 回應取出翻譯結果，失敗回傳 false
     */
    private function parse_cloud_response( $response ) {
        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return isset( $body['data']['translations'][0]['translatedText'] ) ? $body['data']['translations'][0]['translatedText'] : false;
    }

    /**
     * 從免金鑰端點回應取出翻譯結果，失敗回傳 false
     */
    private function parse_free_response( $response ) {
        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return isset( $body[0][0][0] ) ? $body[0][0][0] : false;
    }

    /**
     * 翻譯標題（優先使用 Cloud API，若無金鑰或呼叫失敗則改用免金鑰公開端點）
     */
    private function translate_title( $title ) {
        // 相同標題直接使用快取，減少 API 呼叫次數與存檔延遲
        $cache_key = 'omni_slug_tr_' . md5( $title );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $options    = $this->get_options();
        $translated = false;

        if ( ! empty( $options['api_key'] ) ) {
            $translated = $this->parse_cloud_response( $this->call_cloud_api( $options['api_key'], $title ) );
        }

        if ( false === $translated || '' === $translated ) {
            $translated = $this->parse_free_response( $this->call_free_api( $title ) );
        }

        if ( false !== $translated && '' !== $translated ) {
            set_transient( $cache_key, $translated, WEEK_IN_SECONDS );
            return $translated;
        }

        return false;
    }

    /**
     * 處理翻譯結果為標準 Slug 格式
     */
    private function create_slug( $text ) {
        $text = strtolower( $text );
        $text = remove_accents( $text );
        // 免金鑰端點可能回傳 HTML entities，先解碼再過濾
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
        $text = preg_replace( '/[^a-z0-9\s-]/', '', $text );
        $text = preg_replace( '/\s+/', '-', $text );
        $text = trim( $text, '-' );

        // 取得設定的最大長度，預留空間給 post_id
        $options         = $this->get_options();
        $max_length      = absint( $options['max_length'] );
        $reserved_length = 12; // 預留給 "-123456" 這樣的 ID 格式
        // 保底 8 個字元，避免設定值過小時 slug 被截成空字串
        $actual_max_length = max( 8, $max_length - $reserved_length );

        if ( strlen( $text ) > $actual_max_length ) {
            $text = substr( $text, 0, $actual_max_length );
            $text = preg_replace( '/-[^-]*$/', '', $text ); // 移除尾部未完字詞
        }

        return $text;
    }

    /**
     * 測試 API 連線的 AJAX 端點
     */
    public function test_translation_api() {
        check_ajax_referer( 'omni_slug_test_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '權限不足' );
            return;
        }

        $api_key   = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
        $test_text = '測試文字';

        // 如果沒填 key，測試公開免 Key 端點
        if ( '' === $api_key ) {
            $response = $this->call_free_api( $test_text );

            if ( is_wp_error( $response ) ) {
                wp_send_json_error( '免金鑰 API 測試連線失敗：' . esc_html( $response->get_error_message() ) );
                return;
            }

            $translated = $this->parse_free_response( $response );
            if ( false !== $translated ) {
                wp_send_json_success( '免金鑰翻譯測試成功！翻譯結果：' . esc_html( $translated ) );
            } else {
                wp_send_json_error( '免金鑰翻譯端點回傳異常資料' );
            }
            return;
        }

        $response = $this->call_cloud_api( $api_key, $test_text );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Cloud API 連線失敗：' . esc_html( $response->get_error_message() ) );
            return;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['data']['translations'][0]['translatedText'] ) ) {
            wp_send_json_success( 'Cloud API 測試成功！翻譯結果：' . esc_html( $body['data']['translations'][0]['translatedText'] ) );
        } elseif ( isset( $body['error']['message'] ) ) {
            wp_send_json_error( 'Cloud API 錯誤：' . esc_html( $body['error']['message'] ) );
        } else {
            wp_send_json_error( '無法取得翻譯結果，請檢查 API Key 是否正確' );
        }
    }
}
