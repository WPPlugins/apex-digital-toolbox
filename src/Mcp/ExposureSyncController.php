<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp;

use ApexToolbox\Lib\Action;
use ApexToolbox\Mcp\Abilities\AbilityRegistry;
use ApexToolbox\Mcp\OAuth\Scopes;
use ApexToolbox\Mcp\OAuth\TokenValidator;
use WP_REST_Request;
use WP_REST_Response;

/**
 * PUT /wp-json/apex/v1/exposure-sync — Geoff pushes the full allow-list; the plugin overwrites
 * its copy. Requires the `admin:exposure` scope. Geoff is the source of truth; this is a full
 * replace, not a partial update.
 */
final class ExposureSyncController
{
    public function __construct(
        private readonly ExposedAbilities $exposed,
        private readonly TokenValidator $tokens,
    ) {
    }

    #[Action('rest_api_init')]
    public function registerRoutes(): void
    {
        register_rest_route(
            ManifestController::NAMESPACE,
            '/exposure-sync',
            array(
                'methods' => 'PUT',
                'callback' => array($this, 'handle'),
                'permission_callback' => array($this, 'permission'),
            ),
        );
    }

    /** @return true|\WP_Error */
    public function permission(WP_REST_Request $request): bool|\WP_Error
    {
        $auth = $this->tokens->authenticate($request);
        if ($auth instanceof \WP_Error) {
            return $auth;
        }

        if (! in_array(Scopes::ADMIN_EXPOSURE, $auth['scopes'], true)) {
            return new \WP_Error(
                'apex_mcp_insufficient_scope',
                'The admin:exposure scope is required.',
                array('status' => 403),
            );
        }

        return true;
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        $incoming = is_array($body) && isset($body['abilities']) && is_array($body['abilities'])
            ? $body['abilities']
            : array();

        $result = $this->exposed->sync($incoming, AbilityRegistry::names());

        return new WP_REST_Response(
            array(
                'accepted' => count($result['accepted']),
                'ignored' => $result['ignored'],
            ),
            200,
        );
    }
}
