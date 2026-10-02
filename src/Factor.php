<?php

declare(strict_types=1);

namespace SlopShape;

use JsonSerializable;
use SlopShape\Instrument\Leans;

/**
 * One core value's contribution to the score.
 *
 * A factor is the whole audit trail for a single decision: which value, which
 * way it points, how much the study says it is worth, how confidently it was
 * observed, and what that multiplied out to. Given a factor and the calibration
 * constant, anyone can recompute the score by hand.
 *
 * {@see self::$value} is the core value being weighed, the thing the study
 * calibrated. {@see self::$observed} is what was actually read, which is the
 * same string when a single-choice marker fires and something else when it does
 * not. Keeping them apart is what lets a report say "this value was absent
 * because the text read `opening_paragraph`" instead of leaving the reader to
 * work out which of the two they are looking at.
 *
 * A factor with a zero contribution is neutral: the value did not fire, or it
 * fired exactly at parity. Those are not evidence for either side and are
 * excluded from {@see Score::positiveFactors()} and
 * {@see Score::negativeFactors()}.
 */
final readonly class Factor implements JsonSerializable {
    /**
     * @param list<string> $observed what the reading actually said
     * @param bool $read whether the feature received a valid reading at all; a
     *                   marker that was read and did not fire is still read
     */
    public function __construct(
        public string $featureId,
        public string $feature,
        public string $dimension,
        public string $value,
        public Leans $leans,
        public float $gap,
        public float $weight,
        public float $signal,
        public float $confidence,
        public float $contribution,
        public array $observed = [],
        public string $note = '',
        public ?string $snappedFrom = null,
        public bool $read = true,
    ) {
    }

    /**
     * Is this factor evidence for AI authorship?
     */
    public function isPositive(): bool
    {
        return $this->contribution > 0.0;
    }

    /**
     * Is this factor evidence for human authorship?
     */
    public function isNegative(): bool
    {
        return $this->contribution < 0.0;
    }

    public function isNeutral(): bool
    {
        return 0.0 === $this->contribution;
    }

    /**
     * "ai", "human" or "neutral": the direction this factor points.
     */
    public function side(): string
    {
        if ($this->isNeutral())
        {
            return 'neutral';
        }

        return $this->isPositive() ? 'ai' : 'human';
    }

    /**
     * @return array{
     *     feature_id: string,
     *     feature: string,
     *     dimension: string,
     *     value: string,
     *     observed: list<string>,
     *     leans: string,
     *     gap: float,
     *     weight: float,
     *     signal: float,
     *     confidence: float,
     *     contribution: float,
     *     side: string,
     *     note: string,
     *     snapped_from: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'feature_id' => $this->featureId,
            'feature' => $this->feature,
            'dimension' => $this->dimension,
            'value' => $this->value,
            'observed' => $this->observed,
            'leans' => $this->leans->value,
            'gap' => $this->gap,
            'weight' => $this->weight,
            'signal' => $this->signal,
            'confidence' => $this->confidence,
            'contribution' => $this->contribution,
            'side' => $this->side(),
            'note' => $this->note,
            'snapped_from' => $this->snappedFrom,
        ];
    }

    /**
     * @return array{
     *     feature_id: string,
     *     feature: string,
     *     dimension: string,
     *     value: string,
     *     observed: list<string>,
     *     leans: string,
     *     gap: float,
     *     weight: float,
     *     signal: float,
     *     confidence: float,
     *     contribution: float,
     *     side: string,
     *     note: string,
     *     snapped_from: string|null
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
