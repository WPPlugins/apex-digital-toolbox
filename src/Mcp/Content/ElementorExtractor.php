<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\Content;

/**
 * Pulls readable copy out of Elementor's stored page model.
 *
 * Elementor keeps almost nothing in post_content — the real content is a JSON widget tree in the
 * `_elementor_data` post meta. Rendering it the "proper" way needs a full Elementor frontend
 * bootstrap (heavy, and unavailable off the front-end loop), so instead we walk the JSON and emit
 * lightweight HTML from the text-bearing widgets. HtmlConverter then turns that into Markdown/text.
 */
final class ElementorExtractor
{
    public static function toHtml(string $json): string
    {
        $data = json_decode($json, true);

        return is_array($data)
            ? self::walk($data)
            : '';
    }

    /** @param array<int|string, mixed> $nodes */
    private static function walk(array $nodes): string
    {
        $html = '';
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $settings = isset($node['settings']) && is_array($node['settings'])
                ? $node['settings']
                : array();
            $widget = isset($node['widgetType'])
                ? (string) $node['widgetType']
                : '';

            $html .= self::widgetHtml($widget, $settings);

            if (isset($node['elements']) && is_array($node['elements'])) {
                $html .= self::walk($node['elements']);
            }
        }

        return $html;
    }

    /** @param array<string, mixed> $settings */
    private static function widgetHtml(string $widget, array $settings): string
    {
        switch ($widget) {
            case 'heading':
                $level = self::headingLevel($settings);
                $title = self::str($settings, 'title');

                return $title === ''
                    ? ''
                    : '<' . $level . '>' . $title . '</' . $level . '>';
            case 'text-editor':
                // Already HTML.
                return self::str($settings, 'editor');
            case 'button':
                $text = self::str($settings, 'text');

                return $text === ''
                    ? ''
                    : '<p>' . $text . '</p>';
            default:
                return self::genericHtml($settings);
        }
    }

    /**
     * Fallback for widgets we don't model explicitly: emit the first common text key we find so a
     * new/custom widget's copy still surfaces.
     *
     * @param array<string, mixed> $settings
     */
    private static function genericHtml(array $settings): string
    {
        foreach (array('title', 'text', 'editor', 'description', 'content') as $key) {
            $value = self::str($settings, $key);
            if ($value !== '') {
                return '<p>' . $value . '</p>';
            }
        }

        return '';
    }

    /** @param array<string, mixed> $settings */
    private static function headingLevel(array $settings): string
    {
        $size = strtolower(self::str($settings, 'header_size'));

        return in_array($size, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'), true)
            ? $size
            : 'h2';
    }

    /** @param array<string, mixed> $settings */
    private static function str(array $settings, string $key): string
    {
        return isset($settings[ $key ]) && is_string($settings[ $key ])
            ? trim($settings[ $key ])
            : '';
    }
}
