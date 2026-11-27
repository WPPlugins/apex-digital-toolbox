<?php

// Forbid accessing directly
if (! defined('ABSPATH')) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}
require_once __DIR__ . '/toolboxHttpRuleModel.php';

// Ensure there are no conflicts
if (! class_exists('toolboxHttpFirewallTable')) {
    /**
     * Presentation for the firewall settings screen.
     *
     * Split out of toolboxHookOutboundFirewall, which reached the phpcs class-length cap - the hook
     * was already within a handful of lines of it before this change, so any addition would have
     * breached it. Rendering is the obvious thing to lift out: it makes no firewall decisions and
     * holds no state, it just turns rules into HTML.
     *
     * Only the shared table CSS and the curated-rules table have moved so far. The remaining render
     * methods on the hook belong here too, but most of them have no test coverage, so moving them
     * needs its own pass with tests rather than riding along with a rule change.
     */
    class toolboxHttpFirewallTable
    {
        /**
         * The curated rule tier, rendered read-only. Worth showing even though nothing here is
         * editable: without it an operator sees "my.elementor.com" allowed in practice but absent from
         * every allowlist table, and the interesting part - that only these paths are open on that host
         * - is invisible.
         */
        public static function curatedRules(array $rules): string
        {
            if (empty($rules)) {
                return '';
            }

            $rows = '';
            foreach ($rules as $rule) {
                $methods = in_array(toolboxHttpRuleModel::ALL_METHODS, $rule['methods'], true)
                    ? 'ALL'
                    : implode(', ', $rule['methods']);
                $paths = empty($rule['paths'])
                    ? 'any path'
                    : implode('<br>', array_map('esc_html', $rule['paths']));
                $require = '';
                foreach ($rule['require'] as $key => $values) {
                    $require .= '<br>' . esc_html($key . ' = ' . implode(' | ', $values));
                }
                $rows .= '<tr><td>' . esc_html($rule['host']) . '</td>'
                    . '<td><code>' . esc_html($methods) . '</code></td>'
                    . '<td><code>' . $paths . $require . '</code></td>'
                    . '<td>' . esc_html($rule['note']) . '</td></tr>';
            }

            return '<h4 style="margin:18px 0 6px;">Curated vendor rules</h4>'
                . '<p>Shipped with the toolbox and filterable via <code>apex_toolbox_http_default_rules'
                . '</code>. Unlike a host allowlist these open only the listed paths: every other path '
                . 'on the same host stays blocked, which is what keeps a clone from reaching a '
                . 'vendor&rsquo;s licence <em>deactivate</em> endpoint.</p>'
                . self::style()
                . '<table class="widefat striped apex-firewall-table" style="max-width:1040px;margin:0 0 12px;">'
                . '<thead><tr><th>Host</th><th>Methods</th><th>Paths / required params</th>'
                . '<th>Why it is safe</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        }

        /**
         * Shared CSS for the firewall admin tables: cell padding matching the Elementor Revisions panel
         * (10px 14px), plus the collapsible "View details" styling that keeps long URLs/callers wrapped
         * inside a bounded width so they never stretch the page.
         */
        public static function style(): string
        {
            return '<style>'
                . '.apex-firewall-table { border-collapse:separate; }'
                . '.apex-firewall-table th, .apex-firewall-table td { padding:10px 14px; vertical-align:top; }'
                . '.apex-firewall-table summary { cursor:pointer; color:#2271b1; }'
                . '.apex-firewall-table .apex-fw-detail-body { margin-top:8px; max-width:600px; }'
                . '.apex-firewall-table .apex-fw-detail-row { margin-bottom:8px; }'
                // Long URLs stay on one line inside a fixed-width box that scrolls horizontally, so a
                // giant query string can never stretch the page or balloon the row height.
                . '.apex-firewall-table .apex-fw-detail-body code {'
                . ' display:block; max-width:600px; overflow-x:auto; white-space:nowrap;'
                . ' padding:4px 8px; background:#f6f7f7; border:1px solid #e0e0e0; }'
                . '</style>';
        }
    }
}
