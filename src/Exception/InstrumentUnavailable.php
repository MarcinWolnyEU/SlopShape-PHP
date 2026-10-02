<?php

declare(strict_types=1);

namespace SlopShape\Exception;

use RuntimeException;

/**
 * The instrument file is missing, unreadable, or not shaped like an instrument.
 */
final class InstrumentUnavailable extends RuntimeException implements SlopShapeException {
    public static function unreadable(string $path): self
    {
        return new self(\sprintf('The instrument file could not be read: %s', $path));
    }

    public static function notJson(string $path): self
    {
        return new self(\sprintf('The instrument file is not valid JSON: %s', $path));
    }

    /**
     * @param list<string> $problems
     */
    public static function malformed(string $path, array $problems): self
    {
        return new self(\sprintf(
            'The instrument file is not a SlopShape instrument (%s): %s',
            $path,
            implode('; ', $problems),
        ));
    }
}
