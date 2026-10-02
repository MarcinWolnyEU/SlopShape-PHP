<?php

declare(strict_types=1);

namespace SlopShape\Instrument;

use ValueError;

/**
 * How a feature is answered, which decides how the answer is read back.
 */
enum FeatureType: string {
    case Categorical = 'categorical';
    case Binary = 'binary';
    case Ordinal = 'ordinal';
    case MultiSelect = 'multi_select';
    case Scale = 'scale';

    /**
     * @throws ValueError when the taxonomy holds a type this package does not model
     */
    public static function fromTaxonomy(string $type): self
    {
        return self::tryFrom($type)
            ?? throw new ValueError(\sprintf('Unknown feature type "%s".', $type));
    }

    /**
     * Multi-select features answer with a list, single-choice features with one
     * value. The scorer asks "is this value present?", so the distinction is
     * load-bearing, not cosmetic.
     */
    public function isMultiSelect(): bool
    {
        return self::MultiSelect === $this;
    }

    /**
     * Ordinal features are rescaled to [0, 1] by max index, so a reading is
     * scored by its distance from parity rather than by presence.
     */
    public function isOrdinal(): bool
    {
        return self::Ordinal === $this;
    }

    /**
     * How the feature is rendered in the annotation prompt: ONE or MANY.
     */
    public function promptKind(): string
    {
        return $this->isMultiSelect() ? 'MANY' : 'ONE';
    }
}
