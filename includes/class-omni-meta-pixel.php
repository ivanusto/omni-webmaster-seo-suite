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
     * Settings cache to avoid re-reading the option within the same request
     */
    private $settings = null;

    public function __construct() {
        if ( ! empty( $this->get_settings()['meta_pixel_enable'] ) && '' !== $this->get_pixel_id() ) {
            // The tracking code is a static file; its settings go in a JSON data block, so no inline script is printed
            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_pixel_script' ] );
            add_action( 'wp_head', [ $this, 'insert_pixel_config' ], 2 );

            // Insert the noscript pixel code at wp_body_open
            add_action( 'wp_body_open', [ $this, 'insert_pixel_noscript' ] );

            // Fallback: load in wp_footer for older themes that do not support wp_body_open
            add_action( 'wp_footer', [ $this, 'insert_pixel_noscript_fallback' ] );

            // Preconnect to the Facebook domain early to speed up fbevents.js loading
            add_filter( 'wp_resource_hints', [ $this, 'add_resource_hints' ], 10, 2 );
        }
    }

    /**
     * Get the plugin settings (cached within the same request)
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
     * Get the digits-only Pixel ID (Meta Pixel IDs consist of digits only)
     */
    private function get_pixel_id() {
        return preg_replace( '/\D/', '', (string) $this->get_settings()['meta_pixel_id'] );
    }

    /**
     * Determine whether the current request should output the tracking code
     */
    private function should_track() {
        // Exclude non-standard front-end pages: feeds, post previews, Customizer previews, and oEmbed pages
        if ( is_admin() || is_feed() || is_preview() || is_customize_preview() || is_embed() ) {
            return false;
        }

        // Exclude logged-in site staff so their own browsing does not pollute ad audience data
        $settings = $this->get_settings();
        if ( ! empty( $settings['meta_pixel_exclude_admins'] ) && current_user_can( 'edit_posts' ) ) {
            return false;
        }

        /**
         * Allow themes or other plugins (e.g. cookie consent mechanisms) to conditionally disable Pixel tracking.
         *
         * @param bool $enabled Whether to output the Meta Pixel tracking code.
         */
        return (bool) apply_filters( 'omni_meta_pixel_enabled', true );
    }

    /**
     * Add preconnect / dns-prefetch resource hints
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
     * Enqueue the Meta Pixel loader (assets/js/meta-pixel.js).
     *
     * The loader reads its settings from the JSON data block printed by insert_pixel_config(),
     * so the page needs no inline script and works under a `script-src 'self'` policy
     * (plus https://connect.facebook.net for fbevents.js).
     */
    public function enqueue_pixel_script() {
        if ( '' === $this->get_pixel_id() || ! $this->should_track() ) {
            return;
        }

        wp_enqueue_script(
            'omni-meta-pixel',
            OMNI_WEBMASTER_URL . 'assets/js/meta-pixel.js',
            [],
            OMNI_WEBMASTER_VERSION,
            [ 'strategy' => 'defer' ]
        );
    }

    /**
     * Print the Pixel settings as a JSON data block (not executed, so not subject to script-src)
     */
    public function insert_pixel_config() {
        $pixel_id = $this->get_pixel_id();

        if ( '' === $pixel_id || ! $this->should_track() ) {
            return;
        }

        $config = [
            'id'     => $pixel_id,
            'events' => ! empty( $this->get_settings()['meta_pixel_advanced'] ) ? $this->build_advanced_events() : [],
        ];

        wp_print_inline_script_tag(
            wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
            [
                'type' => 'application/json',
                'id'   => 'omni-meta-pixel-config',
            ]
        );
    }

    /**
     * Build the advanced standard events for the current page, as [ event name, parameters ] pairs
     */
    private function build_advanced_events() {
        if ( is_singular() ) {
            $post_id   = get_the_ID();
            $cat_names = [];
            foreach ( get_the_category( $post_id ) as $cat ) {
                $cat_names[] = $cat->name;
            }

            return [
                [
                    'ViewContent',
                    [
                        'content_name'     => get_the_title(),
                        'content_category' => implode( ', ', $cat_names ),
                        'content_ids'      => [ (string) $post_id ],
                        'content_type'     => get_post_type(),
                    ],
                ],
            ];
        }

        if ( is_search() ) {
            return [
                [
                    'Search',
                    [
                        'search_string' => get_search_query(),
                    ],
                ],
            ];
        }

        return [];
    }

    /**
     * Insert the noscript pixel code
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
     * Noscript fallback (for themes that do not support wp_body_open)
     */
    public function insert_pixel_noscript_fallback() {
        $this->insert_pixel_noscript();
    }
}
