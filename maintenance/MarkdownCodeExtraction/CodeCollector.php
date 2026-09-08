<?php

declare(strict_types=1);

namespace Maintenance\Graby\MarkdownCodeExtraction;

final class CodeCollector
{
    /**
     * Extracts source code blocks from markdown file and generates PHP scripts
     * that have blank lines inserted so that the line number in the output
     * matches the line number in the original markdown file for convenience.
     *
     * @return iterable<CodeExample>
     */
    public static function extractCodeExamples(string $path, string $contents): iterable
    {
        $code = '';
        $opening = null;
        $startLine = null;
        $ignore = false;

        $lines = explode(\PHP_EOL, $contents);
        foreach ($lines as $lineNo => $line) {
            if (null === $opening) {
                if (1 === preg_match('/^(?P<prefix>[ \t]*```+)php(?: +(?P<classes>.+))?/', $line, $matches)) {
                    $opening = $matches['prefix'];
                    // Adding 2 since array indexes start at 0 and we want to point
                    // to the start of code itself, not the Markdown code fence.
                    $startLine = $lineNo + 2;
                    $ignore = isset($matches['classes']) && str_contains($matches['classes'], 'no-extract');
                }
            } else {
                if ($line === $opening) {
                    $opening = null;

                    $precedingLines = $startLine - 1;
                    $code = '<?php' . str_repeat(\PHP_EOL, $precedingLines) . $code;
                    if (!$ignore) {
                        yield new CodeExample(
                            file: $path,
                            line: $startLine,
                            code: $code,
                        );
                    }

                    $code = '';
                } elseif (!$ignore) {
                    $code .= $line . \PHP_EOL;
                }
            }
        }
    }
}
