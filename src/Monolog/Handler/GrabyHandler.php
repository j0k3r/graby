<?php

declare(strict_types=1);

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
    /**
     * @var array<array{
     *   channel: string,
     *   level: int,
     *   level_name: string,
     *   datetime: \DateTimeInterface,
     *   message: string,
     *   formatted?: string,
     *   context: array<string, mixed>,
     *   extra: array<string, mixed>,
     * }>
     */
    protected array $records = [];
    /**
     * @var array<int, array{
     *   channel: string,
     *   level: int,
     *   level_name: string,
     *   datetime: \DateTimeInterface,
     *   message: string,
     *   formatted?: string,
     *   context: array<string, mixed>,
     *   extra: array<string, mixed>,
     * }>
     */
    protected array $recordsByLevel = [];

    public function __construct($level = Logger::DEBUG, $bubble = true)
    {
        parent::__construct($level, $bubble);

        $this->setFormatter(new GrabyFormatter());
        $this->pushProcessor(new PsrLogMessageProcessor());
    }

    /**
     * @return array<array{
     *   channel: string,
     *   level: int,
     *   level_name: string,
     *   datetime: \DateTimeInterface,
     *   message: string,
     *   formatted?: string,
     *   context: array<string, mixed>,
     *   extra: array<string, mixed>,
     * }>
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
     * @param string|int $level Logging level value or name
     */
    public function hasRecords($level): bool
    {
        return isset($this->recordsByLevel[$level]);
    }

    /**
     * @param array{
     *   channel: string,
     *   level: int,
     *   level_name: string,
     *   datetime: \DateTimeInterface,
     *   message: string,
     *   formatted?: string,
     *   context: array<string, mixed>,
     *   extra: array<string, mixed>,
     * } $record
     */
    protected function write(array $record): void
    {
        $this->recordsByLevel[$record['level']][] = $record;
        $this->records[] = $record;
    }
}
