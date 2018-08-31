<?php

namespace Graby;

use Graby\Extractor\ContentExtractor;
use Graby\Extractor\HttpClient;
use Graby\Ring\Client\SafeCurlHandler;
use Graby\SiteConfig\ConfigBuilder;
use GuzzleHttp\Client;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Readability\Readability;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\OptionsResolver\OptionsResolver;
use TrueBV\Punycode;

/**
 * @todo add proxy
 * @todo add cache
 */
class Graby
{
    private $debug = false;
    private $logger;

    private $config = [];

    private $httpClient = null;
    private $extractor = null;

    /** @var ConfigBuilder */
    private $configBuilder;
    private $punycode;

    /**
     * @param array         $config
     * @param Client|null   $client        Guzzle client
     * @param ConfigBuilder $configBuilder
     */
    public function __construct($config = [], Client $client = null, ConfigBuilder $configBuilder = null)
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'debug' => false,
            'rewrite_relative_urls' => true,
            'singlepage' => true,
            'multipage' => true,
            'error_message' => '[unable to retrieve full-text content]',
            'error_message_title' => 'No title found',
            'allowed_urls' => [],
            'blocked_urls' => [],
            'xss_filter' => true,
            'content_type_exc' => [
                'application/zip' => ['action' => 'link', 'name' => 'ZIP'],
                'application/pdf' => ['action' => 'link', 'name' => 'PDF'],
                'image' => ['action' => 'link', 'name' => 'Image'],
                'audio' => ['action' => 'link', 'name' => 'Audio'],
                'video' => ['action' => 'link', 'name' => 'Video'],
                'text/plain' => ['action' => 'link', 'name' => 'Plain text'],
            ],
            'content_links' => 'preserve',
            'http_client' => [],
            'extractor' => [],
        ]);

        // @TODO: add more validation ? (setAllowedTypes)
        $resolver->setAllowedValues('content_links', ['preserve', 'footnotes', 'remove']);

        $this->config = $resolver->resolve($config);

        $this->debug = (bool) $this->config['debug'];
        $this->logger = new NullLogger();

        if ($this->debug) {
            $this->logger = new Logger('graby');
            $this->logger->pushHandler(new StreamHandler(__DIR__ . '/../log/graby.log'));
        }

        $this->configBuilder = $configBuilder;
        if (null === $this->configBuilder) {
            $this->configBuilder = new ConfigBuilder(
                isset($this->config['extractor']['config_builder']) ? $this->config['extractor']['config_builder'] : [],
                $this->logger
            );
        }

        $this->extractor = new ContentExtractor(
            $this->config['extractor'],
            $this->logger,
            $this->configBuilder
        );

        $this->httpClient = new HttpClient(
            $client ?: new Client(['handler' => new SafeCurlHandler(), 'defaults' => ['cookies' => true]]),
            $this->config['http_client'],
            $this->logger
        );

        $this->punycode = new Punycode();
    }

    /**
     * Redefine all loggers.
     *
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->extractor->setLogger($logger);
        $this->httpClient->setLogger($logger);
    }

    /**
     * Return a config.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function getConfig($key)
    {
        if (!isset($this->config[$key])) {
            throw new \Exception(sprintf('No config found for key: "%s"', $key));
        }

        return $this->config[$key];
    }

    /**
     * Fetch content from the given url and return a readable content.
     *
     * @param string $url
     *
     * @return array With keys html, title, url & summary
     */
    public function fetchContent($url)
    {
        $this->logger->log('debug', 'Graby is ready to fetch');

        $infos = $this->doFetchContent($url);

        // generate summary
        $infos['summary'] = $this->getExcerpt($infos['html']);

        return $infos;
    }

    /**
     * Cleanup HTML from a DOMElement or a string.
     *
     * @param string|\DOMElement $contentBlock
     * @param string             $url
     *
     * @return string
     */
    public function cleanupHtml($contentBlock, $url)
    {
        $originalContentBlock = $contentBlock;

        // if content is pure html, convert it
        if (!$contentBlock instanceof \DOMElement) {
            $this->extractor->process($contentBlock, $url);

            $contentBlock = $this->extractor->getContent();
        }

        // in case of extractor failed
        if (null === $contentBlock) {
            $this->logger->log('debug', 'Cleanup html failed. Return given content (a bit cleaned)');

            return trim($this->cleanupXss($originalContentBlock));
        }

        $this->extractor->readability->clean($contentBlock, 'select');

        if ($this->config['rewrite_relative_urls']) {
            $this->makeAbsolute($url, $contentBlock);
        }

        // footnotes
        if ('footnotes' === $this->config['content_links'] && false === strpos($url, 'wikipedia.org')) {
            $this->extractor->readability->addFootnotes($contentBlock);
        }

        // normalise
        $contentBlock->normalize();

        // remove empty text nodes
        foreach ($contentBlock->childNodes as $n) {
            if (XML_TEXT_NODE === $n->nodeType && '' === trim($n->textContent)) {
                $contentBlock->removeChild($n);
            }
        }

        // remove nesting: <div><div><div><p>test</p></div></div></div> = <p>test</p>
        while (1 === $contentBlock->childNodes->length && XML_ELEMENT_NODE === $contentBlock->firstChild->nodeType) {
            // only follow these tag names
            if (!\in_array(strtolower($contentBlock->tagName), ['div', 'article', 'section', 'header', 'footer'], true)) {
                break;
            }

            $contentBlock = $contentBlock->firstChild;
        }

        // convert content block to HTML string
        // Need to preserve things like body: //img[@id='feature']
        if (\in_array(strtolower($contentBlock->tagName), ['div', 'article', 'section', 'header', 'footer', 'li', 'td'], true)) {
            $html = $contentBlock->innerHTML;
        } else {
            $html = $contentBlock->ownerDocument->saveXML($contentBlock); // essentially outerHTML
        }

        // post-processing cleanup
        $html = preg_replace('!<p>[\s\h\v]*</p>!u', '', $html);
        if ('remove' === $this->config['content_links']) {
            $html = preg_replace('!</?a[^>]*>!', '', $html);
        }

        return trim($this->cleanupXss($html));
    }

    /**
     * Do fetch content from an url.
     *
     * @param string $url
     *
     * @return array With key status, html, title, language, url, content_type & open_graph
     */
    private function doFetchContent($url)
    {
        $url = $this->validateUrl($url);
        $siteConfig = $this->configBuilder->buildFromUrl($url);

        $this->logger->log('debug', 'Fetching url: {url}', ['url' => $url]);

        $response = $this->httpClient->fetch($url, false, $siteConfig->http_header);

        $effectiveUrl = $response['effective_url'];
        $effectiveUrl = str_replace(' ', '%20', $effectiveUrl);
        if (!$this->isUrlAllowed($effectiveUrl)) {
            throw new \Exception(sprintf('Url "%s" is not allowed to be parsed.', $effectiveUrl));
        }

        // check if action defined for returned Content-Type, like image, pdf, audio or video
        $mimeInfo = $this->getMimeActionInfo($response['all_headers']);
        $infos = $this->handleMimeAction($mimeInfo, $effectiveUrl, $response);
        if (\is_array($infos)) {
            return $infos;
        }

        $html = $this->convert2Utf8($response['body'], $response['all_headers']);

        // Remove empty nodes (except iframe)
        $re = '/<([^>\s]+)[^iframe|>]*>(?:\s*(?:<br \/>|&nbsp;|&thinsp;|&ensp;|&emsp;|&#8201;|&#8194;|&#8195;)\s*)*<\/\1>/m';
        $html = preg_replace($re, '', $html);

        // some non utf8 enconding might be breaking after converting to utf8
        // when it happen the string (usually) starts with this character
        // in that case, we'll take the default response instead of the utf8 forced one
        if (0 === strpos(utf8_encode($response['body']), 'ÿþ')) {
            $html = $response['body'];
        }

        $ogData = $this->extractOpenGraph($html, $effectiveUrl);

        $this->logger->log('debug', 'Opengraph data: {ogData}', ['ogData' => $ogData]);

        // @TODO: log raw html + headers

        // check site config for single page URL - fetch it if found
        $isSinglePage = false;
        if ($this->config['singlepage'] && ($singlePageResponse = $this->getSinglePage($html, $effectiveUrl))) {
            $isSinglePage = true;
            $effectiveUrl = $singlePageResponse['effective_url'];

            // check if action defined for returned Content-Type
            $mimeInfo = $this->getMimeActionInfo($singlePageResponse['all_headers']);
            $infos = $this->handleMimeAction($mimeInfo, $effectiveUrl, $singlePageResponse);
            if (\is_array($infos)) {
                return $infos;
            }

            $html = $this->convert2Utf8($singlePageResponse['body'], $singlePageResponse['all_headers']);
            $this->logger->log('debug', 'Retrieved single-page view from "{url}"', ['url' => $effectiveUrl]);

            unset($singlePageResponse);
        }

        $this->logger->log('debug', 'Attempting to extract content');

        $extractResult = $this->extractor->process($html, $effectiveUrl);
        $readability = $this->extractor->readability;
        $contentBlock = $this->extractor->getContent();
        $extractedTitle = $this->extractor->getTitle();
        $extractedLanguage = $this->extractor->getLanguage();
        $extractedDate = $this->extractor->getDate();
        $extractedAuthors = $this->extractor->getAuthors();

        // in case of no language were found, try using headers Content-Language
        if (empty($extractedLanguage) && !empty($response['all_headers']['content-language'])) {
            $extractedLanguage = $response['all_headers']['content-language'];
        }

        // Deal with multi-page articles
        $isMultiPage = (!$isSinglePage && $extractResult && null !== $this->extractor->getNextPageUrl());
        if ($this->config['multipage'] && $isMultiPage) {
            $this->logger->log('debug', 'Attempting to process multi-page article');
            // store first page to avoid parsing it again (previous url content is in `$contentBlock`)
            $multiPageUrls = [$effectiveUrl];
            $multiPageContent = [];

            while ($nextPageUrl = $this->extractor->getNextPageUrl()) {
                $this->logger->log('debug', 'Processing next page: {url}', ['url' => $nextPageUrl]);
                // If we've got URL, resolve against $url
                $nextPageUrl = $this->makeAbsoluteStr($effectiveUrl, $nextPageUrl);
                if (!$nextPageUrl) {
                    $this->logger->log('debug', 'Failed to resolve against: {url}', ['url' => $effectiveUrl]);
                    $multiPageContent = [];
                    break;
                }

                // check it's not what we have already!
                if (\in_array($nextPageUrl, $multiPageUrls, true)) {
                    $this->logger->log('debug', 'URL already processed');
                    $multiPageContent = [];
                    break;
                }

                // it's not, store it for later check & so let's attempt to fetch it
                $multiPageUrls[] = $nextPageUrl;

                $response = $this->httpClient->fetch($nextPageUrl, false, $siteConfig->http_header);

                // make sure mime type is not something with a different action associated
                $mimeInfo = $this->getMimeActionInfo($response['all_headers']);

                if (isset($mimeInfo['action'])) {
                    $this->logger->log('debug', 'MIME type requires different action');
                    $multiPageContent = [];
                    break;
                }

                $extracSuccess = $this->extractor->process(
                    $this->convert2Utf8($response['body'], $response['all_headers']),
                    $nextPageUrl
                );

                if (!$extracSuccess) {
                    $this->logger->log('debug', 'Failed to extract content');
                    $multiPageContent = [];
                    break;
                }

                $multiPageContent[] = clone $this->extractor->getContent();
            }

            // did we successfully deal with this multi-page article?
            if (empty($multiPageContent)) {
                $this->logger->log('debug', 'Failed to extract all parts of multi-page article, so not going to include them');
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
            'html' => $this->config['error_message'],
            'title' => $extractedTitle ?: $this->config['error_message_title'],
            'language' => $extractedLanguage,
            'date' => $extractedDate,
            'authors' => $extractedAuthors,
            'url' => $effectiveUrl,
            'content_type' => isset($mimeInfo['mime']) ? $mimeInfo['mime'] : '',
            'open_graph' => $ogData,
            'native_ad' => $this->extractor->isNativeAd(),
            'all_headers' => $response['all_headers'],
        ];

        // if we failed to extract content...
        if (!$extractResult || null === $contentBlock) {
            $this->logger->log('debug', 'Extract failed');

            return $res;
        }

        $res['html'] = $this->cleanupHtml($contentBlock, $effectiveUrl);

        $this->logger->log('debug', 'Returning data (most interesting ones): {data}', ['data' => ($res + ['html' => \strlen($res['html'])])]);

        return $res;
    }

    /**
     * Validate & clean the given url.
     *
     * @param string $url
     *
     * @return string
     */
    private function validateUrl($url)
    {
        // Check for feed URL
        $url = trim($url);
        if ('feed://' === strtolower(substr($url, 0, 7))) {
            $url = 'http://' . substr($url, 7);
        }

        if (!preg_match('!^https?://.+!i', $url)) {
            $url = 'http://' . $url;
        }

        // explode url to convert accents
        $parsedUrl = parse_url($url);

        if (false === $parsedUrl) {
            throw new \Exception(sprintf('Url "%s" is not valid.', $url));
        }

        if (isset($parsedUrl['host']) && preg_match('/[\x80-\xff]/', $parsedUrl['host'])) {
            $parsedUrl['host'] = $this->punycode->encode($parsedUrl['host']);
        }

        if (isset($parsedUrl['path']) && preg_match('/[\x80-\xff]/', $parsedUrl['path'])) {
            $path = [];
            foreach (explode('/', $parsedUrl['path']) as $value) {
                $path[] = urlencode($value);
            }
            $parsedUrl['path'] = implode('/', $path);
        }

        // everything should be converted, rebuild the final url
        $url = $this->unparseUrl($parsedUrl);

        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \Exception(sprintf('Url "%s" is not valid.', $url));
        }

        $url = filter_var($url, FILTER_SANITIZE_URL);

        if (false === $this->isUrlAllowed($url)) {
            throw new \Exception(sprintf('Url "%s" is not allowed to be parsed.', $url));
        }

        return $url;
    }

    private function isUrlAllowed($url)
    {
        $allowedUrls = $this->getConfig('allowed_urls');
        $blockedUrls = $this->getConfig('blocked_urls');

        if (!empty($allowedUrls)) {
            foreach ($allowedUrls as $allowurl) {
                if (false !== stristr($url, $allowurl)) {
                    return true;
                }
            }
        } else {
            foreach ($blockedUrls as $blockurl) {
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
     * @param array $headers All headers from the response
     *
     * @return array With keys: 'mime', 'type', 'subtype', 'action', 'name'
     *               e.g. array('mime'=>'image/jpeg', 'type'=>'image', 'subtype'=>'jpeg', 'action'=>'link', 'name'=>'Image')
     */
    private function getMimeActionInfo(array $headers)
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
                if (isset($this->config['content_type_exc'][$mime])) {
                    $info['action'] = $this->config['content_type_exc'][$mime]['action'];
                    $info['name'] = $this->config['content_type_exc'][$mime]['name'];

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
     *
     * @return array|null
     */
    private function handleMimeAction($mimeInfo, $effectiveUrl, $response = [])
    {
        if (!isset($mimeInfo['action']) || !\in_array($mimeInfo['action'], ['link', 'exclude'], true)) {
            return;
        }

        $body = isset($response['body']) ? $response['body'] : '';

        $infos = [
            // at this point status will always be considered as 200
            'status' => 200,
            'title' => $mimeInfo['name'],
            'language' => '',
            'date' => null,
            'authors' => [],
            'html' => '',
            'url' => $effectiveUrl,
            'content_type' => $mimeInfo['mime'],
            'open_graph' => [],
            'native_ad' => false,
            'all_headers' => [],
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
                    $infos['date'] = $details['CreationDate'][0];
                } elseif (\is_string($details['CreationDate']) && '' !== trim($details['CreationDate'])) {
                    $infos['date'] = $details['CreationDate'];
                }
            }
        }

        if ('text/plain' === $mimeInfo['mime']) {
            $infos['html'] = '<pre>' .
                $this->cleanupXss(
                    $this->convert2Utf8($body, isset($response['all_headers']) ? $response['all_headers'] : [])
                ) . '</pre>';
        }

        $infos['html'] = $this->cleanupXss($infos['html']);

        return $infos;
    }

    /**
     * returns single page response, or false if not found.
     *
     * @param string $html
     * @param string $url
     *
     * @return false|array From httpClient fetch
     */
    private function getSinglePage($html, $url)
    {
        $this->logger->log('debug', 'Looking for site config files to see if single page link exists');
        $siteConfig = $this->configBuilder->buildFromUrl($url);

        // no single page found?
        if (empty($siteConfig->single_page_link)) {
            $this->logger->log('debug', 'No "single_page_link" config found');

            return false;
        }

        // Build DOM tree from HTML
        $readability = new Readability($html, $url);
        $xpath = new \DOMXPath($readability->dom);

        // Loop through single_page_link xpath expressions
        $singlePageUrl = null;

        foreach ($siteConfig->single_page_link as $pattern) {
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
            $this->logger->log('debug', 'No single page url found');

            return false;
        }

        // try to resolve against $url
        $singlePageUrl = $this->makeAbsoluteStr($url, $singlePageUrl);

        // check it's not what we have already!
        if (false !== $singlePageUrl && $singlePageUrl !== $url) {
            // it's not, so let's try to fetch it...
            $response = $this->httpClient->fetch($singlePageUrl, false, $siteConfig->http_header);

            if ($response['status'] < 300) {
                $this->logger->log('debug', 'Single page content found with url', ['url' => $singlePageUrl]);

                return $response;
            }
        }

        $this->logger->log('debug', 'No content found with url', ['url' => $singlePageUrl]);

        return false;
    }

    /**
     * Make an absolute url from an element.
     *
     * @param string   $base The base url
     * @param \DOMNode $elem Element on which we'll retrieve the attribute
     */
    private function makeAbsolute($base, \DOMNode $elem)
    {
        $base = new \SimplePie_IRI($base);

        // remove '//' in URL path (used to prevent URLs from resolving properly)
        if (isset($base->ipath)) {
            $base->ipath = str_replace('//', '/', $base->ipath);
        }

        foreach (['a' => 'href', 'img' => 'src', 'iframe' => 'src'] as $tag => $attr) {
            $elems = $elem->getElementsByTagName($tag);

            for ($i = $elems->length - 1; $i >= 0; --$i) {
                $e = $elems->item($i);
                $this->makeAbsoluteAttr($base, $e, $attr);
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
    private function makeAbsoluteAttr($base, \DOMNode $e, $attr)
    {
        if (!$e->attributes->getNamedItem($attr)) {
            return;
        }

        // Trim leading and trailing white space. I don't really like this but
        // unfortunately it does appear on some sites. e.g.  <img src=" /path/to/image.jpg" />
        $url = trim(str_replace('%20', ' ', $e->getAttribute($attr)));
        $url = str_replace(' ', '%20', $url);

        if (!preg_match('!^(https?://|#)!i', $url)) {
            if ($absolute = \SimplePie_IRI::absolutize($base, $url)) {
                $e->setAttribute($attr, $absolute);
            }
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
    private function makeAbsoluteStr($base, $url)
    {
        if (!$url) {
            return false;
        }

        if (preg_match('!^https?://!i', $url)) {
            // already absolute
            return $url;
        }

        $base = new \SimplePie_IRI($base);

        // remove '//' in URL path (causes URLs not to resolve properly)
        if (isset($base->ipath)) {
            $base->ipath = preg_replace('!//+!', '/', $base->ipath);
        }

        if ($absolute = \SimplePie_IRI::absolutize($base, $url)) {
            return $absolute->get_uri();
        }

        return false;
    }

    /**
     * Truncate text.
     *
     * @see https://github.com/twigphp/Twig-extensions/blob/449e3c8a9ffad7c2479c7864557275a32b037499/lib/Twig/Extensions/Extension/Text.php#L40
     *
     * @param string $text
     * @param int    $length
     * @param string $separator
     *
     * @return string
     */
    private function getExcerpt($text, $length = 250, $separator = ' &hellip;')
    {
        // use regex instead of strip_tags to left some spaces when removing tags
        $text = preg_replace('#<[^>]+>#', ' ', $text);

        // trim whitespace at beginning or end of string
        // See: http://stackoverflow.com/a/4167053/569101
        $text = preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $text);
        // clean new lines and tabs
        $text = trim(preg_replace("/[\n\r\t ]+/", ' ', $text), ' ');

        if (mb_strlen($text) > $length) {
            // If breakpoint is on the last word, return the text without separator.
            if (false === ($breakpoint = mb_strpos($text, ' ', $length))) {
                return $text;
            }
            $length = $breakpoint;

            return rtrim(mb_substr($text, 0, $length)) . $separator;
        }

        return $text;
    }

    /**
     * Extract OpenGraph data from the response.
     *
     * @param string $html
     * @param string $baseUrl Used to make the og:image absolute
     *
     * @return array
     *
     * @see http://stackoverflow.com/a/7454737/569101
     */
    private function extractOpenGraph($html, $baseUrl)
    {
        if ('' === trim($html)) {
            return [];
        }

        libxml_use_internal_errors(true);

        $doc = new \DomDocument();
        $doc->loadHTML($html);

        libxml_use_internal_errors(false);

        $xpath = new \DOMXPath($doc);
        $query = '//*/meta[starts-with(@property, \'og:\')]';
        $metas = $xpath->query($query);

        $rmetas = [];
        foreach ($metas as $meta) {
            $property = str_replace(':', '_', $meta->getAttribute('property'));

            if ('og_image' === $property) {
                // avoid image data:uri to avoid sending too much data
                // also, take the first og:image which is usually the best one
                if (0 === stripos($meta->getAttribute('content'), 'data:image') || !empty($rmetas[$property])) {
                    continue;
                }

                $rmetas[$property] = $this->makeAbsoluteStr($baseUrl, $meta->getAttribute('content'));

                continue;
            }

            $rmetas[$property] = $meta->getAttribute('content');
        }

        return $rmetas;
    }

    /**
     * Rebuild an url using the response from parse_url.
     * Useful to rebuild an url after editing only the host, for example.
     *
     * @param array $data
     *
     * @return array
     */
    private function unparseUrl($data)
    {
        $scheme = isset($data['scheme']) ? $data['scheme'] . '://' : '';
        $host = isset($data['host']) ? $data['host'] : '';
        $port = isset($data['port']) ? ':' . $data['port'] : '';
        $user = isset($data['user']) ? $data['user'] : '';
        $pass = isset($data['pass']) ? ':' . $data['pass'] : '';
        $pass = ($user || $pass) ? "$pass@" : '';
        $path = isset($data['path']) ? $data['path'] : '';
        $query = isset($data['query']) ? '?' . $data['query'] : '';
        $fragment = isset($data['fragment']) ? '#' . $data['fragment'] : '';

        return "$scheme$user$pass$host$port$path$query$fragment";
    }

    /**
     * Convert string to utf8
     * (uses HTTP headers and HTML to find encoding).
     *
     * Adapted from http://stackoverflow.com/questions/910793/php-detect-encoding-and-make-everything-utf-8
     *
     * @param string $html
     * @param array  $headers All headers from the response
     *
     * @return string
     */
    private function convert2Utf8($html, array $headers = [])
    {
        $contentType = isset($headers['content-type']) ? strtolower($headers['content-type']) : '';

        if (empty($html) || empty($contentType)) {
            return $html;
        }

        $encoding = null;
        // remove strange things
        $html = str_replace('</[>', '', $html);

        if (\is_array($contentType)) {
            $contentType = implode("\n", $contentType);
        }

        if (empty($contentType) || !preg_match_all('/([^;]+)(?:;\s*charset=["\']?([^;"\'\n]*))?/im', $contentType, $match, PREG_SET_ORDER)) {
            // error parsing the response
            $this->logger->log('debug', 'Could not find Content-Type header in HTTP response', ['headers' => $headers]);
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

        $encoding = strtolower(trim($encoding));

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
            $this->logger->log('debug', 'Converting to UTF-8', ['encoding' => $encoding]);

            return \SimplePie_Misc::change_encoding($html, $encoding, 'utf-8') ?: $html;
        }

        $this->logger->log('debug', 'Treating as UTF-8', ['encoding' => $encoding]);

        return $html;
    }

    /**
     * Try to cleanup XSS using htmLawed.
     *
     * @param string $html
     *
     * @return string
     */
    private function cleanupXss($html)
    {
        if (false === $this->config['xss_filter']) {
            return $html;
        }

        $this->logger->log('debug', 'Filtering HTML to remove XSS');

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
