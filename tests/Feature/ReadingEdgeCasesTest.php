<?php

declare(strict_types=1);

use SlopShape\Bridge\RecordingBridge;
use SlopShape\Detector;
use SlopShape\Exception\UnreadableResponse;
use SlopShape\Instrument\Instrument;
use SlopShape\Reading\WordCountReader;
use SlopShape\Tests\Fixtures\Answers;
use SlopShape\Tests\Fixtures\FakeBridge;
use SlopShape\Text\Document;

/**
 * A detector whose model always replies with $raw.
 */
function replyingWith(string $raw): Detector
{
    return new Detector(static fn (string $prompt): string => $raw, null, instrument());
}

function goodAnswer(): string
{
    return json_encode(Answers::aiFlavoured(), \JSON_THROW_ON_ERROR);
}

it('records what the model said next to what it was asked', function (): void {
    $recorder = new RecordingBridge(FakeBridge::answering(Answers::aiFlavoured()));
    (new Detector($recorder, null, instrument()))->detect(aiText());

    expect($recorder->calls())->toBe(1)
        ->and($recorder->prompts())->toHaveCount(1)
        ->and($recorder->responses())->toBe([goodAnswer()])
    ;
});

it('finds the answer after a few stray braces in the preamble', function (): void {
    $batch = replyingWith(str_repeat('{ ', 10) . goodAnswer())->readings(Document::fromString(aiText()));

    expect($batch->missing())->toBe([])
        ->and($batch->get('VOC_CRD_001'))->not->toBeNull()
    ;
});

it('gives up on a wall of unbalanced braces instead of rescanning it forever', function (): void {
    replyingWith(str_repeat('{ ', 200) . goodAnswer())->readings(Document::fromString(aiText()));
})->throws(UnreadableResponse::class);

it('skips a balanced brace pair that is not valid JSON', function (): void {
    $batch = replyingWith('Draft: {"VOC_CRD_001": } and then ' . goodAnswer())->readings(Document::fromString(aiText()));

    expect($batch->missing())->toBe([]);
});

it('skips a JSON object whose keys look like a list', function (): void {
    $batch = replyingWith('{"0": "title", "1": "no"} ' . goodAnswer())->readings(Document::fromString(aiText()));

    expect($batch->missing())->toBe([]);
});

it('reads a bare non-string answer as its JSON text, and reports it as off-menu', function (): void {
    $answers = [
        ...Answers::aiFlavoured(),
        'STR_FLW_005' => true,
        'STR_FLW_006' => false,
        'VOC_CRD_001' => 42,
        'STR_STG_008' => null,
        'VOC_PRT_005' => ['nested' => 1],
    ];

    $batch = replyingWith(json_encode($answers, \JSON_THROW_ON_ERROR))->readings(Document::fromString(aiText()));

    $given = [];

    foreach ($batch->offMenu() as $answer)
    {
        $given[$answer->featureId] = $answer->given;
    }

    expect($given)->toMatchArray([
        'STR_FLW_005' => 'true',
        'STR_FLW_006' => 'false',
        'VOC_CRD_001' => '42',
        'STR_STG_008' => 'null',
        'VOC_PRT_005' => '{"nested":1}',
    ])
        ->and($batch->missing())->toContain('STR_FLW_005', 'VOC_CRD_001', 'STR_STG_008', 'VOC_PRT_005')
    ;
});

/**
 * @param list<string> $values
 */
function measuredInstrument(array $values): Instrument
{
    return Instrument::fromArray([
        'features' => [[
            'id' => 'LEN', 'name' => 'Length', 'question' => '?', 'type' => 'categorical',
            'values' => $values, 'measured_by_tool' => true,
        ]],
        'core_values' => [[
            'column' => 'LEN__' . $values[0], 'feature_id' => 'LEN', 'value' => $values[0],
            'leans' => 'ai', 'gap' => 0.5,
        ]],
    ], 'measured.json');
}

it('reads a length class out of whatever numbers the value names carry', function (array $values, int $words, string $expected): void {
    $document = Document::fromString(implode(' ', array_fill(0, $words, 'word')));

    $readings = (new WordCountReader())->read(measuredInstrument($values), $document);

    expect($readings[0]->value)->toBe($expected);
})->with([
    'an open-ended top band' => [['short_under_100_words', 'huge_3000_or_more_words'], 3_500, 'huge_3000_or_more_words'],
    'a bare number is a lower bound' => [['big_50', 'tiny_5'], 60, 'big_50'],
    'no number at all matches everything' => [['whatever', 'other'], 10, 'whatever'],
    'nothing matches, so the first value stands' => [['big_50', 'tiny_5'], 3, 'big_50'],
]);

/**
 * The off-menu answers of a batch, as feature id => list of what was given.
 *
 * @return array<string, list<string>>
 */
function offMenuGiven(SlopShape\Reading\ReadBatch $batch): array
{
    $given = [];

    foreach ($batch->offMenu() as $answer)
    {
        $given[$answer->featureId][] = $answer->given;
    }

    return $given;
}

it('reports a multi-select made only of non-strings as missing and off-menu, not as an empty selection', function (): void {
    $answers = [...Answers::aiFlavoured(), 'TIM_CUE_003' => [1.5, true, null]];

    $batch = replyingWith(json_encode($answers, \JSON_THROW_ON_ERROR))->readings(Document::fromString(aiText()));

    expect($batch->get('TIM_CUE_003'))->toBeNull()
        ->and($batch->missing())->toBe(['TIM_CUE_003'])
        ->and(offMenuGiven($batch))->toBe(['TIM_CUE_003' => ['1.5', 'true', 'null']])
    ;
});

it('reports a multi-select answered with an object as one garbled answer', function (): void {
    $answers = [...Answers::aiFlavoured(), 'TIM_CUE_011' => ['nested' => 1]];

    $batch = replyingWith(json_encode($answers, \JSON_THROW_ON_ERROR))->readings(Document::fromString(aiText()));

    expect($batch->get('TIM_CUE_011'))->toBeNull()
        ->and($batch->missing())->toBe(['TIM_CUE_011'])
        ->and(offMenuGiven($batch))->toBe(['TIM_CUE_011' => ['{"nested":1}']])
    ;
});

it('reports a multi-select answered with a bare number as one garbled answer', function (): void {
    $answers = [...Answers::aiFlavoured(), 'TIM_CUE_011' => 42];

    $batch = replyingWith(json_encode($answers, \JSON_THROW_ON_ERROR))->readings(Document::fromString(aiText()));

    expect($batch->missing())->toBe(['TIM_CUE_011'])
        ->and(offMenuGiven($batch))->toBe(['TIM_CUE_011' => ['42']])
    ;
});

it('keeps the good items of a multi-select and reports the garbled ones', function (): void {
    $answers = [...Answers::aiFlavoured(), 'STR_STG_001' => ['summary_or_synthesis', 42, ['x']]];

    $batch = replyingWith(json_encode($answers, \JSON_THROW_ON_ERROR))->readings(Document::fromString(aiText()));

    expect($batch->get('STR_STG_001')?->value)->toBe(['summary_or_synthesis'])
        ->and($batch->missing())->toBe([])
        ->and(offMenuGiven($batch))->toBe(['STR_STG_001' => ['42', '["x"]']])
    ;
});

it('counts an empty multi-select as a reading under which no marker fires', function (): void {
    $answers = [...Answers::aiFlavoured(), 'STR_STG_001' => []];

    $score = replyingWith(json_encode($answers, \JSON_THROW_ON_ERROR))->detect(aiText());

    $stages = array_values(array_filter(
        $score->allFactors(),
        static fn (SlopShape\Factor $factor): bool => 'STR_STG_001' === $factor->featureId,
    ));

    // Both of the feature's core values were read, and neither fired.
    expect($stages)->toHaveCount(2)
        ->and($stages[0]->read)->toBeTrue()
        ->and($stages[0]->contribution)->toBe(0.0)
        ->and($stages[0]->observed)->toBe([])
        ->and($stages[0]->note)->toBe('the model returned an empty selection')
        ->and($stages[1]->read)->toBeTrue()
        ->and($score->coreValuesCovered)->toBe(10)
    ;
});

it('does not count a garbled multi-select as read', function (): void {
    $answers = [...Answers::aiFlavoured(), 'STR_STG_001' => [1, 2]];

    $score = replyingWith(json_encode($answers, \JSON_THROW_ON_ERROR))->detect(aiText());

    expect($score->coreValuesCovered)->toBe(8);
});

it('still treats an empty answer to a single-choice feature as missing', function (): void {
    $answers = [...Answers::aiFlavoured(), 'STR_STG_008' => []];

    $score = replyingWith(json_encode($answers, \JSON_THROW_ON_ERROR))->detect(aiText());

    expect($score->coreValuesCovered)->toBe(9);
});
