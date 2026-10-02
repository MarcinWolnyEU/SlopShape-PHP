<?php

declare(strict_types=1);

use SlopShape\Exception\PcreFailure;
use SlopShape\Text\Pcre;

/**
 * Run $work with a backtrack limit so low that a nested quantifier trips it.
 *
 * @param Closure(): mixed $work
 */
function withTinyBacktrackLimit(Closure $work): mixed
{
    ini_set('pcre.backtrack_limit', '10');
    ini_set('pcre.jit', '0');

    try
    {
        return $work();
    } finally
    {
        ini_restore('pcre.backtrack_limit');
        ini_restore('pcre.jit');
    }
}

const PCRE_TRAP = '~(a+)+$~';

function pcreTrapSubject(): string
{
    return str_repeat('a', 30) . 'b';
}

it('replaces, matches and captures like preg does when nothing goes wrong', function (): void {
    expect(Pcre::replace('~b+~', 'X', 'abbbc'))->toBe('aXc')
        ->and(Pcre::matches('~b+~', 'abbbc'))->toBeTrue()
        ->and(Pcre::matches('~z+~', 'abbbc'))->toBeFalse()
        ->and(Pcre::first('~(b+)c~', 'abbbc'))->toBe(['bbbc', 'bbb'])
        ->and(Pcre::first('~z~', 'abbbc'))->toBeNull()
        ->and(Pcre::all('~\d+~', 'a1 b22 c333'))->toBe(['1', '22', '333'])
        ->and(Pcre::all('~\d+~', 'none'))->toBe([])
    ;
});

it('reports byte offsets for matches and for the pieces of a split', function (): void {
    // "é" is two bytes, so offsets are bytes and not characters.
    expect(Pcre::allWithOffsets('~\d+~', 'é1 é22'))->toBe([['1', 2], ['22', 6]])
        ->and(Pcre::splitWithOffsets('~,~', 'é,b'))->toBe([['é', 0], ['b', 3]])
    ;
});

it('throws PcreFailure from every helper when the engine gives up', function (string $call): void {
    $subject = pcreTrapSubject();

    expect(static fn (): mixed => withTinyBacktrackLimit(match ($call)
    {
        'matches' => static fn (): bool => Pcre::matches(PCRE_TRAP, $subject),
        'first' => static fn (): ?array => Pcre::first(PCRE_TRAP, $subject),
        'all' => static fn (): array => Pcre::all(PCRE_TRAP, $subject),
        'allWithOffsets' => static fn (): array => Pcre::allWithOffsets(PCRE_TRAP, $subject),
        'splitWithOffsets' => static fn (): array => Pcre::splitWithOffsets(PCRE_TRAP, $subject),
        default => static fn (): string => Pcre::replace(PCRE_TRAP, '', $subject),
    }))->toThrow(PcreFailure::class, PCRE_TRAP);
})->with(['replace', 'matches', 'first', 'all', 'allWithOffsets', 'splitWithOffsets']);
