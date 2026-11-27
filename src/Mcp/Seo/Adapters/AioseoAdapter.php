<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\Seo\Adapters;

use ApexToolbox\Mcp\Seo\PostMetaSeoAdapter;

/**
 * All in One SEO adapter.
 *
 * CAVEAT: AIOSEO v4 stores its data in a dedicated `wp_aioseo_posts` table, NOT post meta. This
 * adapter targets the legacy `_aioseop_*` post-meta keys (AIOSEO Pack v2 / older installs). A
 * dedicated table-backed adapter is a documented follow-up before relying on AIOSEO v4 writes.
 */
final class AioseoAdapter extends PostMetaSeoAdapter
{
    public function slug(): string
    {
        return 'aioseo';
    }

    protected function keyMap(): array
    {
        return array(
            'title' => '_aioseop_title',
            'description' => '_aioseop_description',
            'focusKeyword' => '_aioseop_keywords',
            'canonical' => '_aioseop_custom_link',
            'robots' => '_aioseop_noindex',
            'ogTitle' => '_aioseop_opengraph_settings_title',
            'ogDescription' => '_aioseop_opengraph_settings_desc',
        );
    }
}
