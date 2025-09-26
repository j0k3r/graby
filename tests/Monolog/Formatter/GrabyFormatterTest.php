<?php

declare(strict_types=1);

namespace Tests\Graby\Monolog\Formatter;

use Graby\Monolog\Formatter\GrabyFormatter;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class GrabyFormatterTest extends TestCase
{
    public function testFormat(): void
    {
        $formatter = new GrabyFormatter();
        $res = $formatter->formatBatch([new LogRecord(
            message: 'This is a log message',
            context: [
                'cursor' => 'here',
                'success' => true,
            ],
            level: Logger::toMonologLevel(100),
            channel: 'graby',
            datetime: new \DateTimeImmutable(),
            extra: [
                'complex' => [
                    'interesting' => 'ok',
                ],
                'success' => true,
            ],
        )]);

        $this->assertStringContainsString('<pre>This is a log message</pre>', $res);
        $this->assertStringContainsString('<pre>(bool) true</pre>', $res);
        $this->assertStringContainsString('"interesting": "ok"', $res);
    }
}
