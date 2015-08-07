<?php

namespace Graby\SiteConfig;

use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Finder\Finder;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;

class ConfigBuilder
{
    private $logger = false;
    private $config = array();
    private $configFiles = array();
    private $cache = array();

    /**
     * @param array                $config
     * @param LoggerInterface|null $logger
     */
    public function __construct($config = array(), LoggerInterface $logger = null)
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults(array(
            // Directory path to the site config folder WITHOUT trailing slash
            'site_config' => array(dirname(__FILE__).'/../../vendor/j0k3r/graby-site-config'),
            'hostname_regex' => '/^(([a-zA-Z0-9-]*[a-zA-Z0-9])\.)*([A-Za-z0-9-]*[A-Za-z0-9])$/',
        ));

        $resolver->setRequired('site_config');
        $resolver->setAllowedTypes('site_config', 'array');

        $this->config = $resolver->resolve($config);

        $this->logger = $logger;
        if (null === $logger) {
            $this->logger = new NullLogger();
        }

        // we add the data dir by default because it contains the "global.txt" file with global rules
        // that can be applied to every website
        $dirs = array_merge($this->config['site_config'], array(dirname(__FILE__).'/../../data'));

        // load config files
        $finder = new Finder();
        $finder->files()
            ->sortByName()
            ->ignoreDotFiles(false)
            ->name('/\.txt$/')
            ->in($dirs);

        foreach ($finder as $files) {
            $this->configFiles[$files->getRelativePathname()] = $files->getRealpath();
        }
    }

    /**
     * Add the given SiteConfig to the cache.
     *
     * @param string     $key    Key for the cache
     * @param SiteConfig $config Config to be cached
     */
    public function addToCache($key, SiteConfig $config)
    {
        $key = strtolower($key);
        if (substr($key, 0, 4) == 'www.') {
            $key = substr($key, 4);
        }

        if ($config->cache_key) {
            $key = $config->cache_key;
        }

        $this->cache[$key] = $config;

        $this->logger->log('debug', 'Cached site config with key '.$key);
    }

    /**
     * Determine if a Config is already cached.
     * If so, return it otherwise return false.
     *
     * @param string $key Key for the cache
     *
     * @return bool|SiteConfig
     */
    public function getCachedVersion($key)
    {
        $key = strtolower($key);
        if (substr($key, 0, 4) == 'www.') {
            $key = substr($key, 4);
        }

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return false;
    }

    /**
     * Create a new config.
     *
     * @return SiteConfig
     */
    public function create()
    {
        return new SiteConfig();
    }

    /**
     * Returns SiteConfig instance if an appropriate one is found, false otherwise.
     * by default if host is 'test.example.org' we will look for and load '.example.org.txt' if it exists.
     *
     * @param string $host             Host, like en.wikipedia.org
     * @param bool   $exact_host_match if true, we will not look for wildcard config matches
     *
     * @return false|SiteConfig
     */
    public function build($host, $exact_host_match = false)
    {
        $host = strtolower($host);
        if (substr($host, 0, 4) == 'www.') {
            $host = substr($host, 4);
        }

        if (!$host || (strlen($host) > 200) || !preg_match($this->config['hostname_regex'], ltrim($host, '.'))) {
            return false;
        }

        $try = array($host);
        // should we look for wildcard matches
        // will try to see for a host without the first subdomain (fr.example.org & .example.org)
        // @todo: should we look for all possible subdomain? (fr.m.example.org &.m.example.org & .example.org)
        if (!$exact_host_match) {
            $split = explode('.', $host);

            if (count($split) > 1) {
                // remove first subdomain
                array_shift($split);
                $try[] = '.'.implode('.', $split);
            }
        }

        // will contain the matched host
        $matched_name = '';

        // look for site config file in primary folder
        $this->logger->log('debug', '. looking for site config for '.$host.' in primary folder');
        foreach ($try as $host) {
            if ($siteConfig = $this->getCachedVersion($host)) {
                $this->logger->log('debug', '... site config for '.$host.' already loaded in this request');

                return $siteConfig;
            } elseif (isset($this->configFiles[$host.'.txt'])) {
                $this->logger->log('debug', '... found site config ('.$host.'.txt)');
                $file_primary = $this->configFiles[$host.'.txt'];
                $matched_name = $host;
                break;
            }
        }

        // if we found site config, process it
        if (isset($file_primary)) {
            $config_lines = file($file_primary, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            // no lines ? we don't found config then
            // @todo: should we better try with secondary file instead?
            if (empty($config_lines) || !is_array($config_lines)) {
                return false;
            }

            $config = $this->parseLines($config_lines);
            $config->cache_key = $matched_name;

            return $config;
        }

        // return false if no config file found
        $this->logger->log('debug', '... no site config match for '.$host);

        return false;
    }

    /**
     * Append a configuration from to an existing one.
     *
     * @param SiteConfig $currentConfig Current configuration
     * @param SiteConfig $newConfig     New configuration to be merged
     *
     * @return SiteConfig Merged config
     */
    public function mergeConfig(SiteConfig $currentConfig, SiteConfig $newConfig)
    {
        // check for commands where we accept multiple statements (no test_url)
        foreach (array('title', 'body', 'author', 'date', 'strip', 'strip_id_or_class', 'strip_image_src', 'single_page_link', 'next_page_link', 'http_header') as $var) {
            // append array elements for this config variable from $newConfig to this config
            $currentConfig->$var = array_unique(array_merge($currentConfig->$var, $newConfig->$var));
        }

        // check for single statement commands
        // we do not overwrite existing non null values
        foreach (array('tidy', 'prune', 'parser', 'autodetect_on_failure') as $var) {
            if ($currentConfig->$var === null) {
                $currentConfig->$var = $newConfig->$var;
            }
        }

        // treat find_string and replace_string separately (don't apply array_unique) (thanks fabrizio!)
        foreach (array('find_string', 'replace_string') as $var) {
            // append array elements for this config variable from $newConfig to this config
            $currentConfig->$var = array_merge($currentConfig->$var, $newConfig->$var);
        }

        return $currentConfig;
    }

    /**
     * Parse line from the config file to build the config.
     *
     * @param array $lines
     *
     * @return SiteConfig
     */
    public function parseLines(array $lines)
    {
        $config = new SiteConfig();
        foreach ($lines as $line) {
            $line = trim($line);

            // skip comments, empty lines
            if ($line == '' || $line[0] == '#') {
                continue;
            }

            // get command
            $command = explode(':', $line, 2);
            // if there's no colon ':', skip this line
            if (count($command) != 2) {
                continue;
            }

            $val = trim($command[1]);
            $command = trim($command[0]);
            if ($command == '' || $val == '') {
                continue;
            }

            // check for commands where we accept multiple statements
            if (in_array($command, array('title', 'body', 'author', 'date', 'strip', 'strip_id_or_class', 'strip_image_src', 'single_page_link', 'next_page_link', 'http_header', 'test_url', 'find_string', 'replace_string'))) {
                array_push($config->$command, $val);
            // check for single statement commands that evaluate to true or false
            } elseif (in_array($command, array('tidy', 'prune', 'autodetect_on_failure'))) {
                $config->$command = ($val == 'yes' || $val == 'true');
            // check for single statement commands stored as strings
            } elseif (in_array($command, array('parser'))) {
                $config->$command = $val;
            // check for replace_string(find): replace
            } elseif ((substr($command, -1) == ')') && preg_match('!^([a-z0-9_]+)\((.*?)\)$!i', $command, $match)) {
                if (in_array($match[1], array('replace_string'))) {
                    $command = $match[1];
                    array_push($config->find_string, $match[2]);
                    array_push($config->$command, $val);
                }
            }
        }

        return $config;
    }
}
