<?php
/**
 * Omni_File_Renamer Class
 *
 * Core logic maintained in sync with the standalone plugin smart-file-renamer:
 * https://github.com/ivanusto/smart-file-renamer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Omni_File_Renamer {

    private $options = null;

    public function __construct() {
        // Only rename files that are actually being uploaded or sideloaded.
        //
        // The obvious hook here is 'sanitize_file_name', but that filter is global:
        // WordPress, themes and plugins run it over every name they treat as a file
        // name, including generated CSS caches and temp files. Renaming those breaks
        // whichever code wrote the file under its original name (for example
        // "custom-frontend.min.css" would come back as "custom-frontendmin.css").
        add_filter( 'wp_handle_upload_prefilter', [ $this, 'rename_upload' ] );
        add_filter( 'wp_handle_sideload_prefilter', [ $this, 'rename_upload' ] );
    }

    /**
     * Normalize the file name of an upload in progress
     *
     * @param array $file Upload array as passed by WordPress ('name', 'type', 'tmp_name', ...).
     * @return array
     */
    public function rename_upload( $file ) {
        // WordPress 7.1 client-side media processing uploads each sub-size in a
        // separate request to /wp/v2/media/{id}/sideload. Those file names must
        // derive from the attachment's existing (already renamed) file name:
        // core's own wp_unique_filename workaround in
        // WP_REST_Attachments_Controller::filter_wp_unique_filename() only keeps
        // the expected "name-WxH.ext" form when the base name matches the
        // attachment's file, so renaming here would push every sub-size onto a
        // numeric-suffix name (and double-apply the date prefix).
        if ( self::is_rest_media_sideload_request() ) {
            return $file;
        }

        if ( ! empty( $file['name'] ) ) {
            $file['name'] = $this->rename_file( $file['name'] );
        }
        return $file;
    }

    /**
     * Whether the current request is the WordPress 7.1+ REST sub-size sideload
     * endpoint (POST /wp/v2/media/{id}/sideload), which uploads derivative
     * files for an existing attachment rather than new media
     */
    private static function is_rest_media_sideload_request() {
        if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
            return false;
        }

        $route = '';
        if ( isset( $GLOBALS['wp']->query_vars['rest_route'] ) && is_string( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
            $route = $GLOBALS['wp']->query_vars['rest_route'];
        } elseif ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $path  = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
            $route = is_string( $path ) ? $path : '';
        }

        return (bool) preg_match( '#/wp/v2/media/\d+/sideload/?$#', $route );
    }

    /**
     * Lazy-load settings and merge defaults so older data without
     * individual keys keeps working
     */
    private function get_options() {
        if ( null === $this->options ) {
            $settings      = get_option( 'omni_webmaster_settings', [] );
            $this->options = [
                'enable'      => isset( $settings['file_rename_enable'] ) ? $settings['file_rename_enable'] : '0',
                'date_prefix' => isset( $settings['file_rename_date_prefix'] ) ? $settings['file_rename_date_prefix'] : '0',
            ];
        }
        return $this->options;
    }

    /**
     * Whether the standalone smart-file-renamer plugin is active alongside
     * the suite; if so, the module yields to avoid renaming twice
     */
    public static function standalone_plugin_active() {
        return class_exists( 'SmartFileRenamer', false );
    }

    /**
     * Normalize an uploaded file name to a clean, ASCII-only,
     * hyphen-separated lowercase slug for better SEO
     *
     * Kept public so the transformation can be reused and tested on its own.
     */
    public function rename_file( $filename ) {
        $options = $this->get_options();

        if ( '1' !== $options['enable'] || self::standalone_plugin_active() ) {
            return $filename;
        }

        $extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
        $name      = pathinfo( $filename, PATHINFO_FILENAME );

        // Transliterate Latin diacritics to ASCII equivalents (WordPress built-in, 200+ characters)
        $name = remove_accents( $name );

        // Normalize separators: spaces and underscores become hyphens
        $name = str_replace( [ ' ', '_' ], '-', $name );

        // Strip remaining non-ASCII characters (CJK, symbols, etc.)
        $name = preg_replace( '/[^A-Za-z0-9\-]/', '', $name );

        $name = strtolower( $name );

        // Collapse consecutive hyphens and strip edge hyphens
        $name = preg_replace( '/-{2,}/', '-', $name );
        $name = trim( $name, '-' );

        // Fallback when the entire name is stripped
        if ( '' === $name ) {
            $name = 'file-' . time();
        }

        // Idempotent: a name that already carries a date prefix (a re-upload of
        // a previously renamed file, or a derivative named after one) is not
        // prefixed a second time.
        if ( '1' === $options['date_prefix'] && ! preg_match( '/^\d{4}-\d{2}-\d{2}-/', $name ) ) {
            $name = gmdate( 'Y-m-d' ) . '-' . $name;
        }

        return '' !== $extension ? "{$name}.{$extension}" : $name;
    }
}
