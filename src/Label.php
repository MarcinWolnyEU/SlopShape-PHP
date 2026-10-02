<?php

declare(strict_types=1);

namespace SlopShape;

/**
 * What a score concluded, and why a label was withheld if it was.
 *
 * The instrument refuses to label a document it cannot support. Five of these
 * seven cases are refusals, which is the honest answer for a heuristic reading
 * of the study's ten values rather than the study's own classifier. A caller
 * that treats "inconclusive" as a failure will find this enum uncomfortable;
 * that discomfort is the feature.
 */
enum Label: string {
    case LikelyAi = 'likely AI-generated';
    case LikelyHuman = 'likely human-written';
    case Inconclusive = 'inconclusive';
    case TooShort = 'inconclusive (too short to measure)';
    case BelowCorpusMinimum = 'inconclusive (below the study\'s corpus minimum)';
    case OutOfRange = 'inconclusive (outside the instrument\'s calibrated range)';
    case LowCoverage = 'inconclusive (too few core values readable)';

    public function isInconclusive(): bool
    {
        return self::LikelyAi !== $this && self::LikelyHuman !== $this;
    }

    /**
     * The reason a label was withheld, or null when one was issued.
     */
    public function refusal(): ?string
    {
        return $this->isInconclusive() ? $this->value : null;
    }
}
