<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp;

use ApexToolbox\Lib\Action;
use ApexToolbox\Mcp\OAuth\TokenValidator;

/**
 * Registers the custom MCP server at /wp-json/mcp/apex with the MCP Adapter.
 *
 * The exposed tool list is read from `apex_mcp_exposed_abilities` at registration time, which the
 * adapter re-runs each request (it fires `mcp_adapter_init` on rest_api_init) — so adding/removing
 * an ability via exposure-sync takes effect immediately, no plugin reload. Authentication is our
 * OAuth bearer validator, wired as the transport permission callback.
 */
final class ServerRegistration
{
    public function __construct(
        private readonly ExposedAbilities $exposed,
        private readonly TokenValidator $tokens,
    ) {
    }

    #[Action('plugins_loaded')]
    public function bootAdapter(): void
    {
        // The MCP Adapter ships as a plugin-style package whose mcp-adapter.php bootstrap is what
        // actually wires its init (hooking mcp_adapter_init onto rest_api_init). We MUST require
        // that file — guarding on class_exists() is wrong here because Composer PSR-4 autoloads the
        // adapter's classes, so the class always "exists" even though the bootstrap never ran.
        // Guard instead on WP_MCP_VERSION (defined by the bootstrap) so we don't double-init when a
        // standalone MCP Adapter plugin is also active.
        if (defined('WP_MCP_VERSION')) {
            return;
        }

        // We install the adapter as a Composer DEPENDENCY, so its classes load via our autoloader
        // and it has no nested vendor/autoload.php of its own. Tell its bootstrap not to look for
        // one (WP_MCP_AUTOLOAD=false) — otherwise its Autoloader::autoload() fails and mcp-adapter.php
        // returns before Plugin::instance() runs, so mcp_adapter_init never fires and no server route
        // is registered.
        if (! defined('WP_MCP_AUTOLOAD')) {
            define('WP_MCP_AUTOLOAD', false);
        }

        $bootstrap = APEX_TOOLBOX_PLUGIN_PATH . 'vendor/wordpress/mcp-adapter/mcp-adapter.php';
        if (is_readable($bootstrap)) {
            require_once $bootstrap;
        }

        // Don't stand up the adapter's built-in default server — it would expose generic
        // discover/execute-ability tools OUTSIDE our exposure allow-list. We register exactly one
        // server ('apex'), OAuth-gated, whose tool list is the allow-list Geoff pushes.
        add_filter('mcp_adapter_create_default_server', '__return_false');
    }

    /** @param object $adapter The McpAdapter instance passed by the mcp_adapter_init action. */
    #[Action('mcp_adapter_init')]
    public function registerServer($adapter): void
    {
        if (! is_object($adapter) || ! method_exists($adapter, 'create_server')) {
            return;
        }

        $adapter->create_server(
            'apex',
            'mcp',
            'apex',
            'Apex Toolbox MCP',
            __('Agency MCP server exposing approved abilities to Geoff.', 'apex_toolbox'),
            'v1.0.0',
            array('\WP\MCP\Transport\HttpTransport'),
            '\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler',
            '\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler',
            $this->exposed->names(),
            array(),
            array(),
            array($this->tokens, 'validateForMcp'),
        );
    }
}
