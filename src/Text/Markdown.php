<?php

declare(strict_types=1);

namespace SlopShape\Text;

/**
 * Strips inline Markdown noise while keeping the document's block skeleton.
 *
 * Links keep their anchor text and their target in parentheses, because a
 * "route readers off the article" marker is exactly the kind of thing this
 * package asks a model about, and throwing the URL away would hide it.
 *
 * Code fences collapse to a blank line: the code inside them is not prose, and
 * counting it would inflate the one feature this package measures exactly.
 */
final readonly class Markdown {
    private const string SOFT_HYPHEN = "\u{00AD}";

    private const array ZERO_WIDTH = [
        "\u{200B}" => '',
        "\u{200C}" => '',
        "\u{200D}" => '',
        "\u{FEFF}" => '',
    ];

    /**
     * Emphasis never spans a blank line and is capped in length, so a stray
     * marker cannot make the matcher scan the rest of a huge document.
     */
    private const string SPAN = '(?:(?!\n[ \t]*\n).){1,1000}?';

    /** Underscore emphasis needs non-word boundaries: snake_case stays intact. */
    private const string UNDERSCORE_EMPHASIS
        = '~(?<![\p{L}\p{N}_])(___|__|_)(?=\S)(' . self::SPAN . ')(?<=\S)\1(?![\p{L}\p{N}_])~us';

    private const string STAR_EMPHASIS
        = '~(\*\*\*|\*\*|\*)(?=\S)(' . self::SPAN . ')(?<=\S)\1~us';

    public function toPlainText(string $markdown): string
    {
        if (!mb_check_encoding($markdown, 'UTF-8'))
        {
            $markdown = mb_scrub($markdown, 'UTF-8');
        }

        $text = str_replace(["\r\n", "\r"], "\n", $markdown);
        $text = CodeFences::collapse($text);
        $text = Pcre::replace('~!\[([^\]\n]{0,1000})\]\([^)\n]{0,2000}\)~', '$1', $text);
        $text = Pcre::replace('~\[([^\]]{1,1000})\]\(([^)\s]+)[^)]{0,2000}\)~', '$1 ($2)', $text);
        $text = Pcre::replace('~`([^`]++)`~', '$1', $text);
        $text = Pcre::replace(self::STAR_EMPHASIS, '$2', $text);
        $text = Pcre::replace(self::UNDERSCORE_EMPHASIS, '$2', $text);
        $text = str_replace(self::SOFT_HYPHEN, '', $text);
        $text = strtr($text, self::ZERO_WIDTH);
        $text = Pcre::replace('~[ \t]+~', ' ', $text);
        $text = Pcre::replace('~ (?=\n)~', '', $text);
        $text = Pcre::replace('~ $~', '', $text);

        return trim(Pcre::replace('~\n{3,}~', "\n\n", $text));
    }

    /**
     * The title, as far as the text admits one.
     *
     * A level-one heading at the top of the document is the reliable signal:
     * only blank lines or front matter may come before it. A heading further
     * down is a section heading, not the title. Failing that, one of the first
     * lines of the document qualifies when it is short and does not read as a
     * sentence, which is the same test the study's annotators were given.
     */
    public function title(string $plainText): ?string
    {
        return $this->locateTitle($plainText)['text'] ?? null;
    }

    /**
     * A deck or standfirst directly beneath the title, if there is one.
     */
    public function subtitle(string $plainText, ?string $title): ?string
    {
        if (null === $title)
        {
            return null;
        }

        $located = $this->locateTitle($plainText);

        if (null !== $located && $located['text'] === $title)
        {
            $end = $located['end'];
        } else
        {
            $at = strpos($plainText, $title);

            if (false === $at)
            {
                return null;
            }

            $end = $at + \strlen($title);
        }

        return $this->locateSubtitle($plainText, $end)['text'] ?? null;
    }

    /**
     * The title and where it sits, as byte offsets into the plain text.
     *
     * @return array{text: string, start: int, end: int}|null
     */
    public function locateTitle(string $plainText): ?array
    {
        $lines = $this->lines($plainText);
        $first = $lines[0] ?? null;

        if (null !== $first)
        {
            $heading = $this->headingText($first[0], 1);

            if (null !== $heading)
            {
                return ['text' => $heading, 'start' => $first[1], 'end' => $first[1] + \strlen($first[0])];
            }
        }

        foreach (\array_slice($lines, 0, 4) as [$line, $offset])
        {
            if ($this->looksLikeTitle($line))
            {
                return ['text' => $line, 'start' => $offset, 'end' => $offset + \strlen($line)];
            }
        }

        return null;
    }

    /**
     * The standfirst after a title that ends at byte offset $titleEnd.
     *
     * @return array{text: string, start: int, end: int}|null
     */
    public function locateSubtitle(string $plainText, int $titleEnd): ?array
    {
        $after = substr($plainText, $titleEnd);

        foreach (Pcre::splitWithOffsets('~\n\s*\n~', $after) as [$piece, $pieceOffset])
        {
            $block = trim($piece);

            if ('' === $block || str_starts_with($block, '#') || $this->isListItem($block))
            {
                continue;
            }

            if (mb_strlen($block) >= 200 || !$this->looksLikeTitle($block))
            {
                return null;
            }

            $start = $titleEnd + $pieceOffset + (\strlen($piece) - \strlen(ltrim($piece)));

            return ['text' => $block, 'start' => $start, 'end' => $start + \strlen($block)];
        }

        return null;
    }

    private function headingText(string $line, int $level): ?string
    {
        $pattern = \sprintf('~^ {0,3}#{%d}[ \t]+(.+?)(?:[ \t]+#+)?[ \t]*$~', $level);

        $heading = Pcre::first($pattern, $line);

        return null === $heading ? null : trim($heading[1]);
    }

    /**
     * The non-blank lines that can still be a title: those after any YAML
     * front matter, trimmed, with the byte offset of their trimmed text.
     *
     * @return list<array{string, int}>
     */
    private function lines(string $plainText): array
    {
        $offset = $this->frontMatterLength($plainText);
        $lines = [];

        foreach (explode("\n", substr($plainText, $offset)) as $raw)
        {
            $trimmed = trim($raw);

            if ('' !== $trimmed)
            {
                $lines[] = [$trimmed, $offset + \strlen($raw) - \strlen(ltrim($raw))];
            }

            $offset += \strlen($raw) + 1;
        }

        return $lines;
    }

    /**
     * Byte length of a leading YAML front matter block, or 0 when there is none.
     */
    private function frontMatterLength(string $plainText): int
    {
        $lines = explode("\n", $plainText);

        if ('---' !== rtrim($lines[0]))
        {
            return 0;
        }

        $length = \strlen($lines[0]) + 1;

        for ($i = 1, $count = \count($lines); $i < $count; ++$i)
        {
            $length += \strlen($lines[$i]) + 1;

            if (\in_array(rtrim($lines[$i]), ['---', '...'], true))
            {
                return min($length, \strlen($plainText));
            }
        }

        return 0;
    }

    private function isListItem(string $line): bool
    {
        return Pcre::matches('~^(?:[-*+]\s+|\d+[.)]\s+)~', $line);
    }

    /**
     * A title is short, has no terminal sentence punctuation, and is not a
     * heading marker or a list item.
     */
    private function looksLikeTitle(string $line): bool
    {
        $line = trim($line);

        if ('' === $line || str_starts_with($line, '#') || $this->isListItem($line))
        {
            return false;
        }

        if (str_starts_with($line, '```') || str_starts_with($line, '~~~'))
        {
            return false;
        }

        if (mb_strlen($line) > 120)
        {
            return false;
        }

        return !Pcre::matches('~[.!?:,;]$~u', $line);
    }
}
