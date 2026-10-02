<?php

declare(strict_types=1);

use SlopShape\Detector;
use SlopShape\Instrument\Instrument;
use SlopShape\Score;
use SlopShape\Tests\Fixtures\Answers;
use SlopShape\Tests\Fixtures\FakeBridge;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The base case for this package's tests. Pest binds a fresh TestCase to
| every test class, so there is nothing shared to leak between tests here
| beyond the immutable instrument, which is loaded once on purpose.
|
*/

/**
 * The instrument vendored with the package, loaded once for the whole suite.
 */
function instrument(): Instrument
{
    static $instrument = null;

    return $instrument ??= Instrument::bundled();
}

/**
 * The vendored instrument plus one ordinal core value, loaded once.
 *
 * The released core has no ordinal value, but the scorer still supports them,
 * and an instrument loaded from a file may carry one. This adds the one earlier
 * SlopShape releases calibrated, Problem Placement (AUD_PRB_002, gap 0.222,
 * leans human), so the ordinal path is exercised on real numbers.
 */
function ordinalInstrument(): Instrument
{
    static $instrument = null;

    if (null !== $instrument)
    {
        return $instrument;
    }

    $data = json_decode((string) file_get_contents(__DIR__ . '/../resources/instrument.json'), true, 512, \JSON_THROW_ON_ERROR);
    assert(is_array($data) && is_array($data['features']) && is_array($data['core_values']));

    $data['features'][] = [
        'id' => 'AUD_PRB_002',
        'name' => 'Problem Placement',
        'question' => 'Where does the core problem first become explicit?',
        'type' => 'ordinal',
        'values' => [
            '1_title_or_subtitle',
            '2_opening_sentence',
            '3_first_paragraph',
            '4_after_context_or_preamble',
            '5_only_implied_across_sections',
        ],
        'detection_method' => 'Locate the first sentence that clearly identifies the obstacle, tension, or decision.',
        'dimension' => 'audience_problem_stakes',
        'dimension_label' => 'Audience',
        'aspect' => 'problem_construction',
        'measured_by_tool' => false,
    ];
    $data['core_values'][] = [
        'column' => 'AUD_PRB_002__ord',
        'feature_id' => 'AUD_PRB_002',
        'value' => 'ord',
        'leans' => 'human',
        'gap' => 0.222,
        'ordinal' => true,
    ];

    return $instrument = Instrument::fromArray($data);
}

/**
 * A detector wired to a fake bridge that answers with $answers.
 *
 * @param array<string, string|list<string>> $answers
 */
function detectorFor(array $answers, ?SlopShape\Config $config = null): Detector
{
    return new Detector(FakeBridge::answering($answers), $config, instrument());
}

/**
 * A detector wired to a bridge that fails.
 */
function failingDetector(int $failures = 1): Detector
{
    return new Detector(FakeBridge::failing($failures), null, instrument());
}

/**
 * The document that reads as model-flavoured.
 */
function aiText(): string
{
    return Answers::aiFlavouredText();
}

/**
 * A document long enough to be inside the study's corpus, so that length
 * stops being the reason a label is withheld.
 */
function longEnoughText(): string
{
    $paragraph = 'The kettle in the office has stopped boiling properly and I have '
        . 'logged this four times now, which is one more than I intended to write '
        . 'about a kettle this week, and the rain has not helped the situation at all.';

    return implode("\n\n", array_fill(0, 30, $paragraph));
}

/**
 * A document inside the study's corpus but under 800 words, so the length
 * class reads "short" and a label can still be issued.
 */
function shortBandText(): string
{
    $paragraph = 'The kettle in the office has stopped boiling properly and I have '
        . 'logged this four times now, which is one more than I intended to write '
        . 'about a kettle this week, and the rain has not helped the situation at all.';

    return implode("\n\n", array_fill(0, 15, $paragraph));
}

/**
 * Assert a factor list is ordered by absolute contribution, strongest first.
 *
 * @param list<SlopShape\Factor> $factors
 */
function expectStrongestFirst(array $factors): void
{
    $magnitudes = array_map(
        static fn (SlopShape\Factor $factor): float => abs($factor->contribution),
        $factors,
    );
    $sorted = $magnitudes;
    rsort($sorted);

    expect($magnitudes)->toBe($sorted);
}

/**
 * The factor for one core value, or a loud failure.
 *
 * Ten factors, and a test that cares about one of them should say which.
 *
 * @throws RuntimeException when the score has no such factor
 */
function factorFor(Score $score, string $featureId): SlopShape\Factor
{
    foreach ($score->allFactors() as $factor)
    {
        if ($factor->featureId === $featureId)
        {
            return $factor;
        }
    }

    throw new RuntimeException(sprintf('No factor for "%s". The score has: %s', $featureId, implode(', ', array_map(static fn (SlopShape\Factor $factor): string => $factor->featureId, $score->allFactors()))));
}

/**
 * A short, readable rendering of a score, for assertion messages.
 */
function summarise(Score $score): string
{
    return sprintf(
        '%s (P[AI]=%.3f, %d/%d read, confidence %.2f)',
        $score->label->value,
        $score->probabilityAi,
        $score->coreValuesCovered,
        $score->coreValuesTotal,
        $score->confidence,
    );
}
