<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\Seo;

/**
 * Reads/writes a post's SEO meta against whichever SEO plugin is active.
 *
 * Inject this interface, not a concrete adapter — the container binds the implementation that
 * matches the detected SEO plugin (see Detect::adapterFor()).
 */
interface SeoAdapter
{
    public function get(int $postId): SeoMeta;

    /**
     * Update only the fields present in $meta; absent fields are left untouched.
     */
    public function update(int $postId, SeoMeta $meta): void;

    /** Identifier of the SEO plugin this adapter targets (yoast|rankmath|aioseo|seopress). */
    public function slug(): string;

    /**
     * The underlying storage key for a canonical field, or null if this adapter can't expose one
     * (used to build "missing meta" queries). Post-meta adapters return the post-meta key.
     */
    public function metaKeyFor(string $field): ?string;
}
