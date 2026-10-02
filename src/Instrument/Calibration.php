<?php

declare(strict_types=1);

namespace SlopShape\Instrument;

/**
 * The calibration block carried alongside the instrument.
 *
 * The word-count distribution of the study's own human corpus, and with it the
 * corpus minimum, come from the release. The weight scale, verdict thresholds,
 * coverage floor, script ratio and read floor are this package's own choices,
 * not numbers from the paper. They are exposed for provenance and for callers
 * who want to report them; the runtime defaults live in {@see \SlopShape\Config},
 * and a test asserts the two agree.
 */
final readonly class Calibration {
    /**
     * @param array{min: int, p10: int, median: int, p90: int, max: int, n: int, share_under_800: float}|array{} $corpusLength
     */
    public function __construct(
        public float $default = 0.6,
        public float $aiVerdictAt = 0.80,
        public float $humanVerdictAt = 0.20,
        public int $minCoreValues = 4,
        public float $minLatinRatio = 0.8,
        public int $readFloorWords = 250,
        public int $judgementFloorWords = 572,
        public array $corpusLength = [],
        public string $note = '',
    ) {
    }

    /**
     * @return array{min: int, p10: int, median: int, p90: int, max: int, n: int, share_under_800: float}|array{}
     */
    public function corpusLength(): array
    {
        return $this->corpusLength;
    }
}
