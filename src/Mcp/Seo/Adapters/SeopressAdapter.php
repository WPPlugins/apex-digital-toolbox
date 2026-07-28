<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\Seo\Adapters;

use ApexToolbox\Mcp\Seo\PostMetaSeoAdapter;

/**
 * SEOPress stores its meta in post meta under _seopress_* keys.
 */
final class SeopressAdapter extends PostMetaSeoAdapter
{
    public function slug(): string
    {
        return 'seopress';
    }

    protected function keyMap(): array
    {
        return array(
            'title' => '_seopress_titles_title',
            'description' => '_seopress_titles_desc',
            'focusKeyword' => '_seopress_analysis_target_kw',
            'canonical' => '_seopress_robots_canonical',
            'robots' => '_seopress_robots_index',
            'ogTitle' => '_seopress_social_fb_title',
            'ogDescription' => '_seopress_social_fb_desc',
        );
    }
}
