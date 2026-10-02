<?php

declare(strict_types=1);

namespace SlopShape\Instrument;

/**
 * One calibrated value from the study's released value-level selection.
 *
 * `$gap` is the released |human mean - mean(AI models)| prevalence difference
 * of this value, and `$leans` is the class it is more common in. Together they
 * are the whole of this package's prior knowledge: the weight of a firing
 * marker is a function of its gap, and its direction is a function of which
 * class it belongs to.
 */
final readonly class CoreValue {
    /**
     * @param non-empty-string $column e.g. "STR_STG_008__restated_thesis_or_reframe"
     * @param non-empty-string $featureId
     * @param non-empty-string $value
     * @param float $gap 0..1
     */
    public function __construct(
        public string $column,
        public string $featureId,
        public string $value,
        public Leans $leans,
        public float $gap,
        public bool $ordinal = false,
    ) {
    }

    public function leansAi(): bool
    {
        return $this->leans->isAi();
    }
}
