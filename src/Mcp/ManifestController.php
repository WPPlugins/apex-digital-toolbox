<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp;

use ApexToolbox\Lib\Action;
use ApexToolbox\Mcp\Abilities\AbilityRegistry;
use ApexToolbox\Mcp\OAuth\Scopes;
use ApexToolbox\Mcp\Seo\Detect;
use ApexToolbox\Support\WordPressVersionGate;
use WP_REST_Response;

/**
 * GET /wp-json/apex/v1/info — the anonymous manifest Geoff pre-flights before initiating OAuth.
 *
 * Leaks no secrets: it reports configuration shape and discovered-ability NAMES only (never
 * schemas — those go via MCP tools/list after auth).
 */
final class ManifestController
{
    public const NAMESPACE = 'apex/v1';

    /**
     * Pure manifest assembly (testable without REST/HTTP).
     *
     * @param list<array{name:string,description?:string,isWrite?:bool}> $availableAbilities
     * @param list<string> $exposedNames
     * @return array<string, mixed>
     */
    public static function buildManifest(
        string $wpVersion,
        string $pluginVersion,
        ?string $seoSlug,
        array $availableAbilities,
        array $exposedNames,
        bool $mcpReady,
    ): array {
        $versionOk = WordPressVersionGate::isSupported($wpVersion);

        $availableNames = array_map(static fn (array $a): string => (string) $a['name'], $availableAbilities);
        $exposedSet = array_values(array_intersect($availableNames, $exposedNames));

        return array(
            'plugin' => array(
                'name' => 'Apex Toolbox',
                'version' => $pluginVersion,
            ),
            'wordpress' => array(
                'version' => $wpVersion,
                'compatible' => $versionOk && $mcpReady,
            ),
            'mcp' => array(
                'serverPath' => '/wp-json/mcp/apex',
                'ready' => $versionOk && $mcpReady,
            ),
            'oauth' => array(
                'supported' => $versionOk,
                'authorizeUrl' => \ApexToolbox\Mcp\OAuth\OAuthController::AUTHORIZE_PATH,
                'tokenUrl' => '/wp-json/' . self::NAMESPACE . '/oauth/token',
                'scopesAvailable' => Scopes::names(),
            ),
            'site' => array(
                'name' => get_bloginfo('name'),
                'homeUrl' => home_url(),
            ),
            'abilities' => array(
                // Objects ({name, description, isWrite, inputSchema, outputSchema}) so an admin can
                // see what each not-yet-exposed ability does before approving it in Geoff.
                'available' => array_values($availableAbilities),
                'exposed' => $exposedSet,
            ),
        );
    }

    public function __construct(private readonly ExposedAbilities $exposed)
    {
    }

    #[Action('rest_api_init')]
    public function registerRoutes(): void
    {
        register_rest_route(
            self::NAMESPACE,
            '/info',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'handle'),
                'permission_callback' => '__return_true',
            ),
        );
    }

    public function handle(): WP_REST_Response
    {
        return new WP_REST_Response($this->manifest(), 200);
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        $wpVersion = WordPressVersionGate::currentVersion();
        $mcpReady = class_exists('\WP\MCP\Core\McpAdapter');

        return self::buildManifest(
            $wpVersion,
            $this->pluginVersion(),
            Detect::detect(),
            AbilityRegistry::describe(),
            $this->exposed->names(),
            $mcpReady,
        );
    }

    private function pluginVersion(): string
    {
        $file = APEX_TOOLBOX_PLUGIN_PATH . 'apex-digital-toolbox.php';
        $data = get_file_data($file, array('Version' => 'Version'));

        return $data['Version'] ?? '0.0.0';
    }
}
