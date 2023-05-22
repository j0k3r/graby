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
        '@PHP74Migration' => true,
        '@PHP74Migration:risky' => true,
        // Breaks spacing around faux named arguments (in a comment).
        'method_argument_space' => false,
        'array_syntax' => ['syntax' => 'short'],
        'combine_consecutive_unsets' => true,
        // Enabled by @Symfony:risky but requires PHP 8.
        'get_class_to_class_keyword' => false,
        'heredoc_to_nowdoc' => true,
        'no_extra_blank_lines' => ['tokens' => ['break', 'continue', 'extra', 'return', 'throw', 'use', 'parenthesis_brace_block', 'square_brace_block', 'curly_brace_block']],
        'no_unreachable_default_argument_value' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        // Enabled by Symfony and changes properties without type hints but we cannot use those yet because they require PHP 8.
        'no_null_property_initialization' => false,
        // Enabled by @Symfony but is inconsistent.
        'nullable_type_declaration_for_default_null_value' => [
            'use_nullable_type_declaration' => true,
        ],
        'ordered_class_elements' => true,
        'ordered_imports' => true,
        'php_unit_strict' => true,
        'phpdoc_order' => true,
        'phpdoc_to_param_type' => true,
        'phpdoc_to_return_type' => true,
        'phpdoc_to_property_type' => true,
        // 'psr4' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'concat_space' => ['spacing' => 'one'],
    ])
    ->setFinder($finder)
;
