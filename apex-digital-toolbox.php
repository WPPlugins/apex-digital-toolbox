<?php
/*
Plugin Name: Apex Digital Toolbox
Plugin URI: https://www.apexdigital.co.nz/
Description: Adds additional functionality that to make it easier to setup sites
Version: 1.0
Author: Apex Digital
Author URI: https://www.apexdigital.co.nz/
*/

// Forbid accessing directly
if ( ! defined( 'ABSPATH' ) ) {
	header( 'HTTP/1.1 401 Unauthorized' );
	exit;
}
// Load the main controller
require_once( plugin_dir_path( __FILE__ ) . 'controllers/toolboxController.php');
// Start her up!
$Toolbox = new toolboxController();