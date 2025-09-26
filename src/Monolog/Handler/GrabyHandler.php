<?php

declare(strict_types=1);

namespace Graby\Monolog\Handler;

use Graby\Monolog\Formatter\GrabyFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LogLevel;

/**
 * Custom handler to keep all related log
 * and be able to display them in the test page.
 */
class GrabyHandler extends AbstractProcessingHandler
{
    /** @var LogRecord[] */
    protected array $records = [];
    /** @phpstan-var array<value-of<Level::VALUES>, LogRecord[]> */
    protected array $recordsByLevel = [];

    public function __construct($level = Level::Debug, $bubble = true)
    {
        parent::__construct($level, $bubble);

        $this->setFormatter(new GrabyFormatter());
        $this->pushProcessor(new PsrLogMessageProcessor());
    }

    /**
     * @return array<LogRecord>
     */
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
     * @param int|string|Level|LogLevel::* $level Logging level value or name
     *
     * @phpstan-param value-of<Level::VALUES>|value-of<Level::NAMES>|Level|LogLevel::* $level
     */
    public function hasRecords(int|string|Level $level): bool
    {
        return isset($this->recordsByLevel[Logger::toMonologLevel($level)->value]);
    }

    protected function write(LogRecord $record): void
    {
        $this->recordsByLevel[$record->level->value][] = $record;
        $this->records[] = $record;
    }
}
