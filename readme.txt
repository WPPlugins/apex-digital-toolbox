=== Apex Digital Toolbox ===
Contributors: nwells
Donate link: https://www.limelightdigital.co.nz/contact-us/
Tags: administration, setup, staging, production
Requires at least: 6.9
Requires PHP: 8.1
Tested up to: 7.0
Stable tag: 2.7.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Too many plugins installed to do basic things? Bring some common functions ones into one plugin to make life that little bit easier for developers.

== Description ==

Too many plugins installed to do basic things? This plugin tries to bring some common ones into one plugin to make life that little bit easier.

**Current functionality**

* Install and update the private plugins supplied to your website from Apex Toolbox -> Packages, with updates then appearing on the normal WordPress Updates screen
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
= 2.7.0 =
* Outgoing email that has nobody to send it to is no longer attempted. WooCommerce still calls the mailer with an empty recipient after that address has been removed, and the mailer then records a failure and can alert on it. Those emails are now stopped before they are sent. Any email that does have a recipient, including one that is only copied or blind-copied, is left alone. This is on for every site. A blocked send is written to the PHP error log with the subject, where the send came from, and a short extract of the message. To turn it off on one site, set APEX_TOOLBOX_DISABLED_HOOKS in wp-config.php with site_wide and blockMailWithoutRecipients
* The optional WooCommerce hook "Disable Backorder Notification Emails" is unchanged in what it does: it switches off all backorder notification emails, even when a recipient is set, which is how a site stops backorder emails while keeping that address for other stock notifications. Its description now says so, so it is not confused with the block above
* New command `wp apex mail retry-failed` retries emails that FluentSMTP has logged as failed, for example after a mail provider outage. It looks back 24 hours by default, skips a row that has already been retried 5 times or was tried in the last 30 minutes, and skips a row with no valid recipient or a permanent rejection such as an invalid address. A 400 on its own is retried, because that is how a connection failure is stored, and an authentication failure is retried too, because a replaced API key should let those emails go. The retry limit still applies, and skipped rows do not use it up, so they cannot crowd out an email that can still be sent. When a retry fails again, the result says why. `--dry-run` lists what it would do and sends nothing. It does nothing harmful on a site without FluentSMTP: it stops with a clear message

= 2.6.1 =
* Premium theme updates are now visible from the command line as well. `wp theme list`, `wp theme update` and `wp theme status` now wake the same premium updaters that `wp plugin list` already does. Jupiter X only loads its theme updater inside wp-admin, so until now `wp theme list` reported it as up to date while wp-admin showed an update, and `wp theme update jupiterx` on a copy answered "already updated". The must-use plugin that does the waking is rewritten automatically on this update; every other WP-CLI command is left exactly as it was

= 2.6.0 =
* Outbound firewall: a development or staging copy can now deactivate an Essential Addons (WPDeveloper) licence, which is the step that has to happen before a licence key can be entered on the copy. Until now the deactivate was blocked, the copy kept showing the live site's licence as active, and there was no way to enter a key. Only a deactivate that names the copy's own web address is allowed: the firewall checks the address in the request against the copy's own, so a copy can release its own registration but never the live site's. Deactivating a copy that had been activated frees that seat
* Outbound firewall: rules can now require that part of a request names the copy's own web address, which is what makes the deactivate above safe to allow. It is checked by the firewall itself, not taken on trust from the plugin making the request
* Outbound firewall: rules for Jupiter X (Artbees). A copy can now see and install updates for the theme and for its companion plugin, and browse the recommended plugins list. Registering or revoking the Jupiter X API key stays blocked on every Artbees address, because the key is tied to a single web address and registering a copy would move it away from the live site
* Outbound firewall: a rule for RealHomes (Inspiry Themes). A copy can now see and install updates for the theme and for Easy Real Estate, the RealHomes Elementor Addon and Real Estate CRM. Nothing on that address can register or release anything
* Outbound firewall: allowing a licence activation or deactivation on a copy is deliberate and the cost is yours to weigh - it uses or frees one of the licence's seats. Any shipped rule can be removed on a site with the apex_toolbox_http_default_rules filter

= 2.5.0 =
* Packages: a private plugin that is already installed from somewhere else - an old manual upload, a GitHub download or the vendor's own zip - can now be taken over from the Packages screen. Until now the screen offered Install, WordPress refused because the plugin's folder already existed, and the only way through was to delete the working plugin first, which meant downtime on a live site
* Take over installs the registry's build over the existing copy. The new package is downloaded and checked against the registry's fingerprint before anything is changed. An active plugin stays active throughout, and if it cannot be switched back on afterwards the screen says so. From then on the plugin updates from the registry like any other package
* A confirmation screen comes first. It shows the installed plugin's name and version, the version that will replace it, and that the files will be overwritten in place, so any changes made to them on the site are lost. A copy only has to be in the right folder to be offered, so the screen warns prominently when the installed plugin has a different name, and it says when the copy currently gets its updates from somewhere else
* A copy newer than the registry's version is never taken over, because that would be a downgrade. The same version is allowed: that is the usual case, swapping in the registry's build of what the site already runs
* A package whose zip would unpack into a different folder is refused before anything is touched, instead of being installed beside the old copy as a second plugin

= 2.4.0 =
* Premium plugin updates are now visible from the command line. Crocoblock's Jet plugins, Essential Addons Pro and other plugins that use EDD Software Licensing only check for updates inside wp-admin or WP-Cron, so `wp plugin list` reported them as up to date when wp-admin showed an update. A small must-use plugin, installed and removed by the Toolbox, now lets them check when `wp plugin list`, `wp plugin update` or `wp plugin status` runs. Other WP-CLI commands are left exactly as they were. To turn it off on a site, add define('APEX_TOOLBOX_WAKE_UPDATERS', false) to wp-config.php
* New `wp apex updates list` command: every installed plugin and theme - must-use plugins and drop-ins included, as `wp plugin list` shows them - with the update each one's updater offers, as JSON. It always reports at least everything `wp plugin list` does, because it runs the same update check. It also says whether WordPress has any update information for each package (`checked`), so a plugin nobody checked no longer looks the same as one that is up to date. If one vendor's update check fails, that package is reported as unchecked with a warning, and the rest of the list still comes back
* New `wp apex changelog get <plugin>` command: a plugin's changelog as HTML. It uses Crocoblock's published changelog for Jet plugins, then plugin information from wordpress.org or the vendor, then the plugin's own readme.txt
* Outbound firewall: development and staging copies may now read Crocoblock's published changelog files, and nothing else on crocoblock.com

= 2.3.2 =
* WP Defender: the option that skips audit logging during an import now actually recognises one. It only matched a request whose action was "process" or "processing", which missed nearly every real import - WP All Import's scheduled runs use "trigger" and "pipe" as well, and some carry no action at all - so the option could be switched on and change nothing at all. On one site importing every half hour this had let the audit log reach 86 million rows and 22 GB, 97% of the whole database, almost all of it one row per imported image
* A request is now treated as an import whatever action it carries, so long as it is genuinely one. A WP All Import cron request must carry that site's real cron key, checked the same way WP All Import checks it before running the import, and the admin import screen counts only for someone allowed to run imports. Matching on the parameter alone would have let anyone append it to any request and go unlogged, which is not a trade worth making for an audit log
* A site whose importer endpoint carries neither the cron key nor the WP All Import page - a theme with its own import trigger, say - can declare a request an import through the new apex_toolbox_is_import_request filter, instead of needing a change to this plugin
* The option stays off by default and is still turned on per site from the Hooks page. Its name and description now describe the symptom it cures, an audit log grown to gigabytes, so it can be found by someone looking at a bloated database rather than only by someone who already knows it exists
* Ordinary site activity is still audited. Only requests identified as an import are skipped, and WP Defender already ignores its own audit listeners under WP-Cron and WP-CLI

= 2.3.1 =
* Outbound firewall: a development or staging copy can now log in to the WPMU DEV Dashboard, and so install and update WPMU DEV plugins. Connecting is what was blocked before - the Dashboard clears its stored key when it cannot reach WPMU DEV, so a copy would log itself out. **Connecting a copy adds it to your Hub as its own site**, because the address it registers is the copy's own and the live site's Hub entry is left alone. Unlike the other plugins that allow this, the Dashboard also re-connects twice a day on its own rather than only when someone presses a button. If your membership has a limited number of sites, remove this rule with the apex_toolbox_http_default_rules filter
* A development or staging copy now tells the WPMU DEV Hub its own address rather than the live site's, so connecting a copy adds that copy to the Hub and the live site's entry is never written to. This matters because a copy reports the live address when WordPress is used from the command line on a common local setup, and the Hub identifies sites by that address. It does nothing on the live site, or where the site already sets WPMUDEV_HUB_SITE_URL itself
* Disconnecting a site from the Hub stays blocked, as do granting and removing WPMU DEV support access and turning the Hub's analytics on or off
* The plugin changelog and the translation list the current Dashboard needs are allowed too, so its screens stop reporting connection errors. Downloads - plugins, themes and translation files - still have no rule and are only reachable while WordPress is actually installing them

= 2.3.0 =
* Outbound firewall: a development or staging copy can now see WP All Import updates, check its licence, and activate a licence manually. As with the other plugins that allow this, **activating a copy will use one of your licence's sites** - registering adds the copied site and leaves the live site alone, and it only happens when someone activates from the licence screen. Removing a licence from a site stays blocked: all three of those calls are the same address and differ by one parameter, so a copy carrying the live site's key could otherwise release it. If your licence has a limited number of sites, remove the activation value with the apex_toolbox_http_default_rules filter
* New **Apex Toolbox -> Packages** screen: the private plugins supplied to this website, what version each is on, and a one-click install - then an Activate button in the same place, so a newly installed plugin does not have to be hunted down on another screen. Once installed, they appear on the normal WordPress Updates screen and update like any other plugin, so there is nothing new to remember
* Each download is checked against a fingerprint published by the registry before WordPress is allowed to install it. A package that does not match is refused and deleted rather than installed
* If a plugin is no longer supplied to a website it simply stops being offered updates and is shown as no longer licensed. **Nothing is deactivated, disabled or removed** - a live site cannot break because of a change made at our end
* A website that cannot reach the registry keeps working from the answer it last received, for a week, and reports what went wrong on the Packages screen. Only a successful reply can remove a package from the list, so a connection problem can never be mistaken for a package being withdrawn - and a registry that cannot be reached is never re-tried on every page load
* Updates to private plugins appear everywhere WordPress reports updates - the Plugins screen, Dashboard -> Updates, and the command line - without anyone visiting the Packages screen. This is what lets the hosting dashboard, our update tooling and the team see a new release the same way they see any other plugin update, on WordPress's own schedule
* The screen reads the package list directly rather than waiting for WordPress's update check, so it still shows the truth on a site that cannot reach wordpress.org
* Outbound firewall: a development or staging copy can now reach the package list. Only that one read-only address is allowed, on GET alone. The download itself has no rule at all and stays blocked, except during the moment WordPress is actually installing an update - so a private plugin can be installed on a copy, and fetched at no other time
* The Packages screen shows whether the site is connected to the registry rather than offering a text box over the top of a token that is already in place. Replacing one means disconnecting first, so the step that stops updates arriving is a deliberate one, and saving a token checks for packages straight away. The site's registry token is set in wp-config.php where the site has been set up for it, and can otherwise be pasted in on the Packages screen. It is never written anywhere that loads on a front-end page request, and never appears in the firewall's blocked-request log
* Fix a plugin being switched off by updating it from the Packages screen. WordPress switches a plugin off before replacing its files and relies on the browser to switch it back on afterwards, which only happens on its own Plugins screen - so an update started from here left the plugin installed, up to date and inactive, and someone had to notice. A plugin that was running before an update is running after it
* Install, update and activate buttons show that they are working, and the others are held while one runs, so a slow install cannot be started twice by clicking again
* One-click install is offered only where WordPress can actually write to the plugins folder; where it cannot, the reason is shown up front instead of failing at the end of a long request. On a multisite the network administrator capability is required, since plugin files are shared across the whole network
* Developer: the plugin's namespaced layer now boots in two halves. The package features run on every supported WordPress; the MCP features keep their WordPress 7.0 requirement as before

= 2.2.2 =
* Outbound firewall: a licence can now be activated manually on a development or staging copy for Essential Addons for Elementor Pro, and the rest of the WPDeveloper range that shares its licensing. As with the other plugins that allow this, **activating a copy will use one of your licence's sites**: registering is allowed because it adds the copied site and leaves the live site alone, and it only ever happens when someone activates from the licence screen. Removing a site stays blocked, because a copy carries the live site's licence key and could otherwise release it. If your licence has a limited number of sites, remove the activation values with the apex_toolbox_http_default_rules filter
* The one-time-code step some accounts require is allowed too, because the vendor's server decides whether it is needed - without it, activation would fail halfway on those accounts
* Outbound firewall: Elementor's "Switch License" and "Deactivate License" no longer show a firewall error on a development or staging copy. Elementor treats a blocked request as fatal, so the page stopped with an error before the plugin had cleared its own licence details - deactivating happened to work anyway, switching did not. The firewall now answers that one request itself, without sending anything: the copy clears its connection exactly as it would normally, and no request reaches Elementor. It is answered rather than allowed because the request carries the live site's credentials and would otherwise ask Elementor to disconnect the live site
* Outbound firewall: licences can now be activated manually on a development or staging copy for Crocoblock's Jet plugins as well. The same terms apply as for the other plugins - registering the copied site is allowed, removing a site stays blocked. One difference worth knowing: this plugin can also register a site by itself, without anyone pressing a button, if the live site has a Crocoblock theme licence it has never synced. If your licence has a limited number of sites, remove this rule with the apex_toolbox_http_default_rules filter
* Outbound firewall: FileBird Pro's update check now works on a development or staging copy, and its licence can be activated there. As with the other plugins that allow this, **activating a copy will use one of your licence's sites** - registering adds the copied site and leaves the live site alone, and it only happens when someone activates from the licence screen. Removing a licence from a site stays blocked. If your licence has a limited number of sites, remove this rule with the apex_toolbox_http_default_rules filter
* Outbound firewall: Gravity Wiz Spellbook updates can now be installed on a development or staging copy, not just seen. That plugin builds its download address from a licence identifier it only learns during its licence check, so with those calls blocked the download address was incomplete and the vendor refused it - which looked like a silent failure, because nothing was being blocked at that point. Both the licence check and the registration it triggers are needed; with only the check allowed the site reports itself as inactive and the download is still refused. Note this plugin refreshes its licence by itself on a schedule, and doing so registers the copied site, so **a copy will use one of your licence's sites without anyone pressing a button**. If your Gravity Wiz licence has a limited number of sites, remove this rule with the apex_toolbox_http_default_rules filter
* New FileBird option: report the Production URL as FileBird's licence domain. FileBird Pro ties its licence to a web address and re-checks it on every update, so a development or staging copy is asked to sign in to Envato again and cannot install updates - which no firewall setting can change, because the copy is reporting its own address truthfully. With this option on, a copy tells FileBird the Production URL instead, so the licence it inherited keeps working. It is off by default and does nothing unless the Production URL is set and differs from the site's own address, so it is a no-op on the live site. Turning it on on the live site is safe and carries the setting to every copy made from it
= 2.2.1 =
* Outbound firewall: a development or staging copy can now install Admin Menu Editor Pro updates, not just see them. That plugin removes the download link entirely unless its licence check has succeeded, so the update appeared in the list and then could not be installed. The licence status check is now allowed
* Outbound firewall: Elementor Pro widgets can now be edited on a development or staging copy. Elementor Pro locks every Pro widget unless its licence check has succeeded, which made a copied site unusable for checking that an update had not broken anything. The licence status check is now allowed. Note that this check tells Elementor the copied site's own web address, so on a licence with a limited number of sites you may want to remove that rule - see the apex_toolbox_http_default_rules filter
* Outbound firewall: a licence can now be activated manually on a development or staging copy, for both Elementor Pro and Admin Menu Editor Pro. Elementor Pro's "Connect & Activate" makes four calls the firewall was blocking, and Admin Menu Editor Pro one. The last of them registers the copied site against the licence, so **activating a copy will use one of your licence's sites**. That is allowed deliberately: registering adds the copied site and leaves the live site alone, and it only ever happens when someone clicks Activate. Removing a site stays blocked throughout, because a copy carries the live site's own registration and could otherwise release it. If your licence has a limited number of sites and you would rather copies could not be activated at all, remove those rules with the apex_toolbox_http_default_rules filter
* Outbound firewall: rules can now pin one part of a web address while allowing another to vary, which is what lets the two Admin Menu Editor Pro calls above be told apart - they are otherwise identical, and one of them registers a site while the other removes one
= 2.2.0 =
* Outbound firewall: vendor endpoints can now be allowed by exact path and parameter, not just by host. This matters for licence servers - on one vendor host the harmless "is there an update?" call and the call that would deactivate the live site's licence are often the same host and the same method, and for some vendors the same address with one parameter changed. Allowing the host would allow both; a rule allows only the first
* The plugin now ships rules for common premium plugins, so a development or staging copy stops reporting connection errors and can still see which updates are available. Only the read-only calls are allowed - update checks, licence status, plugin information. Licence activation and deactivation stay blocked on every one of them, which also stops a copied site from silently claiming or releasing one of the live site's licence seats
* A development or staging copy can now install the updates it can see. Premium plugin downloads do not go to wordpress.org - they go to the vendor, often on a web address containing the licence key - so previously a copied site would show an update was available and then fail to install it. When WordPress itself starts downloading an update, the firewall now permits that one download, and its redirects, and nothing else. Outside that moment the same address is blocked as before, and a licence call made while an update happens to be running is still blocked, because it is not a download
* Anything a rule does not explicitly cover stays blocked and keeps appearing in the blocked-request list, so a new vendor endpoint is surfaced rather than let through quietly
* The shipped rules are listed on the firewall settings screen with the reason each one is safe, and can be removed or extended by a site via the apex_toolbox_http_default_rules filter
* The firewall no longer writes its "blocked outbound request" notice to the server log when WordPress is being run from the command line. On the command line that notice was landing in the middle of ordinary output - so a script reading, for example, the plugin list would see it mixed in with the list and fail. A blocked outbound request is a normal, expected thing on a development copy, so this affected every run. The notice is still available on demand by adding --debug, and every blocked request is still recorded in full on the firewall settings screen
* Request values are still never stored: parameter values are checked in memory only, and the blocked-request log continues to record key names alone
= 2.1.1 =
* Fix Elementor pages silently losing whole groups of styles - headings and other elements dropping to browser-default sizes - after a plugin is installed, activated, deactivated or updated, or a theme is switched. The cause is a bug in Elementor itself: when one page's stylesheet is built and that build triggers another (a page using an embedded template, which is ordinary), the inner build switches off an internal "use style controls" setting and never puts it back. Every element after that point contributes no CSS, so the page's stylesheet is written and linked correctly but is missing rules. Elementor treats it as good and never rebuilds it, so the page stays wrong until someone regenerates CSS by hand
* The plugin now holds that setting on for the duration of each build, which prevents the fault, and detects any page that was already affected - by reading the setting at the moment it matters, not by guessing from the result. Nothing is remembered between requests and no thresholds are involved
* An affected page is rebuilt immediately, before its stylesheet is linked, so the visitor who triggered the rebuild gets a complete page rather than the next one. This is safe because the fault comes from one build running inside another, and that only happens once: by the time the page is rebuilt the embedded template already has its own stylesheet, so there is nothing left to nest. If a page somehow cannot be rebuilt cleanly it is left for a later visit rather than retried in a loop
* Remove the automatic bulk CSS rebuild introduced in 2.1.0. Rebuilding many pages in one request was capable of producing incomplete stylesheets across a whole site in a single pass. Any pending scheduled rebuild left over from 2.1.0 is cleared automatically
* Never touch stylesheets during WP-CLI or cron, where CSS is built without a real page request
* Continue to clear the record for a stylesheet that has gone missing from disk, and if one genuinely cannot be written - a full disk or a read-only uploads folder - serve that page's styles inline rather than retrying indefinitely
* This protection is now on for every site, since the fault is silent and affects any site using Elementor templates. It can be turned off by defining APEX_CSS_HEAL_DISABLED in wp-config.php
* Editor previews are no longer put through the heal, as Elementor already rebuilds those on every request
* Add counters for affected, cleared and inline pages, shown in the admin bar
= 2.1.0 =
* Add redirect management tools for the agency's AI assistant on sites running the Redirection plugin: search and list redirects, read a single redirect, list redirect groups, and create, update or delete one. Creating, updating and deleting each require an explicit confirmation and a dedicated write:redirects permission, and none of the tools appear on a site where Redirection isn't active
* The redirect tools take a plain source, target and status code and deal with the Redirection plugin's internal format for you. A redirect with no target is refused rather than quietly sending visitors to the home page; an update leaves every field you don't mention exactly as it was; and disabling a redirect is offered as the reversible alternative to deleting one, since Redirection has no trash
* Fix Elementor CSS regeneration after a plugin update running inside the update request itself, at the moment Elementor is part old code and part new. On some plugin versions this caused a fatal error during the update; on others it rebuilt every stylesheet against the outdated code without reporting anything wrong. Regeneration now happens in a separate request immediately after the update finishes, so it still completes before the update call returns
* Elementor CSS regeneration now runs only on live sites, and only when Elementor or Elementor Pro was actually part of the update - previously it ran on every site after every plugin update
* Fix the backup path for that regeneration never running at all: the scheduled follow-up - used when the immediate rebuild can't be reached, and to continue on sites with more than 500 affected pages - had nothing listening for it, so it completed silently having done no work
* Fix Elementor CSS regeneration repeatedly failing on sites with no active Elementor Kit, where it tried to rebuild a stylesheet for a kit that doesn't exist and logged an error on every run
* Fix the List Categories Elementor widget breaking on sites without WooCommerce: it read WooCommerce's product category taxonomy without checking it was present, producing PHP warnings in the Elementor editor and an empty category list
* Declare Elementor compatibility in the plugin header, so Elementor warns you that this plugin hasn't been tested with an incoming Elementor release before you install it
* Update the List Categories widget to Elementor's current method for registering controls, replacing one deprecated since Elementor 3.1
* The assistant's tool descriptions now describe only what each tool does; whether a tool writes is already published separately to the assistant, so it is no longer repeated in the text
* Developer: the automated tests now run against real installations of Elementor and Redirection instead of empty placeholder classes, with both pinned to a known version in CI. Four of the fixes above were found by that change
= 2.0.3 =
* Outbound HTTP firewall (security): fix an allowlist entry whose methods were ALL unrecognised - a typo such as array('api.example.com' => array('GETT')) - falling back to allowing EVERY method, which silently granted POST/PUT/DELETE to that host from the setting whose purpose is to withhold them. Such an entry is now dropped and the host stays blocked. A host with no method set at all (a bare host, or a comma-separated list) is unchanged and still means all methods, and a partly mistyped set still keeps its valid verbs
* Outbound HTTP firewall: if you have an allowlist entry with a mistyped method, that host will now be blocked rather than fully open - check the Blocked Hosts panel after updating, and correct the verb in wp-config or allow it from the settings page
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
