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
    public ?Readability $readability = null;
    private ?\DOMXPath $xpath = null;
    private ?string $html = null;
    private ContentExtractorConfig $config;
    private ?SiteConfig $siteConfig = null;
    private ?string $title = null;
    private ?string $language = null;
    /** @var string[] */
    private array $authors = [];
    /** @var \DOMElement|\DOMNode|null */
    private $body = null;
    private ?string $image = null;
    private bool $nativeAd = false;
    private ?string $date = null;
    private bool $success = false;
    private ?string $nextPageUrl = null;
    private LoggerInterface $logger;
    private ConfigBuilder $configBuilder;

    /**
     * @param array{
     *   default_parser?: string,
     *   fingerprints?: array<string, string>,
     *   config_builder?: array,
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

    public function reset(): void
    {
        $this->xpath = null;
        $this->html = null;
        $this->readability = null;
        $this->siteConfig = null;
        $this->title = null;
        $this->body = null;
        $this->image = null;
        $this->nativeAd = false;
        $this->date = null;
        $this->language = null;
        $this->authors = [];
        $this->nextPageUrl = null;
        $this->success = false;
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
     * @return bool true on success, false on failure
     */
    public function process(string $html, UriInterface $url, ?SiteConfig $siteConfig = null, bool $smartTidy = true): bool
    {
        $this->reset();

        $this->prepareSiteConfig($html, $url, $siteConfig);

        $html = $this->processStringReplacements($html, $url, $siteConfig);

        // load and parse html
        $parser = $this->siteConfig->parser();

        if (!\in_array($parser, $this->config->getAllowedParsers(), true)) {
            $this->logger->info('HTML parser {parser} not listed, using {default_parser} instead', ['parser' => $parser, 'default_parser' => $this->config->getDefaultParser()]);
            $parser = $this->config->getDefaultParser();
        }

        $this->logger->info('Attempting to parse HTML with {parser}', ['parser' => $parser]);

        $this->readability = $this->getReadability($html, $url, $parser, $this->siteConfig->tidy() && $smartTidy);
        $tidied = $this->readability->tidied;

        $this->logger->info('Body size after Readability: {length}', ['length' => \strlen((string) $this->readability->dom->saveXML($this->readability->dom->documentElement))]);
        $this->logger->debug('Body after Readability', ['dom_saveXML' => $this->readability->dom->saveXML($this->readability->dom->documentElement)]);

        // we use xpath to find elements in the given HTML document
        $this->xpath = new \DOMXPath($this->readability->dom);

        // try to get next page link
        // @todo: should we test if the link is actually a link?
        foreach ($this->siteConfig->next_page_link as $pattern) {
            // Do we have conditions?
            $condition = $this->siteConfig->getIfPageContainsCondition('next_page_link', $pattern);

            if ($condition) {
                $elems = $this->xpath->evaluate($condition, $this->readability->dom);

                // move on to next next_page_link XPath in case condition isn't met
                if (!($elems instanceof \DOMNodeList && $elems->length > 0)) {
                    continue;
                }
            }

            $elems = $this->xpath->evaluate($pattern, $this->readability->dom);

            if (\is_string($elems)) {
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

        // retrieve info from pre-defined source (OpenGraph / JSON-LD / etc.)
        $this->extractDefinedInformation($html);

        // check if this is a native ad
        foreach ($this->siteConfig->native_ad_clue as $pattern) {
            $elems = $this->xpath->evaluate($pattern, $this->readability->dom);

            if ($elems instanceof \DOMNodeList && $elems->length > 0) {
                $this->nativeAd = true;
                break;
            }
        }

        // try to get title
        foreach ($this->siteConfig->title as $pattern) {
            $this->logger->info('Trying {pattern} for title', ['pattern' => $pattern]);

            if ($this->extractEntityFromPattern('title', $pattern)) {
                break;
            }
        }

        // try to get author (if it hasn't already been set)
        if ([] === $this->authors) {
            foreach ($this->siteConfig->author as $pattern) {
                $this->logger->info('Trying {pattern} for author', ['pattern' => $pattern]);

                if ($this->extractMultipleEntityFromPattern('authors', $pattern)) {
                    break;
                }
            }
        }

        // try to get date
        foreach ($this->siteConfig->date as $pattern) {
            $this->logger->info('Trying {pattern} for date', ['pattern' => $pattern]);

            if ($this->extractEntityFromPattern('date', $pattern)) {
                break;
            }
        }

        // try to get language
        $langXpath = ['//html[@lang]/@lang', '//meta[@name="DC.language"]/@content'];
        foreach ($langXpath as $pattern) {
            $this->logger->info('Trying {pattern} for language', ['pattern' => $pattern]);
            $elems = $this->xpath->evaluate($pattern, $this->readability->dom);

            if ($elems instanceof \DOMNodeList && $elems->length > 0) {
                foreach ($elems as $elem) {
                    $this->language = trim($elem->textContent);
                    $this->logger->info('Language matched: {language}', ['language' => $this->language]);
                }

                if (null !== $this->language) {
                    break;
                }
            }
        }

        // wrapping matching elements with provided tag
        foreach ($this->siteConfig->wrap_in as $tag => $pattern) {
            $this->logger->info('Trying {pattern} to wrap element with {tag}', ['pattern' => $pattern, 'tag' => $tag]);
            $elems = $this->xpath->query($pattern, $this->readability->dom);

            if (false === $elems) {
                $this->logger->info('Bad pattern');

                continue;
            }

            $this->wrapElements($elems, $tag, 'Wrapping {length} elements (wrap_in)');
        }

        // strip elements (using xpath expressions)
        foreach ($this->siteConfig->strip as $pattern) {
            $this->logger->info('Trying {pattern} to strip element', ['pattern' => $pattern]);
            $elems = $this->xpath->query($pattern, $this->readability->dom);

            if (false === $elems) {
                $this->logger->info('Bad pattern');

                continue;
            }

            $this->removeElements($elems, 'Stripping {length} elements (strip)');
        }

        // strip elements (using id and class attribute values)
        foreach ($this->siteConfig->strip_id_or_class as $string) {
            $this->logger->info('Trying {string} to strip element', ['string' => $string]);
            $string = strtr($string, ["'" => '', '"' => '']);
            $elems = $this->xpath->query("//*[contains(concat(' ',normalize-space(@class), ' '),' $string ') or contains(concat(' ',normalize-space(@id),' '), ' $string ')]", $this->readability->dom);

            if (false === $elems) {
                $this->logger->info('Bad pattern');

                continue;
            }

            $this->removeElements($elems, 'Stripping {length} elements (strip_id_or_class)');
        }

        // strip images (using src attribute values)
        foreach ($this->siteConfig->strip_image_src as $string) {
            $string = strtr($string, ["'" => '', '"' => '']);

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

        // strip empty a elements
        $elems = $this->xpath->query("//a[not(./*) and normalize-space(.)='']", $this->readability->dom);

        $this->removeElements($elems, 'Stripping {length} empty a elements');

        $this->logger->debug('DOM after site config stripping', ['dom_saveXML' => $this->readability->dom->saveXML($this->readability->dom->documentElement)]);

        // try to get body
        foreach ($this->siteConfig->body as $pattern) {
            $this->logger->info('Trying {pattern} for body (content length: {content_length})', ['pattern' => $pattern, 'content_length' => \strlen((string) $this->readability->dom->saveXML($this->readability->dom->documentElement))]);

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
        $detectTitle = $detectBody = $detectDate = $detectAuthor = false;

        // detect title?
        if (!isset($this->title) && (empty($this->siteConfig->title) || $this->siteConfig->autodetect_on_failure())) {
            $detectTitle = true;
        }
        // detect body?
        if (!isset($this->body) && (empty($this->siteConfig->body) || $this->siteConfig->autodetect_on_failure())) {
            $detectBody = true;
        }
        // detect date?
        if (!isset($this->date) && (empty($this->siteConfig->date) || $this->siteConfig->autodetect_on_failure())) {
            $detectDate = true;
        }
        // detect author?
        if ([] === $this->authors && (empty($this->siteConfig->author) || $this->siteConfig->autodetect_on_failure())) {
            $detectAuthor = true;
        }

        // check for hNews
        if ($detectTitle || $detectBody) {
            // check for hentry
            $elems = $this->xpath->query("//*[contains(concat(' ',normalize-space(@class),' '),' hentry ')]", $this->readability->dom);

            if (false !== $elems && $this->hasElements($elems)) {
                $this->logger->info('hNews: found hentry');
                $hentry = $elems->item(0);

                // check for entry-title
                $detectTitle = $this->extractTitle(
                    $detectTitle,
                    'entry-title',
                    $hentry,
                    'hNews: found entry-title: {title}'
                );

                // check for published
                $detectDate = $this->extractDate(
                    $detectDate,
                    'published',
                    $hentry,
                    'hNews: found publication date: {date}'
                );

                $detectAuthor = $this->extractAuthor(
                    $detectAuthor,
                    $hentry
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

        // Find author in rel="author" marked element
        // We only use this if there's exactly one.
        // If there's more than one, it could indicate more than
        // one author, but it could also indicate that we're processing
        // a page listing different articles with different authors.
        $this->extractEntityFromQuery(
            'authors',
            $detectAuthor,
            "//a[contains(concat(' ',normalize-space(@rel),' '),' author ')]",
            $this->readability->dom,
            'Author found (rel="author"): {author}',
            fn ($element, $currentEntity) => $currentEntity + [trim($element)]
        );

        $this->extractEntityFromQuery(
            'authors',
            $detectAuthor,
            '//meta[@name="author"]/@content',
            $this->readability->dom,
            'Author found (meta name="author"): {author}',
            fn ($element, $currentEntity) => $currentEntity + [trim($element)]
        );

        // Find date in pubdate marked time element
        // For the same reason given above, we only use this
        // if there's exactly one element.
        $this->extractEntityFromQuery(
            'date',
            $detectDate,
            '//time[@pubdate or @pubDate]',
            $this->readability->dom,
            'Date found (datetime marked time element): {date}'
        );

        // still missing title or body, so we detect using Readability
        $success = false;
        if ($detectTitle || $detectBody) {
            $this->logger->info('Using Readability');
            // clone body if we're only using Readability for title (otherwise it may interfere with body element)
            if (isset($this->body)) {
                $this->body = $this->body->cloneNode(true);
            }
            $success = $this->readability->init();
        }

        if ($detectTitle && $this->readability->getTitle()->textContent) {
            $this->title = trim($this->readability->getTitle()->textContent);
            $this->logger->info('Detected title: {title}', ['title' => $this->title]);
        }

        $this->date = $this->validateDate($this->date);

        if ($this->date) {
            $this->logger->info('Detected date: {date}', ['date' => $this->date]);
        }

        if ($detectBody && $success) {
            $this->logger->info('Detecting body');
            $this->body = $this->readability->getContent();

            if (1 === $this->body->childNodes->length && \XML_ELEMENT_NODE === $this->body->firstChild->nodeType) {
                $this->body = $this->body->firstChild;
            }

            // prune (clean up elements that may not be content)
            if ($this->siteConfig->prune()) {
                $this->logger->info('Pruning content');
                $this->readability->prepArticle($this->body);
            }
        }

        if (isset($this->body)) {
            // remove any h1-h6 elements that appear as first thing in the body
            // and which match our title
            if (isset($this->title) && '' !== $this->title && null !== $this->body->firstChild) {
                $firstChild = $this->body->firstChild;

                while (null !== $firstChild->nextSibling && $firstChild->nodeType && (\XML_ELEMENT_NODE !== $firstChild->nodeType)) {
                    $firstChild = $firstChild->nextSibling;
                }

                if (\XML_ELEMENT_NODE === $firstChild->nodeType
                    && \in_array(strtolower($firstChild->tagName), ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)
                    && (strtolower(trim($firstChild->textContent)) === strtolower(trim($this->title)))) {
                    $this->body->removeChild($firstChild);
                }
            }

            /** @var \DOMDocument */
            $ownerDocument = $this->body->ownerDocument;

            // prevent self-closing iframes
            if ('iframe' === $this->body->tagName) {
                if (!$this->body->hasChildNodes()) {
                    $this->body->appendChild($ownerDocument->createTextNode('[embedded content]'));
                }
            } else {
                foreach ($this->body->getElementsByTagName('iframe') as $e) {
                    if (!$e->hasChildNodes()) {
                        $e->appendChild($ownerDocument->createTextNode('[embedded content]'));
                    }
                }
            }

            // prevent self-closing iframe when content is ONLY an iframe
            if ('iframe' === $this->body->nodeName && !$this->body->hasChildNodes()) {
                $this->body->appendChild($ownerDocument->createTextNode('[embedded content]'));
            }

            // remove image lazy loading
            foreach ($this->body->getElementsByTagName('img') as $e) {
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
                        && null !== $attributes[$attr]
                        && !empty($attributes[$attr])) {
                        $e->setAttribute($attr, $attributes[$attr]);
                    }
                }
            }

            $this->success = true;
        }

        // if we've had no success and we've used tidy, there's a chance
        // that tidy has messed up. So let's try again without tidy...
        if (!$this->success && $tidied && $smartTidy) {
            unset($this->body, $this->xpath);

            $this->logger->info('Trying again without tidy');

            return $this->process(
                $this->readability->original_html,
                $url,
                $this->siteConfig,
                false
            );
        }

        $this->logger->info('Success ? {is_success}', ['is_success' => $this->success]);

        return $this->success;
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
        $this->prepareSiteConfig($html, $url, $siteConfig);

        // do string replacements
        if (!empty($this->siteConfig->find_string)) {
            if (\count($this->siteConfig->find_string) === \count($this->siteConfig->replace_string)) {
                $html = str_replace($this->siteConfig->find_string, $this->siteConfig->replace_string, $html, $count);
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
     * @return \DOMElement|\DOMNode|null
     */
    public function getContent()
    {
        return $this->body;
    }

    public function isNativeAd(): bool
    {
        return $this->nativeAd;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getDate(): ?string
    {
        return $this->date;
    }

    /**
     * @return string[]
     */
    public function getAuthors(): array
    {
        return $this->authors;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function getSiteConfig(): ?SiteConfig
    {
        return $this->siteConfig;
    }

    public function getNextPageUrl(): ?string
    {
        return $this->nextPageUrl;
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
        } catch (\Exception $exception) {
            $this->logger->info('Cannot parse date: {date}', ['date' => $date]);

            return null;
        }
    }

    protected function addAuthor(string $authorDirty): void
    {
        $author = trim($authorDirty);
        if (!\in_array($author, $this->authors, true)) {
            $this->authors[] = $author;
        }
    }

    /**
     * Set and prepare the SiteConfig, or get a default.
     * If a siteConfig is already set and no prepare site config is passed, this is a noop.
     *
     * @param SiteConfig $siteConfig Will avoid to recalculate the site config
     *
     * @phpstan-assert SiteConfig $this->siteConfig
     */
    private function prepareSiteConfig(string $html, UriInterface $url, ?SiteConfig $siteConfig = null): void
    {
        if (null !== $this->siteConfig && null === $siteConfig) {
            return;
        }

        $this->siteConfig = $siteConfig;
        if (null === $this->siteConfig) {
            $this->siteConfig = $this->buildSiteConfig($url, $html);
        }

        // add lazyload information from siteconfig
        if ($this->siteConfig->src_lazy_load_attr && !\in_array($this->siteConfig->src_lazy_load_attr, $this->config->getSrcLazyLoadAttributes(), true)) {
            $this->config->addSrcLazyLoadAttributes($this->siteConfig->src_lazy_load_attr);
        }

        $this->logger->debug('Actual site config', ['siteConfig' => $this->siteConfig]);
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
     * Example: extractEntityFromQuery('title', $detectEntity, $xpathExpression, $node, $log, $returnCallback)
     * will search for expression and set the found value in $this->title
     *
     * @param string    $entity          Entity to look for ('title', 'date')
     * @param bool      $detectEntity    Do we have to detect entity?
     * @param string    $xpathExpression XPath query to look for
     * @param \DOMNode  $node            DOMNode to look into
     * @param ?callable $returnCallback  Function to cleanup the current value found
     *
     * @return bool Telling if we have to detect entity again or not
     */
    private function extractEntityFromQuery(string $entity, bool $detectEntity, string $xpathExpression, \DOMNode $node, string $logMessage, ?callable $returnCallback = null): bool
    {
        if (false === $detectEntity) {
            return false;
        }

        // we define the default callback here
        if (null === $returnCallback) {
            $returnCallback = fn ($element) => trim($element);
        }

        if (!$this->xpath) {
            return false;
        }

        // check for given css class
        // shut up operator as there is no pre-validation possible.
        $elems = @$this->xpath->query($xpathExpression, $node);

        if (false === $elems || false === $this->hasElements($elems)) {
            return true;
        }

        $this->{$entity} = $returnCallback(
            $elems->item(0)->textContent,
            $this->{$entity}
        );
        $this->logger->info($logMessage, [$entity => $this->{$entity}]);

        // remove entity from document
        try {
            $elems->item(0)->parentNode->removeChild($elems->item(0));
        } catch (\DOMException $e) {
            // do nothing
        }

        return false;
    }

    /**
     * Extract title for a given CSS class a node.
     *
     * @param bool          $detectTitle Do we have to detect title ?
     * @param string        $cssClass    CSS class to look for
     * @param \DOMNode|null $node        DOMNode to look into
     *
     * @return bool Telling if we have to detect title again or not
     */
    private function extractTitle(bool $detectTitle, string $cssClass, ?\DOMNode $node = null, ?string $logMessage=null): bool
    {
        if (null === $node) {
            return true;
        }

        return $this->extractEntityFromQuery(
            'title',
            $detectTitle,
            ".//*[contains(concat(' ',normalize-space(@class),' '),' " . $cssClass . " ')]",
            $node,
            $logMessage
        );
    }

    /**
     * Extract date for a given CSS class a node.
     *
     * @param bool          $detectDate Do we have to detect date ?
     * @param string        $cssClass   CSS class to look for
     * @param \DOMNode|null $node       DOMNode to look into
     *
     * @return bool Telling if we have to detect date again or not
     */
    private function extractDate(bool $detectDate, string $cssClass, ?\DOMNode $node = null, ?string $logMessage=null): bool
    {
        if (null === $node) {
            return true;
        }

        return $this->extractEntityFromQuery(
            'date',
            $detectDate,
            ".//time[@pubdate or @pubDate] | .//abbr[contains(concat(' ',normalize-space(@class),' '),' " . $cssClass . " ')]",
            $node,
            $logMessage
        );
    }

    /**
     * Extract author.
     *
     * @param bool          $detectAuthor Do we have to detect author ?
     * @param \DOMNode|null $node         DOMNode to look into
     *
     * @return bool Telling if we have to detect author again or not
     */
    private function extractAuthor(bool $detectAuthor, ?\DOMNode $node = null): bool
    {
        if (false === $detectAuthor || !$this->xpath) {
            return false;
        }

        if (null === $node) {
            return true;
        }

        // check for time element with pubdate attribute
        $elems = $this->xpath->query(".//*[contains(concat(' ',normalize-space(@class),' '),' vcard ') and (contains(concat(' ',normalize-space(@class),' '),' author ') or contains(concat(' ',normalize-space(@class),' '),' byline '))]", $node);

        if ($elems && $elems->length > 0) {
            /** @var \DOMNode */
            $author = $elems->item(0);
            $fns = $this->xpath->query(".//*[contains(concat(' ',normalize-space(@class),' '),' fn ')]", $author);

            if ($fns && $fns->length > 0) {
                foreach ($fns as $fn) {
                    if ('' !== trim($fn->textContent)) {
                        $this->addAuthor($fn->textContent);
                        $this->logger->info('hNews: found author: ' . trim($fn->textContent));
                    }
                }
            } else {
                if ('' !== trim($author->textContent)) {
                    $this->addAuthor($author->textContent);
                    $this->logger->info('hNews: found author: ' . trim($author->textContent));
                }
            }

            return [] === $this->authors;
        }

        return true;
    }

    /**
     * Extract body from a given CSS for a node.
     *
     * @param bool          $detectBody      Do we have to detect body ?
     * @param string        $xpathExpression XPath expression to extract body
     * @param \DOMNode|null $node            DOMNode to look into
     * @param string        $type            Format type we are looking for, only used for log message
     *
     * @return bool Telling if we have to detect body again or not
     */
    private function extractBody(bool $detectBody, string $xpathExpression, ?\DOMNode $node = null, ?string $type=null): bool
    {
        if (false === $detectBody || !$this->xpath) {
            return false;
        }

        if (null === $node) {
            return true;
        }

        // shut up operator as there is no pre-validation possible.
        $elems = @$this->xpath->query($xpathExpression, $node);

        if (false === $elems || false === $this->hasElements($elems)) {
            return $detectBody;
        }

        $this->logger->info($type . ': found "' . $elems->length . '" with ' . $xpathExpression);

        if (1 === $elems->length) {
            // body can't be an attribute
            if ($elems->item(0) instanceof \DOMAttr) {
                $this->logger->info('Body can not be an attribute');

                return true;
            }

            $this->body = $elems->item(0);

            // prune (clean up elements that may not be content)
            if ($this->siteConfig && $this->siteConfig->prune() && null !== $this->readability) {
                $this->logger->info('Pruning content');
                $this->readability->prepArticle($this->body);
            }

            return false;
        }

        if (!$this->readability) {
            return false;
        }

        $this->body = $this->readability->dom->createElement('div');
        $this->logger->info('{nb} body elems found', ['nb' => $elems->length]);
        $len = 0;

        foreach ($elems as $elem) {
            if (!isset($elem->parentNode)) {
                continue;
            }

            $isDescendant = false;
            foreach ($this->body->childNodes as $parent) {
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
                if ($this->siteConfig && $this->siteConfig->prune()) {
                    $this->logger->info('...pruning content');
                    $this->readability->prepArticle($elem);
                }

                ++$len;
                $this->body->appendChild($elem);
            }
        }

        $this->logger->info('...{len} elements added to body', ['len' => $len]);

        return false;
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
     * Example: extractEntityFromPattern('title', $pattern) will search
     * for pattern and set the found value in $this->title
     *
     * @param string    $entity         Entity to look for ('title', 'date')
     * @param string    $pattern        Pattern to look for
     * @param ?callable $returnCallback Function to apply on the value
     *
     * @return bool Telling if the entity has been found
     */
    private function extractEntityFromPattern(string $entity, string $pattern, ?callable $returnCallback = null): bool
    {
        // we define the default callback here
        if (null === $returnCallback) {
            $returnCallback = fn ($e) => trim($e);
        }

        if (!$this->xpath || !$this->readability) {
            return false;
        }

        $elems = $this->xpath->evaluate($pattern, $this->readability->dom);
        $entityValue = null;

        if (\is_string($elems) && '' !== trim($elems)) {
            $entityValue = $returnCallback($elems);

            $this->logger->info("{$entity} expression evaluated as string: {{$entity}}", [$entity => $entityValue]);
            $this->logger->info('...XPath match: {pattern}', ['pattern', $pattern]);
        } elseif ($elems instanceof \DOMNodeList && $elems->length > 0) {
            if (null === $elems->item(0)) {
                return false;
            }

            $entityValue = $returnCallback($elems->item(0)->textContent);

            $this->logger->info("{$entity} matched: {{$entity}}", [$entity => $entityValue]);
            $this->logger->info('...XPath match: {pattern}', ['pattern', $pattern]);

            // remove entity from document
            try {
                $elems->item(0)->parentNode->removeChild($elems->item(0));
            } catch (\DOMException $e) {
                // do nothing
            }
        }

        if (null !== $entityValue) {
            $this->{$entity} = $entityValue;

            return true;
        }

        return false;
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
     * @return bool Telling if the entity has been found
     */
    private function extractMultipleEntityFromPattern(string $entity, string $pattern, ?callable $returnCallback = null): bool
    {
        // we define the default callback here
        if (null === $returnCallback) {
            $returnCallback = fn ($e) => trim($e);
        }

        if (!$this->xpath || !$this->readability) {
            return false;
        }

        $elems = $this->xpath->evaluate($pattern, $this->readability->dom);
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
                } catch (\DOMException $e) {
                    // do nothing
                }
            }

            $this->logger->info("{$entity} matched: {{$entity}}", [$entity => $entityValue]);
            $this->logger->info('...XPath match: {pattern}', ['pattern', $pattern]);
        }

        if (null !== $entityValue) {
            $this->{$entity} = $entityValue;

            return true;
        }

        return false;
    }

    /**
     * Extract information from defined source:
     *     - OpenGraph
     *     - JSON-LD.
     *
     * @param string $html Html from the page
     */
    private function extractDefinedInformation(string $html): void
    {
        if ('' === trim($html)) {
            return;
        }

        libxml_use_internal_errors(true);

        $doc = new \DOMDocument();
        $doc->loadHTML($html);

        libxml_use_internal_errors(false);

        $xpath = new \DOMXPath($doc);

        $this->extractOpenGraph($xpath);
        if ($this->siteConfig && false === $this->siteConfig->skip_json_ld) {
            $this->extractJsonLdInformation($xpath);
        }
    }

    /**
     * Extract OpenGraph data from the response.
     *
     * @param \DOMXPath $xpath DOMXpath from the DOMDocument of the page
     *
     * @see http://stackoverflow.com/a/7454737/569101
     */
    private function extractOpenGraph(\DOMXPath $xpath): void
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

        if (!empty($ogMetas['og_title'])) {
            $this->title = $ogMetas['og_title'];
        }

        // og:image by default, then og:image:url and finally og:image:secure_url
        if (!empty($ogMetas['og_image'])) {
            $this->image = $ogMetas['og_image'];
        }

        if (!empty($ogMetas['og_image_url'])) {
            $this->image = $ogMetas['og_image_url'];
        }

        if (!empty($ogMetas['og_image_secure_url'])) {
            $this->image = $ogMetas['og_image_secure_url'];
        }

        if (!empty($ogMetas['og_locale'])) {
            $this->language = $ogMetas['og_locale'];
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
            $this->date = $articleMetas['article_modified_time'];
        }

        if (!empty($articleMetas['article_published_time'])) {
            $this->date = $articleMetas['article_published_time'];
        }
    }

    /**
     * Clean extract of JSON-LD authors.
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
     * @see https://json-ld.org/spec/latest/json-ld/
     */
    private function extractJsonLdInformation(\DOMXPath $xpath): void
    {
        $scripts = $xpath->query('//*/script[@type="application/ld+json"]');

        if (false === $scripts) {
            return;
        }

        $ignoreNames = [];
        $candidateNames = [];

        foreach ($scripts as $script) {
            try {
                $data = (array) json_decode(trim($script->nodeValue), true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
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
                $this->date = \is_array($data['dateModified']) ? reset($data['dateModified']) : $data['dateModified'];
                $this->logger->info('date matched from JsonLd: {date}', ['date' => $this->date]);
            }

            if (!empty($data['datePublished'])) {
                $this->date = \is_array($data['datePublished']) ? reset($data['datePublished']) : $data['datePublished'];
                $this->logger->info('date matched from JsonLd: {date}', ['date' => $this->date]);
            }

            // body should be a DOMNode
            if (!empty($data['articlebody'])) {
                $dom = new \DOMDocument('1.0', 'utf-8');
                $this->body = $dom->createElement('p', htmlspecialchars(trim($data['articlebody'])));
                $this->logger->info('body matched from JsonLd: {body}', ['body' => $this->body]);
            }

            if (!empty($data['headline'])) {
                $candidateNames[] = $data['headline'];
            }

            if (!empty($data['name'])) {
                $candidateNames[] = $data['name'];
            }

            if (!empty($data['author'])) {
                $authors = \is_array($data['author']) ? $this->extractAuthorsFromJsonLdArray($data['author']) : [$data['author']];

                foreach ($authors as $author) {
                    $this->addAuthor($author);
                    $this->logger->info('author matched from JsonLd: {author}', ['author' => $author]);
                }
            }

            if (!empty($data['image']['url'])) {
                // some people use ImageObject url field as an array instead of a string...
                $this->image = \is_array($data['image']['url']) ? current($data['image']['url']) : $data['image']['url'];
            }
        }

        if (\is_array($candidateNames) && \count($candidateNames) > 0) {
            foreach ($candidateNames as $name) {
                if (!\in_array($name, $ignoreNames, true)) {
                    $this->title = $name;
                    $this->logger->info('title matched from JsonLd: {{title}}', ['title' => $name]);
                }
            }
        }
    }
}
