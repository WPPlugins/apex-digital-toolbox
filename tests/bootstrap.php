<?php
/**
 * PHPUnit bootstrap for the apex-digital-toolbox plugin.
 *
 * Layout assumed (matches the dev DDEV setup):
 *   <project-root>/
 *     vendor/                                <- composer dev deps incl. yoast/phpunit-polyfills
 *     wordpress-tests-lib/                   <- WP test suite downloaded by install-wp.sh
 *     web/wp-content/plugins/apex-digital-toolbox/
 *       tests/bootstrap.php                  <- this file
 *
 * Override `WP_TESTS_DIR` via env var or phpunit.xml `<php><env>` for other layouts (e.g. CI).
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = '/var/www/html/wordpress-tests-lib';
}
if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    fwrite(
        STDERR,
        "Could not find {$_tests_dir}/includes/functions.php.\n"
        . "Run `./install-wp.sh` inside the DDEV container first (it downloads the WordPress test suite).\n"
    );
    exit( 1 );
}

// Composer autoload from the project root - provides yoast/phpunit-polyfills used by WP's test framework.
$_project_vendor = dirname( __DIR__, 5 ) . '/vendor/autoload.php';
if ( file_exists( $_project_vendor ) ) {
    require_once $_project_vendor;
}

require_once $_tests_dir . '/includes/functions.php';

// Stub third-party classes before the apex toolbox plugin (and tests) load:
// - \Elementor\Plugin: the prune code's `class_exists( '\Elementor\Plugin' )` guard
// - WPSEO_Options: read by hook_yoast.php's defaultOpenGraphImage filters
tests_add_filter( 'muplugins_loaded', function () {
    require_once __DIR__ . '/stubs/elementor-plugin.php';
    require_once __DIR__ . '/stubs/wpseo-options.php';
}, 1 );

// Load the plugin under test.
tests_add_filter( 'muplugins_loaded', function () {
    require dirname( __DIR__ ) . '/apex-digital-toolbox.php';
} );

require $_tests_dir . '/includes/bootstrap.php';
