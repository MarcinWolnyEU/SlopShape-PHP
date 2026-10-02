<?php

declare(strict_types=1);

namespace SlopShape;

use ArrayIterator;
use IteratorAggregate;
use JsonSerializable;
use SlopShape\Instrument\Provenance;
use Stringable;
use Traversable;

/**
 * The result of scoring one document.
 *
 * Four things, in descending order of use:
 *
 *  - {@see self::$probabilityAi}, a number in [0, 1];
 *  - {@see self::$confidence}, how much that number should be trusted;
 *  - {@see self::positiveFactors()} and {@see self::negativeFactors()}, the
 *    evidence, strongest first, with neutral values left out;
 *  - {@see self::$label}, which the instrument often refuses to issue.
 *
 * The score and the label can disagree on purpose. A 370-word post can score
 * 93/100 towards AI and still get no label at all, because the study never
 * scored anything that short. Both facts are in here; neither hides the other.
 *
 * @implements IteratorAggregate<int, Factor>
 */
final readonly class Score implements IteratorAggregate, JsonSerializable, Stringable {
    /**
     * @param list<Factor> $factors every core value, strongest contribution first
     * @param list<string> $caveats why this number deserves the doubt it gets
     */
    public function __construct(
        public float $probabilityAi,
        public float $confidence,
        public Label $label,
        public float $logOdds,
        public array $factors,
        public string $lengthBand,
        public string $lengthNote,
        public int $wordCount,
        public float $latinWordRatio,
        public int $coreValuesCovered,
        public int $coreValuesTotal,
        public array $caveats = [],
        public string $mode = 'llm',
        public string $instrumentVersion = '',
        public ?Provenance $provenance = null,
    ) {
    }

    /**
     * The probability of human authorship: the complement, named so that neither
     * side of a two-way claim is left to arithmetic at the call site.
     */
    public function probabilityHuman(): float
    {
        return 1.0 - $this->probabilityAi;
    }

    /**
     * The score as a whole percentage, which is how humans compare documents.
     */
    public function score(): int
    {
        return (int) round($this->probabilityAi * 100);
    }

    /**
     * Evidence for AI authorship, strongest first.
     *
     * @return list<Factor>
     */
    public function positiveFactors(): array
    {
        return $this->bySide(static fn (Factor $factor): bool => $factor->isPositive());
    }

    /**
     * Evidence for human authorship, strongest first.
     *
     * @return list<Factor>
     */
    public function negativeFactors(): array
    {
        return $this->bySide(static fn (Factor $factor): bool => $factor->isNegative());
    }

    /**
     * Core values that neither moved the score nor were read at all: the marker
     * did not fire, or the model did not answer.
     *
     * @return list<Factor>
     */
    public function neutralFactors(): array
    {
        return $this->bySide(static fn (Factor $factor): bool => $factor->isNeutral());
    }

    /**
     * Both sides of the evidence, strongest first within each.
     *
     * @return array{positive: list<Factor>, negative: list<Factor>}
     */
    public function evidence(): array
    {
        return [
            'positive' => $this->positiveFactors(),
            'negative' => $this->negativeFactors(),
        ];
    }

    /**
     * Every core value, strongest contribution first, neutral included.
     *
     * @return list<Factor>
     */
    public function allFactors(): array
    {
        return $this->factors;
    }

    public function isInconclusive(): bool
    {
        return $this->label->isInconclusive();
    }

    public function isLikelyAi(): bool
    {
        return Label::LikelyAi === $this->label;
    }

    public function isLikelyHuman(): bool
    {
        return Label::LikelyHuman === $this->label;
    }

    /**
     * Why the label was withheld, or null when one was issued.
     */
    public function refusal(): ?string
    {
        return $this->label->refusal();
    }

    /**
     * @return Traversable<int, Factor>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->factors);
    }

    /**
     * A one-line summary, for logs and terminals.
     */
    public function __toString(): string
    {
        return \sprintf(
            '%d/100  P[AI]=%.3f  %s  confidence %.2f  %d/%d core values read',
            $this->score(),
            $this->probabilityAi,
            $this->label->value,
            $this->confidence,
            $this->coreValuesCovered,
            $this->coreValuesTotal,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label->value,
            'score_0_100' => $this->score(),
            'probability_ai' => $this->probabilityAi,
            'probability_human' => $this->probabilityHuman(),
            'log_odds' => $this->logOdds,
            'confidence' => $this->confidence,
            'mode' => $this->mode,
            'core_values_covered' => \sprintf('%d/%d', $this->coreValuesCovered, $this->coreValuesTotal),
            'word_count' => $this->wordCount,
            'length_band' => $this->lengthBand,
            'length_note' => $this->lengthNote,
            'latin_word_ratio' => $this->latinWordRatio,
            'positive_factors' => array_map(
                static fn (Factor $factor): array => $factor->toArray(),
                $this->positiveFactors(),
            ),
            'negative_factors' => array_map(
                static fn (Factor $factor): array => $factor->toArray(),
                $this->negativeFactors(),
            ),
            'neutral_factors' => array_map(
                static fn (Factor $factor): array => $factor->toArray(),
                $this->neutralFactors(),
            ),
            'caveats' => $this->caveats,
            'instrument' => $this->provenance?->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param callable(Factor): bool $filter
     *
     * @return list<Factor>
     */
    private function bySide(callable $filter): array
    {
        return array_values(array_filter($this->factors, $filter));
    }
}
