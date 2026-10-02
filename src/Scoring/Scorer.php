<?php

declare(strict_types=1);

namespace SlopShape\Scoring;

use SlopShape\Config;
use SlopShape\Factor;
use SlopShape\Instrument\CoreValue;
use SlopShape\Instrument\Feature;
use SlopShape\Instrument\Instrument;
use SlopShape\Label;
use SlopShape\Reading\Reading;
use SlopShape\Score;
use SlopShape\Text\Document;

/**
 * Turns readings into a score, a label, a confidence and a set of caveats.
 *
 * Four things happen here, in this order, and the order matters:
 *
 *  1. every core value gets a factor, including the ones that did not fire, so
 *     a report can say "this marker was absent" rather than omitting it;
 *  2. coverage and length decide whether a label may be issued at all;
 *  3. confidence is computed from coverage, reading quality, agreement between
 *     the signals and the margin, then discounted when the document is shorter
 *     than anything the study ever scored;
 *  4. the caveats are assembled, including the ones true of every document this
 *     package will ever score.
 *
 * The scorer is immutable and holds no state between documents, so one instance
 * can serve an entire request.
 */
final readonly class Scorer {
    private LogOdds $model;

    public function __construct(
        private Instrument $instrument,
        private Config $config,
        ?LogOdds $model = null,
    ) {
        $this->model = $model ?? new LogOdds($config->calibration);
    }

    /**
     * @param array<string, Reading> $readings keyed by feature id
     */
    public function score(Document $document, array $readings): Score
    {
        $factors = $this->factors($readings);
        $logOdds = array_sum(array_map(
            static fn (Factor $factor): float => $factor->contribution,
            $factors,
        ));

        $covered = \count(array_filter($factors, static fn (Factor $factor): bool => $factor->read));
        $inRange = $this->inScriptRange($document);
        [$band, $lengthOk] = $this->lengthBand($document->wordCount);
        $probability = LogOdds::sigmoid($logOdds);

        return new Score(
            probabilityAi: $probability,
            confidence: $this->confidence($factors, $covered, $lengthOk),
            label: $this->label($probability, $covered, $inRange, $lengthOk, $band),
            logOdds: $logOdds,
            factors: $factors,
            lengthBand: $band,
            lengthNote: $this->lengthNote($document->wordCount, $band),
            wordCount: $document->wordCount,
            latinWordRatio: $document->latinWordRatio(),
            coreValuesCovered: $covered,
            coreValuesTotal: \count($factors),
            caveats: $this->caveats($covered, $inRange, $lengthOk),
            instrumentVersion: $this->instrument->provenance->instrumentVersion,
            provenance: $this->instrument->provenance,
        );
    }

    public function model(): LogOdds
    {
        return $this->model;
    }

    /**
     * @param array<string, Reading> $readings
     *
     * @return list<Factor>
     */
    private function factors(array $readings): array
    {
        $factors = [];

        foreach ($this->instrument->coreValues() as $coreValue)
        {
            $factors[] = $this->factor($coreValue, $readings[$coreValue->featureId] ?? null);
        }

        usort(
            $factors,
            static function (Factor $a, Factor $b): int {
                $byMagnitude = abs($b->contribution) <=> abs($a->contribution);

                return 0 !== $byMagnitude ? $byMagnitude : $a->featureId <=> $b->featureId;
            },
        );

        return $factors;
    }

    private function factor(CoreValue $coreValue, ?Reading $reading): Factor
    {
        $feature = $this->instrument->feature($coreValue->featureId);
        $weight = $this->model->weight($coreValue->gap);

        if (!$reading instanceof Reading || !$this->isUsable($feature, $coreValue, $reading))
        {
            return new Factor(
                featureId: $feature->id,
                feature: $feature->name,
                dimension: $feature->dimensionLabel,
                value: $coreValue->value,
                leans: $coreValue->leans,
                gap: $coreValue->gap,
                weight: $weight,
                signal: 0.0,
                confidence: 0.0,
                contribution: 0.0,
                note: $coreValue->ordinal
                    ? 'no reading of the ordinal scale: ' . implode(' | ', $feature->values)
                    : 'no reading was produced for this feature',
                read: false,
            );
        }

        $signal = $coreValue->ordinal
            ? $this->model->ordinalSignal($feature, $reading->primary())
            : $this->model->markerSignal($reading->has($coreValue->value));

        return new Factor(
            featureId: $feature->id,
            feature: $feature->name,
            dimension: $feature->dimensionLabel,
            value: $coreValue->value,
            leans: $coreValue->leans,
            gap: $coreValue->gap,
            weight: $weight,
            signal: $signal,
            confidence: $reading->confidence,
            contribution: $this->model->contribution($coreValue, $signal, $reading->confidence),
            observed: $reading->values(),
            note: $this->note($coreValue, $reading, $signal),
            snappedFrom: $reading->snappedFrom,
        );
    }

    /**
     * Did this feature receive a valid reading, whether or not its marker fired?
     *
     * A reading with no value at all is a missing reading, except for a
     * multi-select: the prompt allows an empty list there, and "none of these
     * applies" is an answer, under which no marker fires. An ordinal answer
     * that is not on the scale is missing too, because there is no position to
     * measure.
     */
    private function isUsable(Feature $feature, CoreValue $coreValue, Reading $reading): bool
    {
        if ('' === $reading->primary())
        {
            return $feature->isMultiSelect() && [] === $reading->value;
        }

        return !$coreValue->ordinal || $feature->hasValue($reading->primary());
    }

    private function note(CoreValue $coreValue, Reading $reading, float $signal): string
    {
        $parts = [];

        if ('' !== $reading->note)
        {
            $parts[] = $reading->note;
        }

        if ($coreValue->ordinal)
        {
            if (abs($signal) <= 0.05)
            {
                $parts[] = 'the ordinal reading sat at parity';
            }

            return implode('; ', $parts);
        }

        // A marker's signal is 0 only when its value was not among the ones
        // read. An empty selection has nothing to quote, and its reading note
        // already says so.
        if (0.0 === $signal && [] !== $reading->values())
        {
            $parts[] = \sprintf('the text read "%s" instead', implode(', ', $reading->values()));
        }

        return implode('; ', $parts);
    }

    /**
     * A document the instrument can read at all: mostly Latin script, because
     * the study was calibrated on English text only.
     *
     * Kept apart from length, because the two failures deserve different
     * explanations and a long document in another language is not a short one.
     */
    private function inScriptRange(Document $document): bool
    {
        return $document->latinWordRatio() >= $this->config->minLatinRatio;
    }

    /**
     * @return array{0: string, 1: bool} the band, and whether a label may be issued
     */
    private function lengthBand(int $words): array
    {
        if ($words < $this->config->readFloorWords)
        {
            return [
                \sprintf('too short to measure (%d words, under %d)', $words, $this->config->readFloorWords),
                false,
            ];
        }

        if ($words < $this->config->judgementFloorWords)
        {
            return [
                \sprintf(
                    'below the study\'s corpus minimum (%d words, minimum seen %d)',
                    $words,
                    $this->config->judgementFloorWords,
                ),
                false,
            ];
        }

        if ($words < 800)
        {
            return [\sprintf('short band (%d words)', $words), true];
        }

        return [\sprintf('in the corpus range (%d words)', $words), true];
    }

    private function label(
        float $probability,
        int $covered,
        bool $inRange,
        bool $lengthOk,
        string $band,
    ): Label {
        // Script first, then length: "outside the calibrated range" is the true
        // reason a Japanese document cannot be judged, and "too short to
        // measure" would be a false claim about a long one.
        if (!$inRange)
        {
            return Label::OutOfRange;
        }

        if (!$lengthOk)
        {
            return str_starts_with($band, 'too short') ? Label::TooShort : Label::BelowCorpusMinimum;
        }

        if ($covered < $this->config->minCoreValues)
        {
            return Label::LowCoverage;
        }

        if ($probability >= $this->config->aiVerdictAt)
        {
            return Label::LikelyAi;
        }

        if ($probability <= $this->config->humanVerdictAt)
        {
            return Label::LikelyHuman;
        }

        return Label::Inconclusive;
    }

    /**
     * How much the number deserves to be trusted.
     *
     * Four signals, weighted 40/30/20/10: how much of the instrument was
     * read (a marker that was read and did not fire counts as read), how good
     * the readings were, whether they mostly point the same
     * way, and whether the weakest of them is near zero. A weak read is then
     * discounted a further 30%, because a document shorter than anything in the
     * study's corpus is missing evidence that no confidence formula can invent.
     *
     * @param list<Factor> $factors
     */
    private function confidence(array $factors, int $covered, bool $lengthOk): float
    {
        $total = \count($factors);
        $coverage = $total > 0 ? $covered / $total : 0.0;
        $active = array_values(array_filter(
            $factors,
            static fn (Factor $factor): bool => 0.0 !== $factor->contribution,
        ));

        $read = array_values(array_filter(
            $factors,
            static fn (Factor $factor): bool => $factor->read,
        ));

        $meanReading = [] === $read ? 0.0 : array_sum(array_map(
            static fn (Factor $factor): float => $factor->confidence,
            $read,
        )) / \count($read);

        $margin = [] === $active ? 0.0 : min(array_map(
            static fn (Factor $factor): float => abs($factor->contribution),
            $active,
        ));

        $positive = \count(array_filter($active, static fn (Factor $factor): bool => $factor->isPositive()));
        $agreement = [] === $active ? 0.0 : max($positive, \count($active) - $positive) / \count($active);

        $value = 0.40 * $coverage
            + 0.30 * $meanReading
            + 0.20 * $agreement
            + 0.10 * min(1.0, $margin / 0.4);

        return round($lengthOk ? $value : $value * 0.7, 3);
    }

    /**
     * @return list<string>
     */
    private function caveats(int $covered, bool $inRange, bool $lengthOk): array
    {
        $caveats = [];

        if (!$inRange)
        {
            $caveats[] = 'This document is outside the range the instrument was calibrated on (the study '
                . 'scored English text only), so the score is shown but no label is issued.';
        }

        if (!$lengthOk)
        {
            $caveats[] = 'Length is outside the study\'s corpus. The instrument was never applied to a '
                . 'document this short, so the readings that did fire are extrapolations.';
        }

        if ($covered < $this->config->minCoreValues)
        {
            $caveats[] = \sprintf(
                'Only %d of the instrument\'s core values were readable from this text; it may be too '
                . 'short or too unstructured for the instrument to say anything useful.',
                $covered,
            );
        }

        $caveats[] = 'A log-odds sum over the ten values the study released calibration for — not the '
            . "study's classifier, which is XGBoost over 176 structurally scored features at 0.9695 "
            . 'macro-F1.';
        $caveats[] = 'Trained and validated on English commercial B2B blog posts; transfer to other genres '
            . 'or languages is unmeasured.';
        $caveats[] = 'Scoped to single-pass generation from the five studied models (gpt, claude, gemini, '
            . 'deepseek, kimi).';

        return $caveats;
    }

    private function lengthNote(int $words, string $band): string
    {
        $corpus = $this->instrument->calibration->corpusLength();

        if ([] === $corpus)
        {
            return \sprintf('%d words — %s', $words, $band);
        }

        return \sprintf(
            '%d words — %s (study corpus n=%d: min %d, p10 %d, median %d, p90 %d; %.0f%% under 800 words)',
            $words,
            $band,
            $corpus['n'],
            $corpus['min'],
            $corpus['p10'],
            $corpus['median'],
            $corpus['p90'],
            $corpus['share_under_800'] * 100,
        );
    }
}
