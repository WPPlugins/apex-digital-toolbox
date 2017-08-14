=== Plugin Name ===
Contributors: nwells
Donate link: https://www.apexdigital.co.nz/contact.php
Tags: administration, setup, staging, production, find and replace
Requires at least: 3.0.1
Tested up to: 4.7.4
Stable tag: 1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Too many plugins installed to do basic things? Bring some common functions ones into one plugin to make life that little bit easier for developers.

== Description ==

Too many plugins installed to do basic things? This plugin tries to bring some common ones into one plugin to make life that little bit easier.

**Current functionality**

* Identify the production URL so as to apply specific logic or hooks depending on which environment the site is in
* Block visitors to the staging site based on IP or by using a specific cookie - great for showing clients but not the world
* Find & replace functionality - great for changing from a staging URL to a production URL
* Auto 301 redirect to the site domain for WordPress - useful to ensure everyone is using the correct path i.e. with www (or not) and https (or not)
* Add additional classes to the main body tag to easily target device and operating system i.e. iOS, Android, Chrome, etc...
* Sitemap generator to display a list of pages (or any post type) on the site as well as offering the ability to exclude pages
* WooCommerce settings to disable categories list on single product page, remove reviews tab, remove product count on categories
* When using Visual Composer you can automatically load in any PHP files that make use of vc_map() within your theme
* When using Gravity Forms & Bootstrap all correct classes will be applied to input boxes and buttons. Also, a new field type is added to add columns to forms as well as placing the submit button wherever you like
* Can specify a stylesheet that you want to appear last in the enqueue - useful for overwriting parent themes or other plugins

**Coming soon**

* Bulk plugin installer
* Export settings & setup
* Import settings & setup
* Set parent hierarchy pages as place holders so they don't provide links in menus to empty pages
* Drag & drop page re-ordering
* Improve noindexing on WooCommerce hidden products as well as ensuring the don't appear in sitemaps both HTML & XML
* Auto hide a page from any menu when its status is no longer published
* Additional default settings for Visual Composer to make it easier to extend and remove built in elements & templates
* More to come!

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/apex-wordpress-toolbox` directory, or install the plugin through the WordPress plugins screen directly.
1. Activate the plugin through the 'Plugins' screen in WordPress
1. Navigate to `Apex Toolbox->Hooks` to switch on which hooks you want to take advantage of
1. Some hooks provide specific settings that can be found under `Apex Toolbox->Settings`

== Frequently Asked Questions ==

= Why can't I un-check some hooks? =

Some hooks need to be on by default for the plugin to run in its basic state. Being able to remove the menu from WordPress, for example, would mean you could no longer change anything.

= Common hook your after not listed above? =

Let us know what you're after and we can look at adding it to the list. This plugin is developed to be very light weight by allowing the administrator
to switch features on and off as needed. Only when a specific features is required does WordPress even get told about it.

== Screenshots ==

1. Lists available hooks that can be switched on or off
2. Various settings available based on the hooks in use
3. Find & replace hook interface
4. Blocked user for when trying to access development site

== Changelog ==

= 0.3.8 =
* Added new sitemap hook and shortcode
* Updated find and replace hook to work better with post meta data when updating URLs
= 0.3.7 =
* Initial release

== Upgrade Notice ==
None