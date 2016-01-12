<?php

namespace Graby;

use Graby\SiteConfig\ConfigBuilder;
use Symfony\Component\OptionsResolver\OptionsResolver;
use GuzzleHttp\Client;
use Readability\Readability;
use Graby\Extractor\ContentExtractor;
use Graby\Extractor\HttpClient;
use Graby\Ring\Client\SafeCurlHandler;
use ForceUTF8\Encoding;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * @todo add proxy
 * @todo add cache
 */
class Graby
{
    private $debug = false;
    private $logger;

    private $config = array();

    private $httpClient = null;
    private $extractor = null;

    /** @var \Graby\SiteConfig\ConfigBuilder */
    private $configBuilder;

    /**
     * @param array                           $config
     * @param Client|null                     $client        Guzzle client
     * @param \Graby\SiteConfig\ConfigBuilder $configBuilder
     */
    public function __construct($config = array(), Client $client = null, ConfigBuilder $configBuilder = null)
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults(array(
            'debug' => false,
            'rewrite_relative_urls' => true,
            'singlepage' => true,
            'multipage' => true,
            'error_message' => '[unable to retrieve full-text content]',
            'allowed_urls' => array(),
            'blocked_urls' => array(),
            'xss_filter' => true,
            'content_type_exc' => array(
                'application/pdf' => array('action' => 'link', 'name' => 'PDF'),
                'image' => array('action' => 'link', 'name' => 'Image'),
                'audio' => array('action' => 'link', 'name' => 'Audio'),
                'video' => array('action' => 'link', 'name' => 'Video'),
                'text/plain' => array('action' => 'link', 'name' => 'Plain text'),
            ),
            'content_links' => 'preserve',
            'http_client' => array(),
            'extractor' => array(),
        ));

        // @TODO: add more validation ? (setAllowedTypes)
        $resolver->setAllowedValues('content_links', array('preserve', 'footnotes', 'remove'));

        $this->config = $resolver->resolve($config);

        $this->debug = (bool) $this->config['debug'];
        $this->logger = new NullLogger();

        if ($this->debug) {
            $this->logger = new Logger('graby');
            $this->logger->pushHandler(new StreamHandler(dirname(__FILE__).'/../log/graby.log'));
        }

        $this->extractor = new ContentExtractor(
            $this->config['extractor'],
            $this->logger
        );

        $this->httpClient = new HttpClient(
            $client ?: new Client(array('handler' => new SafeCurlHandler(), 'defaults' => array('cookies' => true))),
            $this->config['http_client'],
            $this->logger
        );

        if ($configBuilder === null) {
            $configBuilder = new ConfigBuilder(
                isset($this->config['extractor']['config_builder']) ? $this->config['extractor']['config_builder'] : [],
                $this->logger
            );
        }
        $this->configBuilder = $configBuilder;
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

        $html = $infos['html'];

        // filter xss?
        if ($this->config['xss_filter']) {
            $this->logger->log('debug', 'Filtering HTML to remove XSS');
            $html = htmLawed($html, array(
                'safe' => 1,
                'deny_attribute' => 'style',
                'comment' => 1,
                'cdata' => 1,
            ));
        }

        // generate summary
        $infos['summary'] = $this->getExcerpt($html);

        return $infos;
    }

    /**
     * Do fetch content from an url.
     *
     * @param string $url
     *
     * @return array With key html, url & title
     */
    private function doFetchContent($url)
    {
        // Check for feed URL
        $url = trim($url);
        if (strtolower(substr($url, 0, 7)) == 'feed://') {
            $url = 'http://'.substr($url, 7);
        }

        if (!preg_match('!^https?://.+!i', $url)) {
            $url = 'http://'.$url;
        }

        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \Exception(sprintf('Url "%s" is not valid.', $url));
        }

        $url = filter_var($url, FILTER_SANITIZE_URL);

        if (false === $this->isUrlAllowed($url)) {
            throw new \Exception(sprintf('Url "%s" is not allowed to be parsed.', $url));
        }

        $this->logger->log('debug', 'Fetching url: {url}', array('url' => $url));

        $response = $this->httpClient->fetch($url);

        $effectiveUrl = $response['effective_url'];
        if (!$this->isUrlAllowed($effectiveUrl)) {
            throw new \Exception(sprintf('Url "%s" is not allowed to be parsed.', $effectiveUrl));
        }

        // check if action defined for returned Content-Type, like image, pdf, audio or video
        $mimeInfo = $this->getMimeActionInfo($response['headers']);
        $infos = $this->handleMimeAction($mimeInfo, $effectiveUrl, $response['body']);
        if (is_array($infos)) {
            return $infos;
        }

        $html = Encoding::toUTF8($response['body']);

        $ogData = $this->extractOpenGraph($html);

        $this->logger->log('debug', 'Opengraph data: {ogData}', array('ogData' => $ogData));

        // @TODO: log raw html + headers

        // check site config for single page URL - fetch it if found
        $is_single_page = false;
        if ($this->config['singlepage'] && ($singlePageResponse = $this->getSinglePage($html, $effectiveUrl))) {
            $is_single_page = true;
            $effectiveUrl = $singlePageResponse['effective_url'];

            // check if action defined for returned Content-Type
            $mimeInfo = $this->getMimeActionInfo($singlePageResponse['headers']);
            $infos = $this->handleMimeAction($mimeInfo, $effectiveUrl, $singlePageResponse['body']);
            if (is_array($infos)) {
                return $infos;
            }

            $html = Encoding::toUTF8($singlePageResponse['body']);
            $this->logger->log('debug', 'Retrieved single-page view from "{url}"', array('url' => $effectiveUrl));

            unset($singlePageResponse);
        }

        $this->logger->log('debug', 'Attempting to extract content');
        $extract_result = $this->extractor->process($html, $effectiveUrl);
        $readability = $this->extractor->readability;

        $contentBlock = $this->extractor->getContent();
        $extractedTitle = $this->extractor->getTitle();
        $extractedLanguage = $this->extractor->getLanguage();

        // Deal with multi-page articles
        $isMultiPage = (!$is_single_page && $extract_result && null !== $this->extractor->getNextPageUrl());
        if ($this->config['multipage'] && $isMultiPage) {
            $this->logger->log('debug', 'Attempting to process multi-page article');
            // store first page to avoid parsing it again (previous url content is in `$contentBlock`)
            $multiPageUrls = array($effectiveUrl);
            $multiPageContent = array();

            while ($nextPageUrl = $this->extractor->getNextPageUrl()) {
                $this->logger->log('debug', 'Processing next page: {url}', array('url' => $nextPageUrl));
                // If we've got URL, resolve against $url
                $nextPageUrl = $this->makeAbsoluteStr($effectiveUrl, $nextPageUrl);
                if (!$nextPageUrl) {
                    $this->logger->log('debug', 'Failed to resolve against: {url}', array('url' => $effectiveUrl));
                    $multiPageContent = array();
                    break;
                }

                // check it's not what we have already!
                if (in_array($nextPageUrl, $multiPageUrls)) {
                    $this->logger->log('debug', 'URL already processed');
                    $multiPageContent = array();
                    break;
                }

                // it's not, store it for later check & so let's attempt to fetch it
                $multiPageUrls[] = $nextPageUrl;

                $response = $this->httpClient->fetch($nextPageUrl);

                // make sure mime type is not something with a different action associated
                $mimeInfo = $this->getMimeActionInfo($response['headers']);

                if (isset($mimeInfo['action'])) {
                    $this->logger->log('debug', 'MIME type requires different action');
                    $multiPageContent = array();
                    break;
                }

                $extracSuccess = $this->extractor->process(
                    Encoding::toUTF8($response['body']),
                    $nextPageUrl
                );

                if (!$extracSuccess) {
                    $this->logger->log('debug', 'Failed to extract content');
                    $multiPageContent = array();
                    break;
                }

                $multiPageContent[] = $this->extractor->getContent();
            }

            // did we successfully deal with this multi-page article?
            if (empty($multiPageContent)) {
                $this->logger->log('debug', 'Failed to extract all parts of multi-page article, so not going to include them');
                $_page = $readability->dom->createElement('p');
                $_page->innerHTML = '<em>This article appears to continue on subsequent pages which we could not extract</em>';
                $multiPageContent[] = $_page;
            }

            foreach ($multiPageContent as $_page) {
                $_page = $contentBlock->ownerDocument->importNode($_page, true);
                $contentBlock->appendChild($_page);
            }

            unset($multiPageUrls, $multiPageContent, $nextPageUrl, $_page);
        }

        // if we failed to extract content...
        if (!$extract_result || null === $contentBlock) {
            return array(
                'status' => $response['status'],
                'html' => $this->config['error_message'],
                'title' => $extractedTitle,
                'language' => $extractedLanguage,
                'url' => $effectiveUrl,
                'content_type' => isset($mimeInfo['mime']) ? $mimeInfo['mime'] : '',
                'open_graph' => $ogData,
            );
        }

        $readability->clean($contentBlock, 'select');

        if ($this->config['rewrite_relative_urls']) {
            $this->makeAbsolute($effectiveUrl, $contentBlock);
        }

        // footnotes
        if ($this->config['content_links'] == 'footnotes' && strpos($effectiveUrl, 'wikipedia.org') === false) {
            $readability->addFootnotes($contentBlock);
        }

        // normalise
        $contentBlock->normalize();
        // remove empty text nodes
        foreach ($contentBlock->childNodes as $_n) {
            if ($_n->nodeType === XML_TEXT_NODE && trim($_n->textContent) == '') {
                $contentBlock->removeChild($_n);
            }
        }

        // remove nesting: <div><div><div><p>test</p></div></div></div> = <p>test</p>
        while ($contentBlock->childNodes->length == 1 && $contentBlock->firstChild->nodeType === XML_ELEMENT_NODE) {
            // only follow these tag names
            if (!in_array(strtolower($contentBlock->tagName), array('div', 'article', 'section', 'header', 'footer'))) {
                break;
            }

            $contentBlock = $contentBlock->firstChild;
        }

        // convert content block to HTML string
        // Need to preserve things like body: //img[@id='feature']
        if (in_array(strtolower($contentBlock->tagName), array('div', 'article', 'section', 'header', 'footer', 'li', 'td'))) {
            $html = $contentBlock->innerHTML;
        } else {
            $html = $contentBlock->ownerDocument->saveXML($contentBlock); // essentially outerHTML
        }

        unset($contentBlock);

        // post-processing cleanup
        $html = preg_replace('!<p>[\s\h\v]*</p>!u', '', $html);
        if ($this->config['content_links'] == 'remove') {
            $html = preg_replace('!</?a[^>]*>!', '', $html);
        }

        $this->logger->log('debug', 'Returning data (most interesting ones): {data}', array('data' => array(
            'title' => $extractedTitle,
            'language' => $extractedLanguage,
            'url' => $effectiveUrl,
            'content_type' => $mimeInfo['mime'],
        )));

        return array(
            'status' => $response['status'],
            'html' => $html,
            'title' => $extractedTitle,
            'language' => $extractedLanguage,
            'url' => $effectiveUrl,
            'content_type' => $mimeInfo['mime'],
            'open_graph' => $ogData,
        );
    }

    private function isUrlAllowed($url)
    {
        $allowedUrls = $this->getConfig('allowed_urls');
        $blockedUrls = $this->getConfig('blocked_urls');

        if (!empty($allowedUrls)) {
            foreach ($allowedUrls as $allowurl) {
                if (stristr($url, $allowurl) !== false) {
                    return true;
                }
            }
        } else {
            foreach ($blockedUrls as $blockurl) {
                if (stristr($url, $blockurl) !== false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Based on content-type http header, decide what to do.
     *
     * @param string $headers Content-Type header content
     *
     * @return array With keys: 'mime', 'type', 'subtype', 'action', 'name'
     *               e.g. array('mime'=>'image/jpeg', 'type'=>'image', 'subtype'=>'jpeg', 'action'=>'link', 'name'=>'Image')
     */
    private function getMimeActionInfo($headers)
    {
        // check if action defined for returned Content-Type
        $info = array(
            'mime' => '',
        );

        if (preg_match('!\s*(([-\w]+)/([-\w\+]+))!im', strtolower($headers), $match)) {
            // look for full mime type (e.g. image/jpeg) or just type (e.g. image)
            // match[1] = full mime type, e.g. image/jpeg
            // match[2] = first part, e.g. image
            // match[3] = last part, e.g. jpeg
            $info['mime'] = trim($match[1]);
            $info['type'] = trim($match[2]);
            $info['subtype'] = trim($match[3]);

            foreach (array($info['mime'], $info['type']) as $_mime) {
                if (isset($this->config['content_type_exc'][$_mime])) {
                    $info['action'] = $this->config['content_type_exc'][$_mime]['action'];
                    $info['name'] = $this->config['content_type_exc'][$_mime]['name'];

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
     * @param string $body         Content from the response
     *
     * @return array|null
     */
    private function handleMimeAction($mimeInfo, $effectiveUrl, $body = '')
    {
        if (!isset($mimeInfo['action'])) {
            return;
        }

        $infos = array(
            // at this point status will always be considered as 200
            'status' => 200,
            'title' => $mimeInfo['name'],
            'language' => '',
            'html' => '',
            'url' => $effectiveUrl,
            'content_type' => $mimeInfo['mime'],
            'open_graph' => array(),
        );

        switch ($mimeInfo['action']) {
            case 'exclude':
                throw new \Exception(sprintf('This is url "%s" is blocked by mime action.', $effectiveUrl));

            case 'link':
                $infos['html'] = '<a href="'.$effectiveUrl.'">Download '.$mimeInfo['name'].'</a>';

                if ($mimeInfo['type'] == 'image') {
                    $infos['html'] = '<a href="'.$effectiveUrl.'"><img src="'.$effectiveUrl.'" alt="'.$mimeInfo['name'].'" /></a>';
                }

                if ($mimeInfo['mime'] == 'application/pdf') {
                    $parser = new PdfParser();
                    $pdf = $parser->parseFile($effectiveUrl);

                    $html = Encoding::toUTF8(nl2br($pdf->getText()));

                    // strip away unwanted chars (that usualy came from PDF extracted content)
                    // @see http://www.phpwact.org/php/i18n/charsets#common_problem_areas_with_utf-8
                    $html = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $html);

                    $infos['html'] = $html;

                    // update title in case of details are present
                    $details = $pdf->getDetails();

                    // Title can be a string or an array with one key
                    if (isset($details['Title'])) {
                        if (is_array($details['Title']) && isset($details['Title'][0]) && '' !== trim($details['Title'][0])) {
                            $infos['title'] = $details['Title'][0];
                        } elseif (is_string($details['Title']) && '' !== trim($details['Title'])) {
                            $infos['title'] = $details['Title'];
                        }
                    }
                }

                if ($mimeInfo['mime'] == 'text/plain') {
                    $infos['html'] = '<pre>'.$body.'</pre>';
                }

                return $infos;
        }

        return;
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
        $site_config = $this->configBuilder->buildFromUrl($url);

        // no single page found?
        if (empty($site_config->single_page_link)) {
            return false;
        }

        // Build DOM tree from HTML
        $readability = new Readability($html, $url);
        $xpath = new \DOMXPath($readability->dom);

        // Loop through single_page_link xpath expressions
        $singlePageUrl = null;

        foreach ($site_config->single_page_link as $pattern) {
            $elems = $xpath->evaluate($pattern, $readability->dom);

            if (is_string($elems)) {
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
            return false;
        }

        // try to resolve against $url
        $singlePageUrl = $this->makeAbsoluteStr($url, $singlePageUrl);

        // check it's not what we have already!
        if (false !== $singlePageUrl && $singlePageUrl != $url) {
            // it's not, so let's try to fetch it...
            return $this->httpClient->fetch($singlePageUrl);
        }

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

        foreach (array('a' => 'href', 'img' => 'src', 'iframe' => 'src') as $tag => $attr) {
            $elems = $elem->getElementsByTagName($tag);

            for ($i = $elems->length - 1; $i >= 0; --$i) {
                $e = $elems->item($i);
                $this->makeAbsoluteAttr($base, $e, $attr);
            }

            if (strtolower($elem->nodeName) == $tag) {
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

        if (!preg_match('!https?://!i', $url)) {
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

    // Adapted from WordPress
    // http://core.trac.wordpress.org/browser/tags/3.5.1/wp-includes/formatting.php#L2173
    private function getExcerpt($text, $numWords = 55, $more = null)
    {
        if (null === $more) {
            $more = ' &hellip;';
        }

        // use regex instead of strip_tags to left some spaces when removing tags
        $text = preg_replace('#<[^>]+>#', ' ', $text);

        // @todo: Check if word count is based on single characters (East Asian characters)
        /*
        if (1==2) {
            $text = trim(preg_replace("/[\n\r\t ]+/", ' ', $text), ' ');
            preg_match_all('/./u', $text, $wordsArray);
            $wordsArray = array_slice($wordsArray[0], 0, $numWords + 1);
            $sep = '';
        } else {
            $wordsArray = preg_split("/[\n\r\t ]+/", $text, $numWords + 1, PREG_SPLIT_NO_EMPTY);
            $sep = ' ';
        }
        */
        $wordsArray = preg_split("/[\n\r\t ]+/", $text, $numWords + 1, PREG_SPLIT_NO_EMPTY);
        $sep = ' ';

        if (count($wordsArray) > $numWords) {
            array_pop($wordsArray);
            $text = implode($sep, $wordsArray);
            $text = $text.$more;
        } else {
            $text = implode($sep, $wordsArray);
        }

        // trim whitespace at beginning or end of string
        // See: http://stackoverflow.com/questions/4166896/trim-unicode-whitespace-in-php-5-2
        $text = preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $text);

        return $text;
    }

    /**
     * Extract OpenGraph data from the response.
     *
     * @param string $html
     *
     * @return array
     *
     * @see  http://stackoverflow.com/a/7454737/569101
     */
    private function extractOpenGraph($html)
    {
        if ('' === trim($html)) {
            return array();
        }

        libxml_use_internal_errors(true);

        $doc = new \DomDocument();
        $doc->loadHTML($html);

        libxml_use_internal_errors(false);

        $xpath = new \DOMXPath($doc);
        $query = '//*/meta[starts-with(@property, \'og:\')]';
        $metas = $xpath->query($query);

        $rmetas = array();
        foreach ($metas as $meta) {
            $rmetas[str_replace(':', '_', $meta->getAttribute('property'))] = $meta->getAttribute('content');
        }

        return $rmetas;
    }
}
