<?php

declare(strict_types=1);

namespace Graby\Extractor;

use Graby\SiteConfig\ConfigBuilder;
use Graby\SiteConfig\SiteConfig;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Readability\Readability;

/**
 * Content Extractor.
 *
 * Uses patterns specified in site config files and auto detection (hNews/PHP Readability)
 * to extract content from HTML files.
 */
class ContentExtractor
{
    private readonly ContentExtractorConfig $config;
    private LoggerInterface $logger;
    private readonly ConfigBuilder $configBuilder;

    /**
     * @param array{
     *   default_parser?: string,
     *   fingerprints?: array<string, string>,
     *   config_builder?: array{
     *     site_config?: string[],
     *     hostname_regex?: string,
     *   },
     *   readability?: array{
     *     pre_filters?: array<string, string>,
     *     post_filters?: array<string, string>,
     *   },
     *   src_lazy_load_attributes?: string[],
     *   json_ld_ignore_types?: string[],
     * } $config
     */
    public function __construct(array $config = [], ?LoggerInterface $logger = null, ?ConfigBuilder $configBuilder = null)
    {
        $this->config = new ContentExtractorConfig($config);

        $this->logger = $logger ?? new NullLogger();
        $this->configBuilder = $configBuilder ?? new ConfigBuilder($this->config->getConfigBuilder(), $this->logger);
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
        $this->configBuilder->setLogger($logger);
    }

    /**
     * Try to find a host depending on a meta that can be in the html.
     * It allow to determine if a website is generated using Wordpress, Blogger, etc ..
     */
    public function findHostUsingFingerprints(string $html): ?string
    {
        foreach ($this->config->getFingerprints() as $metaPattern => $host) {
            if (1 === preg_match($metaPattern, $html)) {
                return $host;
            }
        }

        return null;
    }

    /**
     * Returns SiteConfig instance (joined in order: exact match, wildcard, fingerprint, global, default).
     */
    public function buildSiteConfig(UriInterface $url, string $html = '', bool $addToCache = true): SiteConfig
    {
        $config = $this->configBuilder->buildFromUrl($url, $addToCache);

        // load fingerprint config?
        if (true !== $config->autodetect_on_failure()) {
            return $config;
        }

        // check HTML for fingerprints
        $fingerprintHost = $this->findHostUsingFingerprints($html);
        if (null === $fingerprintHost) {
            return $config;
        }

        $configFingerprint = $this->configBuilder->buildForHost($fingerprintHost);

        if (!empty($this->config->getFingerprints())) {
            $this->logger->info('Appending site config settings from {host} (fingerprint match)', ['host' => $fingerprintHost]);
            $this->configBuilder->mergeConfig($config, $configFingerprint);

            if ($addToCache && null === $this->configBuilder->getCachedVersion($fingerprintHost)) {
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
     * @param SiteConfig $siteConfig Will avoid to recalculate the site config
     * @param bool       $smartTidy  Do we need to tidy the html ?
     *
     * @return ExtractedContent The extracted content with all data and readability instance
     */
    public function process(string $html, UriInterface $url, ?SiteConfig $siteConfig = null, bool $smartTidy = true): ExtractedContent
    {
        // Initialize extraction state
        $readability = null;
        $xpath = null;
        $title = null;
        $language = null;
        $authors = [];
        $body = null;
        $image = null;
        $nativeAd = false;
        $date = null;
        $success = false;
        $nextPageUrl = null;

        $siteConfig = $this->prepareSiteConfig($html, $url, $siteConfig);

        $html = $this->processStringReplacements($html, $url, $siteConfig);

        // load and parse html
        $parser = $siteConfig->parser();

        if (!\in_array($parser, $this->config->getAllowedParsers(), true)) {
            $this->logger->info('HTML parser {parser} not listed, using {default_parser} instead', ['parser' => $parser, 'default_parser' => $this->config->getDefaultParser()]);
            $parser = $this->config->getDefaultParser();
        }

        $this->logger->info('Attempting to parse HTML with {parser}', ['parser' => $parser]);

        $readability = $this->getReadability($html, $url, $parser, $siteConfig->tidy() && $smartTidy);
        $tidied = $readability->tidied;

        $this->logger->info('Body size after Readability: {length}', ['length' => \strlen((string) $readability->dom->saveXML($readability->dom->documentElement))]);
        $this->logger->debug('Body after Readability', ['dom_saveXML' => $readability->dom->saveXML($readability->dom->documentElement)]);

        // we use xpath to find elements in the given HTML document
        $xpath = new \DOMXPath($readability->dom);

        // try to get next page link
        // @todo: should we test if the link is actually a link?
        foreach ($siteConfig->next_page_link as $pattern) {
            // Do we have conditions?
            $condition = $siteConfig->getIfPageContainsCondition('next_page_link', $pattern);

            if ($condition) {
                $elems = $xpath->evaluate($condition, $readability->dom);

                // move on to next next_page_link XPath in case condition isn't met
                if (!($elems instanceof \DOMNodeList && $elems->length > 0)) {
                    continue;
                }
            }

            $elems = $xpath->evaluate($pattern, $readability->dom);

            if (\is_string($elems)) {
                $nextPageUrl = trim($elems);
                break;
            } elseif ($elems instanceof \DOMNodeList && $elems->length > 0) {
                foreach ($elems as $item) {
                    if ($item instanceof \DOMElement && $item->hasAttribute('href')) {
                        $nextPageUrl = $item->getAttribute('href');
                        break 2;
                    } elseif ($item instanceof \DOMAttr && $item->value) {
                        $nextPageUrl = $item->value;
                        break 2;
                    }
                }
            }
        }

        // retrieve info from pre-defined source (OpenGraph / JSON-LD / etc.)
        $extractedInfo = $this->extractDefinedInformation($html, $siteConfig);
        $title = $extractedInfo['title'] ?? $title;
        $image = $extractedInfo['image'] ?? $image;
        $language = $extractedInfo['language'] ?? $language;
        $date = $extractedInfo['date'] ?? $date;
        $authors = array_merge($authors, $extractedInfo['authors'] ?? []);
        $body = $extractedInfo['body'] ?? $body;

        // check if this is a native ad
        foreach ($siteConfig->native_ad_clue as $pattern) {
            $elems = $xpath->evaluate($pattern, $readability->dom);

            if ($elems instanceof \DOMNodeList && $elems->length > 0) {
                $nativeAd = true;
                break;
            }
        }

        // try to get title
        foreach ($siteConfig->title as $pattern) {
            $this->logger->info('Trying {pattern} for title', ['pattern' => $pattern]);

            $extractedTitle = $this->extractEntityFromPattern('title', $pattern, $xpath, $readability);
            if (null !== $extractedTitle) {
                $title = $extractedTitle;
                break;
            }
        }

        // try to get author (if it hasn't already been set)
        if ([] === $authors) {
            foreach ($siteConfig->author as $pattern) {
                $this->logger->info('Trying {pattern} for author', ['pattern' => $pattern]);

                $extractedAuthors = $this->extractMultipleEntityFromPattern('authors', $pattern, $xpath, $readability);
                if (null !== $extractedAuthors) {
                    $authors = $extractedAuthors;
                    break;
                }
            }
        }

        // try to get date
        foreach ($siteConfig->date as $pattern) {
            $this->logger->info('Trying {pattern} for date', ['pattern' => $pattern]);

            $extractedDate = $this->extractEntityFromPattern('date', $pattern, $xpath, $readability);
            if (null !== $extractedDate) {
                $date = $extractedDate;
                break;
            }
        }

        // try to get language
        $langXpath = ['//html[@lang]/@lang', '//meta[@name="DC.language"]/@content'];
        foreach ($langXpath as $pattern) {
            $this->logger->info('Trying {pattern} for language', ['pattern' => $pattern]);
            $elems = $xpath->evaluate($pattern, $readability->dom);

            if ($elems instanceof \DOMNodeList && $elems->length > 0) {
                foreach ($elems as $elem) {
                    $language = trim($elem->textContent);
                    $this->logger->info('Language matched: {language}', ['language' => $language]);
                }

                if (null !== $language) {
                    break;
                }
            }
        }

        // wrapping matching elements with provided tag
        foreach ($siteConfig->wrap_in as $tag => $pattern) {
            $this->logger->info('Trying {pattern} to wrap element with {tag}', ['pattern' => $pattern, 'tag' => $tag]);
            $elems = $xpath->query($pattern, $readability->dom);

            if (false === $elems) {
                $this->logger->info('Bad pattern');

                continue;
            }

            $this->wrapElements($elems, $tag, 'Wrapping {length} elements (wrap_in)');
        }

        // strip elements (using xpath expressions)
        foreach ($siteConfig->strip as $pattern) {
            $this->logger->info('Trying {pattern} to strip element', ['pattern' => $pattern]);
            $elems = $xpath->query($pattern, $readability->dom);

            if (false === $elems) {
                $this->logger->info('Bad pattern');

                continue;
            }

            $this->removeElements($elems, 'Stripping {length} elements (strip)');
        }

        // strip elements (using id and class attribute values)
        foreach ($siteConfig->strip_id_or_class as $string) {
            $this->logger->info('Trying {string} to strip element', ['string' => $string]);
            $string = strtr($string, ["'" => '', '"' => '']);
            $elems = $xpath->query("//*[contains(concat(' ',normalize-space(@class), ' '),' $string ') or contains(concat(' ',normalize-space(@id),' '), ' $string ')]", $readability->dom);

            if (false === $elems) {
                $this->logger->info('Bad pattern');

                continue;
            }

            $this->removeElements($elems, 'Stripping {length} elements (strip_id_or_class)');
        }

        // strip images (using src attribute values)
        foreach ($siteConfig->strip_image_src as $string) {
            $string = strtr($string, ["'" => '', '"' => '']);

            foreach ($readability->dom->getElementsByTagName('img') as $e) {
                if (strpos($e->getAttribute('src'), $string)) {
                    $e->parentNode->removeChild($e);
                }
            }
        }

        // strip elements using Readability.com and Instapaper.com ignore class names
        // .entry-unrelated and .instapaper_ignore
        // See https://www.readability.com/publishers/guidelines/#view-plainGuidelines
        // and http://blog.instapaper.com/post/730281947
        $elems = $xpath->query("//*[contains(concat(' ',normalize-space(@class),' '),' entry-unrelated ') or contains(concat(' ',normalize-space(@class),' '),' instapaper_ignore ')]", $readability->dom);

        $this->removeElements($elems, 'Stripping {length} .entry-unrelated,.instapaper_ignore elements');

        // strip elements that contain style 'display: none' or 'visibility:hidden'
        // @todo: inline style are convert to <style> by tidy, so we can't remove hidden content ...
        $elems = $xpath->query("//*[contains(@style,'display:none') or contains(@style,'visibility:hidden')]", $readability->dom);

        $this->removeElements($elems, 'Stripping {length} elements with inline display:none or visibility:hidden style');

        // strip empty a elements
        $elems = $xpath->query("//a[not(./*) and normalize-space(.)='']", $readability->dom);

        $this->removeElements($elems, 'Stripping {length} empty a elements');

        $this->logger->debug('DOM after site config stripping', ['dom_saveXML' => $readability->dom->saveXML($readability->dom->documentElement)]);

        // try to get body
        foreach ($siteConfig->body as $pattern) {
            $this->logger->info('Trying {pattern} for body (content length: {content_length})', ['pattern' => $pattern, 'content_length' => \strlen((string) $readability->dom->saveXML($readability->dom->documentElement))]);

            $extractedBody = $this->extractBody(
                true,
                $pattern,
                $readability->dom,
                'XPath',
                $xpath,
                $readability,
                $siteConfig
            );

            // this mean we have *found* a body, so we don't need to continue
            if (null !== $extractedBody) {
                $body = $extractedBody;
                break;
            }
        }

        // auto detect?
        $detectTitle = $detectBody = $detectDate = $detectAuthor = false;

        // detect title?
        if (!isset($title) && (empty($siteConfig->title) || $siteConfig->autodetect_on_failure())) {
            $detectTitle = true;
        }
        // detect body?
        if (!isset($body) && (empty($siteConfig->body) || $siteConfig->autodetect_on_failure())) {
            $detectBody = true;
        }
        // detect date?
        if (!isset($date) && (empty($siteConfig->date) || $siteConfig->autodetect_on_failure())) {
            $detectDate = true;
        }
        // detect author?
        if ([] === $authors && (empty($siteConfig->author) || $siteConfig->autodetect_on_failure())) {
            $detectAuthor = true;
        }

        // check for hNews
        if ($detectTitle || $detectBody) {
            // check for hentry
            $elems = $xpath->query("//*[contains(concat(' ',normalize-space(@class),' '),' hentry ')]", $readability->dom);

            if (false !== $elems && $this->hasElements($elems)) {
                $this->logger->info('hNews: found hentry');
                $hentry = $elems->item(0);

                // check for entry-title
                $extractedTitle = $this->extractTitle(
                    $detectTitle,
                    'entry-title',
                    $hentry,
                    'hNews: found entry-title: {title}',
                    $xpath
                );
                if (null !== $extractedTitle) {
                    $title = $extractedTitle;
                    $detectTitle = false;
                }

                // check for published
                $extractedDate = $this->extractDate(
                    $detectDate,
                    'published',
                    $hentry,
                    'hNews: found publication date: {date}',
                    $xpath
                );
                if (null !== $extractedDate) {
                    $date = $extractedDate;
                    $detectDate = false;
                }

                $extractedAuthors = $this->extractAuthor(
                    $detectAuthor,
                    $hentry,
                    $xpath
                );
                if (null !== $extractedAuthors) {
                    $authors = array_merge($authors, $extractedAuthors);
                    $detectAuthor = false;
                }

                // check for entry-content.
                // according to hAtom spec, if there are multiple elements marked entry-content,
                // we include all of these in the order they appear - see http://microformats.org/wiki/hatom#Entry_Content
                $extractedBody = $this->extractBody(
                    $detectBody,
                    ".//*[contains(concat(' ',normalize-space(@class),' '),' entry-content ')]",
                    $hentry,
                    'hNews',
                    $xpath,
                    $readability,
                    $siteConfig
                );
                if (null !== $extractedBody) {
                    $body = $extractedBody;
                    $detectBody = false;
                }
            }
        }

        // check for elements marked with instapaper_title
        $extractedTitle = $this->extractTitle(
            $detectTitle,
            'instapaper_title',
            $readability->dom,
            'Title found (.instapaper_title): {title}',
            $xpath
        );
        if (null !== $extractedTitle) {
            $title = $extractedTitle;
            $detectTitle = false;
        }

        // check for elements marked with instapaper_body
        $extractedBody = $this->extractBody(
            $detectBody,
            "//*[contains(concat(' ',normalize-space(@class),' '),' instapaper_body ')]",
            $readability->dom,
            'instapaper',
            $xpath,
            $readability,
            $siteConfig
        );
        if (null !== $extractedBody) {
            $body = $extractedBody;
            $detectBody = false;
        }

        // check for elements marked with itemprop="articleBody" (from Schema.org)
        $extractedBody = $this->extractBody(
            $detectBody,
            "//*[@itemprop='articleBody']",
            $readability->dom,
            'Schema.org',
            $xpath,
            $readability,
            $siteConfig
        );
        if (null !== $extractedBody) {
            $body = $extractedBody;
            $detectBody = false;
        }

        // Find author in rel="author" marked element
        // We only use this if there's exactly one.
        // If there's more than one, it could indicate more than
        // one author, but it could also indicate that we're processing
        // a page listing different articles with different authors.
        $extractedAuthors = $this->extractEntityFromQuery(
            'authors',
            $detectAuthor,
            "//a[contains(concat(' ',normalize-space(@rel),' '),' author ')]",
            $readability->dom,
            'Author found (rel="author"): {author}',
            $xpath,
            fn ($element, $currentEntity) => $currentEntity + [trim((string) $element)]
        );
        if (null !== $extractedAuthors) {
            $authors = array_merge($authors, $extractedAuthors);
        }

        $extractedAuthors = $this->extractEntityFromQuery(
            'authors',
            $detectAuthor,
            '//meta[@name="author"]/@content',
            $readability->dom,
            'Author found (meta name="author"): {author}',
            $xpath,
            fn ($element, $currentEntity) => $currentEntity + [trim((string) $element)]
        );
        if (null !== $extractedAuthors) {
            $authors = array_merge($authors, $extractedAuthors);
        }

        // Find date in pubdate marked time element
        // For the same reason given above, we only use this
        // if there's exactly one element.
        $extractedDate = $this->extractEntityFromQuery(
            'date',
            $detectDate,
            '//time[@pubdate or @pubDate]',
            $readability->dom,
            'Date found (datetime marked time element): {date}',
            $xpath
        );
        if (null !== $extractedDate) {
            $date = $extractedDate;
        }

        // still missing title or body, so we detect using Readability
        $readabilitySuccess = false;
        if ($detectTitle || $detectBody) {
            $this->logger->info('Using Readability');
            // clone body if we're only using Readability for title (otherwise it may interfere with body element)
            if (isset($body)) {
                $cloned = $body->cloneNode(true);
                if ($cloned instanceof \DOMElement) {
                    $body = $cloned;
                }
            }
            $readabilitySuccess = $readability->init();
        }

        if ($detectTitle && $readability->getTitle()->textContent) {
            $title = trim($readability->getTitle()->textContent);
            $this->logger->info('Detected title: {title}', ['title' => $title]);
        }

        $date = $this->validateDate($date);

        if ($date) {
            $this->logger->info('Detected date: {date}', ['date' => $date]);
        }

        if ($detectBody && $readabilitySuccess) {
            $this->logger->info('Detecting body');
            $body = $readability->getContent();

            if (1 === $body->childNodes->length && $body->firstChild instanceof \DOMElement) {
                $body = $body->firstChild;
            }

            // prune (clean up elements that may not be content)
            if ($siteConfig->prune()) {
                $this->logger->info('Pruning content');
                $readability->prepArticle($body);
            }
        }

        if (isset($body)) {
            // remove any h1-h6 elements that appear as first thing in the body
            // and which match our title
            if (isset($title) && '' !== $title && null !== $body->firstChild) {
                $firstChild = $body->firstChild;

                while (null !== $firstChild->nextSibling && !$firstChild instanceof \DOMElement) {
                    $firstChild = $firstChild->nextSibling;
                }

                if ($firstChild instanceof \DOMElement
                    && \in_array(strtolower($firstChild->tagName), ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)
                    && (strtolower(trim($firstChild->textContent)) === strtolower(trim((string) $title)))) {
                    $body->removeChild($firstChild);
                }
            }

            /** @var \DOMDocument */
            $ownerDocument = $body->ownerDocument;

            // prevent self-closing iframes
            if ('iframe' === $body->tagName) {
                if (!$body->hasChildNodes()) {
                    $body->appendChild($ownerDocument->createTextNode('[embedded content]'));
                }
            } else {
                foreach ($body->getElementsByTagName('iframe') as $e) {
                    if (!$e->hasChildNodes()) {
                        $e->appendChild($ownerDocument->createTextNode('[embedded content]'));
                    }
                }
            }

            // prevent self-closing iframe when content is ONLY an iframe
            if ('iframe' === $body->nodeName && !$body->hasChildNodes()) {
                $body->appendChild($ownerDocument->createTextNode('[embedded content]'));
            }

            // remove image lazy loading
            foreach ($body->getElementsByTagName('img') as $e) {
                $hasAttribute = false;
                foreach ($this->config->getSrcLazyLoadAttributes() as $attribute) {
                    if ($e->hasAttribute($attribute)) {
                        $hasAttribute = true;
                    }
                }

                if (false === $hasAttribute) {
                    continue;
                }

                // Custom case for WordPress plugin http://wordpress.org/extend/plugins/lazy-load/
                // the plugin replaces the src attribute to point to a 1x1 gif and puts the original src
                // inside the data-lazy-src attribute. It also places the original image inside a noscript element
                // next to the amended one.
                // @see https://plugins.trac.wordpress.org/browser/lazy-load/trunk/lazy-load.php
                if (null !== $e->nextSibling && 'noscript' === $e->nextSibling->nodeName) {
                    $newElem = $e->ownerDocument->createDocumentFragment();
                    $newElem->appendXML($e->nextSibling->innerHTML);
                    $e->nextSibling->parentNode->replaceChild($newElem, $e->nextSibling);
                    $e->parentNode->removeChild($e);

                    continue;
                }

                $attributes = [];
                foreach ($this->config->getSrcLazyLoadAttributes() as $attribute) {
                    if ($e->hasAttribute($attribute)) {
                        $key = 'src';
                        if ('data-srcset' === $attribute) {
                            $key = 'srcset';
                        }
                        $attributes[$key] = $e->getAttribute($attribute);
                        $e->removeAttribute($attribute);
                    }
                }

                foreach (['src', 'srcset'] as $attr) {
                    if (\array_key_exists($attr, $attributes)
                        && !empty($attributes[$attr])) {
                        $e->setAttribute($attr, $attributes[$attr]);
                    }
                }
            }

            $success = true;
        }

        // if we've had no success and we've used tidy, there's a chance
        // that tidy has messed up. So let's try again without tidy...
        if (!$success && $tidied && $smartTidy) {
            $this->logger->info('Trying again without tidy');

            return $this->process(
                $readability->original_html,
                $url,
                $siteConfig,
                false
            );
        }

        $this->logger->info('Success ? {is_success}', ['is_success' => $success]);

        return new ExtractedContent(
            $readability,
            $siteConfig,
            $title,
            $language,
            $authors,
            $body,
            $image,
            $nativeAd,
            $date,
            $success,
            $nextPageUrl
        );
    }

    /**
     * Process string replacements in the $html body.
     *
     * @param SiteConfig $siteConfig Will avoid to recalculate the site config
     *
     * @return string $html with replacements performed
     */
    public function processStringReplacements(string $html, UriInterface $url, ?SiteConfig $siteConfig = null): string
    {
        // We repeat this step from process(), so this method can be called on its own
        $siteConfig = $this->prepareSiteConfig($html, $url, $siteConfig);

        // do string replacements
        if (!empty($siteConfig->find_string)) {
            if (\count($siteConfig->find_string) === \count($siteConfig->replace_string)) {
                $html = str_replace($siteConfig->find_string, $siteConfig->replace_string, $html, $count);
                $this->logger->info('Strings replaced: {count} (find_string and/or replace_string)', ['count' => $count]);
            } else {
                $this->logger->info('Skipped string replacement - incorrect number of find-replace strings in site config');
            }
            unset($count);
        }

        $this->logger->debug('HTML after site config strings replacements', ['html' => $html]);

        return $html;
    }

    /**
     * Validate and convert a date to the W3C format.
     *
     * @return string|null Formatted date using the W3C format (Y-m-d\TH:i:sP) OR null if the date is badly formatted
     */
    public function validateDate(?string $date): ?string
    {
        if (null === $date) {
            return null;
        }

        try {
            return (new \DateTime($date))->format(\DateTime::W3C);
        } catch (\Exception) {
            $this->logger->info('Cannot parse date: {date}', ['date' => $date]);

            return null;
        }
    }

    /**
     * Set and prepare the SiteConfig, or get a default.
     *
     * @param SiteConfig $siteConfig Will avoid to recalculate the site config
     */
    private function prepareSiteConfig(string $html, UriInterface $url, ?SiteConfig $siteConfig = null): SiteConfig
    {
        if (null === $siteConfig) {
            $siteConfig = $this->buildSiteConfig($url, $html);
        }

        // add lazyload information from siteconfig
        if ($siteConfig->src_lazy_load_attr && !\in_array($siteConfig->src_lazy_load_attr, $this->config->getSrcLazyLoadAttributes(), true)) {
            $this->config->addSrcLazyLoadAttributes($siteConfig->src_lazy_load_attr);
        }

        $this->logger->debug('Actual site config', ['siteConfig' => $siteConfig]);

        return $siteConfig;
    }

    /**
     * Check if given node list exists and has length more than 0.
     *
     * @param \DOMNodeList<\DOMNode>|false $elems Not force typed because it can also be false
     */
    private function hasElements($elems = false): bool
    {
        if (false === $elems) {
            return false;
        }

        return $elems->length > 0;
    }

    /**
     * Remove elements.
     *
     * @param \DOMNodeList<\DOMNode>|false $elems Not force typed because it can also be false
     */
    private function removeElements($elems = false, ?string $logMessage = null): void
    {
        if (false === $elems || false === $this->hasElements($elems)) {
            return;
        }

        if (null !== $logMessage) {
            $this->logger->info($logMessage, ['length' => $elems->length]);
        }

        for ($i = $elems->length - 1; $i >= 0; --$i) {
            $item = $elems->item($i);

            if (null !== $item && null !== $item->parentNode) {
                if ($item instanceof \DOMAttr) {
                    $item->ownerElement->removeAttributeNode($item);
                } else {
                    $item->parentNode->removeChild($item);
                }
            }
        }
    }

    /**
     * Wrap elements with provided tag.
     *
     * @param \DOMNodeList<\DOMNode>|false $elems
     */
    private function wrapElements($elems = false, string $tag = 'div', ?string $logMessage = null): void
    {
        if (false === $elems || false === $this->hasElements($elems)) {
            return;
        }

        if (null !== $logMessage) {
            $this->logger->info($logMessage, ['length' => $elems->length]);
        }

        $a = iterator_to_array($elems);
        foreach ($a as $item) {
            if ($item instanceof \DOMElement && null !== $item->parentNode) {
                /** @var \DOMDocument */
                $ownerDocument = $item->ownerDocument;
                $newNode = $ownerDocument->createElement($tag);
                \assert(false !== $newNode); // For PHPStan
                $newNode->setInnerHtml($ownerDocument->saveXML($item));

                $item->parentNode->replaceChild($newNode, $item);
            }
        }
    }

    /**
     * Extract entity for a given CSS class a node.
     *
     * The $entity argument is used as the name of the property to set in
     * the current ContentExtractor instance (variable reference) and as
     * the name to use in log messages
     *
     * Example: extractEntityFromQuery('title', $detectEntity, $xpathExpression, $node, $log, $xpath, $returnCallback)
     * will search for expression and set the found value in $this->title
     *
     * @param string    $entity          Entity to look for ('title', 'date')
     * @param bool      $detectEntity    Do we have to detect entity?
     * @param string    $xpathExpression XPath query to look for
     * @param \DOMNode  $node            DOMNode to look into
     * @param ?callable $returnCallback  Function to cleanup the current value found
     *
     * @return mixed|null The extracted entity or null if not found
     */
    private function extractEntityFromQuery(string $entity, bool $detectEntity, string $xpathExpression, \DOMNode $node, string $logMessage, \DOMXPath $xpath, ?callable $returnCallback = null): mixed
    {
        if (false === $detectEntity) {
            return null;
        }

        // we define the default callback here
        if (null === $returnCallback) {
            $returnCallback = fn ($element) => trim((string) $element);
        }

        // check for given css class
        // shut up operator as there is no pre-validation possible.
        $elems = @$xpath->query($xpathExpression, $node);

        if (false === $elems || false === $this->hasElements($elems)) {
            return null;
        }

        $entityValue = $returnCallback(
            $elems->item(0)->textContent,
            []
        );
        $this->logger->info($logMessage, [$entity => $entityValue]);

        // remove entity from document
        try {
            $elems->item(0)->parentNode->removeChild($elems->item(0));
        } catch (\DOMException) {
            // do nothing
        }

        return $entityValue;
    }

    /**
     * Extract title for a given CSS class a node.
     *
     * @param bool          $detectTitle Do we have to detect title ?
     * @param string        $cssClass    CSS class to look for
     * @param \DOMNode|null $node        DOMNode to look into
     *
     * @return string|null The extracted title or null if not found
     */
    private function extractTitle(bool $detectTitle, string $cssClass, ?\DOMNode $node, string $logMessage, \DOMXPath $xpath): ?string
    {
        if (null === $node) {
            return null;
        }

        return $this->extractEntityFromQuery(
            'title',
            $detectTitle,
            ".//*[contains(concat(' ',normalize-space(@class),' '),' " . $cssClass . " ')]",
            $node,
            $logMessage,
            $xpath
        );
    }

    /**
     * Extract date for a given CSS class a node.
     *
     * @param bool          $detectDate Do we have to detect date ?
     * @param string        $cssClass   CSS class to look for
     * @param \DOMNode|null $node       DOMNode to look into
     *
     * @return string|null The extracted date or null if not found
     */
    private function extractDate(bool $detectDate, string $cssClass, ?\DOMNode $node, string $logMessage, \DOMXPath $xpath): ?string
    {
        if (null === $node) {
            return null;
        }

        return $this->extractEntityFromQuery(
            'date',
            $detectDate,
            ".//time[@pubdate or @pubDate] | .//abbr[contains(concat(' ',normalize-space(@class),' '),' " . $cssClass . " ')]",
            $node,
            $logMessage,
            $xpath
        );
    }

    /**
     * Extract author.
     *
     * @param bool          $detectAuthor Do we have to detect author ?
     * @param \DOMNode|null $node         DOMNode to look into
     *
     * @return string[]|null The extracted authors or null if not found
     */
    private function extractAuthor(bool $detectAuthor, ?\DOMNode $node, \DOMXPath $xpath): ?array
    {
        if (false === $detectAuthor) {
            return null;
        }

        if (null === $node) {
            return null;
        }

        $authors = [];

        // check for time element with pubdate attribute
        $elems = $xpath->query(".//*[contains(concat(' ',normalize-space(@class),' '),' vcard ') and (contains(concat(' ',normalize-space(@class),' '),' author ') or contains(concat(' ',normalize-space(@class),' '),' byline '))]", $node);

        if ($elems && $elems->length > 0) {
            /** @var \DOMNode */
            $author = $elems->item(0);
            $fns = $xpath->query(".//*[contains(concat(' ',normalize-space(@class),' '),' fn ')]", $author);

            if ($fns && $fns->length > 0) {
                foreach ($fns as $fn) {
                    if ('' !== trim($fn->textContent)) {
                        $authors[] = trim($fn->textContent);
                        $this->logger->info('hNews: found author: ' . trim($fn->textContent));
                    }
                }
            } else {
                if ('' !== trim($author->textContent)) {
                    $authors[] = trim($author->textContent);
                    $this->logger->info('hNews: found author: ' . trim($author->textContent));
                }
            }

            return [] === $authors ? null : $authors;
        }

        return null;
    }

    /**
     * Extract body from a given CSS for a node.
     *
     * @param bool          $detectBody      Do we have to detect body ?
     * @param string        $xpathExpression XPath expression to extract body
     * @param \DOMNode|null $node            DOMNode to look into
     * @param string        $type            Format type we are looking for, only used for log message
     *
     * @return \DOMElement|null The extracted body or null if not found
     */
    private function extractBody(bool $detectBody, string $xpathExpression, ?\DOMNode $node, string $type, \DOMXPath $xpath, Readability $readability, SiteConfig $siteConfig): ?\DOMElement
    {
        if (false === $detectBody) {
            return null;
        }

        if (null === $node) {
            return null;
        }

        // shut up operator as there is no pre-validation possible.
        $elems = @$xpath->query($xpathExpression, $node);

        if (false === $elems || false === $this->hasElements($elems)) {
            return null;
        }

        $this->logger->info($type . ': found "' . $elems->length . '" with ' . $xpathExpression);

        if (1 === $elems->length) {
            // body can't be anything other than element
            if (!$elems->item(0) instanceof \DOMElement) {
                $this->logger->info('Body must be an element');

                return null;
            }

            $body = $elems->item(0);

            // prune (clean up elements that may not be content)
            if ($siteConfig->prune()) {
                $this->logger->info('Pruning content');
                $readability->prepArticle($body);
            }

            return $body;
        }

        $body = $readability->dom->createElement('div');
        $this->logger->info('{nb} body elems found', ['nb' => $elems->length]);
        $len = 0;

        foreach ($elems as $elem) {
            if (!isset($elem->parentNode)) {
                continue;
            }

            $isDescendant = false;
            foreach ($body->childNodes as $parent) {
                $node = $elem->parentNode;
                while (null !== $node) {
                    if ($node->isSameNode($parent)) {
                        $isDescendant = true;
                        break 2;
                    }
                    $node = $node->parentNode;
                }
            }

            if ($isDescendant) {
                $this->logger->info('...element is child of another body element, skipping.');
            } else {
                // prune (clean up elements that may not be content)
                if ($siteConfig->prune()) {
                    $this->logger->info('...pruning content');
                    $readability->prepArticle($elem);
                }

                ++$len;
                $body->appendChild($elem);
            }
        }

        $this->logger->info('...{len} elements added to body', ['len' => $len]);

        return $body;
    }

    /**
     * Return an instance of Readability with pre & post filters added.
     *
     * @param string $html       HTML to make readable from Readability lib
     * @param string $parser     Parser to use
     * @param bool   $enableTidy Should it use tidy extension?
     */
    private function getReadability(string $html, UriInterface $url, string $parser, bool $enableTidy): Readability
    {
        $readability = new Readability($html, (string) $url, $parser, $enableTidy);

        foreach ($this->config->getReadability()['pre_filters'] as $filter => $replacer) {
            $readability->addPreFilter($filter, $replacer);
        }

        foreach ($this->config->getReadability()['post_filters'] as $filter => $replacer) {
            $readability->addPostFilter($filter, $replacer);
        }

        return $readability;
    }

    /**
     * Extract and apply a callback to an entity according to a pattern.
     *
     * The $entity argument is used as the name of the property to set in
     * the current ContentExtractor instance (variable reference) and as
     * the name to use in log messages
     *
     * Example: extractEntityFromPattern('title', $pattern, ...) will search
     * for pattern and set the found value in $this->title
     *
     * @param string    $entity         Entity to look for ('title', 'date')
     * @param string    $pattern        Pattern to look for
     * @param ?callable $returnCallback Function to apply on the value
     *
     * @return mixed|null The extracted entity value or null if not found
     */
    private function extractEntityFromPattern(string $entity, string $pattern, \DOMXPath $xpath, Readability $readability, ?callable $returnCallback = null): mixed
    {
        // we define the default callback here
        if (null === $returnCallback) {
            $returnCallback = fn ($e) => trim((string) $e);
        }

        $elems = $xpath->evaluate($pattern, $readability->dom);
        $entityValue = null;

        if (\is_string($elems) && '' !== trim($elems)) {
            $entityValue = $returnCallback($elems);

            $this->logger->info("{$entity} expression evaluated as string: {{$entity}}", [$entity => $entityValue]);
            $this->logger->info('...XPath match: {pattern}', ['pattern', $pattern]);
        } elseif ($elems instanceof \DOMNodeList && $elems->length > 0) {
            if (null === $elems->item(0)) {
                return null;
            }

            $entityValue = $returnCallback($elems->item(0)->textContent);

            $this->logger->info("{$entity} matched: {{$entity}}", [$entity => $entityValue]);
            $this->logger->info('...XPath match: {pattern}', ['pattern', $pattern]);

            // remove entity from document
            try {
                $elems->item(0)->parentNode->removeChild($elems->item(0));
            } catch (\DOMException) {
                // do nothing
            }
        }

        return $entityValue;
    }

    /**
     * Same as extractEntityFromPattern except this one always return all matched elements.
     *
     * @see extractEntityFromPattern
     *
     * @param string    $entity         Entity to look for ('title', 'date')
     * @param string    $pattern        Pattern to look for
     * @param ?callable $returnCallback Function to apply on the value
     *
     * @return string[]|null Array of extracted values or null if not found
     */
    private function extractMultipleEntityFromPattern(string $entity, string $pattern, \DOMXPath $xpath, Readability $readability, ?callable $returnCallback = null): ?array
    {
        // we define the default callback here
        if (null === $returnCallback) {
            $returnCallback = fn ($e) => trim((string) $e);
        }

        $elems = $xpath->evaluate($pattern, $readability->dom);
        $entityValue = null;

        if (\is_string($elems) && '' !== trim($elems)) {
            $entityValue[] = $returnCallback($elems);

            $this->logger->info("{$entity} expression evaluated as string: {{$entity}}", [$entity => $entityValue]);
            $this->logger->info('...XPath match: {pattern}', ['pattern', $pattern]);
        } elseif ($elems instanceof \DOMNodeList && $elems->length > 0) {
            foreach ($elems as $item) {
                $entityValue[] = $returnCallback($item->textContent);

                // remove entity from document
                try {
                    $item->parentNode->removeChild($item);
                } catch (\DOMException) {
                    // do nothing
                }
            }

            $this->logger->info("{$entity} matched: {{$entity}}", [$entity => $entityValue]);
            $this->logger->info('...XPath match: {pattern}', ['pattern', $pattern]);
        }

        return $entityValue;
    }

    /**
     * Extract information from defined source:
     *     - OpenGraph
     *     - JSON-LD.
     *
     * @param string $html UTF-8-encoded HTML fragment of the page
     *
     * @return array<string, mixed> Array with extracted information
     */
    private function extractDefinedInformation(string $html, SiteConfig $siteConfig): array
    {
        if ('' === trim($html)) {
            return [];
        }

        libxml_use_internal_errors(true);

        $doc = new \DOMDocument();
        $doc->loadHTML('<meta charset="utf-8">' . $html);

        libxml_use_internal_errors(false);

        $xpath = new \DOMXPath($doc);

        $extracted = [];

        $ogData = $this->extractOpenGraph($xpath);
        if (!empty($ogData['title'])) {
            $extracted['title'] = $ogData['title'];
        }
        if (!empty($ogData['image'])) {
            $extracted['image'] = $ogData['image'];
        }
        if (!empty($ogData['language'])) {
            $extracted['language'] = $ogData['language'];
        }
        if (!empty($ogData['date'])) {
            $extracted['date'] = $ogData['date'];
        }

        if (false === $siteConfig->skip_json_ld) {
            $jsonLdData = $this->extractJsonLdInformation($xpath);
            if (!empty($jsonLdData['title']) && empty($extracted['title'])) {
                $extracted['title'] = $jsonLdData['title'];
            }
            if (!empty($jsonLdData['authors'])) {
                $extracted['authors'] = $jsonLdData['authors'];
            }
            if (!empty($jsonLdData['date']) && empty($extracted['date'])) {
                $extracted['date'] = $jsonLdData['date'];
            }
            if (!empty($jsonLdData['body'])) {
                $extracted['body'] = $jsonLdData['body'];
            }
            if (!empty($jsonLdData['image']) && empty($extracted['image'])) {
                $extracted['image'] = $jsonLdData['image'];
            }
        }

        return $extracted;
    }

    /**
     * Extract OpenGraph data from the response.
     *
     * @param \DOMXPath $xpath DOMXpath from the DOMDocument of the page
     *
     * @return array<string, mixed> Extracted OpenGraph data
     *
     * @see http://stackoverflow.com/a/7454737/569101
     */
    private function extractOpenGraph(\DOMXPath $xpath): array
    {
        // retrieve "og:" properties
        $metas = $xpath->query('//*/meta[starts-with(@property, \'og:\')]');

        $ogMetas = [];
        if (false !== $metas) {
            foreach ($metas as $meta) {
                if (!$meta instanceof \DOMElement) {
                    continue;
                }

                $property = str_replace(':', '_', (string) $meta->getAttribute('property'));

                if (\in_array($property, ['og_image', 'og_image_url', 'og_image_secure_url'], true)) {
                    // avoid image data:uri to avoid sending too much data
                    // also, take the first og:image which is usually the best one
                    if (0 === stripos($meta->getAttribute('content'), 'data:image') || !empty($ogMetas[$property])) {
                        continue;
                    }

                    $ogMetas[$property] = $meta->getAttribute('content');

                    continue;
                }

                $ogMetas[$property] = $meta->getAttribute('content');
            }
        }

        $this->logger->info('Opengraph "og:" data: {ogData}', ['ogData' => $ogMetas]);

        $extracted = [];

        if (!empty($ogMetas['og_title'])) {
            $extracted['title'] = $ogMetas['og_title'];
        }

        // og:image by default, then og:image:url and finally og:image:secure_url
        if (!empty($ogMetas['og_image'])) {
            $extracted['image'] = $ogMetas['og_image'];
        }

        if (!empty($ogMetas['og_image_url'])) {
            $extracted['image'] = $ogMetas['og_image_url'];
        }

        if (!empty($ogMetas['og_image_secure_url'])) {
            $extracted['image'] = $ogMetas['og_image_secure_url'];
        }

        if (!empty($ogMetas['og_locale'])) {
            $extracted['language'] = $ogMetas['og_locale'];
        }

        // retrieve "article:" properties
        $metas = $xpath->query('//*/meta[starts-with(@property, \'article:\')]');

        $articleMetas = [];
        if (false !== $metas) {
            foreach ($metas as $meta) {
                if (!$meta instanceof \DOMElement) {
                    continue;
                }

                $articleMetas[str_replace(':', '_', (string) $meta->getAttribute('property'))] = $meta->getAttribute('content');
            }
        }

        $this->logger->info('Opengraph "article:" data: {ogData}', ['ogData' => $articleMetas]);

        if (!empty($articleMetas['article_modified_time'])) {
            $extracted['date'] = $articleMetas['article_modified_time'];
        }

        if (!empty($articleMetas['article_published_time'])) {
            $extracted['date'] = $articleMetas['article_published_time'];
        }

        return $extracted;
    }

    /**
     * Clean extract of JSON-LD authors.
     *
     * @param array<mixed> $authors
     *
     * @return string[]
     */
    private function extractAuthorsFromJsonLdArray(array $authors): array
    {
        if (isset($authors['name'])) {
            if (\is_array($authors['name'])) {
                return $authors['name'];
            }

            return [$authors['name']];
        }

        $ret = [];
        foreach ($authors as $author) {
            if (isset($author['name']) && \is_string($author['name'])) {
                $ret[] = $author['name'];
            }
        }

        return $ret;
    }

    /**
     * Extract data from JSON-LD information.
     *
     * @param \DOMXPath $xpath DOMXpath from the DOMDocument of the page
     *
     * @return array<string, mixed> Extracted JSON-LD data
     *
     * @see https://json-ld.org/spec/latest/json-ld/
     */
    private function extractJsonLdInformation(\DOMXPath $xpath): array
    {
        $scripts = $xpath->query('//*/script[@type="application/ld+json"]');

        if (false === $scripts) {
            return [];
        }

        $ignoreNames = [];
        $candidateNames = [];
        $extracted = [];

        foreach ($scripts as $script) {
            try {
                $data = (array) json_decode(trim((string) $script->nodeValue), true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (isset($data['@type']) && \in_array($data['@type'], $this->config->getJsonLdIgnoreTypes(), true)) {
                if (isset($data['name'])) {
                    $ignoreNames[] = $data['name'];
                }
                continue;
            }

            $this->logger->info('JSON-LD data: {JsonLdData}', ['JsonLdData' => $data]);

            // just in case datePublished isn't defined, we use the modified one at first
            if (!empty($data['dateModified'])) {
                $extracted['date'] = \is_array($data['dateModified']) ? reset($data['dateModified']) : $data['dateModified'];
                $this->logger->info('date matched from JsonLd: {date}', ['date' => $extracted['date']]);
            }

            if (!empty($data['datePublished'])) {
                $extracted['date'] = \is_array($data['datePublished']) ? reset($data['datePublished']) : $data['datePublished'];
                $this->logger->info('date matched from JsonLd: {date}', ['date' => $extracted['date']]);
            }

            // body should be a DOMNode
            if (!empty($data['articleBody'])) {
                $dom = new \DOMDocument('1.0', 'utf-8');
                $extracted['body'] = $dom->createElement('p', htmlspecialchars(trim((string) $data['articleBody'])));
                $this->logger->info('body matched from JsonLd: {body}', ['body' => $extracted['body']]);
            }

            if (!empty($data['headline'])) {
                $candidateNames[] = $data['headline'];
            }

            if (!empty($data['name'])) {
                $candidateNames[] = $data['name'];
            }

            if (!empty($data['author'])) {
                $authors = \is_array($data['author']) ? $this->extractAuthorsFromJsonLdArray($data['author']) : [$data['author']];

                if (!isset($extracted['authors'])) {
                    $extracted['authors'] = [];
                }

                foreach ($authors as $author) {
                    $extracted['authors'][] = $author;
                    $this->logger->info('author matched from JsonLd: {author}', ['author' => $author]);
                }
            }

            if (!empty($data['image']['url'])) {
                // some people use ImageObject url field as an array instead of a string...
                $extracted['image'] = \is_array($data['image']['url']) ? current($data['image']['url']) : $data['image']['url'];
            }
        }

        foreach ($candidateNames as $name) {
            if (!\in_array($name, $ignoreNames, true)) {
                $extracted['title'] = $name;
                $this->logger->info('title matched from JsonLd: {{title}}', ['title' => $name]);
            }
        }

        return $extracted;
    }
}
