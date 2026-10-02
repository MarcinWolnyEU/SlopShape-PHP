<?php

declare(strict_types=1);

namespace SlopShape\Exception;

use OutOfBoundsException;

/**
 * A feature id was requested that the instrument does not contain.
 */
final class UnknownFeature extends OutOfBoundsException implements SlopShapeException {
    /**
     * @param list<string> $known
     */
    public static function forId(string $id, array $known = []): self
    {
        $hint = [] === $known
            ? ''
            : \sprintf(' Known ids: %s.', implode(', ', $known));

        return new self(\sprintf('The instrument has no feature "%s".%s', $id, $hint));
    }
}
