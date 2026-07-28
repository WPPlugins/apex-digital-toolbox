<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\Content;

use ApexToolbox\Mcp\Seo\SeoAdapter;
use WP_Query;

/**
 * Enumerates posts/pages/CPTs for the "audit every page" workflows.
 *
 * Paginated and filterable so an agent can pull the site in slices instead of all at once — and,
 * crucially, filter to just the items missing an SEO title/description via the active SEO adapter,
 * so Geoff only reads the copy of pages that actually need work. That filter is applied in the DB
 * query (not post-hoc), so pagination totals stay honest.
 */
final class ContentList
{
    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly ?SeoAdapter $seo)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function list(array $input): array
    {
        $postTypes = $this->postTypes($input['postType'] ?? 'page');
        $status = $this->status($input['status'] ?? 'publish');
        $perPage = $this->perPage($input['perPage'] ?? self::DEFAULT_PER_PAGE);
        $page = max(1, (int) ($input['page'] ?? 1));
        $search = trim((string) ($input['search'] ?? ''));
        $missing = $this->missingFields((string) ($input['missing'] ?? ''));

        $metaQuery = $this->missingMetaQuery($missing);
        $filterApplied = $missing !== array() && $metaQuery !== array();

        $args = array(
            'post_type' => $postTypes,
            'post_status' => $status,
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
            'ignore_sticky_posts' => true,
            'no_found_rows' => false,
        );
        if ($search !== '') {
            $args['s'] = $search;
        }
        if ($filterApplied) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The whole point of this filter is to find posts missing SEO meta; there is no non-meta way to do it.
            $args['meta_query'] = $metaQuery;
        }

        $query = new WP_Query($args);
        $total = (int) $query->found_posts;

        $seoPlugin = null;
        if ($this->seo !== null) {
            $seoPlugin = $this->seo->slug();
        }

        return array(
            'items' => array_map(array($this, 'item'), $query->posts),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => (int) ceil($total / $perPage),
            'seoPlugin' => $seoPlugin,
            'missingFilterApplied' => $filterApplied,
        );
    }

    /**
     * @param \WP_Post $post
     * @return array<string, mixed>
     */
    public function item($post): array
    {
        // Plain if-assignment, not a ternary: the ruleset's RequireNullSafeObjectOperator sniff would
        // rewrite a ternary to `?->`, which its DisallowNullSafeObjectOperator sniff then rejects.
        $meta = null;
        if ($this->seo !== null) {
            $meta = $this->seo->get($post->ID);
        }

        return array(
            'id' => $post->ID,
            'type' => $post->post_type,
            'title' => (string) get_the_title($post),
            'url' => (string) get_permalink($post),
            'status' => $post->post_status,
            'hasSeoTitle' => $meta === null ? null : $meta->get('title') !== null,
            'hasSeoDescription' => $meta === null ? null : $meta->get('description') !== null,
        );
    }

    /**
     * @param mixed $requested
     * @return list<string>
     */
    private function postTypes($requested): array
    {
        $candidates = is_array($requested)
            ? $requested
            : array($requested);

        $valid = array();
        foreach ($candidates as $type) {
            $type = sanitize_key((string) $type);
            if ($type !== '' && post_type_exists($type)) {
                $valid[] = $type;
            }
        }

        return $valid === array()
            ? array('page')
            : array_values(array_unique($valid));
    }

    /** @param mixed $requested */
    private function status($requested): string
    {
        $status = sanitize_key((string) $requested);
        $allowed = array('publish', 'draft', 'pending', 'future', 'private', 'any');

        return in_array($status, $allowed, true)
            ? $status
            : 'publish';
    }

    /** @param mixed $requested */
    private function perPage($requested): int
    {
        $value = (int) $requested;
        if ($value < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($value, self::MAX_PER_PAGE);
    }

    /**
     * Resolve the `missing` filter to canonical SEO field names.
     *
     * @return list<string>
     */
    private function missingFields(string $missing): array
    {
        return match ($missing) {
            'title' => array('title'),
            'description' => array('description'),
            'either' => array('title', 'description'),
            default => array(),
        };
    }

    /**
     * Build a meta_query matching posts missing (unset OR empty) any of the given SEO fields.
     *
     * @param list<string> $fields
     * @return array<int|string, mixed>
     */
    private function missingMetaQuery(array $fields): array
    {
        if ($fields === array() || $this->seo === null) {
            return array();
        }

        $groups = array();
        foreach ($fields as $field) {
            $key = $this->seo->metaKeyFor($field);
            if ($key === null) {
                continue;
            }
            // phpcs:disable SlevomatCodingStandard.Arrays.DisallowPartiallyKeyed.DisallowedPartiallyKeyed -- WordPress meta_query requires a 'relation' key alongside unkeyed clause arrays.
            $groups[] = array(
                'relation' => 'OR',
                array('key' => $key, 'compare' => 'NOT EXISTS'),
                array('key' => $key, 'value' => '', 'compare' => '='),
            );
            // phpcs:enable SlevomatCodingStandard.Arrays.DisallowPartiallyKeyed.DisallowedPartiallyKeyed
        }

        if ($groups === array()) {
            return array();
        }

        // A post missing ANY requested field qualifies.
        return count($groups) === 1
            ? $groups[0]
            : array_merge(array('relation' => 'OR'), $groups);
    }
}
