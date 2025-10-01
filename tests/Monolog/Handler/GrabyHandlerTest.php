<?php

declare(strict_types=1);

namespace Tests\Graby\Monolog\Handler;

use Graby\Monolog\Handler\GrabyHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class GrabyHandlerTest extends TestCase
{
    public function testFormat(): void
    {
        $handler = new GrabyHandler();
        $handler->handle(new LogRecord(
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
        )
        );

        $this->assertCount(1, $handler->getRecords());
        $this->assertArrayHasKey('formatted', $handler->getRecords()[0]);
        $this->assertTrue($handler->hasRecords(Level::Debug));

        $handler->clear();

        $this->assertCount(0, $handler->getRecords());
        $this->assertFalse($handler->hasRecords(Level::Debug));
    }
}
