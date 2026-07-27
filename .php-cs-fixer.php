<?php


$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude(['vendor', 'log'])
;
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP7x4Migration' => true,
        '@PHP7x4Migration:risky' => true,
        // Breaks spacing around faux named arguments (in a comment).
        'method_argument_space' => false,
        'combine_consecutive_unsets' => true,
        'heredoc_to_nowdoc' => true,
        'no_extra_blank_lines' => ['tokens' => ['break', 'continue', 'extra', 'return', 'throw', 'use', 'parenthesis_brace_block', 'square_brace_block', 'curly_brace_block']],
        'ordered_class_elements' => true,
        'php_unit_strict' => true,
        'phpdoc_to_param_type' => ['union_types' => false],
        'phpdoc_to_return_type' => ['union_types' => false],
        'phpdoc_to_property_type' => ['union_types' => false],
        // 'psr4' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'concat_space' => ['spacing' => 'one'],
        'multiline_promoted_properties' => [
            'minimum_number_of_parameters' => 2
        ],
    ])
    ->setFinder($finder)
;
