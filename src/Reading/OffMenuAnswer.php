<?php

declare(strict_types=1);

namespace SlopShape\Reading;

/**
 * An answer that was not on the feature's menu, and what it became.
 *
 * Recorded rather than hidden: an off-menu rate is the cheapest way to tell a
 * model that understands the instrument from one that is improvising.
 */
final readonly class OffMenuAnswer {
    public function __construct(
        public string $featureId,
        public string $given,
        public string $snappedTo,
    ) {
    }

    /**
     * @return array{feature_id: string, given: string, snapped_to: string}
     */
    public function toArray(): array
    {
        return [
            'feature_id' => $this->featureId,
            'given' => $this->given,
            'snapped_to' => $this->snappedTo,
        ];
    }
}
