<?php

namespace Graby\Monolog\Handler;

use Graby\Monolog\Formatter\GrabyFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

/**
 * Custom handler to keep all related log
 * and be able to display them in the test page.
 */
class GrabyHandler extends AbstractProcessingHandler
{
    protected array $records = [];
    protected array $recordsByLevel = [];

    public function __construct($level = Logger::DEBUG, $bubble = true)
    {
        parent::__construct($level, $bubble);

        $this->setFormatter(new GrabyFormatter());
        $this->pushProcessor(new PsrLogMessageProcessor());
    }

    public function getRecords(): array
    {
        return $this->records;
    }

    public function clear(): void
    {
        $this->records = [];
        $this->recordsByLevel = [];
    }

    /**
     * @param string|int $level Logging level value or name
     */
    public function hasRecords($level): bool
    {
        return isset($this->recordsByLevel[$level]);
    }

    protected function write(array $record): void
    {
        $this->recordsByLevel[$record['level']][] = $record;
        $this->records[] = $record;
    }
}
