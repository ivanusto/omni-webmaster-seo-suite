<?php
/**
 * Omni_Slug_Converter Class
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Omni_Slug_Converter {
    
    private $options;
    
    public function __construct() {
        $settings = get_option( 'omni_webmaster_settings', [] );
        $this->options = [
            'api_key'    => isset( $settings['slug_api_key'] ) ? $settings['slug_api_key'] : '',
            'max_length' => isset( $settings['slug_max_length'] ) ? absint( $settings['slug_max_length'] ) : 30
        ];
        
        // 修改 slug 生成的時機
        add_filter( 'wp_insert_post_data', [ $this, 'process_post_data' ], 10, 2 );
        
        // AJAX API 測試端點
        if ( is_admin() ) {
            add_action( 'wp_ajax_omni_test_translation_api', [ $this, 'test_translation_api' ] );
        }
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
        
        // 僅允許草稿 (draft) 和發布 (publish) 狀態處理
        $allowed_statuses = [ 'draft', 'publish' ];
        $current_status   = isset( $postarr['post_status'] ) ? $postarr['post_status'] : '';
        
         // 檢查是否含有中文字 (使用更完整的 Han Unicode 範圍檢查)
        if ( ! preg_match( '/\p{Han}/u', $data['post_title'] ) ) {
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
                $data['post_name'] = $base_slug;
            }
        }
        
        return $data;
    }
    
    /**
     * 呼叫 Google 翻譯 API (優先使用 Cloud API，若無金鑰則使用免 Key 公開端點，並使用 Transient 快取以提升效能)
     */
    private function translate_title( $title ) {
        $cache_key = 'omni_slug_tr_' . md5( $title );
        $cached    = get_transient( $cache_key );
        
        if ( false !== $cached ) {
            return $cached;
        }

        $translated = false;

        if ( ! empty( $this->options['api_key'] ) ) {
            // 方案 A: 官方 Google Cloud Translation API
            $url = add_query_arg(
                [ 'key' => $this->options['api_key'] ],
                'https://translation.googleapis.com/language/translate/v2'
            );
            
            $body = [
                'q'      => $title,
                'source' => 'zh-TW',
                'target' => 'en',
            ];
            
            $args = [
                'body'    => wp_json_encode( $body ),
                'headers' => [
                    'Content-Type'     => 'application/json; charset=utf-8',
                    'Referer'          => home_url(),
                    'X-Requested-With' => 'XMLHttpRequest'
                ],
                'timeout' => 5, // 縮短為 5 秒
            ];
            
            $response = wp_remote_post( $url, $args );
            
            if ( ! is_wp_error( $response ) ) {
                $body_decoded = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( isset( $body_decoded['data']['translations'][0]['translatedText'] ) ) {
                    $translated = $body_decoded['data']['translations'][0]['translatedText'];
                }
            }
        }

        // 方案 B: 免 Key 的官方公開端點作為 Fallback (若金鑰未設定或 Cloud API 調用失敗時)
        if ( ! $translated ) {
            $url = 'https://translate.googleapis.com/translate_a/single';
            $url = add_query_arg(
                [
                    'client' => 'gtx',
                    'sl'     => 'zh-TW',
                    'tl'     => 'en',
                    'dt'     => 't',
                    'q'      => rawurlencode( $title ),
                ],
                $url
            );

            $args = [
                'headers' => [
                    'Referer' => home_url(),
                ],
                'timeout' => 5, // 縮短為 5 秒
            ];

            $response = wp_remote_get( $url, $args );

            if ( ! is_wp_error( $response ) ) {
                $body_decoded = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( isset( $body_decoded[0][0][0] ) ) {
                    $translated = $body_decoded[0][0][0];
                }
            }
        }

        // 若翻譯成功，寫入快取 (保存 30 天)
        if ( $translated ) {
            set_transient( $cache_key, $translated, DAY_IN_SECONDS * 30 );
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
        // 解碼可能包含的 HTML 實體
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
        $text = preg_replace( '/[^a-z0-9\s-]/', '', $text );
        $text = preg_replace( '/\s+/', '-', $text );
        $text = trim( $text, '-' );
        
        $max_length = isset( $this->options['max_length'] ) ? $this->options['max_length'] : 30;
        $reserved_length = 12; // 預留空間給 -%post_id%
        $actual_max_length = $max_length - $reserved_length;
        if ( $actual_max_length < 5 ) {
            $actual_max_length = 5;
        }
        
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
        
        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
        $test_text = "測試中文字";
        
        if ( empty( $api_key ) ) {
            // 如果沒填 key，測試公開免 Key 端點
            $url = 'https://translate.googleapis.com/translate_a/single';
            $url = add_query_arg(
                [
                    'client' => 'gtx',
                    'sl'     => 'zh-TW',
                    'tl'     => 'en',
                    'dt'     => 't',
                    'q'      => rawurlencode( $test_text ),
                ],
                $url
            );

            $args = [
                'headers' => [
                    'Referer' => home_url(),
                ],
                'timeout' => 5,
            ];

            $response = wp_remote_get( $url, $args );

            if ( is_wp_error( $response ) ) {
                wp_send_json_error( '免金鑰 API 測試連線失敗：' . $response->get_error_message() );
                return;
            }

            $body_decoded = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $body_decoded[0][0][0] ) ) {
                wp_send_json_success( '免金鑰翻譯測試成功！翻譯結果：' . $body_decoded[0][0][0] );
            } else {
                wp_send_json_error( '免金鑰翻譯端點回傳異常資料' );
            }
            return;
        }
        
        $url = add_query_arg(
            [ 'key' => $api_key ],
            'https://translation.googleapis.com/language/translate/v2'
        );
        
        $body = [
            'q'      => $test_text,
            'source' => 'zh-TW',
            'target' => 'en',
        ];
        
        $args = [
            'body'    => wp_json_encode( $body ),
            'headers' => [
                'Content-Type'     => 'application/json; charset=utf-8',
                'Referer'          => home_url(),
                'X-Requested-With' => 'XMLHttpRequest'
            ],
            'timeout' => 5,
        ];
        
        $response = wp_remote_post( $url, $args );
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Cloud API 連線失敗：' . $response->get_error_message() );
            return;
        }
        
        $body_decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        
        if ( isset( $body_decoded['data']['translations'][0]['translatedText'] ) ) {
            wp_send_json_success( 'Cloud API 測試成功！翻譯結果：' . $body_decoded['data']['translations'][0]['translatedText'] );
        } elseif ( isset( $body_decoded['error'] ) ) {
            wp_send_json_error( 'Cloud API 錯誤：' . $body_decoded['error']['message'] );
        } else {
            wp_send_json_error( '無法取得翻譯結果，請檢查 API Key 是否正確' );
        }
    }
}
