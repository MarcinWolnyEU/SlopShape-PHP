<?php

declare(strict_types=1);

namespace SlopShape\Internal;

/**
 * String similarity, used only to rescue an answer that is on the right track
 * but off the menu ("summary_or_synthesis" arriving as "summary of the main
 * points").
 *
 * The study's own pipeline snaps with a 0.6 cutoff on difflib's ratio; this is
 * a normalised Levenshtein distance, which measures the same idea (how much of
 * the answer is already the menu value) without a dependency.
 */
final class Similarity {
    public const float CUTOFF = 0.6;

    /**
     * Strings are compared on their first this-many characters only. Menu values
     * are short, so anything longer is prose that cannot be a paraphrase of one,
     * and an unbounded model answer must not decide the cost of the comparison.
     */
    public const int MAX_LENGTH = 200;

    /**
     * How similar two strings are, from 0.0 to 1.0.
     *
     * Empty strings are 0.0 rather than a division by zero: a blank answer
     * resembles nothing, least of all the first value on the menu.
     *
     * Only the first {@see self::MAX_LENGTH} characters of each string are
     * compared. When the lengths differ so much that even a perfect overlap
     * could not reach the cutoff, the best possible ratio is returned without
     * running the distance at all: it is below the cutoff either way.
     */
    public static function ratio(string $a, string $b): float
    {
        $a = mb_substr(mb_strtolower(trim($a)), 0, self::MAX_LENGTH);
        $b = mb_substr(mb_strtolower(trim($b)), 0, self::MAX_LENGTH);

        if ('' === $a || '' === $b)
        {
            return 0.0;
        }

        if ($a === $b)
        {
            return 1.0;
        }

        $lengthA = mb_strlen($a);
        $lengthB = mb_strlen($b);
        $longest = max($lengthA, $lengthB);

        // The distance is at least the difference in length, so this is an
        // upper bound on the ratio.
        $ceiling = min($lengthA, $lengthB) / $longest;

        if ($ceiling < self::CUTOFF)
        {
            return $ceiling;
        }

        return 1.0 - (self::distance($a, $b) / $longest);
    }

    /**
     * Levenshtein distance, single-row dynamic programming over characters.
     *
     * Each string is split once, not re-sliced per comparison. The cost is still
     * O(n*m), so callers with untrusted input should cap it, as {@see self::ratio()} does.
     */
    public static function distance(string $a, string $b): int
    {
        $a = mb_strtolower($a);
        $b = mb_strtolower($b);

        if ($a === $b)
        {
            return 0;
        }

        $charsA = mb_str_split($a);
        $charsB = mb_str_split($b);
        $lengthA = \count($charsA);
        $lengthB = \count($charsB);

        if (0 === $lengthA)
        {
            return $lengthB;
        }

        if (0 === $lengthB)
        {
            return $lengthA;
        }

        $previous = range(0, $lengthB);

        for ($i = 1; $i <= $lengthA; ++$i)
        {
            $current = [$i];
            $charA = $charsA[$i - 1];

            for ($j = 1; $j <= $lengthB; ++$j)
            {
                $substitution = $previous[$j - 1] + ($charA === $charsB[$j - 1] ? 0 : 1);
                $deletion = $previous[$j] + 1;
                $insertion = $current[$j - 1] + 1;
                $current[$j] = min($substitution, $deletion, $insertion);
            }

            $previous = $current;
        }

        return $previous[$lengthB];
    }
}
