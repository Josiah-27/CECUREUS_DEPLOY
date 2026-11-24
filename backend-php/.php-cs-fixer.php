<?php

declare(strict_types=1);

/*
 * Style check for the mobile API (composer cs:check / cs:fix). The rules mirror how the code was
 * already written, so existing files pass without changes and new files get the same shape.
 */

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/public',
        __DIR__ . '/bin',
        __DIR__ . '/tests',
        __DIR__ . '/config',
    ])
    ->notPath('#^vendor/#');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'blank_line_after_opening_tag' => true,
        'cast_spaces' => ['space' => 'single'],
        'class_attributes_separation' => ['elements' => ['method' => 'one']],
        'concat_space' => ['spacing' => 'one'],
        'declare_strict_types' => true,
        'explicit_indirect_variable' => true,
        'fully_qualified_strict_types' => true,
        'no_empty_comment' => true,
        'no_empty_statement' => true,
        'no_leading_namespace_whitespace' => true,
        'no_spaces_after_function_name' => true,
        'no_spaces_inside_parenthesis' => true,
        'no_superfluous_elseif' => true,
        'no_trailing_whitespace' => true,
        'no_trailing_whitespace_in_comment' => true,
        'no_unused_imports' => true,
        'not_operator_with_space' => false,
        'ordered_imports' => ['sort_algorithm' => 'alpha', 'case_sensitive' => false],
        'phpdoc_align' => false,
        'phpdoc_no_package' => true,
        'phpdoc_order' => true,
        'phpdoc_trim' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'yoda_style' => false,
    ])
    ->setFinder($finder);
