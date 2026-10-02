<?php

declare(strict_types=1);

namespace SlopShape\Bridge;

use Throwable;

/**
 * Wraps a bridge and remembers every exchange.
 *
 * Two good reasons to use it. In tests, it is the seam that lets you assert on
 * the prompt without stubbing the transport. In production, it is the audit
 * trail: an evidence report that cannot show the reading it was based on is
 * not much of an evidence report.
 *
 * ```php
 * $recorder = new RecordingBridge($bridge);
 * $score = (new Detector($recorder))->detect($text);
 * $log->info('read from the model', $recorder->prompts());
 * ```
 */
final class RecordingBridge implements LlmBridge {
    /** @var list<PromptExchange> */
    private array $exchanges = [];

    public function __construct(private readonly LlmBridge $inner)
    {
    }

    public function complete(string $prompt): string
    {
        try
        {
            $response = $this->inner->complete($prompt);
        } catch (Throwable $throwable)
        {
            $this->exchanges[] = new PromptExchange($prompt, '', $throwable);

            throw $throwable;
        }

        $this->exchanges[] = new PromptExchange($prompt, $response);

        return $response;
    }

    /**
     * @return list<PromptExchange>
     */
    public function exchanges(): array
    {
        return $this->exchanges;
    }

    /**
     * @return list<string>
     */
    public function prompts(): array
    {
        return array_map(
            static fn (PromptExchange $exchange): string => $exchange->prompt,
            $this->exchanges,
        );
    }

    /**
     * @return list<string>
     */
    public function responses(): array
    {
        return array_map(
            static fn (PromptExchange $exchange): string => $exchange->response,
            $this->exchanges,
        );
    }

    public function calls(): int
    {
        return \count($this->exchanges);
    }

    public function reset(): void
    {
        $this->exchanges = [];
    }
}
