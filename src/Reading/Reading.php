<?php

declare(strict_types=1);

namespace SlopShape\Reading;

/**
 * One feature's answer, after it has been forced onto the frozen menu.
 *
 * A reading is evidence, not a fact: it carries how much to trust it, where it
 * came from, and (when the answer had to be repaired) what it was before.
 *
 * For a multi-select feature {@see self::$value} is a list, never a bare
 * string, because the scorer asks whether a given value is present.
 */
final readonly class Reading {
    /** The reading was made by this package, exactly, from the text. */
    public const string SOURCE_TOOL = 'tool';

    /** The reading was made by a model. */
    public const string SOURCE_LLM = 'llm';

    /**
     * @param string|list<string> $value a menu value, or a list of them
     * @param float $confidence 0..1
     * @param list<string> $evidence spans of the text that produced it
     * @param self::SOURCE_* $source
     * @param string|null $snappedFrom the answer as given, if it was repaired
     */
    public function __construct(
        public string $featureId,
        public string|array $value,
        public float $confidence,
        public string $note = '',
        public string $source = self::SOURCE_LLM,
        public ?string $snappedFrom = null,
        public array $evidence = [],
    ) {
    }

    public function isFromModel(): bool
    {
        return self::SOURCE_LLM === $this->source;
    }

    /**
     * Is this value among the ones observed? Works for single and multi answers.
     */
    public function has(string $candidate): bool
    {
        return \in_array($candidate, $this->values(), true);
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return \is_array($this->value) ? array_values($this->value) : [$this->value];
    }

    /**
     * The first observed value, for reporting.
     */
    public function primary(): string
    {
        return $this->values()[0] ?? '';
    }

    /**
     * @return array{
     *     feature_id: string,
     *     value: string|list<string>,
     *     confidence: float,
     *     source: string,
     *     snapped_from: string|null,
     *     note: string,
     *     evidence: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'feature_id' => $this->featureId,
            'value' => $this->value,
            'confidence' => $this->confidence,
            'source' => $this->source,
            'snapped_from' => $this->snappedFrom,
            'note' => $this->note,
            'evidence' => $this->evidence,
        ];
    }
}
