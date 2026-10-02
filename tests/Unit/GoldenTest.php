<?php

declare(strict_types=1);

use SlopShape\Config;
use SlopShape\Detector;
use SlopShape\Factor;
use SlopShape\Instrument\Instrument;
use SlopShape\Reading\Reading;
use SlopShape\Reading\WordCountReader;
use SlopShape\Score;
use SlopShape\Scoring\Scorer;
use SlopShape\Tests\Fixtures\Answers;
use SlopShape\Tests\Fixtures\FakeBridge;
use SlopShape\Text\Document;

/*
|--------------------------------------------------------------------------
| Golden numbers
|--------------------------------------------------------------------------
|
| The score is a formula, and a formula is worth exactly as much as the
| tests that pin it. Every number below is hand-computed from the released
| calibration, so a change in the arithmetic shows up here as a failure
| rather than as a quietly different number in someone's report.
|
|   weight(gap)  = 0.6 * ln((1 + gap) / (1 - gap))
|   contribution = confidence * weight * direction * signal
|   P[AI]        = sigmoid(sum)
|
*/

/**
 * @return array<string, list<Reading>>
 */
function goldenReadings(): array
{
    $bridge = new FakeBridge(
        [json_encode(Answers::aiFlavoured(), \JSON_THROW_ON_ERROR)],
    );

    return (new SlopShape\Reading\LlmReader($bridge, new Config()))
        ->read(instrument(), Document::fromString(aiText()))
        ->readings()
    ;
}

/**
 * The factor for one core value, by its column ("FEATURE__value").
 *
 * Functional Stage Inventory carries two core values, so a feature id alone
 * does not name a factor.
 */
function factorByColumn(Score $score, string $column): Factor
{
    foreach ($score->allFactors() as $factor)
    {
        if ($factor->featureId . '__' . $factor->value === $column)
        {
            return $factor;
        }
    }

    throw new RuntimeException(sprintf('No factor for "%s".', $column));
}

it('weights each released gap exactly as the release formula prescribes', function (): void {
    $expected = [
        // 0.6 * ln(1.65 / 0.35)
        'STR_STG_008__restated_thesis_or_reframe' => 0.6 * log(1.65 / 0.35),
        // 0.6 * ln(1.609 / 0.391)
        'STR_STG_001__summary_or_synthesis' => 0.6 * log(1.609 / 0.391),
        // 0.6 * ln(1.36 / 0.64)
        'TIM_CUE_011__modern_or_next_generation_framing' => 0.6 * log(1.36 / 0.64),
        // 0.6 * ln(1.347 / 0.653)
        'VOC_CRD_001__confident_institutional_explanation' => 0.6 * log(1.347 / 0.653),
        // 0.6 * ln(1.285 / 0.715)
        'STR_STG_001__mechanism_explanation' => 0.6 * log(1.285 / 0.715),
        // 0.6 * ln(1.283 / 0.717)
        'TIM_CUE_003__historical_date_or_period' => 0.6 * log(1.283 / 0.717),
        // 0.6 * ln(1.274 / 0.726)
        'PAG_FUR_011__short_under_800_words' => 0.6 * log(1.274 / 0.726),
    ];

    $score = detectorFor(Answers::aiFlavoured())->detect(aiText());

    foreach ($expected as $column => $weight)
    {
        expect(factorByColumn($score, $column)->weight)->toEqualWithDelta($weight, 1e-12, $column);
    }
});

it('sums the released evidence to the released log-odds', function (): void {
    $score = detectorFor(Answers::aiFlavoured())->detect(aiText());

    $sum = array_sum(array_map(
        static fn (Factor $factor): float => $factor->contribution,
        $score->allFactors(),
    ));

    expect($sum)->toEqualWithDelta(2.381_786_532_067_913_2, 1e-9)
        ->and($score->logOdds)->toEqualWithDelta(2.381_786_532_067_913_2, 1e-9)
        ->and($score->probabilityAi)->toEqualWithDelta(0.915_427_849_740_680, 1e-9)
        ->and($score->score())->toBe(92)
    ;
});

it('reproduces the reference contributions feature by feature', function (): void {
    // Each entry is weight(gap) * confidence (0.9 for a model reading, 0.99
    // for the measured length), signed by the class the value leans to, or
    // zero when the marker did not fire. Hand-derived, then pinned.
    $expected = [
        'STR_STG_008__restated_thesis_or_reframe' => 0.837_322_602_702_030_2,
        'STR_STG_001__summary_or_synthesis' => 0.763_916_716_983_789_4,
        'TIM_CUE_011__modern_or_next_generation_framing' => 0.407_036_773_283_245_2,
        'VOC_CRD_001__confident_institutional_explanation' => 0.390_991_345_452_323_7,
        'STR_STG_001__mechanism_explanation' => 0.316_564_985_503_068_7,
        'PAG_FUR_011__short_under_800_words' => -0.334_045_891_856_543_7,
        'VOC_PRT_005__absent' => 0.0,
        'STR_FLW_005__no' => 0.0,
        'STR_FLW_006__no' => 0.0,
        'TIM_CUE_003__historical_date_or_period' => 0.0,
    ];

    $score = detectorFor(Answers::aiFlavoured())->detect(aiText());

    expect($score->allFactors())->toHaveCount(10);

    foreach ($expected as $column => $contribution)
    {
        // Found by column, so this also pins the calibrated value each factor
        // weighs, not what the model read.
        expect(factorByColumn($score, $column)->contribution)->toEqualWithDelta($contribution, 1e-9, $column);
    }
});

it('reproduces the ordinal contribution from an instrument that carries one', function (): void {
    $answers = [...Answers::aiFlavoured(), 'AUD_PRB_002' => '2_opening_sentence'];
    $score = (new Detector(FakeBridge::answering($answers), null, ordinalInstrument()))->detect(aiText());

    // One rung before parity on a five-rung scale is a signal of 0.5, so the
    // contribution is 0.6 * ln(1.222 / 0.778) * 0.9 * 0.5.
    expect(factorByColumn($score, 'AUD_PRB_002__ord')->contribution)->toEqualWithDelta(0.121_909_756_199_350_2, 1e-9);
});

it('separates the calibrated value from the observed reading on every factor', function (): void {
    $score = detectorFor(Answers::aiFlavoured())->detect(aiText());

    foreach ($score->allFactors() as $factor)
    {
        $coreValue = null;

        foreach (instrument()->coreValues() as $candidate)
        {
            if ($candidate->featureId === $factor->featureId && $candidate->value === $factor->value)
            {
                $coreValue = $candidate;
            }
        }

        // Every factor weighs a released core value, and keeps the model's
        // reading apart from it.
        expect($coreValue)->not->toBeNull($factor->featureId . '__' . $factor->value)
            ->and($factor->observed)->toBeArray()
        ;
    }
});

it('signs the ordinal reading by the class that holds the high end of the scale', function (): void {
    $answers = [...Answers::aiFlavoured(), 'AUD_PRB_002' => '2_opening_sentence'];
    $score = (new Detector(FakeBridge::answering($answers), null, ordinalInstrument()))->detect(aiText());

    $ordinal = factorFor($score, 'AUD_PRB_002');

    // AUD_PRB_002 leans human, and humans place the problem later, so an early
    // placement is evidence for AI even though the value leans human. The
    // ordinal is the one case where `leans` and `side` point opposite ways.
    expect($ordinal->leans)->toBe(SlopShape\Instrument\Leans::Human)
        ->and($ordinal->side())->toBe('ai')
        ->and($ordinal->signal)->toBe(0.5)
        ->and($ordinal->isPositive())->toBeTrue()
    ;
});

it('gives a longer document a chance at the length class, not just the short band', function (): void {
    $short = detectorFor(Answers::aiFlavoured())->detect(aiText());
    $long = detectorFor(Answers::aiFlavoured())->detect(longEnoughText());

    // The length class is measured, not asked about, so the *observed* value is
    // where the tool's own reading shows up; the calibrated value stays put.
    $observedOf = static fn (Score $score): string => factorFor($score, 'PAG_FUR_011')->observed[0] ?? '';

    expect($observedOf($short))->toBe('short_under_800_words')
        ->and($observedOf($long))->toBe('medium_800_to_1499_words')
        ->and($short->lengthBand)->toContain('below the study')
        ->and($long->lengthBand)->toContain('in the corpus range')
    ;
});

it('discounts confidence for a document shorter than the study corpus', function (): void {
    $short = detectorFor(Answers::aiFlavoured())->detect(aiText());
    $long = detectorFor(Answers::aiFlavoured())->detect(longEnoughText());

    // Both documents get the same readings, so all ten core values are covered
    // either way. The short one differs in two ways: the length class reads
    // "short" and leans human, which costs agreement (5 of 6 active factors
    // instead of 5 of 5), and the whole value is then discounted by 30%.
    //   short: (0.400 + 0.273 + 0.167 + 0.079) * 0.7 = 0.919 * 0.7 = 0.643
    //   long:   0.400 + 0.273 + 0.200 + 0.079                     = 0.952
    expect($short->confidence)->toEqualWithDelta(0.643, 1e-9)
        ->and($long->confidence)->toEqualWithDelta(0.952, 1e-9)
        ->and($short->confidence)->toBeLessThan($long->confidence)
    ;
});

it('computes confidence from coverage, reading quality, agreement and margin', function (): void {
    $readings = goldenReadings();
    $document = Document::fromString(longEnoughText());

    foreach ((new WordCountReader())->read(instrument(), $document) as $reading)
    {
        $readings[$reading->featureId] = $reading;
    }

    $score = (new Scorer(instrument(), new Config()))->score($document, $readings);

    // Coverage counts core values whose feature got a valid reading, whether
    // or not the marker fired, so all ten are covered even though only five
    // contribute to the log-odds. The length class reads "medium" here, so it
    // is covered but neutral.
    //   coverage    0.40 * 10/10     = 0.400
    //   readings    0.30 * 0.909     = 0.273   (nine readings at 0.9, length at 0.99)
    //   agreement   0.20 * 5/5       = 0.200   (all five active factors point AI-ward)
    //   margin      0.10 * 0.3166/0.4= 0.079   (the weakest is the mechanism stage)
    expect($score->coreValuesCovered)->toBe(10)
        ->and($score->confidence)->toEqualWithDelta(0.952, 1e-9)
    ;
});

it('keeps the length marker in the evidence on a short document', function (): void {
    $score = detectorFor(Answers::aiFlavoured())->detect(aiText());

    // On a short document the length class *does* read "short", and it leans
    // human, so it is the one piece of human-ward evidence in the report. All
    // ten core values are covered: coverage counts readings, not fired markers.
    expect($score->coreValuesCovered)->toBe(10)
        ->and($score->negativeFactors())->toHaveCount(1)
        ->and($score->negativeFactors()[0]->featureId)->toBe('PAG_FUR_011')
    ;
});

it('moves the probability in the right direction as calibration rises', function (): void {
    $gentle = detectorFor(Answers::aiFlavoured(), new Config(calibration: 0.3))
        ->detect(longEnoughText())
    ;
    $firm = detectorFor(Answers::aiFlavoured(), new Config(calibration: 1.2))
        ->detect(longEnoughText())
    ;

    expect($gentle->logOdds)->toBeLessThan($firm->logOdds)
        ->and($gentle->probabilityAi)->toBeLessThan($firm->probabilityAi)
    ;
});

it('scores an uninformative set of readings near the middle', function (): void {
    $score = detectorFor(Answers::uninformative())->detect(longEnoughText());

    // Only the tool-measured length fires, and it leans human, so the sum is
    // mildly negative but nowhere near a verdict.
    expect($score->probabilityAi)->toBeLessThan(0.5)
        ->and($score->probabilityAi)->toBeGreaterThan(0.1)
        ->and($score->isInconclusive())->toBeTrue()
    ;
});

it('names the short band for a document inside the corpus range but under 800 words', function (): void {
    $paragraph = 'The kettle in the office has stopped boiling properly and I have '
        . 'logged this four times now, which is one more than I intended to write '
        . 'about a kettle this week, and the rain has not helped the situation at all.';
    $score = detectorFor(Answers::aiFlavoured())->detect(implode("\n\n", array_fill(0, 15, $paragraph)));

    expect($score->wordCount)->toBeGreaterThanOrEqual(572)
        ->and($score->wordCount)->toBeLessThan(800)
        ->and($score->lengthBand)->toContain('short band')
    ;
});

it('notes the length without corpus statistics when the instrument releases none', function (): void {
    $data = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/instrument.json'), true, 512, \JSON_THROW_ON_ERROR);
    assert(is_array($data) && is_array($data['calibration']));
    unset($data['calibration']['corpus_length']);

    $score = (new Detector(
        FakeBridge::answering(Answers::aiFlavoured()),
        null,
        Instrument::fromArray($data),
    ))->detect(aiText());

    expect($score->lengthNote)->toBe(sprintf('%d words — %s', $score->wordCount, $score->lengthBand))
        ->and($score->lengthNote)->not->toContain('study corpus')
    ;
});

it('notes an ordinal reading that sits at parity, and gives it no weight', function (): void {
    // The third of five rungs is the midpoint of the scale: no signal either way.
    $answers = [...Answers::aiFlavoured(), 'AUD_PRB_002' => '3_first_paragraph'];
    $score = (new Detector(FakeBridge::answering($answers), null, ordinalInstrument()))->detect(aiText());

    $ordinal = factorByColumn($score, 'AUD_PRB_002__ord');

    expect($ordinal->read)->toBeTrue()
        ->and($ordinal->signal)->toBe(0.0)
        ->and($ordinal->contribution)->toBe(0.0)
        ->and($ordinal->note)->toBe('the ordinal reading sat at parity')
    ;
});
