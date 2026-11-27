<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\Seo;

use ApexToolbox\Lib\Action;

/**
 * Yoast does not expose its meta to REST by default. When Yoast is active we register the core
 * Yoast meta keys with show_in_rest so abilities (and Geoff) can read/write them. register_post_meta
 * must run every request on `init`, so this is a hook, not a one-off activation step.
 */
final class YoastRestMeta
{
    #[Action('init')]
    public function registerMeta(): void
    {
        if (Detect::detect() !== 'yoast') {
            return;
        }

        $keys = array('_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw', '_yoast_wpseo_canonical');

        foreach ($keys as $key) {
            register_post_meta('', $key, array(
                'show_in_rest' => true,
                'single' => true,
                'type' => 'string',
                'auth_callback' => static fn (): bool => current_user_can('edit_posts'),
            ));
        }
    }
}
