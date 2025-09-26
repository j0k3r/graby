<?php

declare(strict_types=1);

use Maintenance\Graby\Rector\MockGrabyResponseRector;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Set\ValueObject\LevelSetList;

return RectorConfig::configure()
    ->withSets([
        LevelSetList::UP_TO_PHP_82,
    ])

    ->withRules([
        MockGrabyResponseRector::class,
    ])

    ->withPaths([
        __DIR__ . '/../maintenance',
        __DIR__ . '/../src',
        __DIR__ . '/../tests',
    ])

    ->withBootstrapFiles([
        __DIR__ . '/../vendor/bin/.phpunit/phpunit/vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php',
    ])

    ->withSkip([
        // nodeNameResolver requires string.
        StringClassNameToClassConstantRector::class => __DIR__ . '/Rector/**',
    ])
;
