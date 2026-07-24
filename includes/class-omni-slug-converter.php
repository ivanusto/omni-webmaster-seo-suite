<?php
/**
 * Omni_Slug_Converter Class
 *
 * Core logic maintained in sync with the standalone plugin zh-to-en-slug:
 * https://github.com/ivanusto/zh-to-en-slug
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Omni_Slug_Converter {

    private $options = null;

    public function __construct() {
        // Hook into slug generation on post save
        add_filter( 'wp_insert_post_data', [ $this, 'process_post_data' ], 10, 2 );

        // AJAX endpoint for testing the translation API
        if ( is_admin() ) {
            add_action( 'wp_ajax_omni_test_translation_api', [ $this, 'test_translation_api' ] );
        }
    }

    /**
     * Lazy-load settings and merge defaults so older data without
     * individual keys keeps working
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
     * On post save: if the title contains Asian-script characters,
     * translate it and use the result as an English slug
     */
    public function process_post_data( $data, $postarr ) {
        // Skip autosaves and revisions
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return $data;
        }

        if ( empty( $data['post_title'] ) ) {
            return $data;
        }

        // Whitelist of post statuses to process, customizable via filter
        $allowed_statuses = apply_filters( 'omni_slug_allowed_statuses', [ 'draft', 'publish', 'future', 'pending', 'private' ] );
        $current_status   = isset( $postarr['post_status'] ) ? $postarr['post_status'] : '';

        if ( ! in_array( $current_status, $allowed_statuses, true ) ) {
            return $data;
        }

        // Detect Asian scripts: CJK ideographs (incl. Extension A and
        // compatibility block), Japanese kana, Hangul, and Thai
        if ( ! preg_match( '/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{3040}-\x{30FF}\x{31F0}-\x{31FF}\x{1100}-\x{11FF}\x{AC00}-\x{D7AF}\x{0E00}-\x{0E7F}]/u', $data['post_title'] ) ) {
            return $data;
        }

        // Respect a manually entered custom slug (not the default auto-draft
        // and not just the sanitized title) — do not re-translate
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

            // Posts that already have an ID get it appended to avoid slug collisions
            if ( $post_id > 0 ) {
                $data['post_name'] = $base_slug . '-' . $post_id;
            } else {
                // New posts have no ID yet; use the translated slug directly.
                // Uniqueness is guaranteed by core's wp_unique_post_slug
                $data['post_name'] = $base_slug;
            }
        }

        return $data;
    }

    /**
     * Call the official Google Cloud Translation API.
     * Source language is omitted so Google auto-detects it.
     */
    private function call_cloud_api( $api_key, $text ) {
        $url = add_query_arg(
            [ 'key' => $api_key ],
            'https://translation.googleapis.com/language/translate/v2'
        );

        $args = [
            'body'    => wp_json_encode( [
                'q'      => $text,
                'target' => 'en',
                // Request plain-text responses so HTML entities (e.g. &#39;)
                // never leak into the slug
                'format' => 'text',
            ] ),
            'headers' => [
                'Content-Type'     => 'application/json; charset=utf-8',
                'Referer'          => home_url(),
                'X-Requested-With' => 'XMLHttpRequest',
            ],
            // On failure we fall back to the keyless endpoint or the WP
            // default slug — fail fast rather than block the save flow
            'timeout' => 8,
        ];

        return wp_remote_post( $url, $args );
    }

    /**
     * Call the keyless public Google Translate endpoint (fallback).
     * sl=auto lets Google detect the source language.
     */
    private function call_free_api( $text ) {
        $url = add_query_arg(
            [
                'client' => 'gtx',
                'sl'     => 'auto',
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
     * Extract the translation from a Cloud API response; false on failure
     */
    private function parse_cloud_response( $response ) {
        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return isset( $body['data']['translations'][0]['translatedText'] ) ? $body['data']['translations'][0]['translatedText'] : false;
    }

    /**
     * Extract the translation from a keyless-endpoint response; false on failure
     */
    private function parse_free_response( $response ) {
        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return isset( $body[0][0][0] ) ? $body[0][0][0] : false;
    }

    /**
     * Translate a title (Cloud API first; fall back to the keyless
     * public endpoint when no key is set or the call fails)
     */
    private function translate_title( $title ) {
        // Cache identical titles to cut API calls and save-time latency
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
     * Normalize a translation into a standard slug
     */
    private function create_slug( $text ) {
        $text = strtolower( $text );
        $text = remove_accents( $text );
        // The keyless endpoint may return HTML entities; decode before filtering
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
        $text = preg_replace( '/[^a-z0-9\s-]/', '', $text );
        $text = preg_replace( '/\s+/', '-', $text );
        $text = trim( $text, '-' );

        // Apply the configured max length, reserving room for the post ID suffix
        $options         = $this->get_options();
        $max_length      = absint( $options['max_length'] );
        $reserved_length = 12; // room for an ID suffix like "-123456"
        // Floor of 8 chars so a tiny setting can't truncate the slug to nothing
        $actual_max_length = max( 8, $max_length - $reserved_length );

        if ( strlen( $text ) > $actual_max_length ) {
            $text = substr( $text, 0, $actual_max_length );
            $text = preg_replace( '/-[^-]*$/', '', $text ); // drop the trailing partial word
        }

        return $text;
    }

    /**
     * AJAX endpoint for testing API connectivity
     */
    public function test_translation_api() {
        check_ajax_referer( 'omni_slug_test_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'omni-webmaster-seo-suite' ) );
            return;
        }

        $api_key   = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
        $test_text = '測試文字';

        // With no key provided, test the keyless public endpoint
        if ( '' === $api_key ) {
            $response = $this->call_free_api( $test_text );

            if ( is_wp_error( $response ) ) {
                wp_send_json_error( sprintf(
                    /* translators: %s: error message */
                    __( 'Keyless API connection test failed: %s', 'omni-webmaster-seo-suite' ),
                    esc_html( $response->get_error_message() )
                ) );
                return;
            }

            $translated = $this->parse_free_response( $response );
            if ( false !== $translated ) {
                wp_send_json_success( sprintf(
                    /* translators: %s: translated sample text */
                    __( 'Keyless translation test succeeded! Result: %s', 'omni-webmaster-seo-suite' ),
                    esc_html( $translated )
                ) );
            } else {
                wp_send_json_error( __( 'The keyless translation endpoint returned unexpected data.', 'omni-webmaster-seo-suite' ) );
            }
            return;
        }

        $response = $this->call_cloud_api( $api_key, $test_text );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( sprintf(
                /* translators: %s: error message */
                __( 'Cloud API connection failed: %s', 'omni-webmaster-seo-suite' ),
                esc_html( $response->get_error_message() )
            ) );
            return;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['data']['translations'][0]['translatedText'] ) ) {
            wp_send_json_success( sprintf(
                /* translators: %s: translated sample text */
                __( 'Cloud API test succeeded! Result: %s', 'omni-webmaster-seo-suite' ),
                esc_html( $body['data']['translations'][0]['translatedText'] )
            ) );
        } elseif ( isset( $body['error']['message'] ) ) {
            wp_send_json_error( sprintf(
                /* translators: %s: error message */
                __( 'Cloud API error: %s', 'omni-webmaster-seo-suite' ),
                esc_html( $body['error']['message'] )
            ) );
        } else {
            wp_send_json_error( __( 'Could not retrieve a translation. Please check that the API key is correct.', 'omni-webmaster-seo-suite' ) );
        }
    }
}
