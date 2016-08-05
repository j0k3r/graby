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
    private $xpath = null;
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
                '/\<meta\s*content=([\'"])blogger([\'"])\s*name=([\'"])generator([\'"])/i' => 'fingerprint.blogspot.com',
                '/\<meta\s*name=([\'"])generator([\'"])\s*content=([\'"])Blogger([\'"])/i' => 'fingerprint.blogspot.com',
                '/\<meta\s*name=([\'"])generator([\'"])\s*content=([\'"])WordPress/i' => 'fingerprint.wordpress.com',
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
        $this->xpath = null;
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
     * @param bool   $addToCache
     *
     * @return SiteConfig
     */
    public function buildSiteConfig($url, $html = '', $addToCache = true)
    {
        $config = $this->configBuilder->buildFromUrl($url, $addToCache);

        // load fingerprint config?
        if (true !== $config->autodetect_on_failure()) {
            return $config;
        }

        // check HTML for fingerprints
        $fingerprintHost = $this->findHostUsingFingerprints($html);
        if (false === $fingerprintHost) {
            return $config;
        }

        $configFingerprint = $this->configBuilder->buildForHost($fingerprintHost);

        if (!empty($this->config['fingerprints']) && false !== $configFingerprint) {
            $this->logger->log('debug', 'Appending site config settings from {host} (fingerprint match)', array('host' => $fingerprintHost));
            $this->configBuilder->mergeConfig($config, $configFingerprint);

            if ($addToCache && false === $this->configBuilder->getCachedVersion($fingerprintHost)) {
                $this->configBuilder->addToCache($fingerprintHost, $configFingerprint);
            }
        }

        return $config;
    }

    /**
     * $smartTidy indicates that if tidy is used and no results are produced, we will try again without it.
     * Tidy helps us deal with PHP's patchy HTML parsing most of the time
     * but it has problems of its own which we try to avoid with this option.
     *
     * @param string     $html
     * @param string     $url
     * @param SiteConfig $siteConfig Will avoid to recalculate the site config
     * @param bool       $smartTidy  Do we need to tidy the html ?
     *
     * @return bool true on success, false on failure
     */
    public function process($html, $url, SiteConfig $siteConfig = null, $smartTidy = true)
    {
        $this->reset();

        $this->siteConfig = $siteConfig;
        if (null === $this->siteConfig) {
            $this->siteConfig = $this->buildSiteConfig($url, $html);
        }

        // do string replacements
        if (!empty($this->siteConfig->find_string)) {
            if (count($this->siteConfig->find_string) == count($this->siteConfig->replace_string)) {
                $html = str_replace($this->siteConfig->find_string, $this->siteConfig->replace_string, $html, $count);
                $this->logger->log('debug', 'Strings replaced: {count} (find_string and/or replace_string)', array('count' => $count));
            } else {
                $this->logger->log('debug', 'Skipped string replacement - incorrect number of find-replace strings in site config');
            }
            unset($count);
        }

        // load and parse html
        $parser = $this->siteConfig->parser();

        if (!in_array($parser, $this->config['allowed_parsers'])) {
            $this->logger->log('debug', 'HTML parser {parser} not listed, using {default_parser} instead', array('parser' => $parser, 'default_parser' => $this->config['default_parser']));
            $parser = $this->config['default_parser'];
        }

        $this->logger->log('debug', 'Attempting to parse HTML with {parser}', array('parser' => $parser));
        $this->readability = new Readability($html, $url, $parser, $this->siteConfig->tidy() && $smartTidy);
        $tidied = $this->readability->tidied;

        // we use xpath to find elements in the given HTML document
        $this->xpath = new \DOMXPath($this->readability->dom);

        // try to get next page link
        // @todo: should we test if the link is actually a link?
        foreach ($this->siteConfig->next_page_link as $pattern) {
            $elems = $this->xpath->evaluate($pattern, $this->readability->dom);

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
            $elems = $this->xpath->evaluate($pattern, $this->readability->dom);

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
            $elems = $this->xpath->evaluate($pattern, $this->readability->dom);

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
            $elems = $this->xpath->query($pattern, $this->readability->dom);

            $this->removeElements($elems, 'Stripping {length} elements (strip)');
        }

        // strip elements (using id and class attribute values)
        foreach ($this->siteConfig->strip_id_or_class as $string) {
            $this->logger->log('debug', 'Trying {string} to strip element', array('string' => $string));
            $string = strtr($string, array("'" => '', '"' => ''));
            $elems = $this->xpath->query("//*[contains(@class, '$string') or contains(@id, '$string')]", $this->readability->dom);

            $this->removeElements($elems, 'Stripping {length} elements (strip_id_or_class)');
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
        $elems = $this->xpath->query("//*[contains(concat(' ',normalize-space(@class),' '),' entry-unrelated ') or contains(concat(' ',normalize-space(@class),' '),' instapaper_ignore ')]", $this->readability->dom);

        $this->removeElements($elems, 'Stripping {length} .entry-unrelated,.instapaper_ignore elements');

        // strip elements that contain style 'display: none' or 'visibility:hidden'
        // @todo: inline style are convert to <style> by tidy, so we can't remove hidden content ...
        $elems = $this->xpath->query("//*[contains(@style,'display:none') or contains(@style,'visibility:hidden')]", $this->readability->dom);

        $this->removeElements($elems, 'Stripping {length} elements with inline display:none or visibility:hidden style');

        // try to get body
        foreach ($this->siteConfig->body as $pattern) {
            $this->logger->log('debug', 'Trying {pattern} for body', array('pattern' => $pattern));

            $res = $this->extractBody(
                true,
                $pattern,
                $this->readability->dom,
                'XPath'
            );

            // this mean we have *found* a body, so we don't need to continue
            if (false === $res) {
                break;
            }
        }

        // auto detect?
        $detectTitle = $detectBody = false;

        // detect title?
        if (!isset($this->title) && (empty($this->siteConfig->title) || $this->siteConfig->autodetect_on_failure())) {
            $detectTitle = true;
        }
        // detect body?
        if (!isset($this->body) && (empty($this->siteConfig->body) || $this->siteConfig->autodetect_on_failure())) {
            $detectBody = true;
        }

        // check for hNews
        if ($detectTitle || $detectBody) {
            // check for hentry
            $elems = $this->xpath->query("//*[contains(concat(' ',normalize-space(@class),' '),' hentry ')]", $this->readability->dom);

            if ($this->hasElements($elems)) {
                $this->logger->log('debug', 'hNews: found hentry');
                $hentry = $elems->item(0);

                // check for entry-title
                $detectTitle = $this->extractTitle(
                    $detectTitle,
                    'entry-title',
                    $hentry,
                    'hNews: found entry-title: {title}'
                );

                // check for entry-content.
                // according to hAtom spec, if there are multiple elements marked entry-content,
                // we include all of these in the order they appear - see http://microformats.org/wiki/hatom#Entry_Content
                $detectBody = $this->extractBody(
                    $detectBody,
                    ".//*[contains(concat(' ',normalize-space(@class),' '),' entry-content ')]",
                    $hentry,
                    'hNews'
                );
            }
        }

        // check for elements marked with instapaper_title
        $detectTitle = $this->extractTitle(
            $detectTitle,
            'instapaper_title',
            $this->readability->dom,
            'Title found (.instapaper_title): {title}'
        );

        // check for elements marked with instapaper_body
        $detectBody = $this->extractBody(
            $detectBody,
            "//*[contains(concat(' ',normalize-space(@class),' '),' instapaper_body ')]",
            $this->readability->dom,
            'instapaper'
        );

        // check for elements marked with itemprop="articleBody" (from Schema.org)
        $detectBody = $this->extractBody(
            $detectBody,
            "//*[@itemprop='articleBody']",
            $this->readability->dom,
            'Schema.org'
        );

        // still missing title or body, so we detect using Readability
        $success = false;
        if ($detectTitle || $detectBody) {
            $this->logger->log('debug', 'Using Readability');
            // clone body if we're only using Readability for title (otherwise it may interfere with body element)
            if (isset($this->body)) {
                $this->body = $this->body->cloneNode(true);
            }
            $success = $this->readability->init();
        }

        if ($detectTitle && $this->readability->getTitle()) {
            $this->title = $this->readability->getTitle()->textContent;
            $this->logger->log('debug', 'Detected title: {title}', array('title' => $this->title));
        }

        if ($detectBody && $success) {
            $this->logger->log('debug', 'Detecting body');
            $this->body = $this->readability->getContent();

            if ($this->body->childNodes->length === 1 && $this->body->firstChild->nodeType === XML_ELEMENT_NODE) {
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
            if (isset($this->title) && $this->title != '' && null !== $this->body->firstChild) {
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

            // prevent self-closing iframe when content is ONLY an iframe
            if ('iframe' === $this->body->nodeName && !$this->body->hasChildNodes()) {
                $this->body->appendChild($this->body->ownerDocument->createTextNode('[embedded content]'));
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
                    $newElem = $e->ownerDocument->createDocumentFragment();
                    $newElem->appendXML($e->nextSibling->innerHTML);
                    $e->nextSibling->parentNode->replaceChild($newElem, $e->nextSibling);
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
        if (!$this->success && $tidied && $smartTidy) {
            unset($this->body, $this->xpath);

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

    /**
     * Check if given node list exists and has length more than 0.
     *
     * @param \DOMNodeList $elems
     *
     * @return bool
     */
    private function hasElements(\DOMNodeList $elems)
    {
        return $elems && $elems->length > 0;
    }

    /**
     * Remove elements.
     *
     * @param \DOMNodeList $elems
     * @param string       $logMessage
     */
    private function removeElements(\DOMNodeList $elems, $logMessage = null)
    {
        if (false === $this->hasElements($elems)) {
            return;
        }

        if (null !== $logMessage) {
            $this->logger->log('debug', $logMessage, array('length' => $elems->length));
        }

        for ($i = $elems->length - 1; $i >= 0; --$i) {
            if ($elems->item($i)->parentNode) {
                $elems->item($i)->parentNode->removeChild($elems->item($i));
            }
        }
    }

    /**
     * Extract title for a given CSS class a node.
     *
     * @param bool    $detectTitle Do we have to detect title ?
     * @param string  $cssClass    CSS class to look for
     * @param DOMNode $node        DOMNode to look into
     * @param string  $logMessage
     *
     * @return bool Telling if we have to detect title again or not
     */
    private function extractTitle($detectTitle, $cssClass, \DOMNode $node, $logMessage)
    {
        if (false === $detectTitle) {
            return false;
        }

        // check for given css class
        $elems = $this->xpath->query(".//*[contains(concat(' ',normalize-space(@class),' '),' ".$cssClass." ')]", $node);

        if (false === $this->hasElements($elems)) {
            return $detectTitle;
        }

        $this->title = $elems->item(0)->textContent;
        $this->logger->log('debug', $logMessage, array('title' => $this->title));
        // remove title from document
        $elems->item(0)->parentNode->removeChild($elems->item(0));

        return false;
    }

    /**
     * Extract body from a given CSS for a node.
     *
     * @param bool     $detectBody      Do we have to detect body ?
     * @param string   $xpathExpression XPath expression to extract body
     * @param \DOMNode $node            DOMNode to look into
     * @param string   $type            Format type we are looking for, only used for log message
     *
     * @return bool Telling if we have to detect body again or not
     */
    private function extractBody($detectBody, $xpathExpression, \DOMNode $node, $type)
    {
        if (false === $detectBody) {
            return false;
        }

        $elems = $this->xpath->query($xpathExpression, $node);

        if (false === $this->hasElements($elems)) {
            return $detectBody;
        }

        $this->logger->log('debug', $type.': found "'.$elems->length.'" with '.$xpathExpression);

        if ($elems->length === 1) {
            $this->body = $elems->item(0);

            // prune (clean up elements that may not be content)
            if ($this->siteConfig->prune()) {
                $this->logger->log('debug', 'Pruning content');
                $this->readability->prepArticle($this->body);
            }

            return false;
        }

        $this->body = $this->readability->dom->createElement('div');
        $this->logger->log('debug', '{nb} body elems found', array('nb' => $elems->length));
        $len = 0;

        foreach ($elems as $elem) {
            if (!isset($elem->parentNode)) {
                continue;
            }

            $isDescendant = false;
            foreach ($this->body->childNodes as $parent) {
                $node = $elem->parentNode;
                while ($node !== null) {
                    if ($node->isSameNode($parent)) {
                        $isDescendant = true;
                        break 2;
                    }
                    $node = $node->parentNode;
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

        return false;
    }
}
