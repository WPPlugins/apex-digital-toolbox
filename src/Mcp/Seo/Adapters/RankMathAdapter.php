<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\Seo\Adapters;

use ApexToolbox\Mcp\Seo\PostMetaSeoAdapter;

/**
 * Rank Math stores its meta in post meta under rank_math_* keys.
 */
final class RankMathAdapter extends PostMetaSeoAdapter
{
    public function slug(): string
    {
        return 'rankmath';
    }

    protected function keyMap(): array
    {
        return array(
            'title' => 'rank_math_title',
            'description' => 'rank_math_description',
            'focusKeyword' => 'rank_math_focus_keyword',
            'canonical' => 'rank_math_canonical_url',
            'robots' => 'rank_math_robots',
            'ogTitle' => 'rank_math_facebook_title',
            'ogDescription' => 'rank_math_facebook_description',
        );
    }
}
