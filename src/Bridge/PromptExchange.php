<?php

declare(strict_types=1);

namespace SlopShape\Bridge;

use Throwable;

/**
 * One prompt and whatever came back.
 *
 * @internal recorded for diagnostics; {@see RecordingBridge} is the public door
 */
final readonly class PromptExchange {
    public function __construct(
        public string $prompt,
        public string $response,
        public ?Throwable $error = null,
    ) {
    }

    public function failed(): bool
    {
        return $this->error instanceof Throwable;
    }
}
