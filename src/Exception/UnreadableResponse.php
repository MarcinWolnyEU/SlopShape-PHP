<?php

declare(strict_types=1);

namespace SlopShape\Exception;

use RuntimeException;

/**
 * The bridge answered, but not with the JSON object the instrument prompt asked
 * for. The raw answer is quoted (truncated) so the failure is debuggable.
 */
final class UnreadableResponse extends RuntimeException implements SlopShapeException {
    public static function notJson(string $response, int $quoted = 300): self
    {
        return new self(\sprintf(
            'The LLM did not return a JSON object mapping feature ids to answers. Answer began: %s',
            self::quote($response, $quoted),
        ));
    }

    private static function quote(string $response, int $limit): string
    {
        $flat = preg_replace('/\s+/', ' ', trim($response)) ?? $response;

        return mb_strlen($flat) <= $limit
            ? '"' . $flat . '"'
            : '"' . mb_substr($flat, 0, $limit) . '..."';
    }
}
