<?php

declare(strict_types=1);

use SlopShape\Instrument\CoreValue;
use SlopShape\Instrument\Feature;
use SlopShape\Instrument\FeatureType;
use SlopShape\Instrument\Leans;
use SlopShape\Scoring\LogOdds;

function ordinalFeature(): Feature
{
    return new Feature(
        id: 'AUD_PRB_002',
        name: 'Problem Placement',
        question: 'Where does the core problem first become explicit?',
        type: FeatureType::Ordinal,
        values: [
            '1_title_or_subtitle',
            '2_opening_sentence',
            '3_first_paragraph',
            '4_after_context_or_preamble',
            '5_only_implied_across_sections',
        ],
        detectionMethod: 'Locate the first sentence that clearly identifies the obstacle.',
        dimension: 'audience_problem_stakes',
        dimensionLabel: 'Audience',
        aspect: 'problem_construction',
    );
}

function coreValue(string $leans, float $gap, bool $ordinal = false): CoreValue
{
    return new CoreValue(
        column: 'COL',
        featureId: $ordinal ? 'AUD_PRB_002' : 'STR_STG_008',
        value: $ordinal ? 'ord' : 'restated_thesis_or_reframe',
        leans: Leans::from($leans),
        gap: $gap,
        ordinal: $ordinal,
    );
}

it('weights a gap by the log-odds of its prevalence', function (): void {
    // 0.6 * ln(1.65 / 0.35) = 0.9304...
    expect((new LogOdds(0.6))->weight(0.65))->toBe(0.6 * log(1.65 / 0.35))
        ->toBeGreaterThan(0.0)
    ;
});

it('weighs a wider gap more heavily', function (): void {
    $model = new LogOdds(0.6);

    expect($model->weight(0.65))->toBeGreaterThan($model->weight(0.5))
        ->and($model->weight(0.5))->toBeGreaterThan($model->weight(0.25))
        ->and($model->weight(0.0))->toBe(0.0)
    ;
});

it('clamps a gap instead of dividing by zero at 1', function (): void {
    $model = new LogOdds(0.6);

    expect($model->weight(1.0))->toBe($model->weight(0.95))
        ->and($model->weight(-3.0))->toBe(0.0)
        ->and(is_finite($model->weight(1.0)))->toBeTrue()
    ;
});

it('scales every weight with the calibration constant', function (): void {
    expect((new LogOdds(1.2))->weight(0.5))->toBe(2.0 * (new LogOdds(0.6))->weight(0.5))
        ->and((new LogOdds(0.6))->calibration())->toBe(0.6)
    ;
});

it('maps a marker to a binary signal', function (): void {
    $model = new LogOdds(0.6);

    expect($model->markerSignal(true))->toBe(1.0)
        ->and($model->markerSignal(false))->toBe(0.0)
    ;
});

it('signs a marker by the class the value belongs to', function (): void {
    $model = new LogOdds(0.6);

    expect($model->contribution(coreValue('ai', 0.5), 1.0, 1.0))->toBeGreaterThan(0.0)
        ->and($model->contribution(coreValue('human', 0.5), 1.0, 1.0))->toBeLessThan(0.0)
        ->and($model->contribution(coreValue('ai', 0.5), 0.0, 1.0))->toBe(0.0)
    ;
});

it('scales a contribution by how confident the reading was', function (): void {
    $model = new LogOdds(0.6);
    $certain = $model->contribution(coreValue('ai', 0.5), 1.0, 1.0);

    expect($model->contribution(coreValue('ai', 0.5), 1.0, 0.5))->toBe($certain / 2);
});

it('reads an ordinal distance from parity, signed towards the earliest value', function (): void {
    $model = new LogOdds(0.6);
    $feature = ordinalFeature();

    expect($model->ordinalSignal($feature, '1_title_or_subtitle'))->toBe(1.0)
        ->and($model->ordinalSignal($feature, '5_only_implied_across_sections'))->toBe(-1.0)
        ->and($model->ordinalSignal($feature, '3_first_paragraph'))->toBe(0.0)
    ;
});

it('reads an off-menu ordinal as no signal at all', function (): void {
    expect((new LogOdds(0.6))->ordinalSignal(ordinalFeature(), 'not_a_value'))->toBe(0.0)
        ->and((new LogOdds(0.6))->ordinalSignal(ordinalFeature(), null))->toBe(0.0)
    ;
});

it('caps an ordinal signal so one value cannot dominate the sum', function (): void {
    // An eight-rung scale would exceed +/-1 at its ends, so the cap is what
    // stops a single ordinal from swamping a sum of ten.
    $wide = new Feature(
        id: 'ORD',
        name: 'Wide ordinal',
        question: 'Where?',
        type: FeatureType::Ordinal,
        values: ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'],
        detectionMethod: 'Look.',
        dimension: 'structure_and_flow',
        dimensionLabel: 'Structure',
        aspect: 'overall_flow',
    );

    $model = new LogOdds(0.6);

    expect($model->ordinalSignal($wide, 'a'))->toBe(1.0)
        ->and($model->ordinalSignal($wide, 'h'))->toBe(-1.0)
        ->and($model->ordinalSignal($wide, 'a'))->toBeLessThanOrEqual(2.0)
        ->and($model->ordinalSignal($wide, 'h'))->toBeGreaterThanOrEqual(-2.0)
    ;
});

it('flips the sign for an ordinal, because leans names the high end of the scale', function (): void {
    $model = new LogOdds(0.6);

    // The released ordinal leans human, and humans place the problem later, so
    // an early placement is evidence against them and for AI.
    $early = $model->contribution(coreValue('human', 0.222, ordinal: true), 1.0, 0.9);
    $late = $model->contribution(coreValue('human', 0.222, ordinal: true), -1.0, 0.9);

    expect($early)->toBeGreaterThan(0.0)
        ->and($late)->toBeLessThan(0.0)
        ->and(abs($early))->toBe(abs($late))
    ;
});

it('turns log-odds into a probability', function (): void {
    expect(LogOdds::sigmoid(0.0))->toBe(0.5)
        ->and(LogOdds::sigmoid(2.0))->toBeGreaterThan(0.8)
        ->and(LogOdds::sigmoid(-2.0))->toBeLessThan(0.2)
    ;
});

it('survives log-odds large enough to overflow exp()', function (): void {
    expect(LogOdds::sigmoid(1_000.0))->toBeGreaterThan(0.999_999)
        ->and(LogOdds::sigmoid(-1_000.0))->toBeLessThan(0.000_001)
        ->and(LogOdds::sigmoid(\PHP_FLOAT_MAX))->toBeGreaterThan(0.999_999)
        ->and(LogOdds::sigmoid(-\PHP_FLOAT_MAX))->toBeLessThan(0.000_001)
    ;
});
