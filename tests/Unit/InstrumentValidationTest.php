<?php

declare(strict_types=1);

use SlopShape\Exception\InstrumentUnavailable;
use SlopShape\Instrument\Instrument;

/**
 * @return array<string, mixed>
 */
function miniFeature(string $id = 'F1'): array
{
    return [
        'id' => $id, 'name' => $id, 'question' => '?', 'type' => 'binary',
        'values' => ['yes', 'no'], 'measured_by_tool' => false,
    ];
}

/**
 * @return array<string, mixed>
 */
function miniCoreValue(string $featureId = 'F1', string $value = 'yes'): array
{
    return [
        'column' => $featureId . '__' . $value, 'feature_id' => $featureId, 'value' => $value,
        'leans' => 'ai', 'gap' => 0.5,
    ];
}

/**
 * @param list<mixed>|null $features
 */
function miniInstrumentJson(?array $features = null, mixed $coreValues = null): string
{
    return json_encode([
        'features' => $features ?? [miniFeature()],
        'core_values' => $coreValues ?? [miniCoreValue()],
    ], \JSON_THROW_ON_ERROR);
}

it('accepts the smallest well-formed instrument, so the refusals below are about their one defect', function (): void {
    $instrument = Instrument::fromJson(miniInstrumentJson(), 'mini.json');

    expect($instrument->count())->toBe(1);
});

it('refuses instrument JSON whose top level is not an object', function (): void {
    Instrument::fromJson('"just a string"', 'scalar.json');
})->throws(InstrumentUnavailable::class, 'the top level is not an object');

it('refuses a feature row that is not an object', function (): void {
    Instrument::fromJson(miniInstrumentJson(['nope']), 'row.json');
})->throws(InstrumentUnavailable::class, 'features[0] is not an object');

it('refuses a feature without an id', function (): void {
    Instrument::fromJson(miniInstrumentJson([[...miniFeature(), 'id' => '']]), 'noid.json');
})->throws(InstrumentUnavailable::class, 'has no id');

it('refuses a repeated feature id', function (): void {
    Instrument::fromJson(miniInstrumentJson([miniFeature(), miniFeature()]), 'dup.json');
})->throws(InstrumentUnavailable::class, 'repeats the feature id "F1"');

it('refuses an instrument with no core values array', function (): void {
    Instrument::fromJson(miniInstrumentJson(null, []), 'nocv.json');
})->throws(InstrumentUnavailable::class, 'no "core_values" array');

it('refuses a core value row that is not an object', function (): void {
    Instrument::fromJson(miniInstrumentJson(null, ['nope']), 'cvrow.json');
})->throws(InstrumentUnavailable::class, 'core_values[0] is not an object');

it('refuses a core value with no feature id or no value', function (array $override): void {
    Instrument::fromJson(miniInstrumentJson(null, [[...miniCoreValue(), ...$override]]), 'cvempty.json');
})->throws(InstrumentUnavailable::class, 'has no feature id or value')->with([
    'no feature id' => [['feature_id' => '']],
    'no value' => [['value' => '']],
]);

it('refuses a core value repeated for the same feature and value', function (): void {
    Instrument::fromJson(miniInstrumentJson(null, [miniCoreValue(), miniCoreValue()]), 'cvdup.json');
})->throws(InstrumentUnavailable::class);

it('refuses a core value whose gap is not a finite number', function (mixed $gap): void {
    Instrument::fromJson(miniInstrumentJson(null, [[...miniCoreValue(), 'gap' => $gap]]), 'gap.json');
})->throws(InstrumentUnavailable::class, 'non-numeric gap')->with([
    'a string' => ['wide'],
    'null' => [null],
]);

it('refuses a file that cannot be read as a file', function (): void {
    Instrument::fromFile(sys_get_temp_dir());
})->throws(InstrumentUnavailable::class, 'could not be read');

it('loads an instrument from a file on disk', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'inst');
    file_put_contents($path, miniInstrumentJson());

    try
    {
        expect(Instrument::fromFile($path)->count())->toBe(1);
    } finally
    {
        unlink($path);
    }
});
