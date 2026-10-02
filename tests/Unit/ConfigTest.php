<?php

declare(strict_types=1);

use SlopShape\Config;
use SlopShape\Factor;
use SlopShape\Instrument\Instrument;
use SlopShape\Label;
use SlopShape\Reading\Reading;
use SlopShape\Scoring\LogOdds;
use SlopShape\Scoring\Scorer;
use SlopShape\Text\Document;

/**
 * A reading for every core value that does not fire any marker.
 *
 * @return array<string, Reading>
 */
function quietReadings(?Instrument $instrument = null): array
{
    $instrument ??= instrument();
    $readings = [];

    foreach ($instrument->coreValues() as $coreValue)
    {
        $feature = $instrument->feature($coreValue->featureId);
        $answer = $coreValue->ordinal
            ? $feature->values[intdiv(count($feature->values) - 1, 2)]
            : current(array_filter($feature->values, static fn (string $v): bool => $v !== $coreValue->value));

        $readings[$feature->id] = new Reading($feature->id, (string) $answer, 0.9);
    }

    return $readings;
}

it('counts a feature that was read but did not fire as covered', function (): void {
    $scorer = new Scorer(instrument(), new Config());
    $score = $scorer->score(Document::fromString(longEnoughText()), quietReadings());

    expect($score->coreValuesCovered)->toBe(10)
        ->and($score->label)->not->toBe(Label::LowCoverage)
        ->and(implode(' ', $score->caveats))->not->toContain('were readable from this text')
        ->and($score->confidence)->toBeGreaterThan(0.3)
    ;
});

it('counts only features that received a reading as covered', function (): void {
    $readings = quietReadings();

    // Nine features carry the ten core values; keep the last three, which
    // carry one core value each.
    foreach (array_slice(array_keys($readings), 0, 6) as $id)
    {
        unset($readings[$id]);
    }

    $score = (new Scorer(instrument(), new Config()))->score(Document::fromString(longEnoughText()), $readings);

    expect($score->coreValuesCovered)->toBe(3)
        ->and($score->label)->toBe(Label::LowCoverage)
    ;
});

it('treats an empty or off-scale reading as missing', function (): void {
    $readings = quietReadings(ordinalInstrument());
    $readings['STR_STG_008'] = new Reading('STR_STG_008', [], 0.9);
    $readings['AUD_PRB_002'] = new Reading('AUD_PRB_002', 'not_on_the_scale', 0.9);

    $score = (new Scorer(ordinalInstrument(), new Config()))->score(Document::fromString(longEnoughText()), $readings);

    // Eleven core values, less the empty one and the off-scale ordinal.
    expect($score->coreValuesTotal)->toBe(11)
        ->and($score->coreValuesCovered)->toBe(9)
    ;
});

it('reports a neutral factor as neutral, not human', function (): void {
    $score = (new Scorer(instrument(), new Config()))->score(Document::fromString(longEnoughText()), quietReadings());
    $factor = $score->neutralFactors()[0];

    expect($factor->side())->toBe('neutral')
        ->and($factor->toArray()['side'])->toBe('neutral')
        ->and(array_values(array_unique(array_map(static fn (Factor $f): string => $f->side(), $score->neutralFactors()))))->toBe(['neutral'])
    ;
});

it('refuses non-finite or out-of-range settings', function (array $args): void {
    new Config(...$args);
})->with([
    'NaN calibration' => [['calibration' => \NAN]],
    'infinite calibration' => [['calibration' => \INF]],
    'negative calibration' => [['calibration' => -0.1]],
    'confidence above 1' => [['readingConfidence' => 5.0]],
    'confidence NaN' => [['readingConfidence' => \NAN]],
    'negative confidence' => [['readingConfidence' => -0.5]],
    'verdict above 1' => [['aiVerdictAt' => 1.5]],
    'latin ratio above 1' => [['minLatinRatio' => 2.0]],
    'inverted verdicts' => [['aiVerdictAt' => 0.3, 'humanVerdictAt' => 0.7]],
    'zero input chars' => [['maxInputChars' => 0]],
    'negative input chars' => [['maxInputChars' => -5]],
    'negative min core values' => [['minCoreValues' => -1]],
    'inverted floors' => [['readFloorWords' => 600, 'judgementFloorWords' => 500]],
])->throws(InvalidArgumentException::class);

it('validates through the with-ers too', function (): void {
    (new Config())->withReadingConfidence(5.0);
})->throws(InvalidArgumentException::class, 'readingConfidence');

it('accepts the boundaries', function (): void {
    $config = new Config(calibration: 0.0, readingConfidence: 1.0, aiVerdictAt: 0.5, humanVerdictAt: 0.5, maxInputChars: 1);

    expect($config->maxInputChars)->toBe(1);
});

it('never turns NaN into a confident probability', function (): void {
    expect(LogOdds::sigmoid(\NAN))->toBe(0.5)
        ->and((new LogOdds(0.6))->weight(\NAN))->toBe(0.0)
    ;
});

it('refuses a NaN calibration constant in the model', function (): void {
    new LogOdds(\NAN);
})->throws(InvalidArgumentException::class);

it('requires ext-mbstring and does not hide it in the dependency checker', function (): void {
    $composer = (string) file_get_contents(__DIR__ . '/../../composer.json');
    $checker = (string) file_get_contents(__DIR__ . '/../../composer-require-checker.json');

    expect($composer)->toContain('"ext-mbstring": "*"')
        ->and($checker)->not->toContain('mbstring')
    ;
});
