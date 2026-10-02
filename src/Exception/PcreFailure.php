<?php

declare(strict_types=1);

namespace SlopShape\Exception;

use RuntimeException;

/**
 * A regular expression failed to run (backtrack limit, bad UTF-8, JIT stack).
 *
 * preg_* signals this with null or false, which is easy to mistake for "no
 * match" or an empty result. Failing loudly beats silently measuring a
 * document that has been wiped.
 */
final class PcreFailure extends RuntimeException implements SlopShapeException {
    public static function forPattern(string $pattern, string $reason): self
    {
        return new self(\sprintf('Pattern %s failed to run: %s.', $pattern, $reason));
    }
}
