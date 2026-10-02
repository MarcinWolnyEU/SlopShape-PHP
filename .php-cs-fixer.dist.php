<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Coding style
|--------------------------------------------------------------------------
|
| A single source of truth for formatting, so a diff is a formatting change or
| it is a logic change, and never both. The rule set is the strict default
| with a handful of opinionated additions that this codebase actually follows.
|
*/

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->exclude('Fixtures')
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PER-CS2.0' => true,
        '@PHP81Migration' => true,
        '@PHP82Migration' => true,
        // The floor is PHP 8.3 (composer.json). Migration sets above 8.3 would
        // introduce 8.4-only functions, so the target stops here.
        '@PHP83Migration' => true,
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,

        // A package whose whole claim is reproducibility sorts its dependencies
        // and its arrays, so that two runs cannot differ for a reason nobody
        // wrote down.
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'ordered_class_elements' => false,
        'array_syntax' => ['syntax' => 'short'],

        // No one has ever read `else` and thought "ah, more clarity".
        'no_superfluous_elseif' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'no_null_property_initialization' => true,
        'single_line_empty_body' => false,

        // A public API that is not documented is a public API nobody should use.
        'phpdoc_to_comment' => false,
        'no_empty_phpdoc' => true,
        'phpdoc_separation' => true,
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_to_property_type' => false,

        // Non-ASCII is allowed and expected: the instrument's menus, the
        // em dashes in the fixtures, the curly quotes in the docs.
        'no_trailing_whitespace' => true,
        'no_whitespace_in_blank_line' => true,
        'single_blank_line_at_eof' => true,
        'braces_position' => [
            'classes_opening_brace' => 'same_line',
            'functions_opening_brace' => 'next_line_unless_newline_at_signature_end',
            'anonymous_functions_opening_brace' => 'same_line',
            'control_structures_opening_brace' => 'next_line_unless_newline_at_signature_end',
        ],
        'concat_space' => ['spacing' => 'one'],
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
        'declare_strict_types' => true,
        // Not `static_lambda`: Pest binds test closures to the test case, and a
        // static closure cannot be bound. The suite fails to load otherwise.
        'static_lambda' => false,
        // Importing global classes is only an improvement inside a namespace,
        // and actively wrong in a global-namespace test file.
        'global_namespace_import' => false,
        'fully_qualified_strict_types' => true,
        // Off on purpose. Text\Markdown, Text\Html, Text\CodeFences and
        // Reading\LlmReader work in *bytes*: they exchange offsets with PCRE
        // (PREG_OFFSET_CAPTURE) and scan linearly, and JSON's structural
        // characters are ASCII. Rewriting strlen/substr/strpos to mb_* would
        // make those offsets wrong on multi-byte text and the scans quadratic.
        // The rule would also emit mb_trim(), which does not exist before PHP 8.4.
        'mb_str_functions' => false,
        'modernize_strpos' => true,
        'no_unneeded_control_parentheses' => true,
        'no_useless_sprintf' => true,
        'numeric_literal_separator' => true,
        'return_type_declaration' => ['space_before' => 'none'],
        'simplified_null_return' => false,
        'single_class_element_per_statement' => ['elements' => ['const', 'property']],
        'use_arrow_functions' => false,
    ])
    ->setFinder($finder);
