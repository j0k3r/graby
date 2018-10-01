<?php

namespace Graby\SiteConfig;

use GrabySiteConfig\SiteConfig\Files;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ConfigBuilder
{
    private $logger;
    private $config = [];
    private $configFiles = [];
    private $cache = [];

    /**
     * @param array                $config
     * @param LoggerInterface|null $logger
     */
    public function __construct($config = [], LoggerInterface $logger = null)
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            // Directory path to the site config folder WITHOUT trailing slash
            'site_config' => [],
            'hostname_regex' => '/^(([a-zA-Z0-9-]*[a-zA-Z0-9])\.)*([A-Za-z0-9-]*[A-Za-z0-9])$/',
        ]);

        $resolver->setRequired('site_config');
        $resolver->setAllowedTypes('site_config', 'array');

        $this->config = $resolver->resolve($config);

        $this->logger = $logger;
        if (null === $logger) {
            $this->logger = new NullLogger();
        }

        $this->configFiles = Files::getFiles($this->config['site_config']);
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
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
        if ('www.' === substr($key, 0, 4)) {
            $key = substr($key, 4);
        }

        if ($config->cache_key) {
            $key = $config->cache_key;
        }

        $this->cache[$key] = $config;

        $this->logger->log('debug', 'Cached site config with key: {key}', ['key' => $key]);
    }

    /**
     * Determine if a Config is already cached.
     * If so, return it otherwise return false.
     *
     * @param string $key Key for the cache
     *
     * @return false|SiteConfig
     */
    public function getCachedVersion($key)
    {
        $key = strtolower($key);
        if ('www.' === substr($key, 0, 4)) {
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
     * Build a config file from an url.
     * Use `buildForHost` if you already have the host.
     *
     * @param string $url
     * @param bool   $addToCache
     *
     * @return SiteConfig
     */
    public function buildFromUrl($url, $addToCache = true)
    {
        // extract host name
        $host = parse_url($url, PHP_URL_HOST);

        return $this->buildForHost((string) $host, $addToCache);
    }

    /**
     * Build a config file from a host.
     * Use `buildFromUrl` if you have an url.
     *
     * @param string $host       Host, like en.wikipedia.org
     * @param bool   $addToCache
     *
     * @return SiteConfig
     */
    public function buildForHost($host, $addToCache = true)
    {
        $host = strtolower($host);
        if ('www.' === substr($host, 0, 4)) {
            $host = substr($host, 4);
        }

        // is merged version already cached?
        $cachedSiteConfig = $this->getCachedVersion($host . '.merged');
        if (false !== $cachedSiteConfig) {
            $this->logger->log('debug', 'Returning cached and merged site config for {host}', ['host' => $host]);

            return $cachedSiteConfig;
        }

        // let's build it
        $config = $this->loadSiteConfig($host);
        if ($addToCache && false !== $config && false === $this->getCachedVersion($host)) {
            $this->addToCache($host, $config);
        }

        // if no match, use defaults
        if (false === $config) {
            $config = $this->create();
        }

        // load global config?
        $configGlobal = $this->loadSiteConfig('global', true);
        if ($config->autodetect_on_failure() && false !== $configGlobal) {
            $this->logger->log('debug', 'Appending site config settings from global.txt');
            $this->mergeConfig($config, $configGlobal);

            if ($addToCache && false === $this->getCachedVersion('global')) {
                $this->addToCache('global', $configGlobal);
            }
        }

        // store copy of merged config
        if ($addToCache) {
            $config->cache_key = null;
            $this->addToCache($host . '.merged', $config);
        }

        return $config;
    }

    /**
     * Returns SiteConfig instance (joined in order: exact match, wildcard, fingerprint, global, default).
     *
     * Will add the merged result to cache if $addToCache is set to true
     *
     * @param string $host           Host, like en.wikipedia.org
     * @param bool   $exactHostMatch if true, we will not look for wildcard config matches
     *
     * @return false|SiteConfig
     *
     * @deprecated Use either buildForHost() / buildFromUrl() for the merged config or loadSiteConfig() to get the config for a site
     * @codeCoverageIgnore
     */
    public function build($host, $exactHostMatch = false)
    {
        return $this->loadSiteConfig($host, $exactHostMatch);
    }

    /**
     * Returns SiteConfig instance if an appropriate one is found, false otherwise.
     * by default if host is 'test.example.org' we will look for and load '.example.org.txt' if it exists.
     *
     * @param string $host           Host, like en.wikipedia.org
     * @param bool   $exactHostMatch if true, we will not look for wildcard config matches
     *
     * @return false|SiteConfig
     */
    public function loadSiteConfig($host, $exactHostMatch = false)
    {
        $host = strtolower($host);
        if ('www.' === substr($host, 0, 4)) {
            $host = substr($host, 4);
        }

        if (!$host || (\strlen($host) > 200) || !preg_match($this->config['hostname_regex'], ltrim($host, '.'))) {
            return false;
        }

        $try = [$host];
        // should we look for wildcard matches
        // will try to see for a host without the first subdomain (fr.example.org & .example.org)
        // @todo: should we look for all possible subdomain? (fr.m.example.org &.m.example.org & .example.org)
        if (!$exactHostMatch) {
            $split = explode('.', $host);

            if (\count($split) > 1) {
                // remove first subdomain
                array_shift($split);
                $try[] = '.' . implode('.', $split);
            }
        }

        $config = new SiteConfig();

        // look for site config file in primary folder
        $this->logger->log('debug', '. looking for site config for {host} in primary folder', ['host' => $host]);
        foreach ($try as $host) {
            if ($cachedConfig = $this->getCachedVersion($host)) {
                $this->logger->log('debug', '... site config for {host} already loaded in this request', ['host' => $host]);

                return $cachedConfig;
            }

            // if we found site config, process it
            if (isset($this->configFiles[$host . '.txt'])) {
                $this->logger->log('debug', '... found site config {host}', ['host' => $host . '.txt']);

                $configLines = file($this->configFiles[$host . '.txt'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                // no lines ? we don't found config then
                if (empty($configLines) || !\is_array($configLines)) {
                    return false;
                }

                $config = $this->parseLines($configLines);
                $config->cache_key = $host;
                break;
            }
        }

        // append global config?
        if ('global' !== $host && $config->autodetect_on_failure() && isset($this->configFiles['global.txt'])) {
            $this->logger->log('debug', 'Appending site config settings from global.txt');

            $configGlobal = $this->loadSiteConfig('global', true);
            if (false !== $configGlobal) {
                $config = $this->mergeConfig($config, $configGlobal);
            }
        }

        return $config;
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
        foreach (['title', 'body', 'strip', 'strip_id_or_class', 'strip_image_src', 'single_page_link', 'next_page_link', 'date', 'author', 'strip_attr'] as $var) {
            // append array elements for this config variable from $newConfig to this config
            $currentConfig->$var = array_unique(array_merge($currentConfig->$var, $newConfig->$var));
        }

        // check for single statement commands
        // we do not overwrite existing non null values
        foreach (['tidy', 'prune', 'parser', 'autodetect_on_failure', 'requires_login'] as $var) {
            if ($currentConfig->$var === null) {
                $currentConfig->$var = $newConfig->$var;
            }
        }

        // treat find_string and replace_string separately (don't apply array_unique) (thanks fabrizio!)
        foreach (['find_string', 'replace_string'] as $var) {
            // append array elements for this config variable from $newConfig to this config
            $currentConfig->$var = array_merge($currentConfig->$var, $newConfig->$var);
        }

        // merge http_header array from currentConfig into newConfig
        // because final values override former values in case of named keys
        $currentConfig->http_header = array_merge($newConfig->http_header, $currentConfig->http_header);

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
            if ('' === $line || '#' === $line[0]) {
                continue;
            }

            // get command
            $command = explode(':', $line, 2);
            // if there's no colon ':', skip this line
            if (2 !== \count($command)) {
                continue;
            }

            $val = trim($command[1]);
            $command = trim($command[0]);
            if ('' === $command || '' === $val) {
                continue;
            }

            // check for commands where we accept multiple statements
            if (\in_array($command, ['title', 'body', 'strip', 'strip_id_or_class', 'strip_image_src', 'single_page_link', 'next_page_link', 'test_url', 'find_string', 'replace_string', 'login_extra_fields', 'native_ad_clue', 'date', 'author', 'strip_attr'], true)) {
                array_push($config->$command, $val);
            // check for single statement commands that evaluate to true or false
            } elseif (\in_array($command, ['tidy', 'prune', 'autodetect_on_failure', 'requires_login'], true)) {
                $config->$command = ('yes' === $val || 'true' === $val);
            // check for single statement commands stored as strings
            } elseif (\in_array($command, ['parser', 'login_username_field', 'login_password_field', 'not_logged_in_xpath', 'login_uri', 'src_lazy_load_attr'], true)) {
                $config->$command = $val;
            // check for replace_string(find): replace
            } elseif ((')' === substr($command, -1)) && preg_match('!^([a-z0-9_]+)\((.*?)\)$!i', $command, $match) && 'replace_string' === $match[1]) {
                array_push($config->find_string, $match[2]);
                array_push($config->replace_string, $val);
            } elseif ((')' === substr($command, -1)) && preg_match('!^([a-z0-9_]+)\(([a-z0-9_-]+)\)$!i', $command, $match) && 'http_header' === $match[1] && \in_array(strtolower($match[2]), ['user-agent', 'referer', 'cookie'], true)) {
                $config->http_header[strtolower(trim($match[2]))] = $val;
            }
        }

        return $config;
    }
}
