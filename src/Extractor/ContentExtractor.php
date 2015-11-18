<?php

namespace Graby\Extractor;

use Symfony\Component\OptionsResolver\OptionsResolver;
use Readability\Readability;
use Graby\SiteConfig\ConfigBuilder;
use Graby\SiteConfig\SiteConfig;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;

/**
 * Content Extractor.
 *
 * Uses patterns specified in site config files and auto detection (hNews/PHP Readability)
 * to extract content from HTML files.
 */
class ContentExtractor
{
    private $html = null;
    private $config;
    private $siteConfig = null;
    private $title = null;
    private $language = null;
    private $body = null;
    private $success = false;
    private $nextPageUrl = null;
    private $logger;
    private $configBuilder = null;

    public $readability = null;

    /**
     * @param array                $config
     * @param LoggerInterface|null $logger
     */
    public function __construct($config = array(), LoggerInterface $logger = null)
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults(array(
            'default_parser' => 'libxml',
            'allowed_parsers' => array('libxml', 'html5lib'),
            // key is fingerprint (fragment to find in HTML)
            // value is host name to use for site config lookup if fingerprint matches
            // \s* match anything INCLUDING new lines
            'fingerprints' => array(
                '/\<meta\s*content=\"blogger\"\s*name=\"generator\"/i' => 'fingerprint.blogspot.com',
                '/\<meta\s*name=\"generator\"\s*content=\"Blogger\"/i' => 'fingerprint.blogspot.com',
                '/\<meta\s*name=\"generator\"\s*content=\"WordPress/i' => 'fingerprint.wordpress.com',
            ),
            'config_builder' => array(),
        ));

        $this->config = $resolver->resolve($config);

        $this->logger = $logger;
        if (null === $logger) {
            $this->logger = new NullLogger();
        }

        // Set up Content Extractor
        $this->configBuilder = new ConfigBuilder($this->config['config_builder'], $this->logger);
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->configBuilder->setLogger($logger);
    }

    public function reset()
    {
        $this->html = null;
        $this->readability = null;
        $this->siteConfig = null;
        $this->title = null;
        $this->body = null;
        $this->language = null;
        $this->nextPageUrl = null;
        $this->success = false;
    }

    /**
     * Try to find a host depending on a meta that can be in the html.
     * It allow to determine if a website is generated using Wordpress, Blogger, etc ..
     *
     * @param string $html
     *
     * @return string|false
     */
    public function findHostUsingFingerprints($html)
    {
        foreach ($this->config['fingerprints'] as $metaPattern => $host) {
            if (1 === preg_match($metaPattern, $html)) {
                return $host;
            }
        }

        return false;
    }

    /**
     * Returns SiteConfig instance (joined in order: exact match, wildcard, fingerprint, global, default).
     *
     * @param string $url
     * @param string $html
     * @param bool   $add_to_cache
     *
     * @return Graby\SiteConfig\SiteConfig
     */
    public function buildSiteConfig($url, $html = '', $add_to_cache = true)
    {
        // extract host name
        $host = parse_url($url, PHP_URL_HOST);
        $host = strtolower($host);
        if (substr($host, 0, 4) == 'www.') {
            $host = substr($host, 4);
        }

        // is merged version already cached?
        if ($siteConfig = $this->configBuilder->getCachedVersion($host.'.merged')) {
            $this->logger->log('debug', 'Returning cached and merged site config for {host}', array('host' => $host));

            return $siteConfig;
        }

        // let's build from site_config/custom/ and standard/
        $config = $this->configBuilder->build($host);
        if ($add_to_cache && $config && !$this->configBuilder->getCachedVersion($host)) {
            $this->configBuilder->addToCache($host, $config);
        }

        // if no match, use defaults
        if (false === $config) {
            $config = $this->configBuilder->create();
        }

        // load fingerprint config?
        if ($config->autodetect_on_failure()) {
            // check HTML for fingerprints
            $_fphost = $this->findHostUsingFingerprints($html);

            if (false !== $_fphost) {
                $config_fingerprint = $this->configBuilder->build($_fphost);

                if (!empty($this->config['fingerprints']) && false !== $config_fingerprint) {
                    $this->logger->log('debug', 'Appending site config settings from {host} (fingerprint match)', array('host' => $_fphost));
                    $this->configBuilder->mergeConfig($config, $config_fingerprint);

                    if ($add_to_cache && !$this->configBuilder->getCachedVersion($_fphost)) {
                        $this->configBuilder->addToCache($_fphost, $config_fingerprint);
                    }
                }
            }
        }

        // load global config?
        $config_global = $this->configBuilder->build('global', true);
        if ($config->autodetect_on_failure() && false !== $config_global) {
            $this->logger->log('debug', 'Appending site config settings from global.txt');
            $this->configBuilder->mergeConfig($config, $config_global);

            if ($add_to_cache && !$this->configBuilder->getCachedVersion('global')) {
                $this->configBuilder->addToCache('global', $config_global);
            }
        }

        // store copy of merged config
        if ($add_to_cache) {
            $config->cache_key = null;
            $this->configBuilder->addToCache("$host.merged", $config);
        }

        return $config;
    }

    /**
     * $smart_tidy indicates that if tidy is used and no results are produced, we will try again without it.
     * Tidy helps us deal with PHP's patchy HTML parsing most of the time
     * but it has problems of its own which we try to avoid with this option.
     *
     * @param string     $html
     * @param string     $url
     * @param SiteConfig $siteConfig Will avoid to recalculate the site config
     * @param bool       $smart_tidy Do we need to tidy the html ?
     *
     * @return bool true on success, false on failure
     */
    public function process($html, $url, SiteConfig $siteConfig = null, $smart_tidy = true)
    {
        $this->reset();

        $this->siteConfig = $siteConfig;
        if (null === $this->siteConfig) {
            $this->siteConfig = $this->buildSiteConfig($url, $html);
        }

        // do string replacements
        if (!empty($this->siteConfig->find_string)) {
            if (count($this->siteConfig->find_string) == count($this->siteConfig->replace_string)) {
                $html = str_replace($this->siteConfig->find_string, $this->siteConfig->replace_string, $html, $_count);
                $this->logger->log('debug', 'Strings replaced: {count} (find_string and/or replace_string)', array('count' => $_count));
            } else {
                $this->logger->log('debug', 'Skipped string replacement - incorrect number of find-replace strings in site config');
            }
            unset($_count);
        }

        // load and parse html
        $parser = $this->siteConfig->parser();

        if (!in_array($parser, $this->config['allowed_parsers'])) {
            $this->logger->log('debug', 'HTML parser {parser} not listed, using {default_parser} instead', array('parser' => $parser, 'default_parser' => $this->config['default_parser']));
            $parser = $this->config['default_parser'];
        }

        $this->logger->log('debug', 'Attempting to parse HTML with {parser}', array('parser' => $parser));
        $this->readability = new Readability($html, $url, $parser, $this->siteConfig->tidy() && $smart_tidy);
        $tidied = $this->readability->tidied;

        // we use xpath to find elements in the given HTML document
        $xpath = new \DOMXPath($this->readability->dom);

        // try to get next page link
        // @todo: should we test if the link is actually a link?
        foreach ($this->siteConfig->next_page_link as $pattern) {
            $elems = $xpath->evaluate($pattern, $this->readability->dom);

            if (is_string($elems)) {
                $this->nextPageUrl = trim($elems);
                break;
            } elseif ($elems instanceof \DOMNodeList && $elems->length > 0) {
                foreach ($elems as $item) {
                    if ($item instanceof \DOMElement && $item->hasAttribute('href')) {
                        $this->nextPageUrl = $item->getAttribute('href');
                        break 2;
                    } elseif ($item instanceof \DOMAttr && $item->value) {
                        $this->nextPageUrl = $item->value;
                        break 2;
                    }
                }
            }
        }

        // try to get title
        foreach ($this->siteConfig->title as $pattern) {
            $this->logger->log('debug', 'Trying {pattern} for title', array('pattern' => $pattern));
            $elems = $xpath->evaluate($pattern, $this->readability->dom);

            if (is_string($elems)) {
                $this->title = trim($elems);
                $this->logger->log('debug', 'Title expression evaluated as string: {title}', array('title' => $this->title));
                $this->logger->log('debug', '...XPath match: {pattern}', array('pattern', $pattern));
                break;
            } elseif ($elems instanceof \DOMNodeList && $elems->length > 0) {
                $this->title = $elems->item(0)->textContent;
                $this->logger->log('debug', 'Title matched: {title}', array('title' => $this->title));
                $this->logger->log('debug', '...XPath match: {pattern}', array('pattern', $pattern));

                // remove title from document
                try {
                    $elems->item(0)->parentNode->removeChild($elems->item(0));
                } catch (\DOMException $e) {
                    // do nothing
                }
                break;
            }
        }

        // try to get language
        $langXpath = array('//html[@lang]/@lang', '//meta[@name="DC.language"]/@content');
        foreach ($langXpath as $pattern) {
            $this->logger->log('debug', 'Trying {pattern} for language', array('pattern' => $pattern));
            $elems = $xpath->evaluate($pattern, $this->readability->dom);

            if ($elems instanceof \DOMNodeList && $elems->length > 0) {
                foreach ($elems as $elem) {
                    $this->language = trim($elem->textContent);
                    $this->logger->log('debug', 'Language matched: {language}', array('language' => $this->language));
                }

                if (null !== $this->language) {
                    break;
                }
            }
        }

        // strip elements (using xpath expressions)
        foreach ($this->siteConfig->strip as $pattern) {
            $this->logger->log('debug', 'Trying {pattern} to strip element', array('pattern' => $pattern));
            $elems = $xpath->query($pattern, $this->readability->dom);

            // check for matches
            if ($elems && $elems->length > 0) {
                $this->logger->log('debug', 'Stripping {length} elements (strip)', array('length' => $elems->length));
                for ($i = $elems->length - 1; $i >= 0; --$i) {
                    if ($elems->item($i)->parentNode) {
                        $elems->item($i)->parentNode->removeChild($elems->item($i));
                    }
                }
            }
        }

        // strip elements (using id and class attribute values)
        foreach ($this->siteConfig->strip_id_or_class as $string) {
            $this->logger->log('debug', 'Trying {string} to strip element', array('string' => $string));
            $string = strtr($string, array("'" => '', '"' => ''));
            $elems = $xpath->query("//*[contains(@class, '$string') or contains(@id, '$string')]", $this->readability->dom);

            // check for matches
            if ($elems && $elems->length > 0) {
                $this->logger->log('debug', 'Stripping {length} elements (strip_id_or_class)', array('length' => $elems->length));
                for ($i = $elems->length - 1; $i >= 0; --$i) {
                    $elems->item($i)->parentNode->removeChild($elems->item($i));
                }
            }
        }

        // strip images (using src attribute values)
        foreach ($this->siteConfig->strip_image_src as $string) {
            $string = strtr($string, array("'" => '', '"' => ''));

            foreach ($this->readability->dom->getElementsByTagName('img') as $e) {
                if (strpos($e->getAttribute('src'), $string)) {
                    $e->parentNode->removeChild($e);
                }
            }
        }

        // strip elements using Readability.com and Instapaper.com ignore class names
        // .entry-unrelated and .instapaper_ignore
        // See https://www.readability.com/publishers/guidelines/#view-plainGuidelines
        // and http://blog.instapaper.com/post/730281947
        $elems = $xpath->query("//*[contains(concat(' ',normalize-space(@class),' '),' entry-unrelated ') or contains(concat(' ',normalize-space(@class),' '),' instapaper_ignore ')]", $this->readability->dom);
        // check for matches
        if ($elems && $elems->length > 0) {
            $this->logger->log('debug', 'Stripping {length} .entry-unrelated,.instapaper_ignore elements', array('length' => $elems->length));
            for ($i = $elems->length - 1; $i >= 0; --$i) {
                $elems->item($i)->parentNode->removeChild($elems->item($i));
            }
        }

        // strip elements that contain style 'display: none' or 'visibility:hidden'
        // @todo: inline style are convert to <style> by tidy, so we can't remove hidden content ...
        $elems = $xpath->query("//*[contains(@style,'display:none') or contains(@style,'visibility:hidden')]", $this->readability->dom);
        // check for matches
        if ($elems && $elems->length > 0) {
            $this->logger->log('debug', 'Stripping {length} elements with inline display:none or visibility:hidden style', array('length' => $elems->length));
            for ($i = $elems->length - 1; $i >= 0; --$i) {
                $elems->item($i)->parentNode->removeChild($elems->item($i));
            }
        }

        // try to get body
        foreach ($this->siteConfig->body as $pattern) {
            $this->logger->log('debug', 'Trying {pattern} for body', array('pattern' => $pattern));
            $elems = $xpath->query($pattern, $this->readability->dom);

            // check for matches
            if (false === $elems || $elems->length <= 0) {
                continue;
            }

            $this->logger->log('debug', 'Body matched');
            $this->logger->log('debug', '...XPath match: {pattern}, nb: {length}', array('pattern' => $pattern, 'length' => $elems->length));
            if ($elems->length == 1) {
                $this->body = $elems->item(0);

                // prune (clean up elements that may not be content)
                if ($this->siteConfig->prune()) {
                    $this->logger->log('debug', '...pruning content');
                    $this->readability->prepArticle($this->body);
                }
                break;
            } else {
                $this->body = $this->readability->dom->createElement('div');
                $len = 0;
                $this->logger->log('debug', '{nb} body elems found', array('nb' => $elems->length));

                foreach ($elems as $elem) {
                    if (!isset($elem->parentNode)) {
                        continue;
                    }

                    $isDescendant = false;

                    foreach ($this->body->childNodes as $parent) {
                        if ($this->isDescendant($parent, $elem)) {
                            $isDescendant = true;
                            break;
                        }
                    }

                    if ($isDescendant) {
                        $this->logger->log('debug', '...element is child of another body element, skipping.');
                    } else {
                        // prune (clean up elements that may not be content)
                        if ($this->siteConfig->prune()) {
                            $this->logger->log('debug', '...pruning content');
                            $this->readability->prepArticle($elem);
                        }

                        if ($elem) {
                            ++$len;
                            $this->body->appendChild($elem);
                        }
                    }
                }

                $this->logger->log('debug', '...{len} elements added to body', array('len' => $len));
                unset($len);

                if ($this->body->hasChildNodes()) {
                    break;
                }
            }
        }

        // auto detect?
        $detect_title = $detect_body = false;

        // detect title?
        if (!isset($this->title) && (empty($this->siteConfig->title) || $this->siteConfig->autodetect_on_failure())) {
            $detect_title = true;
        }
        // detect body?
        if (!isset($this->body) && (empty($this->siteConfig->body) || $this->siteConfig->autodetect_on_failure())) {
            $detect_body = true;
        }

        // check for hNews
        if ($detect_title || $detect_body) {
            // check for hentry
            $elems = $xpath->query("//*[contains(concat(' ',normalize-space(@class),' '),' hentry ')]", $this->readability->dom);

            if ($elems && $elems->length > 0) {
                $this->logger->log('debug', 'hNews: found hentry');
                $hentry = $elems->item(0);

                if ($detect_title) {
                    // check for entry-title
                    $elems = $xpath->query(".//*[contains(concat(' ',normalize-space(@class),' '),' entry-title ')]", $hentry);

                    if ($elems && $elems->length > 0) {
                        $this->title = $elems->item(0)->textContent;
                        $this->logger->log('debug', 'hNews: found entry-title: {title}', array('title' => $this->title));
                        // remove title from document
                        $elems->item(0)->parentNode->removeChild($elems->item(0));
                        $detect_title = false;
                    }
                }

                // check for entry-content.
                // according to hAtom spec, if there are multiple elements marked entry-content,
                // we include all of these in the order they appear - see http://microformats.org/wiki/hatom#Entry_Content
                if ($detect_body) {
                    $elems = $xpath->query(".//*[contains(concat(' ',normalize-space(@class),' '),' entry-content ')]", $hentry);

                    if ($elems && $elems->length > 0) {
                        $this->logger->log('debug', 'hNews: found entry-content');
                        if ($elems->length == 1) {
                            // what if it's empty? (some sites misuse hNews - place their content outside an empty entry-content element)
                            $e = $elems->item(0);

                            if ((strtolower($e->nodeName) == 'img') || (trim($e->textContent) != '')) {
                                $this->body = $elems->item(0);
                                // prune (clean up elements that may not be content)
                                if ($this->siteConfig->prune()) {
                                    $this->logger->log('debug', 'Pruning content');
                                    $this->readability->prepArticle($this->body);
                                }
                                $detect_body = false;
                            } else {
                                $this->logger->log('debug', 'hNews: skipping entry-content - appears not to contain content');
                            }
                            unset($e);
                        } else {
                            $this->body = $this->readability->dom->createElement('div');
                            $this->logger->log('debug', '{nb} entry-content elems found', array('nb' => $elems->length));
                            $len = 0;

                            foreach ($elems as $elem) {
                                if (!isset($elem->parentNode)) {
                                    continue;
                                }

                                $isDescendant = false;
                                foreach ($this->body->childNodes as $parent) {
                                    if ($this->isDescendant($parent, $elem)) {
                                        $isDescendant = true;
                                        break;
                                    }
                                }

                                if ($isDescendant) {
                                    $this->logger->log('debug', '...element is child of another body element, skipping.');
                                } else {
                                    // prune (clean up elements that may not be content)
                                    if ($this->siteConfig->prune()) {
                                        $this->logger->log('debug', '...pruning content');
                                        $this->readability->prepArticle($elem);
                                    }

                                    if ($elem) {
                                        ++$len;
                                        $this->body->appendChild($elem);
                                    }
                                }
                            }

                            $this->logger->log('debug', '...{len} elements added to body', array('len' => $len));
                            unset($len);

                            $detect_body = false;
                        }
                    }
                }
            }
        }

        // check for elements marked with instapaper_title
        if ($detect_title) {
            // check for instapaper_title
            $elems = $xpath->query("//*[contains(concat(' ',normalize-space(@class),' '),' instapaper_title ')]", $this->readability->dom);

            if ($elems && $elems->length > 0) {
                $this->title = $elems->item(0)->textContent;
                $this->logger->log('debug', 'Title found (.instapaper_title): {title}', array('title' => $this->title));
                // remove title from document
                $elems->item(0)->parentNode->removeChild($elems->item(0));
                $detect_title = false;
            }
        }

        // check for elements marked with instapaper_body
        if ($detect_body) {
            $elems = $xpath->query("//*[contains(concat(' ',normalize-space(@class),' '),' instapaper_body ')]", $this->readability->dom);

            if ($elems && $elems->length > 0) {
                $this->logger->log('debug', 'body found (.instapaper_body)');
                $this->body = $elems->item(0);
                // prune (clean up elements that may not be content)
                if ($this->siteConfig->prune()) {
                    $this->logger->log('debug', 'Pruning content');
                    $this->readability->prepArticle($this->body);
                }
                $detect_body = false;
            }
        }

        // check for elements marked with itemprop="articleBody" (from Schema.org)
        if ($detect_body) {
            $elems = $xpath->query("//*[@itemprop='articleBody']", $this->readability->dom);

            if ($elems && $elems->length > 0) {
                $this->logger->log('debug', 'body found (Schema.org itemprop="articleBody")');
                if ($elems->length == 1) {
                    // what if it's empty? (content placed outside an empty itemprop='articleBody' element)
                    $e = $elems->item(0);

                    if ((strtolower($e->nodeName) == 'img') || (trim($e->textContent) != '')) {
                        $this->body = $elems->item(0);
                        // prune (clean up elements that may not be content)
                        if ($this->siteConfig->prune()) {
                            $this->logger->log('debug', 'Pruning content');
                            $this->readability->prepArticle($this->body);
                        }
                        $detect_body = false;
                    } else {
                        $this->logger->log('debug', 'Schema.org: skipping itemprop="articleBody" - appears not to contain content');
                    }
                    unset($e);
                } else {
                    $this->body = $this->readability->dom->createElement('div');
                    $this->logger->log('debug', '{nb} itemprop="articleBody" elems found', array('nb' => $elems->length));
                    $len = 0;

                    foreach ($elems as $elem) {
                        if (!isset($elem->parentNode)) {
                            continue;
                        }

                        $isDescendant = false;
                        foreach ($this->body->childNodes as $parent) {
                            if ($this->isDescendant($parent, $elem)) {
                                $isDescendant = true;
                                break;
                            }
                        }

                        if ($isDescendant) {
                            $this->logger->log('debug', '...element is child of another body element, skipping.');
                        } else {
                            // prune (clean up elements that may not be content)
                            if ($this->siteConfig->prune()) {
                                $this->logger->log('debug', '...pruning content');
                                $this->readability->prepArticle($elem);
                            }

                            if ($elem) {
                                ++$len;
                                $this->body->appendChild($elem);
                            }
                        }
                    }

                    $this->logger->log('debug', '...{len} elements added to body', array('len' => $len));
                    unset($len);

                    $detect_body = false;
                }
            }
        }

        // still missing title or body, so we detect using Readability
        $success = false;
        if ($detect_title || $detect_body) {
            $this->logger->log('debug', 'Using Readability');
            // clone body if we're only using Readability for title (otherwise it may interfere with body element)
            if (isset($this->body)) {
                $this->body = $this->body->cloneNode(true);
            }
            $success = $this->readability->init();
        }

        if ($detect_title && $this->readability->getTitle()) {
            $this->title = $this->readability->getTitle()->textContent;
            $this->logger->log('debug', 'Detected title: {title}', array('title' => $this->title));
        }

        if ($detect_body && $success) {
            $this->logger->log('debug', 'Detecting body');
            $this->body = $this->readability->getContent();

            if ($this->body->childNodes->length == 1 && $this->body->firstChild->nodeType === XML_ELEMENT_NODE) {
                $this->body = $this->body->firstChild;
            }

            // prune (clean up elements that may not be content)
            if ($this->siteConfig->prune()) {
                $this->logger->log('debug', 'Pruning content');
                $this->readability->prepArticle($this->body);
            }
        }

        if (isset($this->body)) {
            // remove any h1-h6 elements that appear as first thing in the body
            // and which match our title
            if (isset($this->title) && $this->title != '') {
                $firstChild = $this->body->firstChild;

                while ($firstChild->nextSibling != null && $firstChild->nodeType && ($firstChild->nodeType !== XML_ELEMENT_NODE)) {
                    $firstChild = $firstChild->nextSibling;
                }

                if ($firstChild->nodeType === XML_ELEMENT_NODE
                    && in_array(strtolower($firstChild->tagName), array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'))
                    && (strtolower(trim($firstChild->textContent)) == strtolower(trim($this->title)))) {
                    $this->body->removeChild($firstChild);
                }
            }

            // prevent self-closing iframes
            foreach ($this->body->getElementsByTagName('iframe') as $e) {
                if (!$e->hasChildNodes()) {
                    $e->appendChild($this->body->ownerDocument->createTextNode('[embedded content]'));
                }
            }

            // remove image lazy loading
            foreach ($this->body->getElementsByTagName('img') as $e) {
                if (!$e->hasAttribute('data-lazy-src') && !$e->hasAttribute('data-src')) {
                    continue;
                }

                // Custom case for WordPress plugin http://wordpress.org/extend/plugins/lazy-load/
                // the plugin replaces the src attribute to point to a 1x1 gif and puts the original src
                // inside the data-lazy-src attribute. It also places the original image inside a noscript element
                // next to the amended one.
                // @see https://plugins.trac.wordpress.org/browser/lazy-load/trunk/lazy-load.php
                if ($e->nextSibling !== null && $e->nextSibling->nodeName === 'noscript') {
                    $_new_elem = $e->ownerDocument->createDocumentFragment();
                    $_new_elem->appendXML($e->nextSibling->innerHTML);
                    $e->nextSibling->parentNode->replaceChild($_new_elem, $e->nextSibling);
                    $e->parentNode->removeChild($e);

                    continue;
                }

                $src = $e->getAttribute('data-src');
                $e->removeAttribute('data-src');

                if ($e->hasAttribute('data-lazy-src')) {
                    $src = $e->getAttribute('data-lazy-src');
                    $e->removeAttribute('data-lazy-src');
                }

                $e->setAttribute('src', $src);
            }

            $this->success = true;
        }

        // if we've had no success and we've used tidy, there's a chance
        // that tidy has messed up. So let's try again without tidy...
        if (!$this->success && $tidied && $smart_tidy) {
            unset($this->body, $xpath);

            $this->logger->log('debug', 'Trying again without tidy');

            return $this->process(
                $this->readability->original_html,
                $url,
                $this->siteConfig,
                false
            );
        }

        $this->logger->log('debug', 'Success ? {is_success}', array('is_success' => $this->success));

        return $this->success;
    }

    private function isDescendant(\DOMElement $parent, \DOMElement $child)
    {
        $node = $child->parentNode;
        while ($node != null) {
            if ($node->isSameNode($parent)) {
                return true;
            }
            $node = $node->parentNode;
        }

        return false;
    }

    public function getContent()
    {
        return $this->body;
    }

    public function getTitle()
    {
        return trim($this->title);
    }

    public function getLanguage()
    {
        return $this->language;
    }

    public function getSiteConfig()
    {
        return $this->siteConfig;
    }

    public function getNextPageUrl()
    {
        return $this->nextPageUrl;
    }
}
