<?php

declare(strict_types=1);

use SlopShape\Config;
use SlopShape\Instrument\Calibration;
use SlopShape\Label;
use SlopShape\Reading\Reading;
use SlopShape\Tests\Fixtures\Answers;

/*
|--------------------------------------------------------------------------
| The small value objects
|--------------------------------------------------------------------------
*/

it('keeps its released defaults in step with the instrument', function (): void {
    // The instrument carries the release's numbers for provenance; Config
    // carries the runtime defaults. Two copies of one number is a drift risk,
    // so the two are compared here rather than trusted.
    $calibration = instrument()->calibration;
    $config = new Config();

    expect($config->calibration)->toBe($calibration->default)
        ->and($config->aiVerdictAt)->toBe($calibration->aiVerdictAt)
        ->and($config->humanVerdictAt)->toBe($calibration->humanVerdictAt)
        ->and($config->minCoreValues)->toBe($calibration->minCoreValues)
        ->and($config->minLatinRatio)->toBe($calibration->minLatinRatio)
        ->and($config->readFloorWords)->toBe($calibration->readFloorWords)
        ->and($config->judgementFloorWords)->toBe($calibration->judgementFloorWords)
    ;
});

it('can be built from a release calibration block', function (): void {
    $config = Config::fromCalibration(new Calibration(
        default: 0.75,
        aiVerdictAt: 0.85,
        humanVerdictAt: 0.15,
        minCoreValues: 6,
        minLatinRatio: 0.9,
        readFloorWords: 400,
        judgementFloorWords: 800,
    ));

    expect($config->calibration)->toBe(0.75)
        ->and($config->minCoreValues)->toBe(6)
        ->and($config->judgementFloorWords)->toBe(800)
        // untouched values keep their defaults
        ->and($config->readingConfidence)->toBe(0.9)
        ->and($config->maxInputChars)->toBe(24_000)
    ;
});

it('copies rather than mutates when you change a setting', function (): void {
    $config = new Config();
    $firmer = $config->withCalibration(1.0);

    expect($config->calibration)->toBe(0.6)
        ->and($firmer->calibration)->toBe(1.0)
        ->and($firmer->withMaxInputChars(500)->maxInputChars)->toBe(500)
        ->and($firmer->withMaxInputChars(500)->calibration)->toBe(1.0)
        ->and($config->withReadingConfidence(0.5)->readingConfidence)->toBe(0.5)
    ;
});

it('keeps immutable when a detector is reconfigured', function (): void {
    $detector = detectorFor(['VOC_PRT_005' => 'absent']);
    $retuned = $detector->withConfig(new Config(calibration: 1.5));

    expect($detector->config()->calibration)->toBe(0.6)
        ->and($retuned->config()->calibration)->toBe(1.5)
        // the instrument is shared, not reloaded
        ->and($retuned->instrument())->toBe($detector->instrument())
    ;
});

it('knows when a label was withheld and why', function (): void {
    expect(Label::LikelyAi->isInconclusive())->toBeFalse()
        ->and(Label::LikelyAi->refusal())->toBeNull()
        ->and(Label::TooShort->isInconclusive())->toBeTrue()
        ->and(Label::TooShort->refusal())->toBe('inconclusive (too short to measure)')
        ->and(Label::Inconclusive->refusal())->toBe('inconclusive')
    ;
});

it('knows which values a reading holds, whatever shape the answer took', function (): void {
    $single = new Reading('F1', 'yes', 0.9);
    $multi = new Reading('F2', ['a', 'b'], 0.9);

    expect($single->has('yes'))->toBeTrue()
        ->and($single->has('no'))->toBeFalse()
        ->and($single->values())->toBe(['yes'])
        ->and($single->primary())->toBe('yes')
        ->and($multi->has('b'))->toBeTrue()
        ->and($multi->has('c'))->toBeFalse()
        ->and($multi->values())->toBe(['a', 'b'])
        ->and($multi->primary())->toBe('a')
    ;
});

it('marks a reading as the tool\'s own or the model\'s', function (): void {
    expect((new Reading('F1', 'yes', 0.99, source: Reading::SOURCE_TOOL))->isFromModel())->toBeFalse()
        ->and((new Reading('F1', 'yes', 0.9))->isFromModel())->toBeTrue()
    ;
});

it('serialises a score to something a report can ship', function (): void {
    $score = detectorFor(Answers::aiFlavoured())->detect(aiText());
    $array = $score->toArray();

    expect($array)->toHaveKeys([
        'label', 'score_0_100', 'probability_ai', 'probability_human', 'log_odds',
        'confidence', 'core_values_covered', 'word_count', 'length_band', 'length_note',
        'positive_factors', 'negative_factors', 'neutral_factors', 'caveats', 'instrument',
    ])
        ->and($array['probability_ai'] + $array['probability_human'])->toEqualWithDelta(1.0, 1e-12)
        ->and($array['core_values_covered'])->toBe('10/10')
        ->and($array['label'])->toBe('inconclusive (below the study\'s corpus minimum)')
    ;

    // and it survives a JSON round trip intact
    $round = json_decode(json_encode($score, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
    expect($round)->toEqual($array);
});

it('describes itself on one line for logs', function (): void {
    $score = detectorFor(Answers::aiFlavoured())->detect(aiText());

    expect((string) $score)->toBe('92/100  P[AI]=0.915  inconclusive (below the study\'s corpus minimum)  confidence 0.64  10/10 core values read');
});

it('is iterable over its factors', function (): void {
    $score = detectorFor(Answers::aiFlavoured())->detect(aiText());
    $ids = [];

    foreach ($score as $factor)
    {
        $ids[] = $factor->featureId;
    }

    expect($ids)->toHaveCount(10)
        ->and($ids[0])->toBe($score->allFactors()[0]->featureId)
    ;
});

it('groups both sides of the evidence in one call', function (): void {
    $evidence = detectorFor(Answers::aiFlavoured())->detect(aiText())->evidence();

    expect($evidence)->toHaveKeys(['positive', 'negative'])
        ->and($evidence['positive'])->not->toBeEmpty()
        ->and($evidence['negative'])->not->toBeEmpty()
    ;
});

it('names the complement of a two-way claim', function (): void {
    $score = detectorFor(Answers::aiFlavoured())->detect(aiText());

    expect($score->probabilityHuman())->toBe(1.0 - $score->probabilityAi);
});
