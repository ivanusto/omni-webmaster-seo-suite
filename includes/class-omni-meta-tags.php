<?php
/**
 * Omni_Meta_Tags Class
 *
 * When no major SEO plugin is installed, outputs the Meta Description,
 * Open Graph social tags, and Schema.org (WebSite / Organization) structured
 * data on the homepage. OG tags for single posts are left to the theme or
 * other mechanisms; this module only handles the homepage.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Omni_Meta_Tags {

    /**
     * Settings cache to avoid re-reading the option within the same request
     */
    private $settings = null;

    public function __construct() {
        if ( ! empty( $this->get_settings()['meta_tags_enable'] ) ) {
            // priority 5: earlier than the theme and most plugins' wp_head output, so the meta tags follow right after charset/viewport
            add_action( 'wp_head', [ $this, 'output_head_tags' ], 5 );
        }
    }

    /**
     * Get plugin settings (cached within the same request)
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
     * Detect whether a major SEO plugin is installed (they output their own
     * meta/OG/schema, and duplicate output is harmful, so this module stops
     * outputting automatically when one is detected).
     *
     * @return string Name of the detected plugin, or an empty string if none.
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
     * Output Meta Description, Open Graph, and structured data in the homepage head
     */
    public function output_head_tags() {
        // Only output on the first page of the homepage: paginated pages (/page/2/ and beyond)
        // do not repeat the same description. Both a static front page and the
        // "latest posts" mode are covered by is_front_page().
        if ( ! is_front_page() || is_paged() ) {
            return;
        }

        if ( '' !== self::detect_seo_plugin() ) {
            return;
        }

        /**
         * Allow themes or other plugins to conditionally disable homepage meta tag output.
         *
         * @param bool $enabled Whether to output the homepage meta tags.
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
     * Output WebSite + Organization JSON-LD structured data.
     *
     * The main purpose of the WebSite schema is site name recognition in search
     * results, while Organization provides the data source for the Google
     * knowledge panel and the logo shown in search results.
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

        // Prefer the site icon for the logo (usually square, matching Google's recommendation), falling back to the OG share image
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
