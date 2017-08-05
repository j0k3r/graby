<?php

namespace Tests\Graby\Monolog\Handler;

use Graby\Monolog\Handler\GrabyHandler;

class GrabyHandlerTest extends \PHPUnit_Framework_TestCase
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
    }
}
