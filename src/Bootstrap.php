<?php

declare(strict_types=1);

namespace ApexToolbox;

use ApexToolbox\Lib\ControllerManager;
use ApexToolbox\Support\WordPressVersionGate;
use ApexToolbox\Vendor\DI\Container;
use ApexToolbox\Vendor\DI\ContainerBuilder;
use toolboxController;

/**
 * Entry point for the namespaced, DI-driven layer of the plugin (MCP server, OAuth,
 * abilities, admin diagnostics).
 *
 * Runs ALONGSIDE the legacy hook engine (toolboxController), not instead of it. The legacy
 * toolboxController instance is bound into the container so namespaced services can read
 * plugin options, logs and hook state through it.
 *
 * Gated to WordPress 7.0+ — on older sites boot() registers an admin notice and bails, so
 * the plugin's non-MCP features keep working untouched.
 */
final class Bootstrap
{
    /** Geoff's production OAuth callback — allowed by default on every site (the common case). */
    // phpcs:ignore Generic.Files.LineLength.TooLong, SlevomatCodingStandard.Files.LineLength.LineTooLong
    public const GEOFF_PRODUCTION_REDIRECT_URI = 'https://ai.limelightdigital.co.nz/api/integrations/wordpress-mcp/callback';

    /** Geoff's local-dev OAuth callback — allowed by default so dev pairing needs no config. */
    public const GEOFF_DEV_REDIRECT_URI = 'http://localhost:3000/api/integrations/wordpress-mcp/callback';

    private static ?Container $container = null;

    public static function boot(toolboxController $legacyToolbox): void
    {
        self::bootFor(WordPressVersionGate::currentVersion(), $legacyToolbox);
    }

    /**
     * The boot itself, with the WordPress version passed in.
     *
     * Split out so the gate can be exercised at a version other than the one the
     * test suite happens to run on. The claim being protected is that the
     * package layer registers on a 6.9 site - the plugin's declared minimum -
     * while the MCP layer does not, and asserting that needs to be possible
     * without standing up an old WordPress.
     */
    public static function bootFor(string $wpVersion, toolboxController $legacyToolbox): void
    {
        $container = self::container();

        // Make the live legacy controller resolvable by type-hint in namespaced services.
        $container->set(toolboxController::class, $legacyToolbox);

        // Registered on every supported WordPress, including the 6.9 the plugin header still
        // declares. The private-package layer needs none of the 7.0 Abilities/MCP infrastructure,
        // so gating it behind that version would withhold it from sites for no reason.
        ControllerManager::register($container, self::coreControllers());

        if (! WordPressVersionGate::isSupported($wpVersion)) {
            add_action('admin_notices', [self::class, 'renderUnsupportedNotice']);

            return;
        }

        // Ensure the Geoff OAuth client (and its signing key pair) exists and that its allowed
        // redirect URIs match the site config. Configure per site via the wp-config constant
        // APEX_TOOLBOX_MCP_REDIRECT_URIS (Geoff's OAuth callback URL[s]) or the matching filter.
        $container->get(\ApexToolbox\Mcp\OAuth\ClientRegistry::class)->ensureClient(
            'geoff',
            'Geoff',
            self::configuredRedirectUris(),
            \ApexToolbox\Mcp\OAuth\Scopes::names(),
            time(),
        );

        ControllerManager::register($container, self::mcpControllers());
    }

    /**
     * Build (once) the php-di container for the namespaced layer.
     */
    public static function container(): Container
    {
        if (self::$container instanceof Container) {
            return self::$container;
        }

        $builder = new ContainerBuilder();
        $builder->useAttributes(true);
        $builder->addDefinitions(self::definitions());

        return self::$container = $builder->build();
    }

    public static function renderUnsupportedNotice(): void
    {
        $message = sprintf(
            /* translators: %s: minimum required WordPress version. */
            esc_html__(
                'Apex Toolbox MCP features need WordPress %s+. Other plugin features are unaffected.',
                'apex_toolbox',
            ),
            esc_html(WordPressVersionGate::MINIMUM_WP_VERSION),
        );

        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            wp_kses_post($message),
        );
    }

    /**
     * Controllers registered on every supported WordPress version, below the 7.0 gate.
     *
     * Public so the split itself is assertable in a test without standing up an old WordPress.
     *
     * @return array<class-string>
     */
    public static function coreControllers(): array
    {
        return [
            \ApexToolbox\Packages\UpdateController::class,
            \ApexToolbox\Packages\DevRegistryOverride::class,
            \ApexToolbox\Admin\PackagesPage::class,
        ];
    }

    /**
     * Controllers that need the WordPress 7.0 Abilities/MCP infrastructure.
     *
     * @return array<class-string>
     */
    public static function mcpControllers(): array
    {
        return [
            \ApexToolbox\Mcp\ManifestController::class,
            \ApexToolbox\Mcp\ExposureSyncController::class,
            \ApexToolbox\Mcp\ServerRegistration::class,
            \ApexToolbox\Mcp\OAuth\OAuthController::class,
            \ApexToolbox\Mcp\Abilities\AbilitiesController::class,
            \ApexToolbox\Mcp\Seo\YoastRestMeta::class,
            \ApexToolbox\Admin\McpSettingsPage::class,
        ];
    }

    /**
     * Allowed OAuth redirect URIs for the Geoff client.
     *
     * Defaults to Geoff's production + local-dev callbacks (covers ~all sites with no config).
     * A site can ADD more via the wp-config constant APEX_TOOLBOX_MCP_REDIRECT_URIS (string or
     * array); the apex_mcp_oauth_redirect_uris filter then receives the full list for any final
     * override (e.g. to remove a default).
     *
     * @return list<string>
     */
    private static function configuredRedirectUris(): array
    {
        $uris = array(self::GEOFF_PRODUCTION_REDIRECT_URI, self::GEOFF_DEV_REDIRECT_URI);

        if (defined('APEX_TOOLBOX_MCP_REDIRECT_URIS')) {
            $uris = array_merge($uris, (array) constant('APEX_TOOLBOX_MCP_REDIRECT_URIS'));
        }

        $uris = (array) apply_filters('apex_mcp_oauth_redirect_uris', $uris);

        return array_values(array_unique(array_filter(array_map('strval', $uris))));
    }

    /**
     * Interface -> implementation bindings and value definitions.
     *
     * @return array<string, mixed>
     */
    private static function definitions(): array
    {
        return [
            // MCP / SEO / OAuth bindings are added here in Phase C, e.g. the detected
            // SeoAdapter implementation chosen by the active SEO plugin.
        ];
    }
}
