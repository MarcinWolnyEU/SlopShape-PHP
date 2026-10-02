<?php

declare(strict_types=1);

use SlopShape\Instrument\Feature;
use SlopShape\Instrument\FeatureType;
use SlopShape\Reading\Menu;

/**
 * A realistic menu: menu values in this instrument are long snake_case strings,
 * and a test against "alpha"/"beta"/"gamma" would not exercise the cases that
 * actually occur.
 *
 * @param list<string> $values
 */
function menuFeature(array $values, FeatureType $type = FeatureType::Categorical): Feature
{
    return new Feature(
        id: 'STR_STG_008',
        name: 'Conclusion Behavior',
        question: 'How does the article primarily end?',
        type: $type,
        values: $values,
        detectionMethod: 'Classify the final section by dominant function.',
        dimension: 'structure_and_flow',
        dimensionLabel: 'Structure',
        aspect: 'functional_stages',
    );
}

function conclusionMenu(): Menu
{
    return Menu::for(menuFeature([
        'summary_of_main_points',
        'restated_thesis_or_reframe',
        'final_action_plan',
        'product_or_contact_cta',
    ]));
}

function binaryMenu(): Menu
{
    return Menu::for(menuFeature(['absent', 'present'], FeatureType::Binary));
}

function stageMenu(): Menu
{
    return Menu::for(menuFeature(['context', 'problem_diagnosis', 'summary_or_synthesis'], FeatureType::MultiSelect));
}

it('keeps an answer that is already on the menu untouched', function (): void {
    [$value, $snapped] = conclusionMenu()->snap('final_action_plan');

    expect($value)->toBe('final_action_plan')
        ->and($snapped)->toBeNull()
    ;
});

it('recovers an answer that only differs in case, spacing or separators', function (string $given): void {
    [$value] = conclusionMenu()->snap($given);

    expect($value)->toBe('restated_thesis_or_reframe');
})->with([
    'capitalised' => 'Restated Thesis Or Reframe',
    'spaced' => 'restated thesis or reframe',
    'hyphenated' => 'restated-thesis-or-reframe',
    'shouty' => 'RESTATED_THESIS_OR_REFRAME',
    'double spaced' => 'restated__thesis__or__reframe',
    'mixed' => 'Restated_Thesis_Or_Reframe',
]);

it('trims before comparing, so a padded answer is a clean match rather than a repair', function (): void {
    [$value, $snapped] = conclusionMenu()->snap('   restated_thesis_or_reframe   ');

    expect($value)->toBe('restated_thesis_or_reframe')
        ->and($snapped)->toBeNull()
    ;
});

it('records a repair only when the answer was not already on the menu', function (): void {
    [, $exact] = conclusionMenu()->snap('final_action_plan');
    [, $repaired] = conclusionMenu()->snap('Final_Action-Plan');

    expect($exact)->toBeNull()
        ->and($repaired)->toBe('Final_Action-Plan')
    ;
});

it('snaps a paraphrase onto the nearest menu value and remembers the original', function (): void {
    // The separators are missing, which normalisation alone cannot repair, so
    // this is the similarity cutoff doing the work.
    [$value, $snapped] = conclusionMenu()->snap('restatedthesisreframe');

    expect($value)->toBe('restated_thesis_or_reframe')
        ->and($snapped)->toBe('restatedthesisreframe')
    ;
});

it('returns no value when nothing is close enough, rather than the first menu value', function (): void {
    [$value, $snapped] = conclusionMenu()->snap('it just sort of stops');

    expect($value)->toBeNull()
        ->and($snapped)->toBe('it just sort of stops')
    ;
});

it('never turns an unusable answer into the first menu value, which may lean towards AI', function (): void {
    // "absent" is the first value on this menu. An answer like this one must
    // not be read as "absent"; it is no reading at all.
    [$value, $snapped] = binaryMenu()->snap('a bit, intermittently, sometimes');

    expect($value)->toBeNull()
        ->and($snapped)->toBe('a bit, intermittently, sometimes')
    ;
});

it('does not resolve "yes", "unknown" or a blank answer onto a present/absent menu', function (string $given): void {
    expect(binaryMenu()->snap($given)[0])->toBeNull();
})->with(['yes', 'unknown', 'n/a', '', '   ']);

it('still snaps a typo onto the nearest menu value', function (): void {
    expect(binaryMenu()->snap('presnt')[0])->toBe('present')
        ->and(conclusionMenu()->snap('final_acton_plan')[0])->toBe('final_action_plan')
    ;
});

it('drops unmatched items from a multi-select answer and reports each item on its own', function (): void {
    $menu = stageMenu();

    expect($menu->snapAll(['context', 'zzzzzzzz']))->toBe(['context']);

    expect($menu->snapEach(['context', 'Problem Diagnosis', 'zzzzzzzz']))->toBe([
        ['given' => 'context', 'value' => 'context', 'repaired' => false],
        ['given' => 'Problem Diagnosis', 'value' => 'problem_diagnosis', 'repaired' => true],
        ['given' => 'zzzzzzzz', 'value' => null, 'repaired' => true],
    ]);
});

it('keeps a multi-select answer a list, so presence can be asked about', function (): void {
    $values = stageMenu()->snapAll(['summary_or_synthesis']);

    expect($values)->toBe(['summary_or_synthesis'])
        ->and(in_array('summary_or_synthesis', $values, true))->toBeTrue()
    ;
});

it('honours an empty multi-select answer', function (): void {
    expect(stageMenu()->snapAll([]))->toBe([]);
});

it('drops anything that is not a string from a multi-select answer', function (): void {
    expect(stageMenu()->snapAll(['context', 42, null, ['nested'], true]))
        ->toBe(['context'])
    ;
});

it('de-duplicates a multi-select answer so a repetition cannot inflate a factor', function (): void {
    expect(stageMenu()->snapAll(['context', 'CONTEXT', 'context', 'Context']))
        ->toBe(['context'])
    ;
});

it('preserves the order a model gave the stages in', function (): void {
    expect(stageMenu()->snapAll(['summary_or_synthesis', 'context', 'problem_diagnosis']))
        ->toBe(['summary_or_synthesis', 'context', 'problem_diagnosis'])
    ;
});

it('reports what it can answer', function (): void {
    $menu = conclusionMenu();

    expect($menu->has('final_action_plan'))->toBeTrue()
        ->and($menu->has('abrupt_end_without_conclusion'))->toBeFalse()
        ->and($menu->feature()->id)->toBe('STR_STG_008')
    ;
});
