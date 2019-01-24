<?php

namespace Tests\Graby\Monolog\Handler;

use Graby\Monolog\Handler\GrabyHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class GrabyHandlerTest extends TestCase
{
    public function testFormat()
    {
        $handler = new GrabyHandler();
        $handler->handle([
            'level' => 100,
            'message' => 'message',
            'datetime' => new \DateTime(),
            'context' => [],
            'extra' => [],
        ]);

        $this->assertCount(1, $handler->getRecords());
        $this->assertArrayHasKey('formatted', $handler->getRecords()[0]);
        $this->assertTrue($handler->hasRecords(Logger::DEBUG));

        $handler->clear();

        $this->assertCount(0, $handler->getRecords());
        $this->assertFalse($handler->hasRecords(Logger::DEBUG));
    }
}
