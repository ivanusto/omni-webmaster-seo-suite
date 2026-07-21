<?php
/**
 * Omni_Meta_Tags Class
 *
 * 在未安裝大型 SEO 外掛時，於首頁輸出 Meta Description、
 * Open Graph 社群標籤與 Schema.org（WebSite / Organization）結構化資料。
 * 單篇文章頁的 OG 標籤交由佈景主題或其他機制處理，本模組僅負責首頁。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Omni_Meta_Tags {

    /**
     * 設定值快取，避免同一請求內重複讀取 option
     */
    private $settings = null;

    public function __construct() {
        if ( ! empty( $this->get_settings()['meta_tags_enable'] ) ) {
            // priority 5：早於主題與多數外掛的 wp_head 輸出，讓 meta 標籤緊接在 charset/viewport 之後
            add_action( 'wp_head', [ $this, 'output_head_tags' ], 5 );
        }
    }

    /**
     * 取得外掛設定值（同一請求內快取）
     */
    private function get_settings() {
        if ( null === $this->settings ) {
            $defaults = [
                'meta_tags_enable'      => '0',
                'home_meta_description' => '',
                'og_default_image'      => '',
                'site_alternate_name'   => '',
                'schema_website_enable' => '1',
            ];
            $this->settings = wp_parse_args( get_option( 'omni_webmaster_settings', [] ), $defaults );
        }
        return $this->settings;
    }

    /**
     * 偵測是否已安裝大型 SEO 外掛（它們會自行輸出 meta/OG/schema，
     * 重複輸出反而有害，偵測到時本模組自動停止輸出）。
     *
     * @return string 偵測到的外掛名稱，無則回傳空字串。
     */
    public static function detect_seo_plugin() {
        if ( defined( 'WPSEO_VERSION' ) ) {
            return 'Yoast SEO';
        }
        if ( class_exists( 'RankMath' ) ) {
            return 'Rank Math';
        }
        if ( defined( 'AIOSEO_VERSION' ) ) {
            return 'All in One SEO';
        }
        if ( defined( 'SEOPRESS_VERSION' ) ) {
            return 'SEOPress';
        }
        if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
            return 'The SEO Framework';
        }
        return '';
    }

    /**
     * 於首頁 head 輸出 Meta Description、Open Graph 與結構化資料
     */
    public function output_head_tags() {
        // 僅在首頁第一頁輸出：分頁（/page/2/ 之後）不重複輸出相同描述，
        // 靜態首頁與「最新文章」模式皆由 is_front_page() 涵蓋。
        if ( ! is_front_page() || is_paged() ) {
            return;
        }

        if ( '' !== self::detect_seo_plugin() ) {
            return;
        }

        /**
         * 允許佈景主題或其他外掛條件式停用首頁 meta 標籤輸出。
         *
         * @param bool $enabled 是否輸出首頁 meta 標籤。
         */
        if ( ! apply_filters( 'omni_meta_tags_enabled', true ) ) {
            return;
        }

        $settings    = $this->get_settings();
        $description = trim( $settings['home_meta_description'] );
        $image       = trim( $settings['og_default_image'] );
        $site_name   = get_bloginfo( 'name' );
        $tagline     = get_bloginfo( 'description' );
        $og_title    = '' !== $tagline ? $site_name . ' – ' . $tagline : $site_name;

        echo "\n<!-- Omni Meta Tags -->\n";

        if ( '' !== $description ) {
            echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
        }

        echo '<meta property="og:type" content="website" />' . "\n";
        echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr( $og_title ) . '" />' . "\n";

        if ( '' !== $description ) {
            echo '<meta property="og:description" content="' . esc_attr( $description ) . '" />' . "\n";
        }

        echo '<meta property="og:url" content="' . esc_url( home_url( '/' ) ) . '" />' . "\n";

        if ( '' !== $image ) {
            echo '<meta property="og:image" content="' . esc_url( $image ) . '" />' . "\n";
            echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        } else {
            echo '<meta name="twitter:card" content="summary" />' . "\n";
        }

        echo '<meta property="og:locale" content="' . esc_attr( get_locale() ) . '" />' . "\n";

        if ( ! empty( $settings['schema_website_enable'] ) ) {
            $this->output_schema( $site_name, $description, $image );
        }

        echo "<!-- End Omni Meta Tags -->\n";
    }

    /**
     * 輸出 WebSite + Organization JSON-LD 結構化資料。
     *
     * WebSite schema 的主要作用是搜尋結果的站名識別（site name），
     * Organization 則提供 Google 知識面板與搜尋結果 logo 的資料來源。
     */
    private function output_schema( $site_name, $description, $image ) {
        $settings  = $this->get_settings();
        $home      = home_url( '/' );
        $alternate = trim( $settings['site_alternate_name'] );

        $website = [
            '@type'     => 'WebSite',
            '@id'       => $home . '#website',
            'name'      => $site_name,
            'url'       => $home,
            'publisher' => [ '@id' => $home . '#organization' ],
        ];
        if ( '' !== $alternate ) {
            $website['alternateName'] = $alternate;
        }
        if ( '' !== $description ) {
            $website['description'] = $description;
        }

        $organization = [
            '@type' => 'Organization',
            '@id'   => $home . '#organization',
            'name'  => $site_name,
            'url'   => $home,
        ];

        // logo 優先使用網站圖示（通常為正方形，符合 Google 建議），其次退回 OG 分享圖
        $logo = get_site_icon_url( 512 );
        if ( ! $logo && '' !== $image ) {
            $logo = $image;
        }
        if ( $logo ) {
            $organization['logo'] = $logo;
        }

        $graph = [
            '@context' => 'https://schema.org',
            '@graph'   => [ $website, $organization ],
        ];

        wp_print_inline_script_tag(
            wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
            [
                'type' => 'application/ld+json',
                'id'   => 'omni-schema-website',
            ]
        );
    }
}
