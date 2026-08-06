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
        if ( ! empty( $file['name'] ) ) {
            $file['name'] = $this->rename_file( $file['name'] );
        }
        return $file;
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

        if ( '1' === $options['date_prefix'] ) {
            $name = gmdate( 'Y-m-d' ) . '-' . $name;
        }

        return '' !== $extension ? "{$name}.{$extension}" : $name;
    }
}
