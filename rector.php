<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodingStyle\Rector\String_\SimplifyQuoteEscapeRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
    ->withSkip([
        // A library's public surface is a promise; renaming or removing a
        // public function is a breaking change, not a refactor.
        __DIR__ . '/src/functions.php',
        // Rector prefers double quotes for strings that contain an apostrophe;
        // PHP-CS-Fixer's single_quote rule prefers single everywhere. One of
        // them has to lose, and the formatter wins so the style is decidable.
        SimplifyQuoteEscapeRector::class,
        // LogOdds::sigmoid() is a pure function of its argument and is called
        // as such from the scorer and from tests. Converting it to an instance
        // method would add a dependency for no gain.
        LocallyCalledStaticMethodToNonStaticRector::class,
    ])
    ->withPhpVersion(Rector\ValueObject\PhpVersion::PHP_83);
