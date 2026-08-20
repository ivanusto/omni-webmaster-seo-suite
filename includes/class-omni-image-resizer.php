<?php
/**
 * Omni_Image_Resizer Class
 *
 * Core logic maintained in sync with the standalone plugin smart-image-upload-resizer:
 * https://github.com/ivanusto/smart-image-upload-resizer
 *
 * Resizes oversized images at upload time (wp_handle_upload_prefilter) before
 * WordPress stores the original and generates thumbnails, so both the stored
 * original and every sub-size stay within the configured bounds.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Omni_Image_Resizer {

    /**
     * Hard upper bound for the configurable max width/height
     */
    const MAX_DIMENSION = 2560;

    const DEFAULT_MAX_WIDTH  = 1920;
    const DEFAULT_MAX_HEIGHT = 1080;
    const DEFAULT_QUALITY    = 80;

    private $options = null;

    public function __construct() {
        add_filter( 'wp_handle_upload_prefilter', [ $this, 'pre_handle_upload' ] );

        // WordPress 7.1 client-side media processing moves scaling and sub-size
        // generation into the browser: the initial POST /wp/v2/media stores the
        // untouched original and the client later sideloads its own scaled copy
        // and sub-sizes generated from the full-resolution file. A server-side
        // resize of the original in that flow either breaks the upload outright
        // (an 'original' sideload is validated against the stored dimensions and
        // gets a 400) or silently voids the configured bounds (the client's
        // 2560px 'scaled' copy replaces the main file). Keeping processing
        // server-side while this module is active is the only way the
        // configured maximums reliably apply.
        add_filter( 'wp_client_side_media_processing_enabled', [ $this, 'maybe_disable_client_side_processing' ] );
    }

    /**
     * Disable WordPress 7.1+ client-side media processing while this module
     * is actively resizing, so uploads keep flowing through the server-side
     * pipeline where the configured bounds are enforced
     */
    public function maybe_disable_client_side_processing( $enabled ) {
        $options = $this->get_options();

        if ( '1' === $options['enable'] && ! self::standalone_plugin_active() && self::gd_available() ) {
            return false;
        }

        return $enabled;
    }

    /**
     * Lazy-load settings and merge defaults so older data without
     * individual keys keeps working
     */
    private function get_options() {
        if ( null === $this->options ) {
            $settings      = get_option( 'omni_webmaster_settings', [] );
            $this->options = [
                'enable'     => isset( $settings['image_resize_enable'] ) ? $settings['image_resize_enable'] : '0',
                'max_width'  => isset( $settings['image_resize_max_width'] ) ? absint( $settings['image_resize_max_width'] ) : self::DEFAULT_MAX_WIDTH,
                'max_height' => isset( $settings['image_resize_max_height'] ) ? absint( $settings['image_resize_max_height'] ) : self::DEFAULT_MAX_HEIGHT,
                'quality'    => isset( $settings['image_resize_quality'] ) ? absint( $settings['image_resize_quality'] ) : self::DEFAULT_QUALITY,
            ];
        }
        return $this->options;
    }

    /**
     * Whether the standalone smart-image-upload-resizer plugin is active
     * alongside the suite; if so, the module yields to avoid resizing twice
     */
    public static function standalone_plugin_active() {
        return class_exists( 'SmartImageUploadResizer', false );
    }

    /**
     * Whether the PHP GD extension needed for resizing is available
     */
    public static function gd_available() {
        return extension_loaded( 'gd' );
    }

    /**
     * Resize the uploaded image in its temporary location when it exceeds
     * the configured bounds. Any failure returns the file untouched so the
     * upload always succeeds with the original image.
     */
    public function pre_handle_upload( $file ) {
        $options = $this->get_options();

        if ( '1' !== $options['enable'] || self::standalone_plugin_active() || ! self::gd_available() ) {
            return $file;
        }

        // Defense in depth for the WordPress 7.1 client-side media processing
        // flow (normally already prevented by maybe_disable_client_side_processing,
        // but another plugin may force the flag back on, and REST clients can
        // send generate_sub_sizes=false regardless of the flag):
        //
        // - /wp/v2/media/{id}/sideload uploads derivative files for an existing
        //   attachment. Resizing an 'original' sideload fails core's dimension
        //   validation (400), and sub-sizes were already sized by the client.
        // - A media create with generate_sub_sizes=false stores the original the
        //   client will validate later 'original'/'scaled' sideloads against, so
        //   it must be stored untouched.
        if ( self::is_rest_media_sideload_request() || self::is_client_side_media_create_request() ) {
            return $file;
        }

        if ( empty( $file['type'] ) || 0 !== strpos( $file['type'], 'image/' ) ) {
            return $file;
        }

        $supported_types = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ];
        if ( ! in_array( $file['type'], $supported_types, true ) ) {
            return $file;
        }

        $source_image  = false;
        $resized_image = false;

        try {
            wp_raise_memory_limit( 'image' );

            $image_size = getimagesize( $file['tmp_name'] );
            if ( ! $image_size ) {
                return $file;
            }

            list( $orig_width, $orig_height ) = $image_size;

            if ( $orig_width <= $options['max_width'] && $orig_height <= $options['max_height'] ) {
                return $file;
            }

            list( $new_width, $new_height ) = $this->calculate_dimensions( $orig_width, $orig_height );

            $source_image = $this->create_image_from_file( $file['tmp_name'], $file['type'] );
            if ( ! $source_image ) {
                return $file;
            }

            $resized_image = $this->resize_image( $source_image, $orig_width, $orig_height, $new_width, $new_height, $file['type'] );
            if ( ! $resized_image ) {
                return $file;
            }

            if ( ! $this->save_image( $resized_image, $file['tmp_name'], $file['type'], (int) $options['quality'] ) ) {
                return $file;
            }

            $file['size'] = filesize( $file['tmp_name'] );

        } finally {
            if ( $source_image ) {
                imagedestroy( $source_image );
            }
            if ( $resized_image ) {
                imagedestroy( $resized_image );
            }
        }

        return $file;
    }

    /**
     * Whether the current request is the WordPress 7.1+ REST sub-size sideload
     * endpoint (POST /wp/v2/media/{id}/sideload)
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
     * Whether the current request is a REST media create that opted out of
     * server-side sub-size generation (generate_sub_sizes=false), i.e. the
     * first request of the WordPress 7.1+ client-side processing flow
     */
    private static function is_client_side_media_create_request() {
        if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
            return false;
        }

        // The client sends the parameter as a form field (multipart) or query
        // arg (raw body), so $_REQUEST covers both transports.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only feature detection, no state change.
        if ( ! isset( $_REQUEST['generate_sub_sizes'] ) ) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $value = sanitize_text_field( wp_unslash( $_REQUEST['generate_sub_sizes'] ) );

        return false === rest_sanitize_boolean( $value );
    }

    /**
     * Fit the original dimensions inside the configured bounds while
     * preserving the aspect ratio, capped at MAX_DIMENSION
     */
    private function calculate_dimensions( $orig_width, $orig_height ) {
        $max_width  = (int) $this->options['max_width'];
        $max_height = (int) $this->options['max_height'];
        $ratio      = $orig_width / $orig_height;

        if ( $max_width / $max_height > $ratio ) {
            $new_width  = min( (int) round( $max_height * $ratio ), self::MAX_DIMENSION );
            $new_height = min( $max_height, self::MAX_DIMENSION );
        } else {
            $new_width  = min( $max_width, self::MAX_DIMENSION );
            $new_height = min( (int) round( $max_width / $ratio ), self::MAX_DIMENSION );
        }

        return [ $new_width, $new_height ];
    }

    private function create_image_from_file( $file_path, $mime_type ) {
        switch ( $mime_type ) {
            case 'image/jpeg':
                return imagecreatefromjpeg( $file_path );
            case 'image/png':
                return imagecreatefrompng( $file_path );
            case 'image/gif':
                return imagecreatefromgif( $file_path );
            case 'image/webp':
                return function_exists( 'imagecreatefromwebp' ) ? imagecreatefromwebp( $file_path ) : false;
            case 'image/avif':
                return function_exists( 'imagecreatefromavif' ) ? imagecreatefromavif( $file_path ) : false;
            default:
                return false;
        }
    }

    private function resize_image( $source_image, $source_width, $source_height, $target_width, $target_height, $mime_type ) {
        $target_image = imagecreatetruecolor( $target_width, $target_height );
        if ( ! $target_image ) {
            return false;
        }

        // Preserve transparency for PNG and GIF
        if ( 'image/png' === $mime_type || 'image/gif' === $mime_type ) {
            imagealphablending( $target_image, false );
            imagesavealpha( $target_image, true );
            $transparent = imagecolorallocatealpha( $target_image, 0, 0, 0, 127 );
            imagefilledrectangle( $target_image, 0, 0, $target_width, $target_height, $transparent );
            imagealphablending( $target_image, true );
        }

        imagecopyresampled(
            $target_image, $source_image,
            0, 0, 0, 0,
            $target_width, $target_height,
            $source_width, $source_height
        );

        return $target_image;
    }

    private function save_image( $image, $file_path, $mime_type, $quality ) {
        switch ( $mime_type ) {
            case 'image/jpeg':
                return imagejpeg( $image, $file_path, $quality );
            case 'image/png':
                // imagepng() expects a 0 (no compression) to 9 scale, inverted vs. quality
                $png_quality = (int) floor( ( 100 - $quality ) / 10 );
                return imagepng( $image, $file_path, $png_quality );
            case 'image/gif':
                return imagegif( $image, $file_path );
            case 'image/webp':
                return function_exists( 'imagewebp' ) ? imagewebp( $image, $file_path, $quality ) : false;
            case 'image/avif':
                return function_exists( 'imageavif' ) ? imageavif( $image, $file_path, $quality ) : false;
            default:
                return false;
        }
    }
}
