<?php

declare(strict_types=1);

namespace SlopShape\Reading;

use SlopShape\Instrument\Feature;
use SlopShape\Internal\Similarity;

/**
 * Forces an answer onto a feature's frozen answer menu.
 *
 * A model that invents a value is not automatically wrong, and a model that
 * paraphrases a value is not automatically unusable. The study's instruction is
 * "never invent values, never omit a feature", and this is where that gets
 * enforced on the way in:
 *
 *  1. an exact menu value is kept as-is;
 *  2. otherwise the value is normalised (case, underscores, dashes, spaces) and
 *     retried, which catches the near-universal "Summary Or Synthesis";
 *  3. otherwise the closest menu value above a similarity cutoff wins, and the
 *     original answer is remembered in {@see Reading::$snappedFrom};
 *  4. otherwise there is no value at all: the answer is unmatched and the
 *     caller treats the feature as unread.
 *
 * Step 4 used to fall back to the first menu value. That is not neutral: for
 * several features the first value is the AI-leaning one, so an unusable answer
 * ("yes", "unknown") silently became evidence. An answer that resembles no menu
 * value is missing information, and is reported as missing.
 *
 * Every snap records what it came from. A silently repaired answer that the
 * caller cannot see is a bug you only find in production.
 */
final readonly class Menu {
    public function __construct(private Feature $feature)
    {
    }

    public static function for(Feature $feature): self
    {
        return new self($feature);
    }

    public function feature(): Feature
    {
        return $this->feature;
    }

    public function has(string $value): bool
    {
        return $this->feature->hasValue($value);
    }

    /**
     * Snap one answer to a single value.
     *
     * @return array{0: string|null, 1: string|null} the menu value, or null when the
     *                                               answer matches nothing on the menu, and
     *                                               the answer it replaced
     */
    public function snap(string $answer): array
    {
        $answer = trim($answer);

        if ($this->has($answer))
        {
            return [$answer, null];
        }

        $normalised = $this->normalise($answer);

        foreach ($this->feature->values as $value)
        {
            if ($this->normalise($value) === $normalised)
            {
                return [$value, $answer];
            }
        }

        return [$this->closest($answer), $answer];
    }

    /**
     * Snap each item of a multi-select answer, keeping what happened to each.
     *
     * Items that are not strings are ignored, as in {@see self::snapAll()}.
     *
     * `repaired` is true when the item was not already on the menu; `value` is
     * null when it matched nothing.
     *
     * @param iterable<mixed> $answers
     *
     * @return list<array{given: string, value: string|null, repaired: bool}>
     */
    public function snapEach(iterable $answers): array
    {
        $items = [];

        foreach ($answers as $answer)
        {
            if (!\is_string($answer))
            {
                continue;
            }

            [$value, $from] = $this->snap($answer);
            $items[] = ['given' => $answer, 'value' => $value, 'repaired' => null !== $from];
        }

        return $items;
    }

    /**
     * Snap a multi-select answer to a list of values.
     *
     * The list is de-duplicated and order-preserving, so a model that repeats
     * itself cannot inflate a contribution. Items that match nothing on the menu
     * are dropped.
     *
     * @param iterable<mixed> $answers
     *
     * @return list<string>
     */
    public function snapAll(iterable $answers): array
    {
        $snapped = [];

        foreach ($this->snapEach($answers) as ['value' => $value])
        {
            if (null !== $value && !\in_array($value, $snapped, true))
            {
                $snapped[] = $value;
            }
        }

        return $snapped;
    }

    /**
     * The nearest menu value, or null when nothing is close enough.
     */
    private function closest(string $answer): ?string
    {
        $best = null;
        $bestScore = -1.0;
        $normalised = $this->normalise($answer);

        foreach ($this->feature->values as $value)
        {
            $score = Similarity::ratio($normalised, $this->normalise($value));

            if ($score > $bestScore)
            {
                $best = $value;
                $bestScore = $score;
            }
        }

        return $bestScore >= Similarity::CUTOFF ? $best : null;
    }

    private function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('~[\s_\-]+~u', ' ', $value);

        return trim($value);
    }
}
