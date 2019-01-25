<?php

namespace Graby\Monolog\Formatter;

use Monolog\Formatter\HtmlFormatter;

/**
 * Formats incoming records into an HTML table.
 *
 * Few things differents from the original HtmlFormatter:
 *   - removed the title (we don't care about the log level)
 *   - changing title cell color
 */
class GrabyFormatter extends HtmlFormatter
{
    /**
     * Formats a log record.
     *
     * @param array $record A record to format
     *
     * @return mixed The formatted record
     */
    public function format(array $record)
    {
        $output = '<table cellspacing="1" width="100%" class="monolog-output">';

        $output .= $this->addRowWithLevel($record['level'], 'Time', $record['datetime']->format($this->dateFormat));
        $output .= $this->addRowWithLevel($record['level'], 'Message', (string) $record['message']);

        if ($record['context']) {
            $embeddedTable = '<table cellspacing="1" width="100%">';
            foreach ($record['context'] as $key => $value) {
                $embeddedTable .= $this->addRowWithLevel($record['level'], $key, $this->convertToString($value));
            }
            $embeddedTable .= '</table>';
            $output .= $this->addRowWithLevel($record['level'], 'Context', $embeddedTable, false);
        }
        if ($record['extra']) {
            $embeddedTable = '<table cellspacing="1" width="100%">';
            foreach ($record['extra'] as $key => $value) {
                $embeddedTable .= $this->addRowWithLevel($record['level'], $key, $this->convertToString($value));
            }
            $embeddedTable .= '</table>';
            $output .= $this->addRowWithLevel($record['level'], 'Extra', $embeddedTable, false);
        }

        return $output . '</table>';
    }

    protected function convertToString($data)
    {
        if (\is_bool($data)) {
            return $data ? '(bool) true' : '(bool) false';
        }

        return parent::convertToString($data);
    }

    /**
     * Creates an HTML table row with background cellon title cell.
     *
     * @param int    $level    Error level
     * @param string $th       Row header content
     * @param string $td       Row standard cell content
     * @param bool   $escapeTd false if td content must not be html escaped
     *
     * @return string
     */
    private function addRowWithLevel($level, $th, $td = ' ', $escapeTd = true)
    {
        $th = htmlspecialchars($th, ENT_NOQUOTES, 'UTF-8');
        if ($escapeTd) {
            $td = '<pre>' . htmlspecialchars($td, ENT_NOQUOTES, 'UTF-8') . '</pre>';
        }

        return "<tr style=\"padding: 4px;spacing: 0;text-align: left;\">\n<th style=\"background:" . $this->logLevels[$level] . "\" width=\"100px\">$th:</th>\n<td style=\"padding: 4px;spacing: 0;text-align: left;background: #eeeeee\">" . $td . "</td>\n</tr>";
    }
}
