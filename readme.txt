=== Apex Digital Toolbox ===
Contributors: nwells
Donate link: https://www.limelightdigital.co.nz/contact-us/
Tags: administration, setup, staging, production
Requires at least: 6.9
Requires PHP: 8.1
Tested up to: 7.0
Stable tag: 2.0.2
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
* Gravity Forms confirmation message appear underneath any fixed header when using AJAX. This hook allows you to scroll to the correct position based on the header
* Can specify a stylesheet that you want to appear last in the enqueue - useful for overwriting parent themes or other plugins
* YouTube embedded videos can have the title, related videos, and controls switched off
* Change the sender name and email address for emails sent
* Short code for displaying the current year - useful for keeping copyright notices up-to-date
* WooCommerce template tweaks for improved usability when using the Jupiter theme
* Set parent hierarchy pages as place holders so they don't provide links in menus to empty pages
* Simple short code for the current page title - useful to add in to links
* Disable certain notifications for admin
* Added Relevanssi support for XforWooCommerce filter plugin when AJAX is in use

**Coming soon**

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
= 2.0.2 =
* Outbound HTTP firewall: the APEX_TOOLBOX_HTTP_ALLOWLIST wp-config constant can now allow specific methods per host, matching what the settings page has been able to do since 2.0.0 - e.g. define('APEX_TOOLBOX_HTTP_ALLOWLIST', array('api.example.com' => array('GET'))) permits reads to that API while writes stay blocked. Previously the constant was host-only and any per-method form was silently discarded, leaving the host blocked entirely. Existing entries (a comma-separated string, or a list of bare hosts) are unchanged and still mean all methods
* Outbound HTTP firewall: the allowlist panel now shows the actual methods allowed for each wp-config entry instead of always reporting ALL
* Outbound HTTP firewall: a malformed allowlist entry with no host is now discarded instead of raising a PHP warning and creating a junk entry
* Developer note: the apex_toolbox_http_allowlist filter now receives and returns host/method entries rather than bare host strings. A filter that only appends a host string keeps working; one that inspects the list needs updating
= 2.0.1 =
* Fix a fatal error (PHP TypeError) that could take down the Apex Toolbox settings page - and any front/admin page that rendered a settings field - when a checkbox option had never been saved: in_array() received false instead of an array. Checkbox values are now always treated as an array. This chiefly affected the WooCommerce "Enhance Jupiter WooCommerce Experience" settings
* Harden the settings field model so no field type can fatal on an unexpected value: text, textarea and URL fields safely degrade a stray array/null to an empty field instead of throwing
* Normalise the 'input' and 'string' field-type aliases so they always render as a text input (previously a field typed 'input' rendered nothing)
= 2.0.0 =
* Modernise the plugin's foundation: introduce a Composer-managed, dependency-injected layer (php-di) alongside the existing hook engine, with vendored runtime libraries isolated (prefixed) via Strauss so they can never clash with another plugin on the same site. The legacy hooks are untouched and keep their lazy-loading behaviour
* Add an MCP (Model Context Protocol) server at /wp-json/mcp/apex so the agency's AI assistant can read and write at the WordPress application layer. Gated to WordPress 7.0+; on older sites the MCP features are disabled and the rest of the plugin is unaffected
* Add a lightweight OAuth 2.1 + PKCE provider (authorize/token/revoke) issuing short-lived RS256 JWT access tokens with narrow scopes, plus a manifest endpoint (/wp-json/apex/v1/info) and an exposure-sync endpoint that lets the assistant manage which abilities are exposed
* Register agency abilities (deployment metadata, health probe, cache flush) and a plugin-agnostic SEO abstraction (Yoast / Rank Math / AIOSEO / SEOPress) for reading and updating SEO meta. Write abilities require an explicit confirmation flag before they run
* Add content and SEO-audit abilities for the assistant: read any page/post/CPT body as Markdown - page-builder aware (Elementor, WPBakery, Gutenberg) so it doesn't scrape the rendered page; list content filtered to items missing an SEO title/description; and read/update Yoast site-wide settings (per-post-type and taxonomy indexing plus default title/description templates, including WooCommerce). Site-wide setting changes stay confirmation-gated behind their own scope
* Add a diagnostic-only Settings -> Apex Toolbox MCP admin page showing version/connection status, connected clients (with revoke), and the exposed/available ability lists
* Outbound HTTP firewall: allow/block is now per host AND method, so a read (GET) can be permitted to an API while writes (POST/PUT/PATCH/DELETE) stay blocked - previously allowlisting was all-or-nothing per host. Existing allowlist entries keep working unchanged (treated as all methods)
* Outbound HTTP firewall: the blocked-request log now records each host+method separately, with a per-verb "Allow" action, and captures the request's shape (query and body KEY names) with all VALUES redacted - which also stops secrets in a GET query string from being written to the log
* Settings page: replace the single long list with a left vertical tab menu - each section (Site Wide, Outbound HTTP Firewall, etc.) is its own tab, showing that section's fields on the right. All fields still save with one form; without JavaScript every section stays visible
* Bump minimum requirements to WordPress 6.9 and PHP 8.1
= 1.8.1 =
* Add Checkout for WooCommerce (CheckoutWC) integration hooks (opt-in): rename the cart's "Local Pickup" shipping label to your configured CheckoutWC pickup label so the cart matches the checkout wording, and keep CheckoutWC's delivery-method selection in sync with the cart's chosen shipping method so picking Local Pickup on the cart pre-selects "Pick up" on the checkout. Both do nothing unless CheckoutWC is active
* Improve the Hooks admin page: group each section into its own panel, sort the sections and the hooks within each section alphabetically, and highlight enabled hooks with a green tint so active hooks are easy to spot at a glance
* Packaging: stop bundling the test suite and developer config (tests, phpunit.xml, build caches) in the wordpress.org release, so the download only contains what runs in production
= 1.8.0 =
* Add an Outbound HTTP Firewall (on by default for every site) that blocks external requests made via the WordPress HTTP API (wp_remote_*) in non-production environments, so a cloned database carrying live credentials cannot reach third-party services (payment gateways, etc.) - born from an incident where a dev clone issued real refunds against a live payment gateway
* Environment is detected from the site URL (works in web, WP-CLI and cron): DDEV and *.kinsta.cloud hosts are always treated as non-production and cannot be disabled; a site whose URL does not match the configured Production URL is treated as staging. Production is never affected
* Blocked outbound hosts are logged (deduped by host with count, first/last seen, a sample URL, and the calling plugin/file) on a Blocked Hosts panel so you can see what tried to call out
* Allowlist with two tiers: a read-only code force-allowlist via the APEX_TOOLBOX_HTTP_ALLOWLIST wp-config constant (and the apex_toolbox_http_allowlist filter), plus an admin-managed list on the settings page; supports exact hosts and leading-wildcard domains (e.g. *.example.com). Self / loopback hosts are always allowed
* Allow WordPress.org infrastructure (*.wordpress.org - update checks, plugin/theme browser, dashboard feeds) by default so a dev clone's dashboard keeps working; this is read-only and safe. WordPress.com (Jetpack/Automattic, which can have side effects) is deliberately NOT allowed. The default list is filterable via apex_toolbox_http_default_allowlist
* Disable per-site via the APEX_TOOLBOX_DISABLED_HOOKS wp-config kill-switch. Note: this only governs the WordPress HTTP API - code using raw cURL/sockets is not blocked
* The Production/Staging setting (Production URL) is now forced on for every site, so the firewall's domain-mismatch detection has the production URL it needs; an admin notice prompts you to set it if it is missing
= 1.7.1 =
* Fix a fatal parse error on sites running PHP older than 8.3 - the 1.7.0 release inadvertently introduced typed class constants (a PHP 8.3-only feature) during a code-style pass; reverted to plain constants so the plugin loads on all supported PHP versions
= 1.7.0 =
* Add "forced default" hooks - a hook can now be turned on automatically for every site (no need to visit the Hooks page and save) by declaring it as a default; new defaults activate on the next plugin update. The Elementor revision prune is now on by default for every site (and quietly does nothing on sites without Elementor)
* Add a per-site kill-switch: define APEX_TOOLBOX_DISABLED_HOOKS in wp-config.php to turn off any forced default (or any saved hook) on an individual site
* Security: read legacy serialized option values with object instantiation disabled to prevent PHP object injection
* Security: bind the WP All Import monitoring query parameters instead of building the SQL directly (prevents SQL injection via the admin-entered import IDs)
* Security: escape taxonomy term links and names in the List Categories widget and skip invalid term links (prevents XSS / broken markup)
* Security: verify the WordPress nonce and sanitize input when saving the alternative taxonomy page title (prevents CSRF)
* Security: skip inline-asset output when the source file is not readable
= 1.6.0 =
* Add daily cron task to prune old Elementor revisions - keeps every revision from the last 7 days, the newest per day for revisions 7-30 days old, the newest per week beyond 30 days, and always keeps the 3 most recent revisions per post as a safety floor
* Fix CSS Heal admin bar item so it always renders when the hook is enabled (previously hidden until the first heal occurred) and gate it explicitly against the enabled-hooks list as a defense-in-depth check
* Add WooCommerce hook to disable the "Product on Backorder" admin notification email
* Add WP Defender hook to skip audit hooks while an import is processing (matches the WP All Import admin page and any importer that uses an "import_key" request parameter)
* Log unauthorized staging access attempts to the PHP error log (IP, request URI, user agent) and always display the requester's IP on the 401 lockout page with HTML-escaping for safety
* Add an Elementor Revisions status panel on the plugin settings page showing the count of revisions in each retention bucket (fresh / daily / weekly), the last completed and next scheduled run, and a "Run prune now" button for triggering the cleanup on demand
* Fix out-of-memory crashes on sites with thousands of Elementor revisions per post - the prune now fetches only revision IDs + dates via a direct query instead of loading the multi-MB Elementor data blobs into memory, evicts each revision from the post cache after deleting, saves the cursor per-parent so partial-batch progress survives a crash, skips a parent that a previous run started but didn't finish (preventing infinite OOM loops), and proactively bails when memory usage exceeds 80% of memory_limit with an auto-reschedule of the remaining work
= 1.5.1 =
* Add per-request self-healing for missing Elementor post-CSS files - regenerates just-in-time when post meta says the file exists but it is missing on disk, with an inline fallback if the regeneration also fails
* Add a CSS Heal counter to the admin bar showing successful and failed regenerations for administrators
= 1.5.0 =
* Add Elementor CSS regeneration hook to automatically fix missing or invalid CSS files after plugin updates
* Improve performance by only regenerating CSS for affected posts rather than all Elementor content
= 1.4.15 =
* Fix a bug when checking privacy policy access
= 1.4.14 =
* Improve the WooCommerce search redirect hook
= 1.4.13 =
* Add a hook to introduce a purchase order number field to the checkout
= 1.4.12 =
* Fix a bug with asset inlining where some CSS or JS files may not be included in the case a script and a style have the same handle.
* Fix a bug with the WC search redirect hook where searching the media library in list would redirect to the frontend.
= 1.4.11 =
* Add hook to better handle WooCommerce session cookies becoming invalid
= 1.4.10 =
* Handle multiple forms correctly in the Gravity Forms Confirmation Scrolling hook
= 1.4.9 =
* Add hook to add Yoast short variables (Title and Excerpt)
= 1.4.8 =
* Fix critical error in Account Menu hook
* Improve performance of WooCommerce search redirect
= 1.4.7 =
* Add hook to disable timeout for WC Cart Fragments request
* Only register Elementor_ListCategories_Widget when required
* Add filter for my account menu label
* Set account menu endpoints to active when endpoint is viewed
= 1.4.6 =
* Add a hook to monitor WP All Import imports
* Add a custom payment gateway to WooCommerce
* Add hook to allow redirection from native to WooCommerce search
* Add hook to hide WooCommerce account menu items
* Add the ability to customize the "My Account" and "Login" text
* Add a custom variable for Yoast to display the primary WC category
= 1.4.5 =
* Avoid WooCommerce schedules being created for download permission
* Adjust output of WooCommerce schema on Elementor pages
= 1.4.4 =
* Added a hook to improve the back button experience on WooCommerce sites using the product filter plugin
= 1.4.3 =
* Added WooCommerce widget for displaying product schema when using Elementor
* Added dynamic WooCommerce my account menus for valid end points
* Improved page title shortcode to allow for other archive types
* Improved styling options for Elementor Category List widget
= 1.3.2 =
* Improved tools for Google Page Speed
= 1.3.1 =
* Added Elementor widget for outputting a list of taxonomy links based on their parent
* Added an option to allow the editor role to manage the privacy policy
* Bug fixes
= 1.3.0 =
* Improved security
* Removed search and replace functionality
* Added start of page speed improvements
* Gravity Forms anchor scroll fix
= 1.2.5 =
* Ignore staging lockout when running from a ddev server
* Ignore redirects when running from CLI or an AJAX call
* Bug fixes
= 1.2.4 =
* Added page title shortcode
* Improved top level menu item being disabled when it is just a placeholder to show the sub-menu
= 1.2.3 =
* Hide WooCommerce shipping destination block
* Add Boostrap classes to time blocks in Gravity Forms
* Don't deny access to cron tasks when checking if there is access to a staging site
* Only show WooCommerce coupons if they have been enabled
* Added a filtering option for showing the shop filter
* New hook for redirecting parent pages to their siblings
= 1.2.2 =
* Updated WooCommerce additional CSS
* Updated website restriction code to allow you to bypass by logging in to the the website
* Notice fixes for queuing CSS & JS files
= 1.2.1 =
* Backwards compatibility fix for serialization of options
= 1.2.0 =
* Fix for serialization of options to work better with WordPress and other plugins
* Added a new hook for the current year
* Added improved template options for WooCommmerce and the Jupiter theme
= 1.1.3 =
* Taxonomy support in sitemaps
* Can now change the sender name and email address for emails sent
= 1.1.2 =
* Update for scanning Visual Composer directory for custom templates - the vc_before_init hook appears to have changed in someway
= 1.1.1 =
* YouTube embedded videos can have the title, related videos, and controls switched off
= 1.1 =
* Gravity Forms support for scrolling confirmation messages in to view
* WordPress 4.9 support
* Bug fixes
= 1.0 =
* Official release
= 0.3.8 =
* Added new sitemap hook and shortcode
* Updated find and replace hook to work better with post meta data when updating URLs
= 0.3.7 =
* Initial release

== Upgrade Notice ==
None
