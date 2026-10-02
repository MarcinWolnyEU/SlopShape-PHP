<?php

declare(strict_types=1);

namespace SlopShape\Reading;

use SlopShape\Instrument\Feature;
use SlopShape\Instrument\Instrument;
use SlopShape\Text\Document;

/**
 * The features this package measures itself, exactly.
 *
 * Word count is countable, so it is counted. The study discarded a model's
 * answer here for the same reason: on a 674-word article, one studied model
 * answered "800-1499 words", and a feature that can be wrong when it is
 * observable has no business being asked about.
 */
final readonly class WordCountReader {
    /**
     * High but not absolute: the count is exact, the class boundaries are the
     * study's rounded bands, and this value is averaged into the confidence
     * formula alongside readings that really are uncertain.
     */
    public const float CONFIDENCE = 0.99;

    /**
     * @return list<Reading>
     */
    public function read(Instrument $instrument, Document $document): array
    {
        $readings = [];

        foreach ($instrument->measuredFeatures() as $feature)
        {
            $readings[] = new Reading(
                featureId: $feature->id,
                value: $this->classify($feature, $document->wordCount),
                confidence: self::CONFIDENCE,
                note: \sprintf('%d words in the article body', $document->wordCount),
                source: Reading::SOURCE_TOOL,
            );
        }

        return $readings;
    }

    /**
     * The instrument expresses length as value names, not as numbers, so the
     * numbers are read back out of the names and applied as bands.
     */
    private function classify(Feature $feature, int $words): string
    {
        foreach ($feature->values as $value)
        {
            [$low, $high] = $this->band($value);

            if ($words < $low || (null !== $high && $words > $high))
            {
                continue;
            }

            return $value;
        }

        return $feature->firstValue();
    }

    /**
     * Parse "short_under_800_words", "medium_800_to_1499_words" or
     * "very_long_3000_or_more_words" into an inclusive band.
     *
     * @return array{0: int, 1: int|null}
     */
    private function band(string $value): array
    {
        if (1 === preg_match('~_under_(\d+)_~', $value, $matches))
        {
            return [0, (int) $matches[1] - 1];
        }

        if (1 === preg_match('~(\d+)_to_(\d+)_~', $value, $matches))
        {
            return [(int) $matches[1], (int) $matches[2]];
        }

        if (1 === preg_match('~(\d+)_or_more_~', $value, $matches))
        {
            return [(int) $matches[1], null];
        }

        if (false !== preg_match_all('~(\d+)~', $value, $matches) && isset($matches[1][0]))
        {
            return [(int) $matches[1][0], null];
        }

        return [0, null];
    }
}
