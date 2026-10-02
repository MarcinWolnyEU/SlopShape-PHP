<?php

declare(strict_types=1);

use SlopShape\Config;
use SlopShape\Detector;
use SlopShape\Factor;
use SlopShape\Instrument\Instrument;
use SlopShape\Label;
use SlopShape\Score;

arch('the public surface is small')
    ->expect('SlopShape')
    ->classes()
    ->toBeClasses([
        Detector::class,
        Score::class,
        Factor::class,
        Config::class,
    ])
;

arch('the root namespace holds one enum, and it is a label')
    ->expect('SlopShape')
    ->enums()
    ->toBeEnums([Label::class])
;

arch('the enums are backed by the strings the release and the wire format use')
    ->expect([Label::class, SlopShape\Instrument\Leans::class, SlopShape\Instrument\FeatureType::class])
    ->toBeEnums()
;

arch('nothing depends on a framework')
    ->expect('SlopShape')
    ->not->toUse(['Illuminate', 'Symfony', 'Psr', 'Doctrine', 'Monolog', 'React', 'PHPUnit', 'Pest', 'Monolog'])
;

arch('the library reaches for nothing outside the language and itself')
    ->expect('SlopShape')
    ->not->toUse(['ast', 'composer', 'libxml'])
;

arch('every class is final, so extending this is a deliberate act')
    ->expect('SlopShape')
    ->classes()
    ->toBeFinal()
;

arch('every value object is immutable')
    ->expect([
        Score::class,
        Factor::class,
        Config::class,
        SlopShape\Reading\Reading::class,
        SlopShape\Instrument\Feature::class,
        SlopShape\Instrument\CoreValue::class,
    ])
    ->toBeReadonly()
;

arch('the bridge is an interface, so any model can be plugged in')
    ->expect(SlopShape\Bridge\LlmBridge::class)
    ->toBeInterface()
;

arch('the instrument is loaded once and shared, not re-read per document')
    ->expect(Instrument::class)
    ->toHaveMethod('bundled')
    ->toHaveMethod('fromFile')
    ->toHaveMethod('fromJson')
;

arch('the detector\'s public surface is the whole API')
    ->expect(Detector::class)
    ->toHaveMethods([
        'detect',
        'probabilityAi',
        'withConfig',
        'withBridge',
        'config',
        'instrument',
        'bridge',
        'readings',
    ])
;

arch('the label enum refuses more often than it commits')
    ->expect(Label::class)
    ->toHaveMethods(['isInconclusive', 'refusal'])
;
