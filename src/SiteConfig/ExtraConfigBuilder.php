<?php
namespace Graby\SiteConfig;

interface ExtraConfigBuilder
{
    /**
     * Parses an array of commands => values into a SiteExtraConfig object.
     *
     * @param array $commands
     *
     * @return \Graby\SiteConfig\SiteExtraConfig
     */
    public function parseCommands(array $commands);
}
