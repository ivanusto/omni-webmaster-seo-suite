<?php
/**
 * Omni_Disable_Thumbnails Class
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Omni_Disable_Thumbnails {
    
    private $option_name = 'omni_webmaster_settings';
    private $sizes_option_name = 'omni_webmaster_known_sizes';
    
    public function __construct() {
        // 核心縮圖停用鉤子
        add_action( 'init', [ $this, 'disable_existing_image_sizes' ], 999 );
        add_filter( 'intermediate_image_sizes_advanced', [ $this, 'disable_image_sizes' ], 999 );
        
        // 管理介面相關（由 Admin 類別載入，這裡先註冊 AJAX 端點）
        if ( is_admin() ) {
            add_action( 'admin_init', [ $this, 'update_known_sizes' ] );
            add_action( 'wp_ajax_omni_delete_thumbnails', [ $this, 'handle_delete_thumbnails' ] );
        }
    }

    /**
     * 更新已知圖片尺寸清單（避免外掛/佈景停用後，歷史尺寸被遺漏）
     */
    public function update_known_sizes() {
        $current_sizes = $this->get_current_image_sizes();
        $known_sizes = get_option( $this->sizes_option_name, [] );
        
        $updated_sizes = array_merge( $known_sizes, $current_sizes );
        update_option( $this->sizes_option_name, $updated_sizes );
    }

    /**
     * 獲取當前系統中的所有圖片尺寸
     */
    public function get_current_image_sizes() {
        global $_wp_additional_image_sizes;
        $sizes = [];
        
        // 獲取所有已註冊的圖片尺寸
        $registered_sizes = wp_get_registered_image_subsizes();
        
        // 內建尺寸列表
        $builtin_sizes = [ 'thumbnail', 'medium', 'medium_large', 'large', '1536x1536', '2048x2048' ];
        
        foreach ( $builtin_sizes as $size ) {
            if ( isset( $registered_sizes[$size] ) || in_array( $size, [ '1536x1536', '2048x2048' ] ) ) {
                $width  = get_option( "{$size}_size_w" );
                $height = get_option( "{$size}_size_h" );
                $crop   = get_option( "{$size}_crop" );
                
                $sizes[$size] = [
                    'width'   => $width,
                    'height'  => $height,
                    'crop'    => $crop,
                    'builtin' => true
                ];
            }
        }
        
        foreach ( $registered_sizes as $size => $data ) {
            if ( ! isset( $sizes[$size] ) ) {
                $sizes[$size] = [
                    'width'   => $data['width'],
                    'height'  => $data['height'],
                    'crop'    => $data['crop'],
                    'builtin' => false
                ];
            }
        }
        
        if ( is_array( $_wp_additional_image_sizes ) ) {
            foreach ( $_wp_additional_image_sizes as $size => $data ) {
                if ( ! isset( $sizes[$size] ) ) {
                    $sizes[$size] = [
                        'width'   => $data['width'],
                        'height'  => $data['height'],
                        'crop'    => $data['crop'],
                        'builtin' => false
                    ];
                }
            }
        }
        
        return $sizes;
    }

    /**
     * 獲取所有已知的圖片尺寸（包括已停用的歷史尺寸）
     */
    public function get_all_image_sizes() {
        $known_sizes   = get_option( $this->sizes_option_name, [] );
        $current_sizes = $this->get_current_image_sizes();
        $sizes         = array_merge( $known_sizes, $current_sizes );
        
        foreach ( $sizes as $size => &$data ) {
            $data['name'] = $this->get_size_name( $size );
        }
        
        return $this->sort_sizes( $sizes );
    }

    /**
     * 排序圖片尺寸
     */
    private function sort_sizes( $sizes ) {
        $builtin_order = [
            'thumbnail'    => 1,
            'medium'       => 2,
            'medium_large' => 3,
            'large'        => 4,
            '1536x1536'    => 5,
            '2048x2048'    => 6
        ];
        
        $sorted = [];
        $custom = [];
        
        foreach ( $sizes as $size => $data ) {
            if ( isset( $builtin_order[$size] ) ) {
                $sorted[$builtin_order[$size]] = [ $size => $data ];
            } else {
                $custom[$size] = $data;
            }
        }
        
        ksort( $sorted );
        ksort( $custom );
        
        $result = [];
        foreach ( $sorted as $items ) {
            $result = array_merge( $result, $items );
        }
        
        return array_merge( $result, $custom );
    }

    /**
     * 獲取圖片尺寸的中文顯示名稱
     */
    private function get_size_name( $size ) {
        $names = [
            'thumbnail'    => '縮圖 (Thumbnail)',
            'medium'       => '中尺寸 (Medium)',
            'medium_large' => '中大尺寸 (Medium Large)',
            'large'        => '大尺寸 (Large)',
            '1536x1536'    => '1536 像素 (1536x1536)',
            '2048x2048'    => '2048 像素 (2048x2048)'
        ];

        if ( isset( $names[$size] ) ) {
            return $names[$size];
        }

        $size_name = str_replace( [ '-', '_' ], ' ', $size );
        $size_name = ucwords( $size_name );
        return '自訂尺寸：' . $size_name;
    }

    /**
     * 停用所勾選的圖片尺寸生成
     */
    public function disable_existing_image_sizes() {
        $settings = get_option( $this->option_name, [] );
        $disabled_sizes = isset( $settings['disabled_sizes'] ) && is_array( $settings['disabled_sizes'] ) ? $settings['disabled_sizes'] : [];
        
        if ( empty( $disabled_sizes ) ) {
            return;
        }
        
        foreach ( $disabled_sizes as $size ) {
            remove_image_size( $size );
            
            // 如果是 WordPress 內建尺寸，將其寬高設為 0 以防生成
            if ( in_array( $size, [ 'thumbnail', 'medium', 'large' ], true ) ) {
                update_option( "{$size}_size_w", 0 );
                update_option( "{$size}_size_h", 0 );
            }
        }
    }

    /**
     * 阻止圖片上傳時的特定尺寸縮圖生成
     */
    public function disable_image_sizes( $sizes ) {
        $settings = get_option( $this->option_name, [] );
        $disabled_sizes = isset( $settings['disabled_sizes'] ) && is_array( $settings['disabled_sizes'] ) ? $settings['disabled_sizes'] : [];
        
        if ( empty( $disabled_sizes ) ) {
            return $sizes;
        }
        
        foreach ( $disabled_sizes as $size ) {
            if ( isset( $sizes[$size] ) ) {
                unset( $sizes[$size] );
            }
        }
        
        return $sizes;
    }

    /**
     * 處理 AJAX 批次刪除縮圖請求
     */
    public function handle_delete_thumbnails() {
        if ( ! check_ajax_referer( 'omni_delete_thumbnails_nonce', 'nonce', false ) ) {
            wp_send_json_error( '無效的安全性權限檢查 (Nonce validation failed)' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '權限不足' );
        }

        $page        = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $delete_mode = isset( $_POST['delete_mode'] ) ? sanitize_key( $_POST['delete_mode'] ) : 'disabled_only';
        if ( ! in_array( $delete_mode, [ 'all', 'disabled_only' ], true ) ) {
            $delete_mode = 'disabled_only';
        }

        $batch_size = 50; // 批次處理數量

        global $wpdb;
        $cache_key    = 'omni_total_images_count';
        $total_images = wp_cache_get( $cache_key, 'omni-webmaster' );
        if ( false === $total_images ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $total_images = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" );
            wp_cache_set( $cache_key, $total_images, 'omni-webmaster', 300 );
        }
        $total_images = (int) $total_images;

        $args = [
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => $batch_size,
            'paged'          => $page,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC'
        ];

        $images = get_posts( $args );

        if ( empty( $images ) ) {
            wp_send_json_success([
                'processed' => 0,
                'deleted'   => 0,
                'finished'  => true,
                'total'     => $total_images
            ]);
        }

        $deleted_count  = 0;
        $upload_dir     = wp_upload_dir();
        $base_dir       = trailingslashit( $upload_dir['basedir'] );

        $settings       = get_option( $this->option_name, [] );
        $disabled_sizes = isset( $settings['disabled_sizes'] ) && is_array( $settings['disabled_sizes'] ) ? $settings['disabled_sizes'] : [];

        foreach ( $images as $image_id ) {
            $metadata = wp_get_attachment_metadata( $image_id );
            
            if ( empty( $metadata ) || empty( $metadata['sizes'] ) ) {
                continue;
            }

            if ( isset( $metadata['file'] ) && isset( $metadata['sizes'] ) ) {
                $file_dir = trailingslashit( dirname( $metadata['file'] ) );
                $metadata_changed = false;
                
                foreach ( $metadata['sizes'] as $size => $size_info ) {
                    // 如果為「僅刪除已停用尺寸」模式，但此尺寸未被停用，則跳過不處理
                    if ( 'disabled_only' === $delete_mode && ! in_array( $size, $disabled_sizes, true ) ) {
                        continue;
                    }

                    if ( empty( $size_info['file'] ) ) {
                        continue;
                    }
                    
                    $file_path = $base_dir . $file_dir . $size_info['file'];
                    if ( file_exists( $file_path ) ) {
                        wp_delete_file( $file_path );
                        $deleted_count++;
                    }
                    
                    // 刪除對應的 WebP 版本 (.webp)
                    $webp_path = preg_replace( '/\.(jpg|jpeg|png)$/i', '.webp', $file_path );
                    if ( file_exists( $webp_path ) ) {
                        wp_delete_file( $webp_path );
                        $deleted_count++;
                    }
                    
                    // 刪除附加的 .webp 檔案 (如 file.jpg.webp)
                    $alt_webp_path = $file_path . '.webp';
                    if ( file_exists( $alt_webp_path ) ) {
                        wp_delete_file( $alt_webp_path );
                        $deleted_count++;
                    }

                    unset( $metadata['sizes'][$size] );
                    $metadata_changed = true;
                }
                
                if ( $metadata_changed ) {
                    wp_update_attachment_metadata( $image_id, $metadata );
                }
            }
        }

        $finished = count( $images ) < $batch_size;

        wp_send_json_success([
            'processed' => count( $images ),
            'deleted'   => $deleted_count,
            'finished'  => $finished,
            'total'     => $total_images
        ]);
    }
}
