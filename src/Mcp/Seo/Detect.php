<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\Seo;

use ApexToolbox\Mcp\Seo\Adapters\AioseoAdapter;
use ApexToolbox\Mcp\Seo\Adapters\RankMathAdapter;
use ApexToolbox\Mcp\Seo\Adapters\SeopressAdapter;
use ApexToolbox\Mcp\Seo\Adapters\YoastAdapter;

/**
 * Detects which SEO plugin is active and builds the matching adapter. The SEO abilities are only
 * registered when detect() is non-null, so a site with no SEO plugin never exposes them.
 */
final class Detect
{
    public static function detect(): ?string
    {
        return self::detectFrom(array(
            'yoast' => defined('WPSEO_VERSION'),
            'rankmath' => defined('RANK_MATH_VERSION'),
            'aioseo' => defined('AIOSEO_VERSION'),
            'seopress' => defined('SEOPRESS_VERSION'),
        ));
    }

    /**
     * Pure precedence resolver (first active wins), testable without defining global constants.
     *
     * @param array<string, bool> $active slug => is-active
     */
    public static function detectFrom(array $active): ?string
    {
        foreach (array('yoast', 'rankmath', 'aioseo', 'seopress') as $slug) {
            if (! empty($active[ $slug ])) {
                return $slug;
            }
        }

        return null;
    }

    public static function adapterFor(string $slug): ?SeoAdapter
    {
        return match ($slug) {
            'yoast' => new YoastAdapter(),
            'rankmath' => new RankMathAdapter(),
            'aioseo' => new AioseoAdapter(),
            'seopress' => new SeopressAdapter(),
            default => null,
        };
    }

    public static function activeAdapter(): ?SeoAdapter
    {
        $slug = self::detect();

        return $slug === null
            ? null
            : self::adapterFor($slug);
    }
}
