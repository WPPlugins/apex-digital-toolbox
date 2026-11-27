<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\Content;

/**
 * Dependency-free HTML -> Markdown / plain-text converter.
 *
 * The content reader returns copy for an LLM to read, so the goal is faithful, compact text — not
 * pixel-perfect Markdown. We walk the DOM and emit the common block/inline constructs (headings,
 * paragraphs, lists, links, emphasis) and drop everything else (scripts, styles, attributes). This
 * keeps the payload small: returning raw rendered HTML blows the token budget and times out.
 */
final class HtmlConverter
{
    public static function toMarkdown(string $html): string
    {
        return self::tidy(self::render($html, true));
    }

    public static function toText(string $html): string
    {
        return self::tidy(self::render($html, false));
    }

    private static function render(string $html, bool $markdown): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // Force UTF-8 and wrap in a known root so we can grab the subtree back reliably.
        $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div id="apex-root">' . $html . '</div>',
            LIBXML_NOWARNING | LIBXML_NOERROR,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('apex-root');

        return $root === null
            ? ''
            : self::children($root, $markdown);
    }

    private static function children(\DOMNode $node, bool $md): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= self::node($child, $md);
        }

        return $out;
    }

    private static function node(\DOMNode $node, bool $md): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return self::collapse($node->textContent);
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        return self::element($node, $md);
    }

    private static function element(\DOMNode $node, bool $md): string
    {
        $tag = strtolower($node->nodeName);

        if (in_array($tag, array('script', 'style', 'noscript', 'template'), true)) {
            return '';
        }

        if (in_array($tag, self::headings(), true)) {
            $inner = trim(self::children($node, $md));
            if ($inner === '') {
                return '';
            }
            $prefix = $md
                ? str_repeat('#', (int) $tag[1]) . ' '
                : '';

            return "\n\n" . $prefix . $inner . "\n\n";
        }

        return self::nonHeadingElement($node, $tag, $md);
    }

    private static function nonHeadingElement(\DOMNode $node, string $tag, bool $md): string
    {
        switch ($tag) {
            case 'br':
                return "\n";
            case 'hr':
                return $md
                    ? "\n\n---\n\n"
                    : "\n\n";
            case 'ul':
                return self::listBlock($node, $md, false);
            case 'ol':
                return self::listBlock($node, $md, true);
            case 'a':
                return self::anchor($node, $md);
            case 'strong':
            case 'b':
                return self::emphasis($node, $md, '**');
            case 'em':
            case 'i':
                return self::emphasis($node, $md, '*');
            case 'p':
            case 'div':
            case 'section':
            case 'article':
            case 'header':
            case 'footer':
            case 'blockquote':
                return self::block($node, $tag, $md);
            default:
                return self::children($node, $md);
        }
    }

    private static function block(\DOMNode $node, string $tag, bool $md): string
    {
        $inner = trim(self::children($node, $md));
        if ($inner === '') {
            return '';
        }
        if ($tag === 'blockquote' && $md) {
            $inner = (string) preg_replace('/^/m', '> ', $inner);
        }

        return "\n\n" . $inner . "\n\n";
    }

    private static function emphasis(\DOMNode $node, bool $md, string $marker): string
    {
        $inner = trim(self::children($node, $md));
        if ($inner === '') {
            return '';
        }

        return $md
            ? $marker . $inner . $marker
            : $inner;
    }

    private static function anchor(\DOMNode $node, bool $md): string
    {
        $inner = trim(self::children($node, $md));
        $href = $node instanceof \DOMElement
            ? trim($node->getAttribute('href'))
            : '';

        if (! $md || $href === '' || $inner === '') {
            return $inner;
        }

        return '[' . $inner . '](' . $href . ')';
    }

    private static function listBlock(\DOMNode $node, bool $md, bool $ordered): string
    {
        $items = array();
        $index = 1;
        foreach ($node->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE || strtolower($child->nodeName) !== 'li') {
                continue;
            }
            $text = trim(self::children($child, $md));
            if ($text === '') {
                continue;
            }
            // Flatten any nested block whitespace so a list item is a single line.
            $text = (string) preg_replace('/\s*\n\s*/', ' ', $text);
            $marker = $md
                ? ($ordered ? $index . '. ' : '- ')
                : '';
            $items[] = $marker . $text;
            $index += 1;
        }

        return $items === array()
            ? ''
            : "\n\n" . implode("\n", $items) . "\n\n";
    }

    /**
     * Heading tags. A method rather than a multi-line array const: phpcbf mis-splits the latter.
     *
     * @return list<string>
     */
    private static function headings(): array
    {
        return array('h1', 'h2', 'h3', 'h4', 'h5', 'h6');
    }

    private static function collapse(string $text): string
    {
        return (string) preg_replace('/\s+/', ' ', $text);
    }

    private static function tidy(string $text): string
    {
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/ *\n */', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
