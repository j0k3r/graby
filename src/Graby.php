<?php

namespace Graby;

use Graby\Extractor\ContentExtractor;
use Graby\Extractor\HttpClient;
use Graby\HttpClient\Plugin\CookiePlugin;
use Graby\SiteConfig\ConfigBuilder;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Http\Client\Common\PluginClient;
use Http\Client\HttpClient as Client;
use Http\Discovery\HttpClientDiscovery;
use Http\Message\CookieJar;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Readability\Readability;
use Smalot\PdfParser\Parser as PdfParser;
use TrueBV\Punycode;

/**
 * @todo add proxy
 * @todo add cache
 */
class Graby
{
    private LoggerInterface $logger;
    private GrabyConfig $config;
    private HttpClient $httpClient;
    private ContentExtractor $extractor;
    private ConfigBuilder $configBuilder;
    private Punycode $punycode;
    private bool $imgNoReferrer = false;

    public function __construct(array $config = [], Client $client = null, ConfigBuilder $configBuilder = null)
    {
        $this->config = new GrabyConfig($config);

        $this->logger = new NullLogger();

        // Debug mode can be activated with 'debug' => true
        // More details on the retrieved code and its consequent modifications can be obtained using 'log_level' = 'debug'
        if ($this->config->getDebug()) {
            $this->logger = new Logger('graby');

            // This statement has to be before Logger::INFO to catch all DEBUG messages
            if ('debug' === $this->config->getLogLevel()) {
                $fp = fopen(__DIR__ . '/../log/html.log', 'w');
                if (false !== $fp) {
                    // Emptying of the HTML logfile to avoid gigantic logs
                    fclose($fp);
                }

                $this->logger->pushHandler(new StreamHandler(__DIR__ . '/../log/html.log', Logger::DEBUG));
            }

            $this->logger->pushHandler(new StreamHandler(__DIR__ . '/../log/graby.log', Logger::INFO, false));
        }

        if (null === $configBuilder) {
            $configBuilder = new ConfigBuilder(
                $this->config->getExtractor()['config_builder'] ?? [],
                $this->logger
            );
        }
        $this->configBuilder = $configBuilder;

        $this->extractor = new ContentExtractor(
            $this->config->getExtractor(),
            $this->logger,
            $this->configBuilder
        );

        $this->httpClient = new HttpClient(
            $client ?: new PluginClient(HttpClientDiscovery::find(), [new CookiePlugin(new CookieJar())]),
            $this->config->getHttpClient(),
            $this->logger
        );

        $this->punycode = new Punycode();
    }

    /**
     * Redefine all loggers.
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
        $this->extractor->setLogger($logger);
        $this->httpClient->setLogger($logger);
    }

    /**
     * Reload configuration files.
     *
     * @see ConfigBuilder::loadConfigFiles
     */
    public function reloadConfigFiles(): void
    {
        $this->configBuilder->loadConfigFiles();
    }

    /**
     * Fetch content from the given url and return a readable content.
     *
     * @return array With keys html, title, url & summary
     */
    public function fetchContent(string $url): array
    {
        $this->logger->info('Graby is ready to fetch');

        $infos = $this->doFetchContent($url);

        // generate summary
        $infos['summary'] = $this->getExcerpt($infos['html']);

        return $infos;
    }

    public function toggleImgNoReferrer(bool $toggle = false): void
    {
        $this->imgNoReferrer = $toggle;
    }

    /**
     * Cleanup HTML from a DOMElement or a string.
     *
     * @param string|\DOMElement|\DOMNode $contentBlock
     */
    public function cleanupHtml($contentBlock, string $url): string
    {
        $originalContentBlock = \is_string($contentBlock) ? $contentBlock : $contentBlock->textContent;

        // if content is pure html, convert it
        if (\is_string($contentBlock)) {
            $this->extractor->process($contentBlock, $url);

            $contentBlock = $this->extractor->getContent();
        }

        // in case of extractor failed
        if (null === $contentBlock) {
            $this->logger->info('Cleanup html failed. Return given content (a bit cleaned)');

            return trim($this->cleanupXss($originalContentBlock));
        }

        if ($this->extractor->readability) {
            $this->extractor->readability->clean($contentBlock, 'select');
        }

        if ($this->config->getRewriteRelativeUrls()) {
            $this->makeAbsolute($url, $contentBlock);
        }

        // footnotes
        if ('footnotes' === $this->config->getContentLinks() && false === strpos($url, 'wikipedia.org') && $this->extractor->readability) {
            $this->extractor->readability->addFootnotes($contentBlock);
        }

        $contentBlock->normalize();

        // remove empty text nodes
        foreach ($contentBlock->childNodes as $n) {
            if (\XML_TEXT_NODE === $n->nodeType && '' === trim($n->textContent)) {
                $contentBlock->removeChild($n);
            }
        }

        // remove nesting: <div><div><div><p>test</p></div></div></div> = <p>test</p>
        while (1 === $contentBlock->childNodes->length && \XML_ELEMENT_NODE === $contentBlock->firstChild->nodeType) {
            // only follow these tag names
            if (!\in_array(strtolower($contentBlock->tagName), ['div', 'article', 'section', 'header', 'footer'], true)) {
                break;
            }

            $contentBlock = $contentBlock->firstChild;
        }

        // set or replace referrerpolicy to no-referrer in img tags
        if ($this->imgNoReferrer) {
            $imgTags = $contentBlock->getElementsByTagName('img');
            foreach ($imgTags as $img) {
                $img->setAttribute('referrerpolicy', 'no-referrer');
            }
        }

        // convert content block to HTML string
        // Need to preserve things like body: //img[@id='feature']
        if (\in_array(strtolower($contentBlock->tagName), ['div', 'article', 'section', 'header', 'footer', 'li', 'td'], true)) {
            $html = $contentBlock->innerHTML;
        } else {
            // essentially outerHTML
            $html = $contentBlock->ownerDocument->saveXML($contentBlock);
        }

        // post-processing cleanup
        $html = preg_replace('!<p>[\s\h\v]*</p>!u', '', (string) $html);
        if ('remove' === $this->config->getContentLinks()) {
            $html = preg_replace('!</?a[^>]*>!', '', (string) $html);
        }

        $this->logger->debug('Body after cleanupHtml, before cleanupXss', ['html' => $html]);

        return trim($this->cleanupXss((string) $html));
    }

    /**
     * Do fetch content from an url.
     *
     * @return array With key status, html, title, language, date, authors, url, image, headers & native_ad
     */
    private function doFetchContent(string $url): array
    {
        $url = $this->validateUrl($url);
        $siteConfig = $this->configBuilder->buildFromUrl($url);

        $this->logger->info('Fetching url: {url}', ['url' => $url]);

        $response = $this->httpClient->fetch($url, false, $siteConfig->http_header);

        $effectiveUrl = $response['effective_url'];
        $effectiveUrl = str_replace(' ', '%20', $effectiveUrl);
        if (!$this->isUrlAllowed($effectiveUrl)) {
            throw new \Exception(sprintf('Url "%s" is not allowed to be parsed.', $effectiveUrl));
        }

        // check if action defined for returned Content-Type, like image, pdf, audio or video
        $mimeInfo = $this->getMimeActionInfo($response['headers']);
        $infos = $this->handleMimeAction($mimeInfo, $effectiveUrl, $response);
        if (\is_array($infos)) {
            return $infos;
        }

        $html = $this->convert2Utf8($response['body'], $response['headers']);

        $this->logger->debug('Fetched HTML', ['html' => $html]);

        // Remove empty lines to avoid runaway evaluation of following regex on badly coded websites
        $re = '/^[ \t]*[\r\n]+/m';
        $htmlCleaned = preg_replace($re, '', $html);

        // Remove empty nodes (except iframe)
        $re = '/<(?!iframe)([^>\s]+)[^>]*>(?:<br \/>|&nbsp;|&thinsp;|&ensp;|&emsp;|&#8201;|&#8194;|&#8195;|\s)*<\/\1>/m';
        $html = preg_replace($re, '', (string) $htmlCleaned);

        // in case html string is too long, keep the html uncleaned to avoid empty html
        if (\PREG_JIT_STACKLIMIT_ERROR === preg_last_error()) {
            $html = $htmlCleaned;
            $this->logger->debug('Failed to properly clean HTML from empty nodes');
        }

        $this->logger->debug('HTML after regex empty nodes stripping', ['html' => $html]);

        // some non utf8 enconding might be breaking after converting to utf8
        // when it happen the string (usually) starts with this character
        // in that case, we'll take the default response instead of the utf8 forced one
        if (0 === strpos(utf8_encode($response['body']), 'ÿþ')) {
            $html = $response['body'];
        }

        // check site config for single page URL - fetch it if found
        $isSinglePage = false;
        if ($this->config->getSinglepage() && ($singlePageResponse = $this->getSinglePage($html, $effectiveUrl))) {
            $isSinglePage = true;
            $effectiveUrl = $singlePageResponse['effective_url'];

            // check if action defined for returned Content-Type
            $mimeInfo = $this->getMimeActionInfo($singlePageResponse['headers']);
            $infos = $this->handleMimeAction($mimeInfo, $effectiveUrl, $singlePageResponse);
            if (\is_array($infos)) {
                return $infos;
            }

            $html = $this->convert2Utf8($singlePageResponse['body'], $singlePageResponse['headers']);
            $this->logger->info('Retrieved single-page view from "{url}"', ['url' => $effectiveUrl]);

            unset($singlePageResponse);
        }

        $this->logger->info('Attempting to extract content');

        $extractResult = $this->extractor->process($html, $effectiveUrl);
        /** @var Readability */
        $readability = $this->extractor->readability;
        $contentBlock = $this->extractor->getContent();
        $extractedTitle = $this->extractor->getTitle();
        $extractedLanguage = $this->extractor->getLanguage();
        $extractedDate = $this->extractor->getDate();
        $extractedAuthors = $this->extractor->getAuthors();
        $extractedImage = $this->extractor->getImage();

        // ensure image is absolute
        if (!empty($extractedImage)) {
            $extractedImage = $this->makeAbsoluteStr($effectiveUrl, $extractedImage);
        }

        // in case of no language were found, try using headers Content-Language
        if (empty($extractedLanguage) && !empty($response['headers']['content-language'])) {
            $extractedLanguage = $response['headers']['content-language'];
        }

        // Deal with multi-page articles
        $isMultiPage = (!$isSinglePage && $extractResult && null !== $this->extractor->getNextPageUrl());
        if ($this->config->getMultipage() && $isMultiPage) {
            $this->logger->info('Attempting to process multi-page article');
            // store first page to avoid parsing it again (previous url content is in `$contentBlock`)
            $multiPageUrls = [$effectiveUrl];
            $multiPageContent = [];

            while ($nextPageUrl = $this->extractor->getNextPageUrl()) {
                $this->logger->info('Processing next page: {url}', ['url' => $nextPageUrl]);
                // If we've got URL, resolve against $url
                $nextPageUrl = $this->makeAbsoluteStr($effectiveUrl, $nextPageUrl);
                if (!$nextPageUrl) {
                    $this->logger->info('Failed to resolve against: {url}', ['url' => $effectiveUrl]);
                    $multiPageContent = [];
                    break;
                }

                // check it's not what we have already!
                if (\in_array($nextPageUrl, $multiPageUrls, true)) {
                    $this->logger->info('URL already processed');
                    $multiPageContent = [];
                    break;
                }

                // it's not, store it for later check & so let's attempt to fetch it
                $multiPageUrls[] = $nextPageUrl;

                $response = $this->httpClient->fetch($nextPageUrl, false, $siteConfig->http_header);

                // make sure mime type is not something with a different action associated
                $mimeInfo = $this->getMimeActionInfo($response['headers']);

                if (isset($mimeInfo['action'])) {
                    $this->logger->info('MIME type requires different action');
                    $multiPageContent = [];
                    break;
                }

                $extracSuccess = $this->extractor->process(
                    $this->convert2Utf8($response['body'], $response['headers']),
                    $nextPageUrl
                );

                if (!$extracSuccess) {
                    $this->logger->info('Failed to extract content');
                    $multiPageContent = [];
                    break;
                }

                $multiPageContent[] = clone $this->extractor->getContent();
            }

            // did we successfully deal with this multi-page article?
            if (empty($multiPageContent)) {
                $this->logger->info('Failed to extract all parts of multi-page article, so not going to include them');
                $page = $readability->dom->createElement('p');
                $page->innerHTML = '<em>This article appears to continue on subsequent pages which we could not extract</em>';
                $multiPageContent[] = $page;
            }

            foreach ($multiPageContent as $page) {
                $page = $contentBlock->ownerDocument->importNode($page, true);
                $contentBlock->appendChild($page);
            }

            unset($multiPageUrls, $multiPageContent, $nextPageUrl, $page);
        }

        $res = [
            'status' => $response['status'],
            'html' => $this->config->getErrorMessage(),
            'title' => $extractedTitle ?: $this->config->getErrorMessageTitle(),
            'language' => $extractedLanguage,
            'date' => $extractedDate,
            'authors' => $extractedAuthors,
            'url' => $effectiveUrl,
            'image' => $extractedImage,
            'native_ad' => $this->extractor->isNativeAd(),
            'headers' => $response['headers'],
        ];

        // if we failed to extract content...
        if (!$extractResult || null === $contentBlock) {
            $this->logger->info('Extract failed');

            return $res;
        }

        $res['html'] = $this->cleanupHtml($contentBlock, $effectiveUrl);

        $this->logger->info('Returning data (most interesting ones): {data}', ['data' => ['html' => '(only length for debug): ' . \strlen($res['html'])] + $res]);

        return $res;
    }

    /**
     * Validate & clean the given url.
     */
    private function validateUrl(string $url): string
    {
        // Check for feed URL
        $url = trim($url);
        if ('feed://' === strtolower(substr($url, 0, 7))) {
            $url = 'http://' . substr($url, 7);
        }

        if (!preg_match('!^https?://.+!i', $url)) {
            $url = 'http://' . $url;
        }

        $uri = new Uri((string) $url);

        if (preg_match('/[\x80-\xff]/', $uri->getHost())) {
            $uri = $uri->withHost($this->punycode->encode($uri->getHost()));
        }

        if (\strlen($uri->getPath()) && preg_match('/[\x80-\xff]/', $uri->getPath())) {
            $path = [];
            foreach (explode('/', $uri->getPath()) as $value) {
                $path[] = urlencode($value);
            }

            $uri = $uri->withPath(implode('/', $path));
        }

        // everything should be converted, rebuild the final url
        $url = (string) $uri;

        if (false === filter_var($url, \FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException(sprintf('Url "%s" is not valid.', $url));
        }

        $url = filter_var($url, \FILTER_SANITIZE_URL);

        if (false === $url) {
            throw new \InvalidArgumentException(sprintf('Sanitizing url "%s" failed.', $url));
        }

        if (false === $this->isUrlAllowed($url)) {
            throw new \InvalidArgumentException(sprintf('Url "%s" is not allowed to be parsed.', $url));
        }

        return $url;
    }

    private function isUrlAllowed(string $url): bool
    {
        if (!empty($this->config->getAllowedUrls())) {
            foreach ($this->config->getAllowedUrls() as $allowurl) {
                if (false !== stristr($url, $allowurl)) {
                    return true;
                }
            }
        } else {
            foreach ($this->config->getBlockedUrls() as $blockurl) {
                if (false !== stristr($url, $blockurl)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Based on content-type http header, decide what to do.
     *
     * @return array With keys: 'mime', 'type', 'subtype', 'action', 'name'
     *               e.g. array('mime'=>'image/jpeg', 'type'=>'image', 'subtype'=>'jpeg', 'action'=>'link', 'name'=>'Image')
     */
    private function getMimeActionInfo(array $headers): array
    {
        $contentType = isset($headers['content-type']) ? strtolower($headers['content-type']) : '';

        // check if action defined for returned Content-Type
        $info = [
            'mime' => '',
        ];

        if (preg_match('!\s*(([-\w]+)/([-\w\+]+))!im', $contentType, $match)) {
            // look for full mime type (e.g. image/jpeg) or just type (e.g. image)
            // match[1] = full mime type, e.g. image/jpeg
            // match[2] = first part, e.g. image
            // match[3] = last part, e.g. jpeg
            $info['mime'] = trim($match[1]);
            $info['type'] = trim($match[2]);
            $info['subtype'] = trim($match[3]);

            foreach ([$info['mime'], $info['type']] as $mime) {
                if (isset($this->config->getContentTypeExc()[$mime])) {
                    $info['action'] = $this->config->getContentTypeExc()[$mime]['action'];
                    $info['name'] = $this->config->getContentTypeExc()[$mime]['name'];

                    break;
                }
            }
        }

        return $info;
    }

    /**
     * Handle action related to mime type detection.
     * These action can be exclude or link to handle custom content (like image, video, pdf, etc ..).
     *
     * @param array  $mimeInfo     From getMimeActionInfo() function
     * @param string $effectiveUrl Current content url
     * @param array  $response     A response
     */
    private function handleMimeAction(array $mimeInfo, string $effectiveUrl, array $response = []): ?array
    {
        if (!isset($mimeInfo['action'])) {
            return null;
        }

        $body = $response['body'] ?? '';

        $infos = [
            // at this point status will always be considered as 200
            'status' => 200,
            'title' => $mimeInfo['name'],
            'language' => '',
            'date' => null,
            'authors' => [],
            'html' => '',
            'url' => $effectiveUrl,
            'image' => '',
            'native_ad' => false,
            'headers' => $response['headers'],
        ];

        if ('exclude' === $mimeInfo['action']) {
            throw new \Exception(sprintf('This is url "%s" is blocked by mime action.', $effectiveUrl));
        }

        $infos['html'] = '<a href="' . $effectiveUrl . '">Download ' . $mimeInfo['name'] . '</a>';

        if ('image' === $mimeInfo['type']) {
            $infos['html'] = '<a href="' . $effectiveUrl . '"><img src="' . $effectiveUrl . '" alt="' . $mimeInfo['name'] . '" /></a>';
        }

        if ('application/pdf' === $mimeInfo['mime']) {
            $parser = new PdfParser();
            $pdf = $parser->parseContent($body);

            // tiny hack to avoid character like �
            $html = mb_convert_encoding(nl2br($pdf->getText()), 'UTF-8', 'UTF-8');

            // strip away unwanted chars (that usualy came from PDF extracted content)
            // @see http://www.phpwact.org/php/i18n/charsets#common_problem_areas_with_utf-8
            $html = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $html);

            $infos['html'] = $html;

            // update title in case of details are present
            $details = $pdf->getDetails();

            // Title can be a string or an array with one key
            if (isset($details['Title'])) {
                if (\is_array($details['Title']) && isset($details['Title'][0]) && '' !== trim($details['Title'][0])) {
                    $infos['title'] = $details['Title'][0];
                } elseif (\is_string($details['Title']) && '' !== trim($details['Title'])) {
                    $infos['title'] = $details['Title'];
                }
            }

            if (isset($details['Author'])) {
                if (\is_array($details['Author']) && isset($details['Author'][0]) && '' !== trim($details['Author'][0])) {
                    $infos['authors'][] = $details['Author'][0];
                } elseif (\is_string($details['Author']) && '' !== trim($details['Author'])) {
                    $infos['authors'][] = $details['Author'];
                }
            }

            if (isset($details['CreationDate'])) {
                if (\is_array($details['CreationDate']) && isset($details['CreationDate'][0]) && '' !== trim($details['CreationDate'][0])) {
                    $infos['date'] = $this->extractor->validateDate($details['CreationDate'][0]);
                } elseif (\is_string($details['CreationDate']) && '' !== trim($details['CreationDate'])) {
                    $infos['date'] = $this->extractor->validateDate($details['CreationDate']);
                }
            }
        }

        if ('text/plain' === $mimeInfo['mime']) {
            $infos['html'] = '<pre>' .
                $this->cleanupXss(
                    $this->convert2Utf8($body, $response['headers'] ?? [])
                ) . '</pre>';
        }

        $infos['html'] = $this->cleanupXss((string) $infos['html']);

        return $infos;
    }

    /**
     * returns single page response, or false if not found.
     *
     * @return false|array From httpClient fetch
     */
    private function getSinglePage(string $html, string $url)
    {
        $this->logger->info('Looking for site config files to see if single page link exists');
        $siteConfig = $this->configBuilder->buildFromUrl($url);

        // no single page found?
        if (empty($siteConfig->single_page_link)) {
            $this->logger->info('No "single_page_link" config found');

            return false;
        }

        // Build DOM tree from HTML
        $readability = new Readability($html, $url);
        $xpath = new \DOMXPath($readability->dom);

        // Loop through single_page_link xpath expressions
        $singlePageUrl = null;

        foreach ($siteConfig->single_page_link as $pattern) {
            // Do we have conditions?
            $condition = $siteConfig->getIfPageContainsCondition('single_page_link', $pattern);

            if ($condition) {
                $elems = $xpath->evaluate($condition, $readability->dom);

                // move on to next single_page_link XPath in case condition isn't met
                if (!($elems instanceof \DOMNodeList && $elems->length > 0)) {
                    continue;
                }
            }

            $elems = $xpath->evaluate($pattern, $readability->dom);

            if (\is_string($elems)) {
                $singlePageUrl = trim($elems);
                break;
            } elseif ($elems instanceof \DOMNodeList && $elems->length > 0) {
                foreach ($elems as $item) {
                    if ($item instanceof \DOMElement && $item->hasAttribute('href')) {
                        $singlePageUrl = $item->getAttribute('href');
                        break 2;
                    } elseif ($item instanceof \DOMAttr && $item->value) {
                        $singlePageUrl = $item->value;
                        break 2;
                    }
                }
            }
        }

        if (!$singlePageUrl) {
            $this->logger->info('No single page url found');

            return false;
        }

        // try to resolve against $url
        $singlePageUrl = $this->makeAbsoluteStr($url, $singlePageUrl);

        // check it's not what we have already!
        if (false !== $singlePageUrl && $singlePageUrl !== $url) {
            // it's not, so let's try to fetch it...
            $headers = $siteConfig->http_header;

            $sourceUrl = parse_url($url);
            $targetUrl = parse_url($singlePageUrl);
            if (\is_array($sourceUrl)
                && \is_array($targetUrl)
                && \array_key_exists('host', $sourceUrl)
                && \array_key_exists('host', $targetUrl)
                && $sourceUrl['host'] !== $targetUrl['host']) {
                $targetSiteConfig = $this->configBuilder->buildForHost($targetUrl['host']);
                $headers = $targetSiteConfig->http_header;
            }
            $response = $this->httpClient->fetch($singlePageUrl, false, $headers);

            if ($response['status'] < 300) {
                $this->logger->info('Single page content found with url', ['url' => $singlePageUrl]);

                return $response;
            }
        }

        $this->logger->info('No content found with url', ['url' => $singlePageUrl]);

        return false;
    }

    /**
     * Make an absolute url from an element.
     *
     * @param string      $base The base url
     * @param \DOMElement $elem Element on which we'll retrieve the attribute
     */
    private function makeAbsolute(string $base, \DOMElement $elem): void
    {
        foreach (['a' => 'href', 'img' => 'src', 'iframe' => 'src'] as $tag => $attr) {
            $elems = $elem->getElementsByTagName($tag);

            for ($i = $elems->length - 1; $i >= 0; --$i) {
                $e = $elems->item($i);
                if (null !== $e) {
                    $this->makeAbsoluteAttr($base, $e, $attr);
                }
            }

            if (strtolower($elem->nodeName) === $tag) {
                $this->makeAbsoluteAttr($base, $elem, $attr);
            }
        }
    }

    /**
     * Make an attribute absolute (href or src).
     *
     * @param string   $base The base url
     * @param \DOMNode $e    Element on which we'll retrieve the attribute
     * @param string   $attr Attribute that contains the url to absolutize
     */
    private function makeAbsoluteAttr(string $base, \DOMNode $e, $attr): void
    {
        if (!$e->attributes->getNamedItem($attr) || !$e instanceof \DOMElement) {
            return;
        }

        // Trim leading and trailing white space. I don't really like this but
        // unfortunately it does appear on some sites. e.g.  <img src=" /path/to/image.jpg" />
        $url = trim(str_replace('%20', ' ', $e->getAttribute($attr)));
        $url = str_replace(' ', '%20', $url);

        try {
            $absolute = $this->makeAbsoluteStr($base, $url);
        } catch (\Exception $exception) {
            $this->logger->info('Wrong content url', ['url' => $url]);
            $absolute = $url;
        }
        if (false !== $absolute) {
            $e->setAttribute($attr, $absolute);
        }
    }

    /**
     * Make an $url absolute based on the $base.
     *
     * @param string $base Base url
     * @param string $url  Url to make it absolute
     *
     * @return false|string
     */
    private function makeAbsoluteStr(string $base, string $url)
    {
        if (!$url) {
            return false;
        }

        $url = new Uri($url);

        if (Uri::isAbsolute($url)) {
            return (string) $url;
        }

        $base = new Uri($base);

        // in case the url has no host
        if ('' === $base->getAuthority()) {
            return false;
        }

        return (string) UriResolver::resolve($base, $url);
    }

    /**
     * Truncate text.
     *
     * @see https://github.com/twigphp/Twig-extensions/blob/449e3c8a9ffad7c2479c7864557275a32b037499/lib/Twig/Extensions/Extension/Text.php#L40
     */
    private function getExcerpt(string $text, int $length = 250, ?string $separator = ' &hellip;'): string
    {
        // use regex instead of strip_tags to left some spaces when removing tags
        $text = preg_replace('#<[^>]+>#', ' ', (string) $text);

        // trim whitespace at beginning or end of string
        // See: http://stackoverflow.com/a/4167053/569101
        $text = preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', (string) $text);
        // clean new lines and tabs
        $text = trim((string) preg_replace("/[\n\r\t ]+/", ' ', (string) $text), ' ');

        if (mb_strlen($text) > $length) {
            // If breakpoint is on the last word, return the text without separator.
            $breakpoint = mb_strpos($text, ' ', $length);
            if (false === $breakpoint) {
                return $text;
            }

            return rtrim(mb_substr($text, 0, $breakpoint)) . $separator;
        }

        return $text;
    }

    /**
     * Convert string to utf8
     * (uses HTTP headers and HTML to find encoding).
     *
     * Adapted from http://stackoverflow.com/questions/910793/php-detect-encoding-and-make-everything-utf-8
     */
    private function convert2Utf8(string $html, array $headers = []): string
    {
        $contentType = isset($headers['content-type']) ? strtolower($headers['content-type']) : '';

        if (empty($html) || empty($contentType)) {
            return $html;
        }

        $encoding = null;
        // remove strange things
        $html = str_replace('</[>', '', $html);

        if (!preg_match_all('/([^;]+)(?:;\s*charset=["\']?([^;"\'\n]*))?/im', $contentType, $match, \PREG_SET_ORDER)) {
            // error parsing the response
            $this->logger->info('Could not find Content-Type header in HTTP response', ['headers' => $headers]);
        } else {
            // get last matched element (in case of redirects)
            $match = end($match);

            if (isset($match[2])) {
                $encoding = trim($match[2], "\"' \r\n\0\x0B\t");
            }
        }

        // TODO: check to see if encoding is supported (can we convert it?)
        // If it's not, result will be empty string.
        // For now we'll check for invalid encoding types returned by some sites, e.g. 'none'
        // Problem URL: http://facta.co.jp/blog/archives/20111026001026.html
        if (empty($encoding) || 'none' === $encoding) {
            // search for encoding in HTML - only look at the first 50000 characters
            // Why 50000? See, for example, http://www.lemonde.fr/festival-de-cannes/article/2012/05/23/deux-cretes-en-goguette-sur-la-croisette_1705732_766360.html
            // TODO: improve this so it looks at smaller chunks first
            $html_head = substr($html, 0, 50000);
            if (preg_match('/^<\?xml\s+version=(?:"[^"]*"|\'[^\']*\')\s+encoding=("[^"]*"|\'[^\']*\')/s', $html_head, $match)) {
                $encoding = trim($match[1], '"\'');
            } elseif (preg_match('/<meta\s+http-equiv=["\']?Content-Type["\']? content=["\'][^;]+;\s*charset=["\']?([^;"\'>]+)/i', $html_head, $match)) {
                $encoding = trim($match[1]);
            } elseif (preg_match_all('/<meta\s+([^>]+)>/i', $html_head, $match)) {
                foreach ($match[1] as $_test) {
                    if (preg_match('/charset=["\']?([^"\']+)/i', $_test, $_m)) {
                        $encoding = trim($_m[1]);
                        break;
                    }
                }
            }
        }

        $encoding = strtolower(trim((string) $encoding));

        // fix bad encoding values
        if ('iso-8850-1' === $encoding) {
            $encoding = 'iso-8859-1';
        }

        if (empty($encoding) || 'iso-8859-1' === $encoding) {
            // replace MS Word smart qutoes
            $trans = [];
            $trans[\chr(130)] = '&sbquo;'; // Single Low-9 Quotation Mark
            $trans[\chr(131)] = '&fnof;'; // Latin Small Letter F With Hook
            $trans[\chr(132)] = '&bdquo;'; // Double Low-9 Quotation Mark
            $trans[\chr(133)] = '&hellip;'; // Horizontal Ellipsis
            $trans[\chr(134)] = '&dagger;'; // Dagger
            $trans[\chr(135)] = '&Dagger;'; // Double Dagger
            $trans[\chr(136)] = '&circ;'; // Modifier Letter Circumflex Accent
            $trans[\chr(137)] = '&permil;'; // Per Mille Sign
            $trans[\chr(138)] = '&Scaron;'; // Latin Capital Letter S With Caron
            $trans[\chr(139)] = '&lsaquo;'; // Single Left-Pointing Angle Quotation Mark
            $trans[\chr(140)] = '&OElig;'; // Latin Capital Ligature OE
            $trans[\chr(145)] = '&lsquo;'; // Left Single Quotation Mark
            $trans[\chr(146)] = '&rsquo;'; // Right Single Quotation Mark
            $trans[\chr(147)] = '&ldquo;'; // Left Double Quotation Mark
            $trans[\chr(148)] = '&rdquo;'; // Right Double Quotation Mark
            $trans[\chr(149)] = '&bull;'; // Bullet
            $trans[\chr(150)] = '&ndash;'; // En Dash
            $trans[\chr(151)] = '&mdash;'; // Em Dash
            $trans[\chr(152)] = '&tilde;'; // Small Tilde
            $trans[\chr(153)] = '&trade;'; // Trade Mark Sign
            $trans[\chr(154)] = '&scaron;'; // Latin Small Letter S With Caron
            $trans[\chr(155)] = '&rsaquo;'; // Single Right-Pointing Angle Quotation Mark
            $trans[\chr(156)] = '&oelig;'; // Latin Small Ligature OE
            $trans[\chr(159)] = '&Yuml;'; // Latin Capital Letter Y With Diaeresis
            $html = strtr($html, $trans);
        }

        if ('utf-8' !== $encoding) {
            // https://www.w3.org/International/articles/http-charset/index#charset
            // HTTP 1.1 says that the default charset is ISO-8859-1
            $encoding = $encoding ?: 'iso-8859-1';
            $this->logger->info('Converting to UTF-8', ['encoding' => $encoding]);

            $converted = \SimplePie_Misc::change_encoding($html, $encoding, 'utf-8');

            return false === $converted ? $html : (string) $converted;
        }

        $this->logger->info('Treating as UTF-8', ['encoding' => $encoding]);

        return $html;
    }

    /**
     * Try to cleanup XSS using htmLawed.
     */
    private function cleanupXss(string $html): string
    {
        if (false === $this->config->getXssFilter()) {
            return $html;
        }

        $this->logger->info('Filtering HTML to remove XSS');

        return htmLawed(
            $html,
            [
                'safe' => 1,
                // *+iframe: do not remove iframe elements
                'elements' => '*+iframe-meta',
                'deny_attribute' => 'style',
                'comment' => 1,
                'cdata' => 1,
            ]
        );
    }
}
