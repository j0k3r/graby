<?php

namespace Graby\SiteConfig;

use GrabySiteConfig\SiteConfig\Files;

class BuiltinConfigLinesProvider implements ConfigLinesProvider
{
    /**
     * @var list<string>
     */
    private $dirs;

    /**
     * @var array<string, string>
     */
    private $configFiles;

    /**
     * @param list<string> $dirs
     */
    public function __construct($dirs = [])
    {
        $this->dirs = $dirs;

        $this->reload();
    }

    public function supportsHost(string $host): bool
    {
        return \array_key_exists($host . '.txt', $this->configFiles);
    }

    public function getLinesForHost(string $host): array
    {
        if (!$this->supportsHost($host)) {
            return [];
        }

        $configLines = file($this->configFiles[$host . '.txt'], \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

        if (false === $configLines) {
            return [];
        }

        return $configLines;
    }

    public function reload(): void
    {
        $this->configFiles = Files::getFiles($this->dirs);
    }
}
