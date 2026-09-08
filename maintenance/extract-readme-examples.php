<?php

declare(strict_types=1);

namespace Maintenance\Graby;

use Symfony\Component\Filesystem\Filesystem;

require_once __DIR__ . '/../vendor/autoload.php';

$sourceDir = __DIR__ . '/../';
$outputDir = __DIR__ . '/../.extracted-code-examples';

$sourceFiles = [
    'README.md',
];

$fs = new Filesystem();

$fs->remove($outputDir);

foreach ($sourceFiles as $sourceFile) {
    $contents = $fs->readFile($sourceDir . '/' . $sourceFile);

    $examples = MarkdownCodeExtraction\CodeCollector::extractCodeExamples($sourceFile, $contents);

    foreach ($examples as $example) {
        $outputPath = $outputDir . '/' . $example->file . '-' . $example->line . '.php';
        $fs->dumpFile($outputPath, $example->code);
    }
}
