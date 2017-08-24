<?php

namespace Tests\Graby\Monolog\Formatter;

use Graby\Monolog\Formatter\GrabyFormatter;

class GrabyFormatterTest extends \PHPUnit_Framework_TestCase
{
    public function testFormat()
    {
        $formatter = new GrabyFormatter();
        $res = $formatter->formatBatch([[
            'level' => 100,
            'datetime' => new \DateTime(),
            'message' => 'This is a log message',
            'context' => [
                'cursor' => 'here',
                'success' => true,
            ],
            'extra' => [
                'complex' => [
                    'interesting' => 'ok',
                ],
                'success' => true,
            ],
        ]]);

        $this->assertContains('<pre>This is a log message</pre>', $res);
        $this->assertContains('<pre>(bool) true</pre>', $res);
        $this->assertContains('"interesting": "ok"', $res);
    }
}
