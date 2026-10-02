<?php

declare(strict_types=1);

use SlopShape\Config;
use SlopShape\Exception\UnknownFeature;
use SlopShape\Instrument\CoreValue;
use SlopShape\Instrument\Feature;
use SlopShape\Instrument\FeatureType;
use SlopShape\Instrument\Leans;
use SlopShape\Reading\ReadBatch;
use SlopShape\Reading\Reading;
use SlopShape\Scoring\Scorer;
use SlopShape\Tests\Fixtures\Answers;

it('gives each direction its sign', function (): void {
    expect(Leans::Ai->sign())->toBe(1)
        ->and(Leans::Human->sign())->toBe(-1)
        ->and(Leans::Ai->isAi())->toBeTrue()
        ->and(Leans::Human->isAi())->toBeFalse()
    ;
});

it('says whether a core value leans AI', function (): void {
    expect((new CoreValue('F1__yes', 'F1', 'yes', Leans::Ai, 0.5))->leansAi())->toBeTrue()
        ->and((new CoreValue('F1__yes', 'F1', 'yes', Leans::Human, 0.5))->leansAi())->toBeFalse()
    ;
});

it('refuses a feature with no allowed values or an empty one', function (array $values, string $message): void {
    new Feature('F1', 'F1', '?', FeatureType::Binary, $values, '', '', '', '');
})->throws(InvalidArgumentException::class)->with([
    'none' => [[], 'no allowed values'],
    'empty' => [['yes', ''], 'empty allowed value'],
]);

it('lists the known ids when it names an unknown feature', function (): void {
    expect(UnknownFeature::forId('X', ['A', 'B'])->getMessage())->toBe('The instrument has no feature "X". Known ids: A, B.')
        ->and(UnknownFeature::forId('X')->getMessage())->toBe('The instrument has no feature "X".')
    ;
});

it('refuses a negative word floor', function (): void {
    new Config(readFloorWords: -1);
})->throws(InvalidArgumentException::class, 'Word floors must not be negative.');

it('refuses word floors that are the wrong way round', function (): void {
    new Config(readFloorWords: 900, judgementFloorWords: 500);
})->throws(InvalidArgumentException::class, 'must not exceed judgementFloorWords');

it('serialises a reading with every field the report needs', function (): void {
    $reading = new Reading('F1', ['a', 'b'], 0.9, 'a note', Reading::SOURCE_LLM, 'A', ['span']);

    expect($reading->toArray())->toBe([
        'feature_id' => 'F1',
        'value' => ['a', 'b'],
        'confidence' => 0.9,
        'source' => Reading::SOURCE_LLM,
        'snapped_from' => 'A',
        'note' => 'a note',
        'evidence' => ['span'],
    ]);
});

it('keeps the prompts a batch was built from', function (): void {
    $batch = new ReadBatch([], [], [], ['prompt one', 'prompt two']);

    expect($batch->prompts())->toBe(['prompt one', 'prompt two']);
});

it('serialises a factor to the same thing as toArray', function (): void {
    $factor = detectorFor(Answers::aiFlavoured())->detect(aiText())->factors[0];

    expect($factor->jsonSerialize())->toBe($factor->toArray())
        ->and(json_decode(json_encode($factor, \JSON_THROW_ON_ERROR), true))->toHaveKey('feature_id')
    ;
});

it('names the verdict with three predicates that agree with the label', function (): void {
    $ai = detectorFor(Answers::aiFlavoured())->detect(longEnoughText());
    $none = (new SlopShape\Detector(static fn (string $prompt): string => '{}', null, instrument()))->detect(aiText());

    expect($ai->isLikelyAi())->toBe(SlopShape\Label::LikelyAi === $ai->label)
        ->and($ai->isLikelyHuman())->toBe(SlopShape\Label::LikelyHuman === $ai->label)
        ->and($none->isInconclusive())->toBeTrue()
        ->and($none->isLikelyAi())->toBeFalse()
        ->and($none->isLikelyHuman())->toBeFalse()
    ;
});

it('exposes the model it scores with', function (): void {
    $scorer = new Scorer(instrument(), new Config());

    expect($scorer->model()->weight(0.5))->toBeGreaterThan(0.0);
});

it('swaps the bridge and the config on a copy, leaving the original alone', function (): void {
    $detector = detectorFor(Answers::aiFlavoured());
    $config = new Config(calibration: 0.3);

    $reconfigured = $detector->withConfig($config);
    $rebridged = $detector->withBridge(SlopShape\Tests\Fixtures\FakeBridge::answering(Answers::aiFlavoured()));

    expect($reconfigured->config())->toBe($config)
        ->and($detector->config())->not->toBe($config)
        ->and($rebridged)->not->toBe($detector)
        ->and($rebridged->config())->toBe($detector->config())
    ;
});
