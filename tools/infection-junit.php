<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Fix-ups so that Infection can run and read a Pest run
|--------------------------------------------------------------------------
|
| 1. Infection looks tests up in the JUnit log by the class names in the
| coverage report ("P\Tests\Unit\FooTest"), while Pest writes the same classes
| to the JUnit log without the "P\" prefix. Without a match Infection aborts
| with "Could not find any information for the test".
|
| 2. Infection runs `vendor/bin/pest --version` to learn the PHPUnit version and
| gets Pest's own number. It reads that number as a PHPUnit older than 9.3 and
| writes the legacy <filter> element into the configuration it generates.
| PHPUnit 12 rejects that element with a warning, and failOnWarning in
| phpunit.xml.dist turns the warning into a failed run. Infection refuses to
| start from a failing run. The <source> element in phpunit.xml.dist is the one
| PHPUnit 12 reads, so this file drops the legacy element before PHPUnit loads
| the configuration.
|
| Loaded through auto_prepend_file for Infection's initial test run only (see
| initialTestsPhpOptions in infection.json5). When that run asks for a JUnit
| log, the prefix is put back once Pest has finished, and relative test file
| paths are made absolute.
*/

$junit = null;
$configuration = null;

foreach ($_SERVER['argv'] ?? [] as $index => $argument)
{
    if (str_starts_with($argument, '--log-junit='))
    {
        $junit = substr($argument, \strlen('--log-junit='));
    }

    if (str_starts_with($argument, '--configuration='))
    {
        $configuration = substr($argument, \strlen('--configuration='));
    }
    elseif ('--configuration' === $argument)
    {
        $configuration = $_SERVER['argv'][$index + 1] ?? null;
    }
}

if (null !== $configuration && is_file($configuration))
{
    $config = file_get_contents($configuration);
    $stripped = false === $config ? null : preg_replace('~\s*<filter>.*?</filter>~s', '', $config);

    if (null !== $stripped && $stripped !== $config)
    {
        file_put_contents($configuration, $stripped);
    }
}

if (null !== $junit)
{
    $rewrite = static function () use ($junit): void {
        $xml = is_file($junit) ? file_get_contents($junit) : false;

        if (false === $xml)
        {
            return;
        }

        $root = (string) getcwd();

        // Pest also writes test file paths relative to the project, where PHPUnit
        // writes them absolute, and Infection hands them back to the test runner
        // from another directory.
        $fixed = preg_replace_callback(
            '~\bfile="(tests/[^":]*\.php)"~',
            static fn (array $match): string => 'file="' . $root . '/' . $match[1] . '"',
            $xml,
        );

        $fixed = null === $fixed ? null : preg_replace('~\b(name|class)="(Tests\\\\[^"]*)"~', '$1="P\\\\$2"', $fixed);

        if (null !== $fixed)
        {
            file_put_contents($junit, $fixed);
        }
    };

    // Pest writes the log from its own shutdown handling, which is queued after
    // this file loads. Queueing again from inside a shutdown function runs the
    // rewrite after everything that is already queued.
    register_shutdown_function(static function () use ($rewrite): void {
        register_shutdown_function($rewrite);
    });
}
