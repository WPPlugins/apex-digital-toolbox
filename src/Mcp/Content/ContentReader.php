<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\Content;

/**
 * Reads a post/page/CPT body as compact, LLM-friendly text.
 *
 * Resolves the page builder first, extracts the real copy (Elementor's JSON model, WPBakery/Classic
 * shortcode+HTML via the_content, Gutenberg blocks via the_content), then converts to Markdown or
 * plain text. Returns just the content — no template chrome — so callers don't scrape the page.
 */
final class ContentReader
{
    /**
     * Supported output formats. A method rather than a multi-line array const: phpcbf mis-splits it.
     *
     * @return list<string>
     */
    private static function formats(): array
    {
        return array('markdown', 'text', 'html');
    }

    private static function wordCount(string $text): int
    {
        $text = trim($text);

        return $text === ''
            ? 0
            : str_word_count($text);
    }

    /** @return array<string, mixed>|\WP_Error {postId,title,url,builder,format,content,wordCount} */
    public function read(int $postId, string $format = 'markdown'): array|\WP_Error
    {
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return new \WP_Error('apex_mcp_not_found', 'Post not found.', array('status' => 404));
        }

        $format = in_array($format, self::formats(), true)
            ? $format
            : 'markdown';

        $builder = BuilderDetect::detect($postId);
        $html = $this->renderHtml($post, $builder);
        $text = HtmlConverter::toText($html);

        $content = match ($format) {
            'html' => $html,
            'text' => $text,
            default => HtmlConverter::toMarkdown($html),
        };

        return array(
            'postId' => $postId,
            'title' => (string) get_the_title($post),
            'url' => (string) get_permalink($post),
            'builder' => $builder,
            'format' => $format,
            'content' => $content,
            'wordCount' => self::wordCount($text),
        );
    }

    /**
     * Seam: fetch the post's content as HTML for the detected builder. Overridable in tests.
     */
    protected function renderHtml(\WP_Post $post, string $builder): string
    {
        if ($builder === BuilderDetect::ELEMENTOR) {
            $data = (string) get_post_meta($post->ID, '_elementor_data', true);
            $html = ElementorExtractor::toHtml($data);
            if (trim($html) !== '') {
                return $html;
            }
        }

        // Classic, Gutenberg and WPBakery all resolve through the_content: shortcodes render, blocks
        // render, autop runs. (For Elementor this is the fallback when the JSON model is empty.)
        return (string) apply_filters('the_content', $post->post_content);
    }
}
