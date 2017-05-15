<?php
/**
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

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
