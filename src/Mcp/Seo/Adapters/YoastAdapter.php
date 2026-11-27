<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\Seo\Adapters;

use ApexToolbox\Mcp\Seo\PostMetaSeoAdapter;

/**
 * Yoast SEO stores its meta in post meta under _yoast_wpseo_* keys.
 */
final class YoastAdapter extends PostMetaSeoAdapter
{
    public function slug(): string
    {
        return 'yoast';
    }

    protected function keyMap(): array
    {
        return array(
            'title' => '_yoast_wpseo_title',
            'description' => '_yoast_wpseo_metadesc',
            'focusKeyword' => '_yoast_wpseo_focuskw',
            'canonical' => '_yoast_wpseo_canonical',
            'robots' => '_yoast_wpseo_meta-robots-noindex',
            'ogTitle' => '_yoast_wpseo_opengraph-title',
            'ogDescription' => '_yoast_wpseo_opengraph-description',
        );
    }
}
