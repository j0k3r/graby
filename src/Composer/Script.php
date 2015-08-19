<?php

namespace Graby\Composer;

use Composer\Script\Event;

class Script
{
    private static $rootDir;

    /**
     * Generate a symlink to the default site config.
     */
    public static function postUpdate(Event $event)
    {
        $io = $event->getIO();
        $config = $event->getComposer()->getConfig();
        $vendorDir = strtr(realpath($config->get('vendor-dir')), '\\', '/');

        self::$rootDir = getcwd();
        $siteConfig = $vendorDir.'/j0k3r/graby-site-config';
        $targetDir = 'site_config';

        if (is_link($fullTargetDir = self::$rootDir.'/'.$targetDir)) {
            return;
        }

        if ($io->isVerbose()) {
            $io->write('Make symlink <info>'.self::displayPath($fullTargetDir).'</info> from <info>'.self::displayPath($siteConfig).'</info>');
        }

        system(sprintf('ln -s %s %s', $siteConfig, $targetDir));
    }

    private static function displayPath($directory)
    {
        return str_replace(self::$rootDir.'/', '', $directory);
    }
}
