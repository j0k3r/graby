<?php

declare(strict_types=1);

namespace Graby\SiteConfig;

use GrabySiteConfig\SiteConfig\Files;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ConfigBuilder
{
    private LoggerInterface $logger;
    private ConfigBuilderConfig $config;
    private array $configFiles = [];
    private array $cache = [];

    // Array for accepted headers for http_header()
    private array $acceptedHeaders = [
        'user-agent',
        'referer',
        'cookie',
        'accept',
    ];

    // Array of accepted HTML tags for wrap_in()
    private array $acceptedWrapInTags = [
        'blockquote',
        'p',
        'div',
    ];

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = new ConfigBuilderConfig($config);

        $this->logger = $logger ?? new NullLogger();

        $this->loadConfigFiles();
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Load configuration files from filesystem.
     * The load is externalized into a dedicated public method so we can reload config files after a custom creation of a config file.
     *     - Config files are loaded when the class is instancied.
     *     - If we add a new file after, it won't be loaded.
     *     - We'll need to manually reload config files.
     */
    public function loadConfigFiles(): void
    {
        $this->configFiles = Files::getFiles($this->config->getSiteConfig());
    }

    /**
     * Add the given SiteConfig to the cache.
     *
     * @param string     $key    Key for the cache
     * @param SiteConfig $config Config to be cached
     */
    public function addToCache(string $key, SiteConfig $config): void
    {
        $key = strtolower($key);
        if (str_starts_with($key, 'www.')) {
            $key = substr($key, 4);
            // For PHPStan on PHP < 8.0: it cannot fail since the prefix checked above has four characters.
            \assert(false !== $key);
        }

        if ($config->cache_key) {
            $key = $config->cache_key;
        }

        $this->cache[$key] = $config;

        $this->logger->info('Cached site config with key: {key}', ['key' => $key]);
    }

    /**
     * Determine if a Config is already cached.
     * If so, return it otherwise return null.
     *
     * @param string $key Key for the cache
     */
    public function getCachedVersion(string $key): ?SiteConfig
    {
        $key = strtolower($key);
        if (str_starts_with($key, 'www.')) {
            $key = substr($key, 4);
            // For PHPStan on PHP < 8.0: it cannot fail since the prefix checked above has four characters.
            \assert(false !== $key);
        }

        if (\array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return null;
    }

    /**
     * Create a new config.
     */
    public function create(): SiteConfig
    {
        return new SiteConfig();
    }

    /**
     * Build a config file from an url.
     * Use `buildForHost` if you already have the host.
     */
    public function buildFromUrl(UriInterface $url, bool $addToCache = true): SiteConfig
    {
        return $this->buildForHost($url->getHost(), $addToCache);
    }

    /**
     * Build a config file from a host.
     * Use `buildFromUrl` if you have an url.
     *
     * @param string $host Host, like en.wikipedia.org
     */
    public function buildForHost(string $host, bool $addToCache = true): SiteConfig
    {
        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
            // For PHPStan on PHP < 8.0: it cannot fail since the prefix checked above has four characters.
            \assert(false !== $host);
        }

        // is merged version already cached?
        $cachedSiteConfig = $this->getCachedVersion($host . '.merged');
        if (null !== $cachedSiteConfig) {
            $this->logger->info('Returning cached and merged site config for {host}', ['host' => $host]);

            return $cachedSiteConfig;
        }

        // let's build it
        $config = $this->loadSiteConfig($host);
        if ($addToCache && null !== $config && null === $this->getCachedVersion($host)) {
            $this->addToCache($host, $config);
        }

        // if no match, use defaults
        if (null === $config) {
            $config = $this->create();
        }

        // load global config?
        $configGlobal = $this->loadSiteConfig('global', true);
        if ($config->autodetect_on_failure() && null !== $configGlobal) {
            $this->logger->info('Appending site config settings from global.txt');
            $this->mergeConfig($config, $configGlobal);

            if ($addToCache && null === $this->getCachedVersion('global')) {
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
     * Returns SiteConfig instance if an appropriate one is found, null otherwise.
     * by default if host is 'test.example.org' we will look for and load '.example.org.txt' if it exists.
     *
     * @param string $host           Host, like en.wikipedia.org
     * @param bool   $exactHostMatch if true, we will not look for wildcard config matches
     */
    public function loadSiteConfig(string $host, bool $exactHostMatch = false): ?SiteConfig
    {
        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        if (!$host || (\strlen($host) > 200) || !preg_match($this->config->getHostnameRegex(), ltrim($host, '.'))) {
            return null;
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
        $this->logger->info('. looking for site config for {host} in primary folder', ['host' => $host]);
        foreach ($try as $host) {
            if ($cachedConfig = $this->getCachedVersion($host)) {
                $this->logger->info('... site config for {host} already loaded in this request', ['host' => $host]);

                return $cachedConfig;
            }

            // if we found site config, process it
            if (isset($this->configFiles[$host . '.txt'])) {
                $this->logger->info('... found site config {host}', ['host' => $host . '.txt']);

                $configLines = file($this->configFiles[$host . '.txt'], \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
                // no lines ? we don't found config then
                if (empty($configLines) || !\is_array($configLines)) {
                    return null;
                }

                $config = $this->parseLines($configLines);
                $config->cache_key = $host;
                break;
            }
        }

        // append global config?
        if ('global' !== $host && $config->autodetect_on_failure() && isset($this->configFiles['global.txt'])) {
            $this->logger->info('Appending site config settings from global.txt');

            $configGlobal = $this->loadSiteConfig('global', true);
            if (null !== $configGlobal) {
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
    public function mergeConfig(SiteConfig $currentConfig, SiteConfig $newConfig): SiteConfig
    {
        // check for commands where we accept multiple statements (no test_url)
        foreach (['title', 'body', 'strip', 'strip_id_or_class', 'strip_image_src', 'single_page_link', 'next_page_link', 'date', 'author'] as $var) {
            // append array elements for this config variable from $newConfig to this config
            $currentConfig->$var = array_unique(array_merge($currentConfig->$var, $newConfig->$var));
        }

        // special handling of if_page_contains directive
        foreach (['single_page_link'] as $var) {
            if (isset($currentConfig->if_page_contains[$var]) && isset($newConfig->if_page_contains[$var])) {
                $currentConfig->if_page_contains[$var] = array_merge($newConfig->if_page_contains[$var], $currentConfig->if_page_contains[$var]);
            } elseif (isset($newConfig->if_page_contains[$var])) {
                $currentConfig->if_page_contains[$var] = $newConfig->if_page_contains[$var];
            }
        }

        // check for single statement commands
        // we do not overwrite existing non null values
        foreach (['tidy', 'prune', 'parser', 'autodetect_on_failure', 'requires_login', 'skip_json_ld'] as $var) {
            if ($currentConfig->$var === null) {
                $currentConfig->$var = $newConfig->$var;
            }
        }

        // merge http_header array from currentConfig into newConfig
        // because final values override former values in case of named keys
        $currentConfig->http_header = array_merge($newConfig->http_header, $currentConfig->http_header);

        // Complex solution to ensure find_string & replace_string aren't duplicated when merging config multiple times
        // We can't perform an array_unique on these values mostly because replace_string can have same values, example:
        //      find_string: <amp-img
        //      replace_string: <img
        //      find_string: <other-img
        //      replace_string: <img
        // To fix that issue, we combine find & replace as key & value in one array, we merge them and then rebuild find & replace string in the current config

        $findReplaceCurrentConfig = array_combine($currentConfig->find_string, $currentConfig->replace_string);
        $findReplaceNewConfig = array_combine($newConfig->find_string, $newConfig->replace_string);
        $findReplaceMerged = array_merge($findReplaceCurrentConfig ?: [], $findReplaceNewConfig ?: []);

        // start from scratch
        $currentConfig->find_string = [];
        $currentConfig->replace_string = [];

        foreach ($findReplaceMerged as $findString => $replaceString) {
            $currentConfig->find_string[] = $findString;
            $currentConfig->replace_string[] = $replaceString;
        }

        return $currentConfig;
    }

    /**
     * Parse line from the config file to build the config.
     */
    public function parseLines(array $lines): SiteConfig
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
            if ('' === $command) {
                continue;
            }

            // strip_attr is now an alias for strip, for example:
            // strip_attr: //img/@srcset
            if ('strip_attr' === $command) {
                $command = 'strip';
            }

            if (\in_array($command, ['title', 'body', 'strip', 'strip_id_or_class', 'strip_image_src', 'single_page_link', 'next_page_link', 'test_url', 'find_string', 'replace_string', 'login_extra_fields', 'native_ad_clue', 'date', 'author'], true)) {
                // check for commands where we accept multiple statements
                $config->$command[] = $val;
            } elseif (\in_array($command, ['tidy', 'prune', 'autodetect_on_failure', 'requires_login', 'skip_json_ld'], true)) {
                // check for single statement commands that evaluate to true or false
                $config->$command = ('yes' === $val || 'true' === $val);
            } elseif (\in_array($command, ['parser', 'login_username_field', 'login_password_field', 'not_logged_in_xpath', 'login_uri', 'src_lazy_load_attr'], true)) {
                // check for single statement commands stored as strings
                $config->$command = $val;
            } elseif (str_ends_with($command, ')') && preg_match('!^([a-z0-9_]+)\((.*?)\)$!i', $command, $match) && 'replace_string' === $match[1]) {
                // check for replace_string(find): replace
                $config->find_string[] = $match[2];
                $config->replace_string[] = $val;
            } elseif (str_ends_with($command, ')') && preg_match('!^([a-z0-9_]+)\(([a-z0-9_-]+)\)$!i', $command, $match) && 'http_header' === $match[1] && \in_array(strtolower($match[2]), $this->acceptedHeaders, true)) {
                $config->http_header[strtolower(trim($match[2]))] = $val;
            } elseif (\in_array($command, ['if_page_contains'], true)) {
                // special treatment for if_page_contains
                $this->handleIfPageContainsCondition($config, $val);
            } elseif (str_ends_with($command, ')') && preg_match('!([a-z0-9_]+)\(([a-z]+)\)$!i', $command, $match) && 'wrap_in' === $match[1] && \in_array(strtolower($match[2]), $this->acceptedWrapInTags, true)) {
                $config->wrap_in[strtolower(trim($match[2]))] = $val;
            }
        }

        // in case of bad configuration
        if (\count($config->find_string) !== \count($config->replace_string)) {
            $this->logger->warning('find_string & replace_string size mismatch, check the site config to fix it', ['find_string' => $config->find_string, 'replace_string' => $config->replace_string]);

            $config->find_string = [];
            $config->replace_string = [];
        }

        return $config;
    }

    /**
     * Build `if_page_contains` rule based on other previous rules defined for:
     *     - single_page_link.
     *     - next_page_link.
     *
     * First one has priority over the next one.
     *
     * @param SiteConfig $config    Current config
     * @param string     $condition XPath condition
     */
    private function handleIfPageContainsCondition(SiteConfig $config, string $condition): void
    {
        $rule = false;
        if (!empty($config->single_page_link)) {
            $rule = 'single_page_link';
        } elseif (!empty($config->next_page_link)) {
            $rule = 'next_page_link';
        }

        // no link found, we can't apply "if_page_contains"
        if ($rule) {
            $key = end($config->$rule);
            reset($config->$rule);

            $config->if_page_contains[$rule][$key] = (string) $condition;
        }
    }
}
