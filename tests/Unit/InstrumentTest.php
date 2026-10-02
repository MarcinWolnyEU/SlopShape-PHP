<?php

declare(strict_types=1);

use SlopShape\Exception\InstrumentUnavailable;
use SlopShape\Instrument\CoreValue;
use SlopShape\Instrument\FeatureType;
use SlopShape\Instrument\Instrument;
use SlopShape\Instrument\Leans;

it('loads the ten calibrated core values from the vendored instrument', function (): void {
    // Ten values on nine features: Functional Stage Inventory carries two.
    expect(instrument()->coreValues())->toHaveCount(10)
        ->and(instrument()->count())->toBe(9)
    ;
});

it('leaves out the features the release withdrew as format artefacts', function (): void {
    // SlopShape v3 found these answers moved with the input format (a title
    // line, markdown) rather than with authorship, and dropped them.
    foreach (['PUR_OUT_003', 'AUD_PRB_002'] as $id)
    {
        expect(instrument()->has($id))->toBeFalse($id);
    }

    expect(instrument()->provenance->sha256)->toHaveKey('instrument/format_exclusions.json');
});

it('orders core values by released gap, widest first', function (): void {
    $gaps = array_map(static fn (CoreValue $cv): float => $cv->gap, instrument()->coreValues());

    $sorted = $gaps;
    rsort($sorted);

    expect($gaps)->toBe($sorted)
        ->and($gaps[0])->toBe(0.65)
    ;
});

it('carries the released gaps, signs and ordinals verbatim', function (): void {
    $byColumn = [];

    foreach (instrument()->coreValues() as $coreValue)
    {
        $byColumn[$coreValue->column] = $coreValue;
    }

    expect($byColumn)->toHaveKeys([
        'PAG_FUR_011__short_under_800_words',
        'STR_FLW_005__no',
        'STR_FLW_006__no',
        'STR_STG_001__mechanism_explanation',
        'STR_STG_001__summary_or_synthesis',
        'STR_STG_008__restated_thesis_or_reframe',
        'TIM_CUE_003__historical_date_or_period',
        'TIM_CUE_011__modern_or_next_generation_framing',
        'VOC_CRD_001__confident_institutional_explanation',
        'VOC_PRT_005__absent',
    ]);

    expect($byColumn['STR_STG_008__restated_thesis_or_reframe']->leans)->toBe(Leans::Ai)
        ->and($byColumn['STR_STG_008__restated_thesis_or_reframe']->gap)->toBe(0.65)
        ->and($byColumn['STR_STG_008__restated_thesis_or_reframe']->ordinal)->toBeFalse()
        ->and($byColumn['TIM_CUE_003__historical_date_or_period']->leans)->toBe(Leans::Human)
        ->and($byColumn['TIM_CUE_003__historical_date_or_period']->gap)->toBe(0.283)
        ->and(array_filter($byColumn, static fn (CoreValue $cv): bool => $cv->ordinal))->toBe([])
    ;
});

it('reads an ordinal core value from an instrument that has one', function (): void {
    $byColumn = [];

    foreach (ordinalInstrument()->coreValues() as $coreValue)
    {
        $byColumn[$coreValue->column] = $coreValue;
    }

    expect($byColumn['AUD_PRB_002__ord']->ordinal)->toBeTrue()
        ->and($byColumn['AUD_PRB_002__ord']->leans)->toBe(Leans::Human)
        ->and($byColumn['AUD_PRB_002__ord']->gap)->toBe(0.222)
    ;
});

it('models feature types from the taxonomy vocabulary', function (): void {
    expect(instrument()->feature('STR_STG_001')->type)->toBe(FeatureType::MultiSelect)
        ->and(instrument()->feature('STR_STG_001')->isMultiSelect())->toBeTrue()
        ->and(ordinalInstrument()->feature('AUD_PRB_002')->type)->toBe(FeatureType::Ordinal)
        ->and(ordinalInstrument()->feature('AUD_PRB_002')->isOrdinal())->toBeTrue()
        ->and(instrument()->feature('STR_FLW_005')->type)->toBe(FeatureType::Binary)
        ->and(instrument()->feature('VOC_CRD_001')->type)->toBe(FeatureType::Categorical)
    ;
});

it('asks the model about everything except what it measures itself', function (): void {
    expect(instrument()->promptFeatureIds())->toHaveCount(8)
        ->and(instrument()->promptFeatureIds())->not->toContain('PAG_FUR_011')
        ->and(array_map(
            static fn (SlopShape\Instrument\Feature $f): string => $f->id,
            instrument()->measuredFeatures(),
        ))->toBe(['PAG_FUR_011'])
    ;
});

it('renders a feature in the study\'s own prompt line format', function (): void {
    expect(instrument()->feature('VOC_CRD_001')->promptLine())
        ->toStartWith("VOC_CRD_001 [ONE]: What is the article's primary method for establishing credibility? ALLOWED: confident_institutional_explanation | ")
        ->and(instrument()->feature('STR_STG_001')->promptLine())
        ->toContain('STR_STG_001 [MANY]:')
    ;
});

it('rejects an unknown feature id by name', function (): void {
    instrument()->feature('NOPE_999');
})->throws(SlopShape\Exception\UnknownFeature::class, 'NOPE_999');

it('knows what it does not have', function (): void {
    expect(instrument()->has('VOC_CRD_001'))->toBeTrue()
        ->and(instrument()->has('NOPE_999'))->toBeFalse()
    ;
});

it('refuses an unreadable instrument file', function (): void {
    Instrument::fromFile('/definitely/not/here.json');
})->throws(InstrumentUnavailable::class, 'could not be read');

it('refuses an instrument file that is not JSON', function (): void {
    Instrument::fromJson('{not json', 'broken.json');
})->throws(InstrumentUnavailable::class, 'not valid JSON');

it('refuses a file with no features', function (): void {
    Instrument::fromJson('{"features": []}', 'empty.json');
})->throws(InstrumentUnavailable::class, 'no "features" array');

it('refuses a core value that names a feature the instrument does not have', function (): void {
    $json = json_encode([
        'features' => [[
            'id' => 'F1', 'name' => 'F1', 'question' => '?', 'type' => 'binary',
            'values' => ['yes', 'no'], 'measured_by_tool' => false,
        ]],
        'core_values' => [[
            'column' => 'F2__yes', 'feature_id' => 'F2', 'value' => 'yes',
            'leans' => 'ai', 'gap' => 0.5,
        ]],
    ], \JSON_THROW_ON_ERROR);

    Instrument::fromJson($json, 'orphan.json');
})->throws(InstrumentUnavailable::class, 'unknown feature "F2"');

it('refuses a core value outside its feature\'s menu', function (): void {
    $json = json_encode([
        'features' => [[
            'id' => 'F1', 'name' => 'F1', 'question' => '?', 'type' => 'binary',
            'values' => ['yes', 'no'], 'measured_by_tool' => false,
        ]],
        'core_values' => [[
            'column' => 'F1__maybe', 'feature_id' => 'F1', 'value' => 'maybe',
            'leans' => 'ai', 'gap' => 0.5,
        ]],
    ], \JSON_THROW_ON_ERROR);

    Instrument::fromJson($json, 'offmenu.json');
})->throws(InstrumentUnavailable::class, 'not an allowed value');

it('refuses a core value with a direction it cannot interpret', function (): void {
    $json = json_encode([
        'features' => [[
            'id' => 'F1', 'name' => 'F1', 'question' => '?', 'type' => 'binary',
            'values' => ['yes', 'no'], 'measured_by_tool' => false,
        ]],
        'core_values' => [[
            'column' => 'F1__yes', 'feature_id' => 'F1', 'value' => 'yes',
            'leans' => 'robot', 'gap' => 0.5,
        ]],
    ], \JSON_THROW_ON_ERROR);

    Instrument::fromJson($json, 'leans.json');
})->throws(InstrumentUnavailable::class, 'unknown "leans"');

it('refuses a feature type the taxonomy does not use', function (): void {
    $json = json_encode([
        'features' => [[
            'id' => 'F1', 'name' => 'F1', 'question' => '?', 'type' => 'telepathy',
            'values' => ['yes', 'no'], 'measured_by_tool' => false,
        ]],
        'core_values' => [],
    ], \JSON_THROW_ON_ERROR);

    Instrument::fromJson($json, 'type.json');
})->throws(InstrumentUnavailable::class, 'telepathy');

it('refuses a feature with no answer menu, because it cannot be read', function (): void {
    $json = json_encode([
        'features' => [['id' => 'F1', 'name' => 'F1', 'question' => '?', 'type' => 'binary', 'values' => []]],
        'core_values' => [],
    ], \JSON_THROW_ON_ERROR);

    Instrument::fromJson($json, 'novalues.json');
})->throws(InstrumentUnavailable::class, 'no values');

it('carries the release calibration', function (): void {
    $calibration = instrument()->calibration;

    expect($calibration->default)->toBe(0.6)
        ->and($calibration->aiVerdictAt)->toBe(0.80)
        ->and($calibration->humanVerdictAt)->toBe(0.20)
        ->and($calibration->minCoreValues)->toBe(4)
        ->and($calibration->readFloorWords)->toBe(250)
        ->and($calibration->judgementFloorWords)->toBe(572)
        ->and($calibration->corpusLength())->toMatchArray([
            'n' => 2_250,
            'min' => 572,
            'median' => 1_068,
        ])
    ;
});

/**
 * @param array<string, mixed> $overrides top-level keys to add or replace
 */
function customInstrumentJson(array $overrides = []): string
{
    return json_encode([
        'features' => [[
            'id' => 'F1', 'name' => 'F1', 'question' => '?', 'type' => 'binary',
            'values' => ['yes', 'no'], 'measured_by_tool' => false,
        ]],
        'core_values' => [[
            'column' => 'F1__yes', 'feature_id' => 'F1', 'value' => 'yes',
            'leans' => 'ai', 'gap' => 0.5,
        ]],
        ...$overrides,
    ], \JSON_THROW_ON_ERROR);
}

it('accepts a minimal custom instrument', function (): void {
    expect(Instrument::fromJson(customInstrumentJson())->count())->toBe(1);
});

it('refuses a duplicated core value row', function (): void {
    $row = ['column' => 'F1__yes', 'feature_id' => 'F1', 'value' => 'yes', 'leans' => 'ai', 'gap' => 0.5];

    Instrument::fromJson(customInstrumentJson(['core_values' => [$row, $row]]), 'dup.json');
})->throws(InstrumentUnavailable::class, 'repeats "yes" of F1');

it('refuses a duplicated feature id', function (): void {
    $feature = ['id' => 'F1', 'name' => 'F1', 'question' => '?', 'type' => 'binary', 'values' => ['yes', 'no']];

    Instrument::fromJson(customInstrumentJson(['features' => [$feature, $feature]]), 'dup.json');
})->throws(InstrumentUnavailable::class, 'repeats the feature id "F1"');

it('refuses a corpus_length block that is not shaped like one', function (mixed $block): void {
    Instrument::fromJson(customInstrumentJson(['calibration' => ['corpus_length' => $block]]), 'shape.json');
})->with([
    'a string' => ['long'],
    'missing keys' => [['min' => 1, 'n' => 3]],
    'non-numeric' => [['min' => 'a', 'p10' => 1, 'median' => 2, 'p90' => 3, 'max' => 4, 'n' => 5, 'share_under_800' => 0.5]],
    'no share' => [['min' => 1, 'p10' => 1, 'median' => 2, 'p90' => 3, 'max' => 4, 'n' => 5]],
])->throws(InstrumentUnavailable::class, 'corpus_length');

it('accepts a complete corpus_length block', function (): void {
    $block = ['min' => 1, 'p10' => 2, 'median' => 3, 'p90' => 4, 'max' => 5, 'n' => 6, 'share_under_800' => 0.5];
    $instrument = Instrument::fromJson(customInstrumentJson(['calibration' => ['corpus_length' => $block]]));

    expect($instrument->calibration->corpusLength())->toBe($block);
});
