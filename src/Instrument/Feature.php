<?php

declare(strict_types=1);

namespace SlopShape\Instrument;

use InvalidArgumentException;

/**
 * One frozen instrument feature: the question, the allowed answers, and the
 * study's own detection guidance.
 *
 * A feature is the question half of the instrument. A {@see CoreValue} is one
 * of its allowed answers, weighted by released calibration. Only the ten core
 * values of {@see Instrument::coreValues()} carry calibration; everything else
 * in the study's taxonomy is deliberately absent here, because an unweighted
 * feature has no place in a score.
 */
final readonly class Feature {
    /**
     * @param non-empty-string $id
     * @param non-empty-string $name
     * @param list<non-empty-string> $values at least two, per the taxonomy
     * @param bool $measuredByTool true when the package measures
     *                             this feature itself instead of
     *                             asking a model about it
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $question,
        public FeatureType $type,
        public array $values,
        public string $detectionMethod,
        public string $dimension,
        public string $dimensionLabel,
        public string $aspect,
        public bool $measuredByTool = false,
    ) {
        if ([] === $this->values)
        {
            throw new InvalidArgumentException(\sprintf('Feature "%s" has no allowed values.', $this->id));
        }

        foreach ($this->values as $value)
        {
            if ('' === $value)
            {
                throw new InvalidArgumentException(\sprintf('Feature "%s" has an empty allowed value.', $this->id));
            }
        }
    }

    /**
     * The study's single-line feature block, verbatim in shape:
     * "ID [KIND]: question ALLOWED: a | b | c".
     */
    public function promptLine(): string
    {
        return \sprintf(
            '%s [%s]: %s ALLOWED: %s',
            $this->id,
            $this->type->promptKind(),
            $this->question,
            implode(' | ', $this->values),
        );
    }

    /**
     * The first allowed value: the study's instruction to a model when a
     * feature is inapplicable is "use the closest allowed value",
     * never "invent one" and never "skip it".
     */
    public function firstValue(): string
    {
        return $this->values[0];
    }

    public function isMultiSelect(): bool
    {
        return $this->type->isMultiSelect();
    }

    public function isOrdinal(): bool
    {
        return $this->type->isOrdinal();
    }

    public function hasValue(string $value): bool
    {
        return \in_array($value, $this->values, true);
    }
}
