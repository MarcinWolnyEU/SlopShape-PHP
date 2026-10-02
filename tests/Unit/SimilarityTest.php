<?php

declare(strict_types=1);

use SlopShape\Internal\Similarity;

it('scores identical strings as fully similar', function (): void {
    expect(Similarity::ratio('summary', 'summary'))->toBe(1.0)
        ->and(Similarity::ratio('Summary', '  summary '))->toBe(1.0)
        ->and(Similarity::distance('summary', 'summary'))->toBe(0)
    ;
});

it('treats an empty string as similar to nothing', function (): void {
    expect(Similarity::ratio('', 'summary'))->toBe(0.0)
        ->and(Similarity::ratio('summary', ''))->toBe(0.0)
    ;
});

it('measures single edits as the inverse of the edit distance over length', function (): void {
    expect(Similarity::distance('summary', 'summaries'))->toBe(3)
        ->and(Similarity::distance('abc', ''))->toBe(3)
        ->and(Similarity::distance('', 'abc'))->toBe(3)
    ;
});

it('is case-insensitive, because menu values are snake_case and answers are not', function (): void {
    expect(Similarity::ratio('ABSENT', 'absent'))->toBe(1.0)
        ->and(Similarity::distance('Absent', 'absent'))->toBe(0)
    ;
});

it('orders candidates by similarity so the nearest menu value wins', function (): void {
    $scores = [
        'absent' => Similarity::ratio('absent', 'absent'),
        'present' => Similarity::ratio('absent', 'present'),
        'first_paragraph' => Similarity::ratio('absent', 'first_paragraph'),
    ];

    arsort($scores);

    expect(array_key_first($scores))->toBe('absent');
});

it('handles multi-byte strings without corrupting them', function (string $a, string $b, int $expected): void {
    expect(Similarity::distance($a, $b))->toBe($expected);
})->with([
    'identical japanese' => ['構造', '構造', 0],
    'one japanese char apart' => ['構造', '構型', 1],
    'japanese to latin' => ['構造', 'abc', 3],
]);

it('finishes quickly on a very long answer', function (): void {
    $answer = str_repeat('the model wandered off into prose ', 600); // about 20k characters
    $start = microtime(true);

    $short = Similarity::ratio($answer, 'summary_of_main_points');
    $long = Similarity::ratio($answer, str_repeat('x', 5_000));

    expect(microtime(true) - $start)->toBeLessThan(1.0)
        ->and($short)->toBeLessThan(Similarity::CUTOFF)
        ->and($long)->toBeLessThan(Similarity::CUTOFF)
    ;
});

it('compares only the first characters of a long string', function (): void {
    $prefix = str_repeat('a', Similarity::MAX_LENGTH);

    expect(Similarity::ratio($prefix . 'bbbbbbbb', $prefix . 'cccccccc'))->toBe(1.0);
});

it('does not run the distance when the lengths already rule out a match', function (): void {
    // A 4-character string cannot be 60% similar to a 40-character one.
    expect(Similarity::ratio('abcd', str_repeat('abcd', 10)))->toBe(0.1);
});

it('measures multi-byte input by character, not by byte', function (): void {
    expect(Similarity::distance('naïve café', 'naive cafe'))->toBe(2)
        ->and(Similarity::ratio('構造の説明', '構造の設明'))->toBe(0.8)
    ;
});
