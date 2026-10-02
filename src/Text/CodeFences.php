<?php

declare(strict_types=1);

namespace SlopShape\Text;

/**
 * Finds fenced code blocks with a linear line scan.
 *
 * A regex over "everything up to the closing fence" backtracks badly on a
 * fence that never closes. Scanning line by line cannot. A fence that never
 * closes is left as it is: guessing that the rest of the document is code
 * would delete an article over one stray marker.
 */
final readonly class CodeFences {
    /**
     * Replace each closed fenced block, fences included, with one blank line.
     */
    public static function collapse(string $text): string
    {
        $lines = explode("\n", $text);
        $out = [];
        $count = \count($lines);
        // Shortest fence length known to have no closer, per fence character.
        $unclosed = [];

        for ($i = 0; $i < $count; ++$i)
        {
            $fence = self::opening($lines[$i]);

            if (null === $fence || (isset($unclosed[$fence[0]]) && $fence[1] >= $unclosed[$fence[0]]))
            {
                $out[] = $lines[$i];

                continue;
            }

            $close = self::closingLine($lines, $i + 1, $fence[0], $fence[1]);

            if (null === $close)
            {
                $unclosed[$fence[0]] = $fence[1];
                $out[] = $lines[$i];

                continue;
            }

            $out[] = ' ';
            $i = $close;
        }

        return implode("\n", $out);
    }

    /**
     * @return array{string, int}|null the fence character and its length
     */
    private static function opening(string $line): ?array
    {
        if (1 !== preg_match('#^ {0,3}(`{3,}|~{3,})#', $line, $m))
        {
            return null;
        }

        return [$m[1][0], \strlen($m[1])];
    }

    /**
     * @param list<string> $lines
     */
    private static function closingLine(array $lines, int $from, string $char, int $length): ?int
    {
        $count = \count($lines);

        for ($i = $from; $i < $count; ++$i)
        {
            $line = rtrim($lines[$i], " \t");
            $trimmed = ltrim($line, ' ');

            if (\strlen($line) - \strlen($trimmed) <= 3
                && \strlen($trimmed) >= $length
                && \strlen($trimmed) === strspn($trimmed, $char))
            {
                return $i;
            }
        }

        return null;
    }
}
