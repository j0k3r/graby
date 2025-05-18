<?php

namespace Graby\SiteConfig;

interface ConfigLinesProvider
{
    public function supportsHost(string $host): bool;

    /**
     * @return list<string>
     */
    public function getLinesForHost(string $host): array;

    public function reload(): void;
}
