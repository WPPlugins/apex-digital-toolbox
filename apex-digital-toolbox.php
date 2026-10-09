<?php

/*
Plugin Name: Apex Digital Toolbox
Plugin URI: https://www.apexdigital.co.nz/
Description: Adds additional functionality that to make it easier to setup sites
Version: 2.6.0
Requires at least: 6.9
Tested up to: 7.0
Elementor tested up to: 4.2
Requires PHP: 8.1
Author: Apex Digital
Author URI: https://www.apexdigital.co.nz/
*/

// Forbid accessing directly
if (! defined('ABSPATH')) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}
// Load the main controller
define('APEX_TOOLBOX_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('APEX_TOOLBOX_PLUGIN_URL', plugin_dir_url(__FILE__));

// Composer autoloader for the namespaced ApexToolbox\ layer + Strauss-scoped runtime libraries.
// Guarded so a fresh checkout without a built vendor still boots the legacy (non-namespaced) features.
// Registering the autoloader is cheap (a classmap) and instantiates nothing on its own.
$apexToolboxAutoload = APEX_TOOLBOX_PLUGIN_PATH . 'vendor/autoload.php';
if (is_readable($apexToolboxAutoload)) {
    require_once $apexToolboxAutoload;
}

require_once APEX_TOOLBOX_PLUGIN_PATH . 'controllers/toolboxController.php';
// Start her up! (legacy hook engine — unchanged, lazy-loads only enabled hooks)
$Toolbox = new toolboxController();

// Boot the namespaced DI layer (MCP server, OAuth, abilities) — gated to WordPress 7.0+.
// Pre-WP-7 sites pay nothing here and keep all non-MCP plugin features.
if (class_exists(\ApexToolbox\Bootstrap::class)) {
    \ApexToolbox\Bootstrap::boot($Toolbox);
    // The mu-plugin that wakes premium updaters under WP-CLI goes with the Toolbox. It is put back on
    // the next admin, cron or WP-CLI request after activation.
    register_deactivation_hook(__FILE__, array(\ApexToolbox\Updates\MuPluginInstaller::class, 'removeInstalled'));
}
