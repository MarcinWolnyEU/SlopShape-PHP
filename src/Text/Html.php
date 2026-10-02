<?php

declare(strict_types=1);

namespace SlopShape\Text;

/**
 * Flattens HTML to text while keeping the block skeleton intact.
 *
 * Block-level tags become line breaks and list items keep their bullets, so
 * downstream heading and paragraph detection still has something to work with.
 * Script and style bodies are dropped rather than flattened: their contents
 * are code, not prose, and would otherwise be counted as words.
 *
 * This is deliberately a small flattener, not a parser. It only has to produce
 * text good enough to count words and hand to a model.
 */
final readonly class Html {
    private const array SKIP_ELEMENTS = ['script', 'style', 'noscript', 'svg', 'head'];

    /**
     * Tags that open a block. A newline goes before them.
     *
     * @var list<string>
     */
    private const array OPENING = [
        'p', 'div', 'section', 'article', 'br', 'ul', 'ol', 'tr', 'table',
        'blockquote', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header',
        'footer', 'nav', 'aside', 'figure', 'figcaption', 'hr', 'li', 'td', 'th',
    ];

    /**
     * Tags that close a block. `li` only opens: a newline after </li> would
     * split every list into one-item paragraphs.
     *
     * @var list<string>
     */
    private const array CLOSING = [
        'p', 'div', 'section', 'article', 'br', 'ul', 'ol', 'tr', 'table',
        'blockquote', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header',
        'footer', 'nav', 'aside', 'figure', 'figcaption', 'hr', 'td', 'th',
    ];

    /**
     * Does this look like markup rather than prose?
     *
     * A Markdown post that discusses "<p>hi</p>" must not be flattened as
     * HTML: strip_tags would eat any "a<b ... >" in the prose. So code spans
     * and fences are ignored, and outside them the block tags must outnumber
     * the Markdown block markers (headings, list items, quotes). A doctype or
     * an <html> tag settles it either way.
     */
    public function looksLikeHtml(string $source): bool
    {
        $prose = $this->withoutCode($source);
        $head = mb_strtolower(mb_substr($prose, 0, 4_000));

        if (str_contains($head, '<!doctype html') || str_contains($head, '<html'))
        {
            return true;
        }

        $blocks = '(?:p|div|h[1-6]|ul|ol|li|blockquote|table|br)';
        $opening = Pcre::all('~<' . $blocks . '\b[^>]*+>~', $head);
        $closing = Pcre::all('~</(?:p|div|h[1-6]|ul|ol|blockquote|table)\s*>~', $head);

        if (str_contains($head, '<body') && [] !== $opening)
        {
            return true;
        }

        if ([] === $opening || [] === $closing)
        {
            return false;
        }

        $markers = Pcre::all('~^ {0,3}(?:#{1,6}[ \t]|[-*+][ \t]|\d+[.)][ \t]|>)~m', $head);

        return \count($opening) + \count($closing) > \count($markers);
    }

    public function toText(string $html): string
    {
        $text = $this->dropSubtrees($html);
        $text = $this->markListItems($text);
        $text = $this->addBreaks($text);
        $text = strip_tags($text);

        return html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    private function withoutCode(string $source): string
    {
        $text = CodeFences::collapse(str_replace(["\r\n", "\r"], "\n", $source));

        return Pcre::replace('~`[^`\n]*+`~', ' ', $text);
    }

    /**
     * Cut out each closed skip element with a plain scan. A lazy regex over a
     * megabyte-long <script> exhausts PCRE's backtrack limit; this cannot.
     * An element that never closes is left alone, as flattening it is safer
     * than swallowing the rest of the page.
     */
    private function dropSubtrees(string $html): string
    {
        foreach (self::SKIP_ELEMENTS as $element)
        {
            $html = $this->dropElement($html, $element);
        }

        return $html;
    }

    private function dropElement(string $html, string $element): string
    {
        $length = \strlen($element);
        $out = '';
        $cursor = 0;

        while (false !== ($open = stripos($html, '<' . $element, $cursor)))
        {
            $next = $html[$open + 1 + $length] ?? '';

            if ($this->isNameByte($next))
            {
                $out .= substr($html, $cursor, $open + 1 - $cursor);
                $cursor = $open + 1;

                continue;
            }

            $tagEnd = strpos($html, '>', $open);
            $close = false === $tagEnd ? false : $this->closingTag($html, $element, $tagEnd + 1);

            if (false === $close)
            {
                break;
            }

            $out .= substr($html, $cursor, $open - $cursor) . ' ';
            $cursor = $close;
        }

        return $out . substr($html, $cursor);
    }

    /**
     * Offset just past the first "</element ... >" at or after $from, or false.
     */
    private function closingTag(string $html, string $element, int $from): int|false
    {
        $length = \strlen($element);

        while (false !== ($at = stripos($html, '</' . $element, $from)))
        {
            $next = $html[$at + 2 + $length] ?? '';

            if (!$this->isNameByte($next))
            {
                $end = strpos($html, '>', $at);

                return false === $end ? false : $end + 1;
            }

            $from = $at + 2;
        }

        return false;
    }

    /**
     * Does this byte continue a tag name? ASCII letters, digits and underscore.
     * Not ctype_alnum(): that would add a dependency on ext-ctype.
     */
    private function isNameByte(string $byte): bool
    {
        return '' !== $byte && Pcre::matches('~^\w$~D', $byte);
    }

    private function markListItems(string $html): string
    {
        $text = Pcre::replace('~<li\b[^>]*+>~i', "\n- ", $html);

        // </li> produced nothing above; a space keeps "a</li><li>b" from
        // running the two items together, and keeps the list one block.
        return Pcre::replace('~</li\s*>~i', ' ', $text);
    }

    private function addBreaks(string $html): string
    {
        $tags = array_map(
            static fn (string $tag): string => preg_quote($tag, '~'),
            array_values(array_unique([...self::OPENING, ...self::CLOSING])),
        );
        $tags = array_values(array_diff($tags, ['li']));

        $pattern = \sprintf('~</?(%s)\b[^>]*+>~i', implode('|', $tags));

        return Pcre::replace($pattern, "\n", $html);
    }
}
