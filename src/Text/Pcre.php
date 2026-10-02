<?php

declare(strict_types=1);

namespace SlopShape\Text;

use SlopShape\Exception\PcreFailure;

/**
 * preg_* with failures made loud.
 *
 * A failed preg_replace returns null and a failed preg_match_all returns
 * false. Casting either to string or treating it as "no matches" would quietly
 * turn a document into nothing, so every call here throws instead.
 */
final readonly class Pcre {
    public static function replace(string $pattern, string $replacement, string $subject): string
    {
        $result = preg_replace($pattern, $replacement, $subject);

        if (null === $result)
        {
            throw self::failure($pattern);
        }

        return $result;
    }

    public static function matches(string $pattern, string $subject): bool
    {
        $result = preg_match($pattern, $subject);

        if (false === $result)
        {
            throw self::failure($pattern);
        }

        return 1 === $result;
    }

    /**
     * @return array<int|string, string>|null the match and its groups, or null for no match
     */
    public static function first(string $pattern, string $subject): ?array
    {
        $result = preg_match($pattern, $subject, $matches);

        if (false === $result)
        {
            throw self::failure($pattern);
        }

        return 1 === $result ? $matches : null;
    }

    /**
     * @return list<string> every full match
     */
    public static function all(string $pattern, string $subject): array
    {
        $result = preg_match_all($pattern, $subject, $matches);

        if (false === $result)
        {
            throw self::failure($pattern);
        }

        return $matches[0];
    }

    /**
     * @return list<array{string, int}> [text, byte offset] of every full match
     */
    public static function allWithOffsets(string $pattern, string $subject): array
    {
        $result = preg_match_all($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE);

        if (false === $result)
        {
            throw self::failure($pattern);
        }

        return $matches[0];
    }

    /**
     * @return list<array{string, int}> [text, byte offset] of every piece
     */
    public static function splitWithOffsets(string $pattern, string $subject): array
    {
        $result = preg_split($pattern, $subject, -1, \PREG_SPLIT_OFFSET_CAPTURE);

        if (false === $result)
        {
            throw self::failure($pattern);
        }

        return $result;
    }

    private static function failure(string $pattern): PcreFailure
    {
        return PcreFailure::forPattern($pattern, preg_last_error_msg());
    }
}
