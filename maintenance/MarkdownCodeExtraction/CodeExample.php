<?php

declare(strict_types=1);

namespace Maintenance\Graby\MarkdownCodeExtraction;

final readonly class CodeExample
{
    public function __construct(
        public string $file,
        public int $line,
        public string $code
    ) {
    }
}
