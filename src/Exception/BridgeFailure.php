<?php

declare(strict_types=1);

namespace SlopShape\Exception;

use RuntimeException;
use Throwable;

/**
 * The LLM bridge failed: the endpoint was unreachable, or it threw.
 *
 * The detector never swallows this. Without a reading there is no score, and a
 * silent zero would be indistinguishable from "this text looks human".
 */
final class BridgeFailure extends RuntimeException implements SlopShapeException {
    private function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function from(Throwable $previous, string $bridgeName = 'the LLM bridge'): self
    {
        return new self(\sprintf(
            '%s threw: %s: %s',
            ucfirst($bridgeName),
            $previous::class,
            $previous->getMessage(),
        ), $previous);
    }
}
