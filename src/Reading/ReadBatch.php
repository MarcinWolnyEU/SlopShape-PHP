<?php

declare(strict_types=1);

namespace SlopShape\Reading;

use SlopShape\Instrument\Feature;

/**
 * A batch of readings plus everything that went wrong while making them.
 *
 * Missing ids and off-menu answers are carried, not dropped. A report that
 * silently loses a feature looks the same as a report where that feature did
 * not fire, and those are very different claims.
 */
final readonly class ReadBatch {
    /**
     * @param array<string, Reading> $readings keyed by feature id
     * @param list<string> $missing feature ids with no reading: the model did not answer them, or its answer matched no menu value
     * @param list<OffMenuAnswer> $offMenu answers that had to be repaired, or matched nothing
     * @param list<string> $prompts the prompts sent, for the audit trail
     */
    public function __construct(
        private array $readings = [],
        private array $missing = [],
        private array $offMenu = [],
        private array $prompts = [],
    ) {
    }

    /**
     * @return array<string, Reading>
     */
    public function readings(): array
    {
        return $this->readings;
    }

    public function get(string $featureId): ?Reading
    {
        return $this->readings[$featureId] ?? null;
    }

    /**
     * @return list<string>
     */
    public function missing(): array
    {
        return $this->missing;
    }

    /**
     * @return list<OffMenuAnswer>
     */
    public function offMenu(): array
    {
        return $this->offMenu;
    }

    /**
     * @return list<string>
     */
    public function prompts(): array
    {
        return $this->prompts;
    }

    /**
     * @param array<string, Reading> $readings
     */
    public static function fromReadings(array $readings): self
    {
        return new self($readings);
    }

    public function isEmpty(): bool
    {
        return [] === $this->readings;
    }

    public function count(): int
    {
        return \count($this->readings);
    }

    public function with(Reading $reading): self
    {
        $readings = $this->readings;
        $readings[$reading->featureId] = $reading;

        return new self($readings, $this->missing, $this->offMenu, $this->prompts);
    }

    public function without(string $featureId): self
    {
        $readings = $this->readings;
        unset($readings[$featureId]);

        return new self($readings, $this->missing, $this->offMenu, $this->prompts);
    }

    /**
     * Combine two batches, with $other winning any id both hold.
     */
    public function merge(self $other): self
    {
        return new self(
            [...$this->readings, ...$other->readings],
            [...$this->missing, ...array_diff($other->missing, $this->missing)],
            [...$this->offMenu, ...$other->offMenu],
            [...$this->prompts, ...$other->prompts],
        );
    }

    /**
     * @return array{
     *     missing: list<string>,
     *     off_menu: list<array{feature_id: string, given: string, snapped_to: string}>,
     * }
     */
    public function diagnostics(): array
    {
        return [
            'missing' => $this->missing,
            'off_menu' => array_map(
                static fn (OffMenuAnswer $answer): array => $answer->toArray(),
                $this->offMenu,
            ),
        ];
    }
}
