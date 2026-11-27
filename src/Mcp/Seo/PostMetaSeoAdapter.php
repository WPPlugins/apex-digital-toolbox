<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\Seo;

/**
 * Base adapter for SEO plugins that store their meta in post meta. A concrete adapter only needs
 * to declare its slug and the field -> meta-key map; get()/update() are shared here.
 *
 * Update is partial: only the fields present in the incoming SeoMeta are written, so a caller can
 * change just the description without clobbering the title.
 */
abstract class PostMetaSeoAdapter implements SeoAdapter
{
    /** @return array<string, string> canonical field name => post-meta key */
    abstract protected function keyMap(): array;

    public function metaKeyFor(string $field): ?string
    {
        return $this->keyMap()[ $field ] ?? null;
    }

    public function get(int $postId): SeoMeta
    {
        $fields = array();
        foreach ($this->keyMap() as $field => $metaKey) {
            $value = get_post_meta($postId, $metaKey, true);
            $fields[ $field ] = $value === '' || $value === false
                ? null
                : (string) $value;
        }

        return new SeoMeta($fields);
    }

    public function update(int $postId, SeoMeta $meta): void
    {
        $map = $this->keyMap();

        foreach ($meta->fields as $field => $value) {
            if (! isset($map[ $field ])) {
                continue;
            }

            if ($value === null) {
                delete_post_meta($postId, $map[ $field ]);

                continue;
            }

            update_post_meta($postId, $map[ $field ], $value);
        }
    }
}
