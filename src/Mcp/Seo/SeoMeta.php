<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\Seo;

/**
 * Plugin-agnostic representation of a post's SEO meta. Each adapter maps these canonical fields
 * onto the meta keys of whichever SEO plugin is active.
 */
final class SeoMeta
{
    /**
     * The canonical field names this abstraction supports.
     *
     * @return list<string>
     */
    public static function fields(): array
    {
        return array('title', 'description', 'focusKeyword', 'canonical', 'robots', 'ogTitle', 'ogDescription');
    }

    /** @param array<string, string|null> $fields */
    public function __construct(
        public readonly array $fields = array(),
    ) {
    }

    /** @param array<string, string|null> $input Raw input; only known fields are kept. */
    public static function fromArray(array $input): self
    {
        $fields = array();
        foreach (self::fields() as $field) {
            if (array_key_exists($field, $input)) {
                $fields[ $field ] = $input[ $field ] === null
                    ? null
                    : (string) $input[ $field ];
            }
        }

        return new self($fields);
    }

    public function get(string $field): ?string
    {
        return $this->fields[ $field ] ?? null;
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        $out = array();
        foreach (self::fields() as $field) {
            $out[ $field ] = $this->fields[ $field ] ?? null;
        }

        return $out;
    }
}
