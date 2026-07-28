<?php

declare(strict_types=1);

namespace ApexToolbox\Admin;

use ApexToolbox\Lib\Action;
use ApexToolbox\Mcp\Abilities\AbilityRegistry;
use ApexToolbox\Mcp\ExposedAbilities;
use ApexToolbox\Mcp\OAuth\ClientRegistry;
use ApexToolbox\Support\WordPressVersionGate;

/**
 * Diagnostic-only WP-admin page (Settings -> Apex Toolbox MCP). The allow-list is managed centrally
 * in Geoff via exposure-sync; this page is read-only visibility plus a per-client revoke.
 */
final class McpSettingsPage
{
    private const SLUG = 'apex-toolbox-mcp';
    private const CAPABILITY = 'manage_options';

    public function __construct(
        private readonly ClientRegistry $clients,
        private readonly ExposedAbilities $exposed,
    ) {
    }

    #[Action('admin_menu')]
    public function registerMenu(): void
    {
        add_options_page(
            __('Apex Toolbox MCP', 'apex_toolbox'),
            __('Apex Toolbox MCP', 'apex_toolbox'),
            self::CAPABILITY,
            self::SLUG,
            array($this, 'render'),
        );
    }

    #[Action('admin_post_apex_mcp_revoke_client')]
    public function handleRevoke(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Insufficient permissions.', 'apex_toolbox'));
        }

        check_admin_referer('apex_mcp_revoke_client');

        $clientId = isset($_POST['client_id'])
            ? sanitize_text_field(wp_unslash((string) $_POST['client_id']))
            : '';
        if ($clientId !== '') {
            $this->clients->revoke($clientId);
        }

        wp_safe_redirect(add_query_arg('page', self::SLUG, admin_url('options-general.php')));
        exit;
    }

    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        $available = AbilityRegistry::names();
        $exposed = $this->exposed->names();
        $notExposed = array_values(array_diff($available, $exposed));

        echo '<div class="wrap"><h1>' . esc_html__('Apex Toolbox MCP', 'apex_toolbox') . '</h1>';

        $supported = WordPressVersionGate::isSupported();
        printf(
            '<p><strong>%s</strong> %s</p>',
            esc_html__('WordPress version:', 'apex_toolbox'),
            esc_html(WordPressVersionGate::currentVersion() . ($supported ? ' ✓' : ' — MCP disabled (needs 7.0+)')),
        );

        echo '<h2>' . esc_html__('Connected clients', 'apex_toolbox') . '</h2><ul>';
        foreach ($this->clients->all() as $client) {
            echo '<li>' . esc_html((string) ($client['client_name'] ?? $client['client_id'])) . ' ';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
            echo '<input type="hidden" name="action" value="apex_mcp_revoke_client">';
            echo '<input type="hidden" name="client_id" value="' . esc_attr((string) $client['client_id']) . '">';
            wp_nonce_field('apex_mcp_revoke_client');
            echo '<button class="button">' . esc_html__('Revoke', 'apex_toolbox') . '</button></form></li>';
        }
        echo '</ul>';

        echo '<h2>' . esc_html__('Exposed abilities', 'apex_toolbox') . '</h2>';
        echo '<p>' . esc_html__('Managed centrally in Geoff at /admin/wordpress-abilities.', 'apex_toolbox') . '</p>';
        $this->renderList($exposed);

        echo '<h2>' . esc_html__('Available but not exposed', 'apex_toolbox') . '</h2>';
        $this->renderList($notExposed);

        echo '</div>';
    }

    /** @param list<string> $names */
    private function renderList(array $names): void
    {
        if ($names === array()) {
            echo '<p><em>' . esc_html__('None.', 'apex_toolbox') . '</em></p>';

            return;
        }

        echo '<ul>';
        foreach ($names as $name) {
            echo '<li><code>' . esc_html($name) . '</code></li>';
        }
        echo '</ul>';
    }
}
