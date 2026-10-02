<?php

declare(strict_types=1);

use SlopShape\Bridge\ClosureBridge;
use SlopShape\Bridge\RecordingBridge;
use SlopShape\Config;
use SlopShape\Detector;
use SlopShape\Exception\BridgeFailure;
use SlopShape\Instrument\FeatureType;
use SlopShape\Reading\LlmReader;
use SlopShape\Reading\PromptBuilder;
use SlopShape\Reading\ReadBatch;
use SlopShape\Reading\Reading;
use SlopShape\Reading\WordCountReader;
use SlopShape\Tests\Fixtures\Answers;
use SlopShape\Tests\Fixtures\FakeBridge;
use SlopShape\Text\Document;

it('sends a prompt the study would recognise', function (): void {
    $bridge = FakeBridge::answering(Answers::aiFlavoured());
    (new Detector($bridge, null, instrument()))->detect(aiText());

    $prompt = $bridge->lastPrompt();

    expect($prompt)
        ->toStartWith('You will annotate the B2B blog post below against fixed features.')
        ->toMatch('/<pasted_content id="[0-9a-f]{16}">/')
        ->toContain('In this guide, you\'ll learn how to move from a legacy, reactive data stack')
        ->toContain('Rules:')
        ->toContain('Judge only what is in the text.')
        ->toContain('Single-choice features (marked ONE)')
        ->toContain('Multi-select features (marked MANY)')
        ->toContain('never invent values, never omit a feature')
        ->toContain('Annotate the post above against these 8 features')
        ->toContain('Return ONLY a JSON object mapping EVERY feature id to its answer')
        ->toContain('All 8 ids must be present')
    ;
});

it('does not send the title', function (): void {
    $bridge = FakeBridge::answering(Answers::aiFlavoured());
    (new Detector($bridge, null, instrument()))->detect(aiText());

    $prompt = $bridge->lastPrompt();

    // SlopShape v3 found a title line moves some answers on its own.
    expect($prompt)->not->toContain('The 7-Step Guide to Modernizing Your Data Stack')
        ->and($prompt)->not->toContain('Title:')
        ->and($prompt)->not->toContain('Body:')
        ->and($prompt)->toContain('<pasted_content id="')
        ->and($prompt)->toContain('The Problem With Legacy Data Stacks')
    ;
});

it('sends a subtitle as the opening paragraph, unlabelled', function (): void {
    $bridge = FakeBridge::answering(Answers::aiFlavoured());
    (new Detector($bridge, null, instrument()))->detect(
        "# The Repair Log\n\nA text file for the things that broke this week\n\nTuesday, 14:10. The kettle.\n",
    );

    $prompt = $bridge->lastPrompt();

    expect($prompt)
        ->toMatch('/<pasted_content id="[0-9a-f]{16}">\nA text file for the things that broke this week\n\nTuesday, 14:10\. The kettle\.\n<\/pasted_content/')
        ->and($prompt)->not->toContain('The Repair Log')
        ->and($prompt)->not->toContain('Subtitle:')
    ;
});

it('puts the text in the block exactly as given', function (): void {
    expect((new PromptBuilder())->build(instrument(), 'Body text.', 'abc'))
        ->toContain("<pasted_content id=\"abc\">\nBody text.\n</pasted_content id=\"abc\">")
    ;
});

it('still measures the length class from the body alone', function (): void {
    $bridge = FakeBridge::answering(Answers::aiFlavoured());
    $score = (new Detector($bridge, null, instrument()))->detect(aiText());

    expect($score->wordCount)->toBe(Document::fromString(aiText())->wordCount);
});

it('prints every menu verbatim, so the model cannot answer off-menu by accident', function (): void {
    $bridge = FakeBridge::answering(Answers::aiFlavoured());
    (new Detector($bridge, null, instrument()))->detect(aiText());

    foreach (instrument()->promptFeatures() as $feature)
    {
        expect($bridge->lastPrompt())
            ->toContain($feature->promptLine())
            ->toContain(implode(' | ', $feature->values))
        ;
    }
});

it('reads only the features it asked about', function (): void {
    $readings = (new LlmReader(
        FakeBridge::answering(Answers::aiFlavoured()),
        new Config(),
    ))->read(instrument(), Document::fromString(aiText()));

    expect(array_keys($readings->readings()))->toBe(instrument()->promptFeatureIds());
});

it('ignores an answer for a feature it never asked about', function (): void {
    $answers = [...Answers::aiFlavoured(), 'STR_STG_999' => 'something'];

    $readings = (new LlmReader(
        FakeBridge::answering($answers),
        new Config(),
    ))->read(instrument(), Document::fromString(aiText()));

    expect($readings->readings())->not->toHaveKey('STR_STG_999')
        ->and($readings->count())->toBe(8)
    ;
});

it('treats an answer that matches no menu value as no reading, and says so', function (): void {
    $answers = Answers::aiFlavoured();
    // Too far from both menu values to be rescued by similarity. It used to be
    // read as the first value ("absent" here, whichever way that happened to lean).
    $answers['VOC_PRT_005'] = 'definitely, there is a demo link';

    $readings = (new LlmReader(
        FakeBridge::answering($answers),
        new Config(),
    ))->read(instrument(), Document::fromString(aiText()));

    expect($readings->offMenu())->toHaveCount(1)
        ->and($readings->offMenu()[0]->featureId)->toBe('VOC_PRT_005')
        ->and($readings->offMenu()[0]->given)->toBe('definitely, there is a demo link')
        ->and($readings->offMenu()[0]->snappedTo)->toBe('')
        ->and($readings->get('VOC_PRT_005'))->toBeNull()
        ->and($readings->missing())->toBe(['VOC_PRT_005'])
        ->and($readings->diagnostics()['off_menu'][0]['given'])->toBe('definitely, there is a demo link')
    ;
});

it('records a near-miss that snapped onto the menu', function (): void {
    $answers = Answers::aiFlavoured();
    $answers['VOC_PRT_005'] = 'Present';

    $readings = (new LlmReader(
        FakeBridge::answering($answers),
        new Config(),
    ))->read(instrument(), Document::fromString(aiText()));

    expect($readings->offMenu())->toHaveCount(1)
        ->and($readings->offMenu()[0]->given)->toBe('Present')
        ->and($readings->offMenu()[0]->snappedTo)->toBe('present')
        ->and($readings->get('VOC_PRT_005')?->value)->toBe('present')
        ->and($readings->get('VOC_PRT_005')?->snappedFrom)->toBe('Present')
        ->and($readings->missing())->toBe([])
    ;
});

it('does not read "yes" as an AI-leaning value on a present/absent feature', function (): void {
    $answers = [...Answers::aiFlavoured(), 'VOC_PRT_005' => 'yes'];

    $batch = (new LlmReader(FakeBridge::answering($answers), new Config()))
        ->read(instrument(), Document::fromString(aiText()))
    ;
    $score = detectorFor($answers)->detect(aiText());
    $factor = factorFor($score, 'VOC_PRT_005');

    expect($batch->get('VOC_PRT_005'))->toBeNull()
        ->and($factor->contribution)->toBe(0.0)
        ->and($factor->confidence)->toBe(0.0)
        ->and($factor->observed)->toBe([])
    ;
});

it('does not let an all-"unknown" answer score as AI', function (): void {
    $answers = array_map(static fn (): string => 'unknown', Answers::aiFlavoured());

    $batch = detectorFor($answers)->readings(Document::fromString(aiText()));
    $score = detectorFor($answers)->detect(aiText());

    expect($batch->missing())->toBe(instrument()->promptFeatureIds())
        ->and($batch->offMenu())->toHaveCount(8)
        ->and($score->probabilityAi)->toBeLessThan(0.5)
    ;
});

it('records every off-menu item of a multi-select answer, with its own match', function (): void {
    $answers = [
        ...Answers::aiFlavoured(),
        'STR_STG_001' => ['context', 'Problem Diagnosis', 'Summary Or Synthesis', 'no such stage at all'],
    ];

    $batch = (new LlmReader(FakeBridge::answering($answers), new Config()))
        ->read(instrument(), Document::fromString(aiText()))
    ;
    $stages = array_values(array_filter(
        $batch->offMenu(),
        static fn (SlopShape\Reading\OffMenuAnswer $answer): bool => 'STR_STG_001' === $answer->featureId,
    ));

    expect($batch->get('STR_STG_001')?->value)->toBe(['context', 'problem_diagnosis', 'summary_or_synthesis'])
        ->and($stages)->toHaveCount(3)
        ->and($stages[0]->given)->toBe('Problem Diagnosis')
        ->and($stages[0]->snappedTo)->toBe('problem_diagnosis')
        ->and($stages[1]->given)->toBe('Summary Or Synthesis')
        ->and($stages[1]->snappedTo)->toBe('summary_or_synthesis')
        ->and($stages[2]->given)->toBe('no such stage at all')
        ->and($stages[2]->snappedTo)->toBe('')
    ;
});

it('treats a multi-select whose items all match nothing as no reading', function (): void {
    $answers = [...Answers::aiFlavoured(), 'STR_STG_001' => ['zzz', 'qqq']];

    $batch = (new LlmReader(FakeBridge::answering($answers), new Config()))
        ->read(instrument(), Document::fromString(aiText()))
    ;

    expect($batch->get('STR_STG_001'))->toBeNull()
        ->and($batch->missing())->toBe(['STR_STG_001'])
    ;
});

it('trusts a model reading to the configured confidence', function (): void {
    $readings = (new LlmReader(
        FakeBridge::answering(Answers::aiFlavoured()),
        new Config(readingConfidence: 0.75),
    ))->read(instrument(), Document::fromString(aiText()));

    foreach ($readings->readings() as $reading)
    {
        expect($reading->confidence)->toBe(0.75);
    }
});

it('does not call the bridge when there is nothing to ask about', function (): void {
    $bridge = FakeBridge::answering([]);
    $json = json_encode([
        'features' => [[
            'id' => 'PAG_FUR_011',
            'name' => 'Article Length Class',
            'question' => 'What word-count class?',
            'type' => 'categorical',
            'values' => ['short_under_800_words', 'medium_800_to_1499_words'],
            'measured_by_tool' => true,
        ]],
        'core_values' => [[
            'column' => 'PAG_FUR_011__short_under_800_words',
            'feature_id' => 'PAG_FUR_011',
            'value' => 'short_under_800_words',
            'leans' => 'human',
            'gap' => 0.274,
        ]],
    ], \JSON_THROW_ON_ERROR);

    $allMeasured = SlopShape\Instrument\Instrument::fromJson($json);
    $batch = (new LlmReader($bridge, new Config()))
        ->read($allMeasured, Document::fromString('Some prose.'))
    ;

    expect($allMeasured->promptFeatures())->toBe([])
        ->and($allMeasured->measuredFeatures())->toHaveCount(1)
        ->and($batch->isEmpty())->toBeTrue()
        ->and($bridge->calls())->toBe(0)
    ;
});

it('notes an empty multi-select rather than hiding it', function (): void {
    $answers = [...Answers::aiFlavoured(), 'STR_STG_001' => []];

    $reading = (new LlmReader(
        FakeBridge::answering($answers),
        new Config(),
    ))->read(instrument(), Document::fromString(aiText()))->get('STR_STG_001');

    expect($reading?->value)->toBe([])
        ->and($reading?->note)->toContain('empty selection')
    ;
});

it('copes with a model that answers "yes" for a many-valued feature', function (): void {
    $answers = [...Answers::aiFlavoured(), 'STR_STG_001' => 'summary_or_synthesis'];

    $reading = (new LlmReader(
        FakeBridge::answering($answers),
        new Config(),
    ))->read(instrument(), Document::fromString(aiText()))->get('STR_STG_001');

    // A single string for a MANY feature still has to read as a list.
    expect($reading?->value)->toBe(['summary_or_synthesis'])
        ->and($reading?->has('summary_or_synthesis'))->toBeTrue()
    ;
});

/**
 * The batch a reader produces from one raw response string.
 */
function readRaw(string $raw): ReadBatch
{
    return (new LlmReader(new FakeBridge([$raw]), new Config()))
        ->read(instrument(), Document::fromString(aiText()))
    ;
}

it('does not choke on braces inside string values when finding the object', function (): void {
    $plain = readRaw(json_encode(Answers::aiFlavoured(), \JSON_THROW_ON_ERROR));
    $withBraces = readRaw(sprintf(
        'prose %s trailing prose',
        json_encode([...Answers::aiFlavoured(), 'NOTE' => 'a value with { braces } and " quotes'], \JSON_THROW_ON_ERROR),
    ));

    // Every one of the eight answers is read exactly as without the noise.
    expect($withBraces->readings())->toEqual($plain->readings())
        ->and($withBraces->missing())->toBe([])
    ;
});

it('finds the object behind a preamble with curly quotes and an em dash', function (): void {
    $json = json_encode(Answers::aiFlavoured(), \JSON_THROW_ON_ERROR);

    $batch = readRaw("Voilà — here’s the answer “as requested”: {$json}\n\nHope that helps — enjoy.");

    expect($batch->missing())->toBe([])
        ->and($batch->count())->toBe(8)
        ->and($batch->get('VOC_CRD_001')?->value)->toBe('confident_institutional_explanation')
    ;
});

it('reads non-ASCII text inside JSON values', function (): void {
    $answers = [
        ...Answers::aiFlavoured(),
        'NOTE' => 'café — “naïve” 構造 {ok}',
        'STR_STG_008' => 'restated thesis or reframe — “final”',
    ];

    $batch = readRaw('Résumé: ' . json_encode($answers, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE) . ' — fin');

    expect($batch->count())->toBe(8)
        ->and($batch->get('STR_STG_008')?->snappedFrom)->toBe('restated thesis or reframe — “final”')
    ;
});

it('picks the answer over an echoed example that comes first', function (): void {
    $answer = json_encode(Answers::aiFlavoured(), \JSON_THROW_ON_ERROR);
    $example = '{"VOC_CRD_001": "firsthand_lived_or_operational_experience"}';

    $batch = readRaw("The post quoted {$example} but my answer is:\n{$answer}");

    expect($batch->get('VOC_CRD_001')?->value)->toBe('confident_institutional_explanation')
        ->and($batch->count())->toBe(8)
    ;
});

it('picks the answer over a quoted object that comes after it', function (): void {
    $answer = json_encode(Answers::aiFlavoured(), \JSON_THROW_ON_ERROR);

    $batch = readRaw("{$answer}\n\nFor reference the post contains {\"VOC_CRD_001\": \"regulatory_or_standards_authority\"} verbatim.");

    expect($batch->get('VOC_CRD_001')?->value)->toBe('confident_institutional_explanation')
        ->and($batch->count())->toBe(8)
    ;
});

it('breaks a tie between equally complete objects in favour of the last one', function (): void {
    $first = json_encode([...Answers::aiFlavoured(), 'VOC_CRD_001' => 'external_research_or_data'], \JSON_THROW_ON_ERROR);
    $last = json_encode(Answers::aiFlavoured(), \JSON_THROW_ON_ERROR);

    $batch = readRaw("Draft: {$first}\nFinal: {$last}");

    expect($batch->get('VOC_CRD_001')?->value)->toBe('confident_institutional_explanation');
});

it('is not fooled by a stray opening brace in the preamble', function (): void {
    $json = json_encode(Answers::aiFlavoured(), \JSON_THROW_ON_ERROR);

    $batch = readRaw("Use { to open an object. {$json}");

    expect($batch->count())->toBe(8);
});

it('measures the length class from the body, not the title', function (): void {
    $text = "# A Very Long Title That Itself Runs On And On And On For Rather A While Indeed\n\n"
        . str_repeat('The kettle has stopped boiling properly and I have logged it four times. ', 100);

    $readings = (new WordCountReader())->read(instrument(), Document::fromString($text));

    expect(Document::fromString($text)->wordCount)->toBeGreaterThan(800)
        ->and($readings[0]->value)->toBe('medium_800_to_1499_words')
    ;
});

it('puts a tool reading apart from a model reading', function (): void {
    $readings = (new WordCountReader())->read(instrument(), Document::fromString(aiText()));

    foreach ($readings as $reading)
    {
        expect($reading->source)->toBe(Reading::SOURCE_TOOL)
            ->and($reading->isFromModel())->toBeFalse()
            ->and($reading->featureId)->toBe('PAG_FUR_011')
            ->and($reading->note)->toContain('words in the article body')
        ;
    }
});

it('keeps a batch immutable while letting a caller add to it', function (): void {
    $batch = new ReadBatch();
    $withOne = $batch->with(new Reading('F1', 'yes', 0.9));

    expect($batch->isEmpty())->toBeTrue()
        ->and($batch->count())->toBe(0)
        ->and($withOne->count())->toBe(1)
        ->and($withOne->get('F1'))->not->toBeNull()
        ->and($withOne->get('F2'))->toBeNull()
        ->and($withOne->without('F1')->isEmpty())->toBeTrue()
    ;
});

it('records every exchange for the audit trail', function (): void {
    $recorder = new RecordingBridge(FakeBridge::answering(Answers::aiFlavoured()));
    $detector = new Detector($recorder, null, instrument());

    $detector->detect(aiText());
    $detector->detect(aiText());

    expect($recorder->calls())->toBe(2)
        ->and($recorder->prompts())->toHaveCount(2)
        ->and($recorder->exchanges()[0]->failed())->toBeFalse()
        ->and($recorder->exchanges()[0]->response)->toContain('VOC_CRD_001')
    ;

    $recorder->reset();

    expect($recorder->calls())->toBe(0);
});

it('records a failure and lets it through unchanged', function (): void {
    $recorder = new RecordingBridge(FakeBridge::failing());

    try
    {
        (new Detector($recorder, null, instrument()))->detect(aiText());
    } catch (RuntimeException)
    {
        // expected
    }

    expect($recorder->calls())->toBe(1)
        ->and($recorder->exchanges()[0]->failed())->toBeTrue()
    ;
});

it('names a closure bridge in its error message', function (): void {
    $detector = new Detector(
        new ClosureBridge(static fn (): string => throw new RuntimeException('boom'), 'the OpenAI client'),
        null,
        instrument(),
    );

    $detector->detect(aiText());
})->throws(BridgeFailure::class, 'The OpenAI client threw');

it('lists the values a feature may be answered with', function (): void {
    expect(instrument()->feature('VOC_PRT_005')->values)->toBe(['absent', 'present'])
        ->and(instrument()->feature('VOC_PRT_005')->hasValue('present'))->toBeTrue()
        ->and(instrument()->feature('VOC_PRT_005')->hasValue('maybe'))->toBeFalse()
        ->and(instrument()->feature('VOC_PRT_005')->firstValue())->toBe('absent')
        ->and(instrument()->feature('VOC_PRT_005')->type->isMultiSelect())->toBeFalse()
        ->and(instrument()->feature('STR_STG_001')->type)->toBe(FeatureType::MultiSelect)
    ;
});
