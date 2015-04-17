<?php

namespace FullText\SiteConfig;

use Symfony\Component\OptionsResolver\OptionsResolver;

class ConfigBuilder
{
    private $debug = false;
    private $config = array();
    private $keySuffix = '';
    private $cache = array();

    public function __construct($config = array(), $debug = false)
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults(array(
            // Directory path to the standard config folder WITHOUT trailing slash
            'site_config_custom' => dirname(__FILE__).'/site_config/custom',
            // Fallback directory path (the custom one) WITHOUT trailing slash
            'site_config_standard' => dirname(__FILE__).'/site_config/standard',
            'hostname_regex' => '/^(([a-zA-Z0-9-]*[a-zA-Z0-9])\.)*([A-Za-z0-9-]*[A-Za-z0-9])$/',
        ));

        $resolver->setRequired('site_config_custom');
        $this->config = $resolver->resolve($config);

        $this->debug = $debug;

        // This is used to make sure that when a different primary folder is chosen
        // The key for the cached result includes that folder choice.
        // Otherwise, a subsequent request choosing a different folder
        // could return the wrong cached config.
        //
        // Which primary folder should we look inside?
        // If it's not the default ('custom'), we need
        // a key suffix to distinguish site config fules
        // held in this folder from those in other folders.
        $this->keySuffix = basename($this->config['site_config_custom']);
        if ($this->keySuffix === 'custom') {
            $this->keySuffix = '';
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

        $key .= '.'.$this->keySuffix;
        $this->cache[$key] = $config;

        // $this->debug("Cached site config with key $key");
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
        $key .= '.'.$this->keySuffix;
        if (substr($key, 0, 4) == 'www.') {
            $key = substr($key, 4);
        }

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return false;
    }

    private function debug($msg)
    {
        if (!$this->debug) {
            return;
        }

        //$mem = round(memory_get_usage()/1024, 2);
        //$memPeak = round(memory_get_peak_usage()/1024, 2);
        echo '* ',$msg;
        //echo ' - mem used: ',$mem," (peak: $memPeak)\n";
        echo "\n";
        ob_flush();
        flush();
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

        // check for site configuration
        $try = array($host);
        // should we look for wildcard matches
        if (!$exact_host_match) {
            $split = explode('.', $host);
            if (count($split) > 1) {
                array_shift($split);
                $try[] = '.'.implode('.', $split);
            }
        }

        // look for site config file in primary folder
        // $this->debug(". looking for site config for $host in primary folder");
        foreach ($try as $h) {
            if ($siteConfig = $this->getCachedVersion($h)) {
                // $this->debug("... site config for $h already loaded in this request");

                return $siteConfig;
            } elseif (file_exists($this->config['site_config_custom'].'/'.$h.'.txt')) {
                // $this->debug("... found site config ($h.txt)");
                $file_primary = $this->config['site_config_custom'].'/'.$h.'.txt';
                $matched_name = $h;
                break;
            }
        }

        // if we found site config, process it
        if (isset($file_primary)) {
            $config_lines = file($file_primary, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!$config_lines || !is_array($config_lines)) {
                return false;
            }

            $config = $this->parseLines($config_lines);
            $config->cache_key = $matched_name;

            // if autodetec on failure is off (on by default) we do not need to look
            // in secondary folder
            if (!$config->autodetect_on_failure()) {
                // $this->debug('... autodetect on failure is disabled (no other site config files will be loaded)');

                return $config;
            }
        }

        // look for site config file in secondary folder
        if (isset($this->config['site_config_standard'])) {
            // $this->debug(". looking for site config for $host in secondary folder");
            foreach ($try as $h) {
                if (file_exists($this->config['site_config_standard']."/$h.txt")) {
                    // $this->debug("... found site config in secondary folder ($h.txt)");
                    $file_secondary = $this->config['site_config_standard']."/$h.txt";
                    $matched_name = $h;
                    break;
                }
            }

            if (!isset($file_secondary)) {
                // $this->debug('... no site config match in secondary folder');
            }
        }

        // return false if no config file found
        if (!isset($file_primary) && !isset($file_secondary)) {
            // $this->debug("... no site config match for $host");

            return false;
        }

        // return primary config if secondary not found
        if (!isset($file_secondary) && isset($config)) {
            return $config;
        }

        // process secondary config file
        $config_lines = file($file_secondary, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$config_lines || !is_array($config_lines)) {
            // failed to process secondary
            if (isset($config)) {
                // return primary config
                return $config;
            }

            return false;
        }

        // merge with primary and return
        if (isset($config)) {
            // $this->debug('. merging config files');
            $config->append($this->parseLines($config_lines));

            return $config;
        }

        // return just secondary
        $config = $this->parseLines($config_lines);
        $config->cache_key = $matched_name;

        return $config;
    }

    /**
     * Append a configuration from to an existing one.
     *
     * @param SiteConfig $currentConfig Current configuration
     * @param SiteConfig $newConfig     New configuration to be merged
     */
    public function mergeConfig(SiteConfig $currentConfig, SiteConfig $newConfig)
    {
        // check for commands where we accept multiple statements (no test_url)
        foreach (array('title', 'body', 'author', 'date', 'strip', 'strip_id_or_class', 'strip_image_src', 'single_page_link', 'single_page_link_in_feed', 'next_page_link', 'http_header') as $var) {
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
            if (in_array($command, array('title', 'body', 'author', 'date', 'strip', 'strip_id_or_class', 'strip_image_src', 'single_page_link', 'single_page_link_in_feed', 'next_page_link', 'http_header', 'test_url', 'find_string', 'replace_string'))) {
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
