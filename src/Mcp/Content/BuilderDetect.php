<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\Content;

/**
 * Works out which page builder produced a given post. Detection is per-post, not per-site — a
 * single site routinely mixes builders page by page, so this lives on the content read rather than
 * in site-info.
 */
final class BuilderDetect
{
    public const ELEMENTOR = 'elementor';
    public const WPBAKERY = 'wpbakery';
    public const GUTENBERG = 'gutenberg';
    public const CLASSIC = 'classic';

    /** Pure precedence classifier, testable without a post. */
    public static function classify(bool $isElementor, bool $isWpBakery, bool $hasBlocks): string
    {
        if ($isElementor) {
            return self::ELEMENTOR;
        }
        if ($isWpBakery) {
            return self::WPBAKERY;
        }
        if ($hasBlocks) {
            return self::GUTENBERG;
        }

        return self::CLASSIC;
    }

    public static function detect(int $postId): string
    {
        $post = get_post($postId);
        $content = $post instanceof \WP_Post
            ? (string) $post->post_content
            : '';

        $isElementor = get_post_meta($postId, '_elementor_edit_mode', true) === 'builder';
        $isWpBakery = strpos($content, '[vc_') !== false
            || get_post_meta($postId, '_wpb_vc_js_status', true) !== '';
        $hasBlocks = function_exists('has_blocks') && has_blocks($content);

        return self::classify($isElementor, $isWpBakery, $hasBlocks);
    }
}
