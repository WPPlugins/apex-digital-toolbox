<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\Seo;

/**
 * Reads and writes Yoast's site-wide SEO settings — the per-post-type / per-taxonomy indexing
 * toggles and the default title/meta-description templates (which support Yoast/WooCommerce
 * replacement variables like %%title%% or %%wc_price%%).
 *
 * This is deliberately Yoast-specific: unlike per-post SEO meta (which every plugin stores the same
 * way), settings storage differs wildly between SEO plugins, so a portable abstraction would be
 * leaky. We read/write the raw `wpseo_titles` option and merge partial changes, preserving every
 * key we weren't asked to touch — so a fresh Yoast request picks the new values up.
 */
final class YoastSettings
{
    private const OPTION = 'wpseo_titles';

    /**
     * The replacement variables an admin can put in a title/description template. A curated set
     * (Yoast core + WooCommerce) so Geoff proposes only ones Yoast will actually resolve.
     *
     * @return list<string>
     */
    public static function replacementVariables(): array
    {
        return array(
            '%%title%%',
            '%%sitename%%',
            '%%sep%%',
            '%%page%%',
            '%%excerpt%%',
            '%%category%%',
            '%%primary_category%%',
            '%%tag%%',
            '%%term_title%%',
            '%%pt_single%%',
            '%%pt_plural%%',
            '%%wc_price%%',
            '%%wc_sku%%',
            '%%wc_brand%%',
            '%%wc_shortdesc%%',
        );
    }

    /**
     * @param list<string>|null $postTypes limit to these (default: all public post types)
     * @param list<string>|null $taxonomies limit to these (default: all public taxonomies)
     * @return array<string, mixed>
     */
    public function read(?array $postTypes = null, ?array $taxonomies = null): array
    {
        $option = $this->option();
        $types = $postTypes ?? $this->publicPostTypes();
        $taxes = $taxonomies ?? $this->publicTaxonomies();

        $postTypeSettings = array();
        foreach ($types as $type) {
            $postTypeSettings[ $type ] = array(
                'title' => $this->str($option, 'title-' . $type),
                'metaDesc' => $this->str($option, 'metadesc-' . $type),
                'noindex' => $this->bool($option, 'noindex-' . $type),
            );
        }

        $taxonomySettings = array();
        foreach ($taxes as $tax) {
            $taxonomySettings[ $tax ] = array(
                'title' => $this->str($option, 'title-tax-' . $tax),
                'metaDesc' => $this->str($option, 'metadesc-tax-' . $tax),
                'noindex' => $this->bool($option, 'noindex-tax-' . $tax),
            );
        }

        return array(
            'plugin' => 'yoast',
            'separator' => $this->str($option, 'separator'),
            'postTypes' => $postTypeSettings,
            'taxonomies' => $taxonomySettings,
            'replacementVariables' => self::replacementVariables(),
        );
    }

    /**
     * Apply a partial change set and return the re-read settings for the affected keys.
     *
     * @param array<string, mixed> $changes {postTypes:{pt:{title?,metaDesc?,noindex?}}, taxonomies:{tax:{...}}}
     * @return array<string, mixed>
     */
    public function update(array $changes): array
    {
        $option = $this->option();

        [$option, $postTypes] = $this->applyPostTypes($option, $this->section($changes, 'postTypes'));
        [$option, $taxonomies] = $this->applyTaxonomies($option, $this->section($changes, 'taxonomies'));

        update_option(self::OPTION, $option);

        return $this->read($postTypes, $taxonomies);
    }

    /**
     * @param array<string, mixed> $option
     * @param array<string, mixed> $incoming
     * @return array{0:array<string,mixed>,1:list<string>} the merged option and the post types touched
     */
    private function applyPostTypes(array $option, array $incoming): array
    {
        $touched = array();
        foreach ($incoming as $type => $fields) {
            $type = sanitize_key((string) $type);
            if ($type === '' || ! post_type_exists($type) || ! is_array($fields)) {
                continue;
            }
            $option = $this->writeFields($option, $type, false, $fields);
            $touched[] = $type;
        }

        return array($option, $touched);
    }

    /**
     * @param array<string, mixed> $option
     * @param array<string, mixed> $incoming
     * @return array{0:array<string,mixed>,1:list<string>} the merged option and the taxonomies touched
     */
    private function applyTaxonomies(array $option, array $incoming): array
    {
        $touched = array();
        foreach ($incoming as $tax => $fields) {
            $tax = sanitize_key((string) $tax);
            if ($tax === '' || ! taxonomy_exists($tax) || ! is_array($fields)) {
                continue;
            }
            $option = $this->writeFields($option, $tax, true, $fields);
            $touched[] = $tax;
        }

        return array($option, $touched);
    }

    /**
     * Merge the provided fields for one post type / taxonomy into the option array.
     *
     * @param array<string, mixed> $option
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function writeFields(array $option, string $slug, bool $isTaxonomy, array $fields): array
    {
        $suffix = $isTaxonomy
            ? 'tax-' . $slug
            : $slug;

        if (array_key_exists('title', $fields)) {
            $option[ 'title-' . $suffix ] = (string) $fields['title'];
        }
        if (array_key_exists('metaDesc', $fields)) {
            $option[ 'metadesc-' . $suffix ] = (string) $fields['metaDesc'];
        }
        if (array_key_exists('noindex', $fields)) {
            $option[ 'noindex-' . $suffix ] = ! empty($fields['noindex']);
        }

        return $option;
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function section(array $changes, string $key): array
    {
        return isset($changes[ $key ]) && is_array($changes[ $key ])
            ? $changes[ $key ]
            : array();
    }

    /** @return array<string, mixed> */
    private function option(): array
    {
        $stored = get_option(self::OPTION, array());

        return is_array($stored)
            ? $stored
            : array();
    }

    /** @return list<string> */
    private function publicPostTypes(): array
    {
        return array_values(get_post_types(array('public' => true), 'names'));
    }

    /** @return list<string> */
    private function publicTaxonomies(): array
    {
        return array_values(get_taxonomies(array('public' => true), 'names'));
    }

    /** @param array<string, mixed> $option */
    private function str(array $option, string $key): string
    {
        return isset($option[ $key ]) && is_scalar($option[ $key ])
            ? (string) $option[ $key ]
            : '';
    }

    /** @param array<string, mixed> $option */
    private function bool(array $option, string $key): bool
    {
        return ! empty($option[ $key ]);
    }
}
