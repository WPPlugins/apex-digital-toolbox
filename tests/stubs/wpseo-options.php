<?php
/**
 * Test stub of Yoast SEO's WPSEO_Options class. Tests assign to WPSEO_Options::$values
 * directly to control what `WPSEO_Options::get('og_default_image_id')` returns.
 */
if ( ! class_exists( 'WPSEO_Options' ) ) {
    class WPSEO_Options {
        public static array $values = array();

        public static function get( $key, $default = null ) {
            return array_key_exists( $key, self::$values ) ? self::$values[ $key ] : $default;
        }

        public static function reset(): void {
            self::$values = array();
        }
    }
}
