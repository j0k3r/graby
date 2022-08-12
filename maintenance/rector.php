<?php

declare(strict_types=1);

use Maintenance\Graby\Rector\MockGrabyResponseRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Set\ValueObject\LevelSetList;

return static function (Rector\Config\RectorConfig $rectorConfig): void {
    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_74,
    ]);

    // Breaks creating new files.
    // https://github.com/rectorphp/rector/issues/7231
    $rectorConfig->disableParallel();

    $rectorConfig->rule(MockGrabyResponseRector::class);
    $rectorConfig->paths([
        __DIR__ . '/../maintenance',
        __DIR__ . '/../src',
        __DIR__ . '/../tests',
    ]);

    $phpunitBridges = glob(__DIR__ . '/../vendor/bin/.phpunit/phpunit-*');
    $latestPhpunitBridge = end($phpunitBridges);
    assert(false !== $latestPhpunitBridge, 'There must be at least one PHPUnit version installed by Symfony PHPUnit bridge, please run `vendor/bin/simple-phpunit install`.');
    $rectorConfig->bootstrapFiles([
        $latestPhpunitBridge . '/vendor/autoload.php',
    ]);

    $rectorConfig->skip([
        // nodeNameResolver requires string.
        StringClassNameToClassConstantRector::class => __DIR__ . '/Rector/**',
    ]);

    $rectorConfig->phpstanConfig(__DIR__ . '/../phpstan.neon');
};
