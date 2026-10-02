<?php

declare(strict_types=1);

namespace SlopShape\Bridge;

use Closure;

/**
 * Adapts any `fn (string $prompt): string` to {@see LlmBridge}.
 *
 * This is the shortest path from a model to a score:
 *
 * ```php
 * $detector = new Detector(
 *     static fn (string $prompt): string => $client->chat($prompt),
 * );
 * ```
 */
final readonly class ClosureBridge implements LlmBridge {
    /**
     * @param Closure(string): string $complete
     * @param non-empty-string $name how to name this bridge in errors
     */
    public function __construct(
        private Closure $complete,
        public string $name = 'the LLM callback',
    ) {
    }

    public function complete(string $prompt): string
    {
        return ($this->complete)($prompt);
    }
}
