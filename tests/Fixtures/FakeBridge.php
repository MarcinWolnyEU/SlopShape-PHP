<?php

declare(strict_types=1);

namespace SlopShape\Tests\Fixtures;

use SlopShape\Bridge\LlmBridge;
use RuntimeException;

/**
 * A bridge that answers from a script, and remembers what it was asked.
 *
 * The whole package is one seam wide, so the test suite needs a seam that is
 * fast, offline, and honest about what it received. This is it.
 */
final class FakeBridge implements LlmBridge
{
    /** @var list<string> */
    private array $prompts = [];

    /**
     * @param list<string> $responses returned in order; the last one repeats
     * @param int          $failures how many calls fail before one answers
     */
    public function __construct(
        private array $responses,
        private int $failures = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $answers
     */
    public static function answering(array $answers): self
    {
        return new self([json_encode($answers, JSON_THROW_ON_ERROR)]);
    }

    public static function failing(int $times = 1, string $message = 'connection reset'): self
    {
        return new self([''], $times);
    }

    public function complete(string $prompt): string
    {
        $this->prompts[] = $prompt;

        if ($this->failures > 0) {
            $this->failures--;

            throw new RuntimeException('connection reset by peer');
        }

        if ($this->responses === []) {
            throw new RuntimeException('the fake bridge has no answer left');
        }

        $response = count($this->responses) === 1
            ? $this->responses[0]
            : array_shift($this->responses);

        if ($response === null) {
            throw new RuntimeException('the fake bridge has no answer left');
        }

        return $response;
    }

    /**
     * @return list<string>
     */
    public function prompts(): array
    {
        return $this->prompts;
    }

    public function lastPrompt(): string
    {
        $last = end($this->prompts);

        if ($last === false) {
            throw new RuntimeException('the fake bridge was never called');
        }

        return $last;
    }

    public function calls(): int
    {
        return count($this->prompts);
    }
}
