<?php

declare(strict_types=1);

use Rector\Core\Configuration\Option;
use Rector\Set\ValueObject\LevelSetList;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->import(LevelSetList::UP_TO_PHP_74);

    $parameters = $containerConfigurator->parameters();
    $parameters->set(Option::PATHS, [
        __DIR__ . '/../src',
        __DIR__ . '/../tests',
    ]);

    $phpunitBridges = glob(__DIR__ . '/../vendor/bin/.phpunit/phpunit-*');
    $latestPhpunitBridge = end($phpunitBridges);
    assert(false !== $latestPhpunitBridge, 'There must be at least one PHPUnit version installed by Symfony PHPUnit bridge, please run `vendor/bin/simple-phpunit install`.');
    $parameters->set(Option::BOOTSTRAP_FILES, [
        $latestPhpunitBridge . '/vendor/autoload.php',
    ]);

    $parameters->set(Option::PHPSTAN_FOR_RECTOR_PATH, __DIR__ . '/../phpstan.neon');
};
