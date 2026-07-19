<?php
/**
 * Omni_Meta_Pixel Class
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Omni_Meta_Pixel {

    private $noscript_printed = false;

    /**
     * 設定值快取，避免同一請求內重複讀取 option
     */
    private $settings = null;

    public function __construct() {
        if ( ! empty( $this->get_settings()['meta_pixel_enable'] ) && '' !== $this->get_pixel_id() ) {
            // 在 wp_head 插入主追蹤程式碼
            add_action( 'wp_head', [ $this, 'insert_pixel_script' ], 20 );

            // 在 wp_body_open 插入 noscript 像素代碼
            add_action( 'wp_body_open', [ $this, 'insert_pixel_noscript' ] );

            // 若佈景主題較舊不支援 wp_body_open，作為後備在 wp_footer 載入
            add_action( 'wp_footer', [ $this, 'insert_pixel_noscript_fallback' ] );

            // 提前與 Facebook 網域建立連線，加速 fbevents.js 載入
            add_filter( 'wp_resource_hints', [ $this, 'add_resource_hints' ], 10, 2 );
        }
    }

    /**
     * 取得外掛設定值（同一請求內快取）
     */
    private function get_settings() {
        if ( null === $this->settings ) {
            $defaults = [
                'meta_pixel_enable'         => '0',
                'meta_pixel_id'             => '',
                'meta_pixel_advanced'       => '0',
                'meta_pixel_exclude_admins' => '1',
            ];
            $this->settings = wp_parse_args( get_option( 'omni_webmaster_settings', [] ), $defaults );
        }
        return $this->settings;
    }

    /**
     * 取得純數字的 Pixel ID（Meta 像素編號僅由數字組成）
     */
    private function get_pixel_id() {
        return preg_replace( '/\D/', '', (string) $this->get_settings()['meta_pixel_id'] );
    }

    /**
     * 判斷目前請求是否應輸出追蹤碼
     */
    private function should_track() {
        // 排除非一般前台頁面：Feed、文章預覽、佈景自訂器預覽與 oEmbed 嵌入頁
        if ( is_admin() || is_feed() || is_preview() || is_customize_preview() || is_embed() ) {
            return false;
        }

        // 排除登入中的網站管理人員，避免自身瀏覽污染廣告受眾數據
        $settings = $this->get_settings();
        if ( ! empty( $settings['meta_pixel_exclude_admins'] ) && current_user_can( 'edit_posts' ) ) {
            return false;
        }

        /**
         * 允許佈景主題或其他外掛（如 Cookie 同意機制）條件式停用 Pixel 追蹤。
         *
         * @param bool $enabled 是否輸出 Meta Pixel 追蹤碼。
         */
        return (bool) apply_filters( 'omni_meta_pixel_enabled', true );
    }

    /**
     * 加入 preconnect / dns-prefetch 資源提示
     */
    public function add_resource_hints( $urls, $relation_type ) {
        if ( ! $this->should_track() ) {
            return $urls;
        }

        if ( 'preconnect' === $relation_type ) {
            $urls[] = 'https://connect.facebook.net';
        } elseif ( 'dns-prefetch' === $relation_type ) {
            $urls[] = '//www.facebook.com';
        }
        return $urls;
    }

    /**
     * 插入 Meta Pixel 主 JavaScript 程式碼
     */
    public function insert_pixel_script() {
        $pixel_id = $this->get_pixel_id();

        if ( '' === $pixel_id || ! $this->should_track() ) {
            return;
        }

        $js  = "!function(f,b,e,v,n,t,s)\n";
        $js .= "{if(f.fbq)return;n=f.fbq=function(){n.callMethod?\n";
        $js .= "n.callMethod.apply(n,arguments):n.queue.push(arguments)};\n";
        $js .= "if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';\n";
        $js .= "n.queue=[];t=b.createElement(e);t.async=!0;\n";
        $js .= "t.src=v;s=b.getElementsByTagName(e)[0];\n";
        $js .= "s.parentNode.insertBefore(t,s)}(window, document,'script',\n";
        $js .= "'https://connect.facebook.net/en_US/fbevents.js');\n";
        $js .= "fbq('init', '" . esc_js( $pixel_id ) . "');\n";
        $js .= "fbq('track', 'PageView');\n";

        if ( ! empty( $this->get_settings()['meta_pixel_advanced'] ) ) {
            $js .= $this->build_advanced_events_js();
        }

        echo "<!-- Meta Pixel Code -->\n";
        wp_print_inline_script_tag( $js, [ 'id' => 'omni-meta-pixel' ] );
        echo "<!-- End Meta Pixel Code -->\n";
    }

    /**
     * 依目前頁面組出進階標準事件的 JavaScript 片段
     */
    private function build_advanced_events_js() {
        if ( is_singular() ) {
            $post_id   = get_the_ID();
            $cat_names = [];
            foreach ( get_the_category( $post_id ) as $cat ) {
                $cat_names[] = $cat->name;
            }

            $params = [
                'content_name'     => get_the_title(),
                'content_category' => implode( ', ', $cat_names ),
                'content_ids'      => [ (string) $post_id ],
                'content_type'     => get_post_type(),
            ];
            return "fbq('track', 'ViewContent', " . wp_json_encode( $params ) . ");\n";
        }

        if ( is_search() ) {
            $params = [
                'search_string' => get_search_query(),
            ];
            return "fbq('track', 'Search', " . wp_json_encode( $params ) . ");\n";
        }

        return '';
    }

    /**
     * 插入 noscript 像素代碼
     */
    public function insert_pixel_noscript() {
        if ( $this->noscript_printed ) {
            return;
        }

        $pixel_id = $this->get_pixel_id();

        if ( '' === $pixel_id || ! $this->should_track() ) {
            return;
        }

        $src = 'https://www.facebook.com/tr?id=' . rawurlencode( $pixel_id ) . '&ev=PageView&noscript=1';
        ?>
<!-- Meta Pixel Code (Noscript) -->
<noscript><img height="1" width="1" style="display:none" alt=""
src="<?php echo esc_url( $src ); ?>"
/></noscript>
<!-- End Meta Pixel Code (Noscript) -->
        <?php
        $this->noscript_printed = true;
    }

    /**
     * noscript 後備機制 (用於不支援 wp_body_open 的主題)
     */
    public function insert_pixel_noscript_fallback() {
        $this->insert_pixel_noscript();
    }
}
