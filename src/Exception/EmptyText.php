<?php

declare(strict_types=1);

namespace SlopShape\Exception;

use InvalidArgumentException;

/**
 * The text handed to the detector carries nothing to measure.
 */
final class EmptyText extends InvalidArgumentException implements SlopShapeException {
    public static function forLength(int $length): self
    {
        return new self(\sprintf(
            'Nothing to score: after normalisation the text holds %d character(s).',
            $length,
        ));
    }
}
