<?php
/**
 * Omni_Google_Tag Class
 *
 * Google tag (gtag.js) for Google Analytics 4 and other Google products, without inline JavaScript.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Omni_Google_Tag {

    /**
     * Settings cache to avoid re-reading the option within the same request
     */
    private $settings = null;

    public function __construct() {
        if ( ! empty( $this->get_settings()['google_tag_enable'] ) && '' !== $this->get_tag_id() ) {
            // The loader is a static file; its settings go in a JSON data block, so no inline script is printed
            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_tag_script' ] );
            add_action( 'wp_head', [ $this, 'insert_tag_config' ], 2 );
            add_filter( 'wp_resource_hints', [ $this, 'add_resource_hints' ], 10, 2 );
        }
    }

    /**
     * Get the plugin settings (cached within the same request)
     */
    private function get_settings() {
        if ( null === $this->settings ) {
            $defaults = [
                'google_tag_enable'         => '0',
                'google_tag_id'             => '',
                'google_tag_exclude_admins' => '1',
            ];
            $this->settings = wp_parse_args( get_option( 'omni_webmaster_settings', [] ), $defaults );
        }
        return $this->settings;
    }

    /**
     * Normalise a Google tag ID (G-, GT-, AW- or DC- followed by letters and digits); '' when it is not one.
     *
     * @param string $value Raw input.
     * @return string
     */
    public static function sanitize_tag_id( $value ) {
        $value = strtoupper( preg_replace( '/\s+/', '', (string) $value ) );
        return preg_match( '/^(G|GT|AW|DC)-[A-Z0-9]{4,20}$/', $value ) ? $value : '';
    }

    /**
     * Get the validated tag ID
     */
    private function get_tag_id() {
        return self::sanitize_tag_id( $this->get_settings()['google_tag_id'] );
    }

    /**
     * Determine whether the current request should output the tag
     */
    private function should_track() {
        // Exclude non-standard front-end pages: feeds, post previews, Customizer previews, and oEmbed pages
        if ( is_admin() || is_feed() || is_preview() || is_customize_preview() || is_embed() ) {
            return false;
        }

        // Exclude logged-in site staff so their own browsing does not show up in the reports
        $settings = $this->get_settings();
        if ( ! empty( $settings['google_tag_exclude_admins'] ) && current_user_can( 'edit_posts' ) ) {
            return false;
        }

        /**
         * Allow themes or other plugins (e.g. cookie consent mechanisms) to conditionally disable the Google tag.
         *
         * @param bool $enabled Whether to output the Google tag.
         */
        return (bool) apply_filters( 'omni_google_tag_enabled', true );
    }

    /**
     * Add a dns-prefetch hint for gtag.js
     */
    public function add_resource_hints( $urls, $relation_type ) {
        if ( 'dns-prefetch' === $relation_type && $this->should_track() ) {
            $urls[] = '//www.googletagmanager.com';
        }
        return $urls;
    }

    /**
     * Enqueue the loader (assets/js/google-tag.js) in the head, async, so page views are recorded as early
     * as with the standard snippet. It reads the tag ID from the JSON data block printed by insert_tag_config()
     * (which comes earlier in the head) and loads https://www.googletagmanager.com/gtag/js, so the page works
     * under a `script-src 'self' https://www.googletagmanager.com` policy.
     */
    public function enqueue_tag_script() {
        if ( '' === $this->get_tag_id() || ! $this->should_track() ) {
            return;
        }

        wp_enqueue_script(
            'omni-google-tag',
            OMNI_WEBMASTER_URL . 'assets/js/google-tag.js',
            [],
            OMNI_WEBMASTER_VERSION,
            [
                'strategy'  => 'async',
                'in_footer' => false,
            ]
        );
    }

    /**
     * Print the tag settings as a JSON data block (not executed, so not subject to script-src)
     */
    public function insert_tag_config() {
        $tag_id = $this->get_tag_id();

        if ( '' === $tag_id || ! $this->should_track() ) {
            return;
        }

        /**
         * Parameters passed to gtag('config', <tag ID>, ...), e.g. [ 'send_page_view' => false ].
         *
         * @param array  $params Config parameters (empty by default).
         * @param string $tag_id The Google tag ID.
         */
        $params = (array) apply_filters( 'omni_google_tag_config', [], $tag_id );

        $config = [ 'id' => $tag_id ];
        if ( $params ) {
            $config['config'] = $params;
        }

        wp_print_inline_script_tag(
            wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
            [
                'type' => 'application/json',
                'id'   => 'omni-google-tag-config',
            ]
        );
    }
}
