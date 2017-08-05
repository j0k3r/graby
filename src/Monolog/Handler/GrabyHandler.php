<?php

namespace Graby\Monolog\Handler;

use Graby\Monolog\Formatter\GrabyFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

/**
 * Custom handler to keep all related log
 * and be able to display the test page.
 */
class GrabyHandler extends AbstractProcessingHandler
{
    protected $records = [];

    public function __construct($level = Logger::DEBUG, $bubble = true)
    {
        parent::__construct($level, $bubble);

        $this->setFormatter(new GrabyFormatter());
        $this->pushProcessor(new PsrLogMessageProcessor());
    }

    public function getRecords()
    {
        return $this->records;
    }

    protected function write(array $record)
    {
        $this->records[] = $record;
    }
}
