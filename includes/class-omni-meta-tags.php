<?php
/**
 * Omni_Meta_Tags Class
 *
 * When no major SEO plugin is installed, outputs the Meta Description,
 * Open Graph social tags, and Schema.org structured data. The homepage
 * (WebSite / Organization) and single posts/pages (Article) are separate
 * switches: the single-post switch is off by default because most themes
 * already print their own OG tags and duplicates break share previews.
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
        $settings = $this->get_settings();
        if ( ! empty( $settings['meta_tags_enable'] ) || ! empty( $settings['og_singular_enable'] ) ) {
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
                'og_singular_enable'    => '0',
                'og_singular_schema'    => '1',
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
     * Decide which set of tags the current request needs and print it
     */
    public function output_head_tags() {
        if ( '' !== self::detect_seo_plugin() ) {
            return;
        }

        /**
         * Allow themes or other plugins to conditionally disable meta tag output.
         *
         * @param bool $enabled Whether to output the meta tags.
         */
        if ( ! apply_filters( 'omni_meta_tags_enabled', true ) ) {
            return;
        }

        $settings = $this->get_settings();

        // The front page always goes through the homepage branch, even when it is a
        // static page, so a static front page never gets article markup.
        if ( is_front_page() ) {
            // Only the first page of the homepage: paginated pages (/page/2/ and beyond)
            // do not repeat the same description.
            if ( is_paged() || empty( $settings['meta_tags_enable'] ) ) {
                return;
            }
            $this->output_home_tags();
            return;
        }

        if ( is_singular() && ! empty( $settings['og_singular_enable'] ) ) {
            $this->output_singular_tags();
        }
    }

    /**
     * Output Meta Description, Open Graph, and structured data on the homepage
     */
    private function output_home_tags() {
        $settings    = $this->get_settings();
        $description = trim( $settings['home_meta_description'] );
        $image       = trim( $settings['og_default_image'] );
        $site_name   = get_bloginfo( 'name' );
        $tagline     = get_bloginfo( 'description' );
        $og_title    = '' !== $tagline ? $site_name . ' – ' . $tagline : $site_name;

        echo "\n<!-- Omni Meta Tags -->\n";

        if ( '' !== $description ) {
            $this->meta_name( 'description', $description );
        }

        $this->meta_property( 'og:type', 'website' );
        $this->meta_property( 'og:site_name', $site_name );
        $this->meta_property( 'og:title', $og_title );

        if ( '' !== $description ) {
            $this->meta_property( 'og:description', $description );
        }

        $this->meta_url( 'og:url', home_url( '/' ) );

        if ( '' !== $image ) {
            $this->meta_url( 'og:image', $image );
            $this->meta_name( 'twitter:card', 'summary_large_image' );
        } else {
            $this->meta_name( 'twitter:card', 'summary' );
        }

        $this->meta_property( 'og:locale', get_locale() );

        if ( ! empty( $settings['schema_website_enable'] ) ) {
            $this->output_website_schema( $site_name, $description, $image );
        }

        echo "<!-- End Omni Meta Tags -->\n";
    }

    /**
     * Output Meta Description, Open Graph, and structured data on a single post or page
     */
    private function output_singular_tags() {
        $post = get_queried_object();
        if ( ! $post instanceof WP_Post ) {
            return;
        }

        $settings  = $this->get_settings();
        $site_name = get_bloginfo( 'name' );
        $title     = wp_strip_all_tags( get_the_title( $post ) );
        $permalink = get_permalink( $post );

        // Password-protected posts must not leak their content through meta tags,
        // so only the shared default image and no description are used.
        $protected   = post_password_required( $post );
        $description = $protected ? '' : $this->build_description( $post );
        $image       = $protected ? $this->get_default_image() : $this->get_singular_image( $post );

        /**
         * Filter the og:type used for a single post or page.
         *
         * @param string  $og_type The Open Graph object type.
         * @param WP_Post $post    The post being rendered.
         */
        $og_type = apply_filters( 'omni_og_type', 'page' === $post->post_type ? 'website' : 'article', $post );

        echo "\n<!-- Omni Meta Tags -->\n";

        if ( '' !== $description ) {
            $this->meta_name( 'description', $description );
        }

        $this->meta_property( 'og:type', $og_type );
        $this->meta_property( 'og:site_name', $site_name );
        $this->meta_property( 'og:title', $title );

        if ( '' !== $description ) {
            $this->meta_property( 'og:description', $description );
        }

        $this->meta_url( 'og:url', $permalink );
        $this->meta_property( 'og:locale', get_locale() );

        if ( ! empty( $image['url'] ) ) {
            $this->meta_url( 'og:image', $image['url'] );
            if ( ! empty( $image['width'] ) && ! empty( $image['height'] ) ) {
                $this->meta_property( 'og:image:width', (string) $image['width'] );
                $this->meta_property( 'og:image:height', (string) $image['height'] );
            }
            if ( ! empty( $image['alt'] ) ) {
                $this->meta_property( 'og:image:alt', $image['alt'] );
            }
        }

        if ( 'article' === $og_type ) {
            $this->meta_property( 'article:published_time', get_post_time( 'c', true, $post ) );
            $this->meta_property( 'article:modified_time', get_post_modified_time( 'c', true, $post ) );
        }

        $this->meta_name( 'twitter:card', ! empty( $image['url'] ) ? 'summary_large_image' : 'summary' );
        $this->meta_name( 'twitter:title', $title );
        if ( '' !== $description ) {
            $this->meta_name( 'twitter:description', $description );
        }
        if ( ! empty( $image['url'] ) ) {
            $this->meta_url( 'twitter:image', $image['url'] );
        }

        if ( ! empty( $settings['og_singular_schema'] ) ) {
            $this->output_article_schema( $post, $title, $description, $image );
        }

        echo "<!-- End Omni Meta Tags -->\n";
    }

    /**
     * Build the description for a single post: the manual excerpt when present,
     * otherwise the post content trimmed down to a sharable length.
     */
    private function build_description( $post ) {
        if ( has_excerpt( $post ) ) {
            $text = $post->post_excerpt;
        } else {
            $text = $post->post_content;
            // Drop blocks that never belong in an excerpt (galleries, embeds, ...)
            if ( function_exists( 'excerpt_remove_blocks' ) ) {
                $text = excerpt_remove_blocks( $text );
            }
        }

        $text = strip_shortcodes( $text );
        $text = wp_strip_all_tags( $text, true );
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
        // Cast guards against preg_replace() returning null on malformed UTF-8
        $text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

        /**
         * Filter the maximum length (in characters) of the generated description.
         *
         * @param int     $length Maximum description length.
         * @param WP_Post $post   The post being rendered.
         */
        $length = (int) apply_filters( 'omni_og_description_length', 160, $post );

        return $this->trim_text( $text, $length );
    }

    /**
     * Trim text to a character limit, backing off to the last space so a Latin
     * word is not cut in half. CJK text has no spaces, so the hard cut stands.
     */
    private function trim_text( $text, $limit ) {
        if ( '' === $text || mb_strlen( $text ) <= $limit ) {
            return $text;
        }

        $cut = mb_substr( $text, 0, $limit );

        // A space is single-byte in UTF-8, so a byte-offset search is safe here.
        $space = strrpos( $cut, ' ' );
        if ( false !== $space && $space > strlen( $cut ) * 0.6 ) {
            $cut = substr( $cut, 0, $space );
        }

        return rtrim( $cut, " ,.;:-" ) . '…';
    }

    /**
     * Resolve the share image for a single post: featured image, then the first
     * image in the content, then the site-wide default image.
     *
     * @return array Image data (url/width/height/alt), or an empty array.
     */
    private function get_singular_image( $post ) {
        /**
         * Filter the image size used for the featured image share tag.
         *
         * @param string  $size Registered image size name.
         * @param WP_Post $post The post being rendered.
         */
        $size = apply_filters( 'omni_og_image_size', 'full', $post );

        $thumbnail_id = get_post_thumbnail_id( $post );
        if ( $thumbnail_id ) {
            $image = $this->get_attachment_image_data( $thumbnail_id, $size );
            if ( ! empty( $image ) ) {
                return $image;
            }
        }

        $image = $this->get_content_image( $post, $size );
        if ( ! empty( $image ) ) {
            return $image;
        }

        return $this->get_default_image();
    }

    /**
     * Build image data from an attachment ID
     */
    private function get_attachment_image_data( $attachment_id, $size ) {
        $src = wp_get_attachment_image_src( $attachment_id, $size );
        if ( ! $src || empty( $src[0] ) ) {
            return [];
        }

        return [
            'url'    => $src[0],
            'width'  => isset( $src[1] ) ? (int) $src[1] : 0,
            'height' => isset( $src[2] ) ? (int) $src[2] : 0,
            'alt'    => trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ),
        ];
    }

    /**
     * Find the first image in the post content.
     *
     * Resolving the URL back to an attachment (needed for the dimensions) costs a
     * database query, so the result is cached in a transient keyed by the post's
     * modified time — editing the post naturally invalidates it.
     */
    private function get_content_image( $post, $size ) {
        $cache_key = 'omni_og_img_' . $post->ID . '_' . (int) get_post_modified_time( 'U', true, $post );

        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $image = [];
        if ( preg_match( '/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $post->post_content, $matches ) ) {
            $url = esc_url_raw( $matches[1] );
            if ( '' !== $url ) {
                // Content images are usually a resized variant (-1024x768), so the
                // size suffix is stripped before looking the attachment up.
                $full_url      = (string) preg_replace( '/-\d+x\d+(?=\.[a-zA-Z0-9]{3,4}$)/', '', $url );
                $attachment_id = attachment_url_to_postid( $full_url );

                if ( $attachment_id ) {
                    $image = $this->get_attachment_image_data( $attachment_id, $size );
                }
                if ( empty( $image ) ) {
                    // External or unattached image: usable as og:image, just without dimensions
                    $image = [
                        'url'    => $url,
                        'width'  => 0,
                        'height' => 0,
                        'alt'    => '',
                    ];
                }
            }
        }

        /**
         * Filter how long the resolved content image is cached.
         *
         * @param int $expiration Cache lifetime in seconds.
         */
        set_transient( $cache_key, $image, (int) apply_filters( 'omni_og_image_cache_expiration', DAY_IN_SECONDS ) );

        return $image;
    }

    /**
     * The site-wide default share image configured in the settings
     */
    private function get_default_image() {
        $url = trim( $this->get_settings()['og_default_image'] );
        if ( '' === $url ) {
            return [];
        }

        return [
            'url'    => $url,
            'width'  => 0,
            'height' => 0,
            'alt'    => '',
        ];
    }

    /**
     * Output WebSite + Organization JSON-LD structured data.
     *
     * The main purpose of the WebSite schema is site name recognition in search
     * results, while Organization provides the data source for the Google
     * knowledge panel and the logo shown in search results.
     */
    private function output_website_schema( $site_name, $description, $image ) {
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

        $this->print_schema( 'omni-schema-website', [ $website, $this->build_organization( $image ) ] );
    }

    /**
     * Output Article (or WebPage) + Organization JSON-LD for a single post
     */
    private function output_article_schema( $post, $title, $description, $image ) {
        $home      = home_url( '/' );
        $permalink = get_permalink( $post );

        $article = [
            // Google recommends keeping the headline under 110 characters
            '@type'            => 'page' === $post->post_type ? 'WebPage' : 'BlogPosting',
            '@id'              => $permalink . '#article',
            'headline'         => $this->trim_text( $title, 110 ),
            'url'              => $permalink,
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id'   => $permalink,
            ],
            'datePublished'    => get_post_time( 'c', true, $post ),
            'dateModified'     => get_post_modified_time( 'c', true, $post ),
            'publisher'        => [ '@id' => $home . '#organization' ],
        ];

        // Posts imported or created programmatically can end up without an author,
        // and an empty Person node is worse than no author at all.
        $author_name = get_the_author_meta( 'display_name', $post->post_author );
        if ( '' !== trim( (string) $author_name ) ) {
            $article['author'] = [
                '@type' => 'Person',
                'name'  => $author_name,
                'url'   => get_author_posts_url( $post->post_author ),
            ];
        }

        if ( '' !== $description ) {
            $article['description'] = $description;
        }

        if ( ! empty( $image['url'] ) ) {
            $image_node = [
                '@type' => 'ImageObject',
                'url'   => $image['url'],
            ];
            if ( ! empty( $image['width'] ) && ! empty( $image['height'] ) ) {
                $image_node['width']  = (int) $image['width'];
                $image_node['height'] = (int) $image['height'];
            }
            $article['image'] = $image_node;
        }

        // The Organization node is repeated here so the publisher reference
        // resolves on its own, without depending on the homepage output.
        $this->print_schema( 'omni-schema-article', [ $article, $this->build_organization( isset( $image['url'] ) ? $image['url'] : '' ) ] );
    }

    /**
     * Build the shared Organization node.
     *
     * @param string $fallback_image Image URL used when the site has no Site Icon.
     */
    private function build_organization( $fallback_image ) {
        $home = home_url( '/' );

        $organization = [
            '@type' => 'Organization',
            '@id'   => $home . '#organization',
            'name'  => get_bloginfo( 'name' ),
            'url'   => $home,
        ];

        // Prefer the site icon for the logo (usually square, matching Google's recommendation), falling back to the OG share image
        $logo = get_site_icon_url( 512 );
        if ( ! $logo && '' !== $fallback_image ) {
            $logo = $fallback_image;
        }
        if ( $logo ) {
            $organization['logo'] = $logo;
        }

        return $organization;
    }

    /**
     * Print a JSON-LD @graph document
     */
    private function print_schema( $id, array $nodes ) {
        $graph = [
            '@context' => 'https://schema.org',
            '@graph'   => $nodes,
        ];

        wp_print_inline_script_tag(
            wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
            [
                'type' => 'application/ld+json',
                'id'   => $id,
            ]
        );
    }

    /**
     * Print a <meta property="..."> tag (Open Graph namespace)
     */
    private function meta_property( $property, $content ) {
        echo '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( $content ) . '" />' . "\n";
    }

    /**
     * Print a <meta name="..."> tag (description, Twitter cards)
     */
    private function meta_name( $name, $content ) {
        echo '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $content ) . '" />' . "\n";
    }

    /**
     * Print a meta tag whose content is a URL
     */
    private function meta_url( $property, $url ) {
        $attribute = 0 === strpos( $property, 'og:' ) ? 'property' : 'name';
        echo '<meta ' . esc_attr( $attribute ) . '="' . esc_attr( $property ) . '" content="' . esc_url( $url ) . '" />' . "\n";
    }
}
