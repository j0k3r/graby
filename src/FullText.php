<?php

namespace FullText;

use Symfony\Component\OptionsResolver\OptionsResolver;
use GuzzleHttp\Client;
use Readability\Readability;
use FullText\Extractor\ContentExtractor;
use FullText\Extractor\HttpClient;
use ForceUTF8\Encoding;

/**
 * @todo add proxy
 * @todo add cache
 * @todo add logger
 */
class FullText
{
    private $debug = false;

    private $config = array();

    private $httpClient = null;
    private $extractor = null;

    /**
     * @param Client $client Guzzle client
     * @param array  $config
     */
    public function __construct(Client $client, $config = array())
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
            ),
            'content_links' => 'preserve',
            'http_client' => array(),
            'extractor' => array(),
        ));

        // @TODO: add more validation ? (setAllowedTypes)
        $resolver->setAllowedValues('content_links', array('preserve', 'footnotes', 'remove'));

        $this->config = $resolver->resolve($config);

        $this->setDebug($this->getConfig('debug'));

        $this->extractor = new ContentExtractor($this->config['extractor'], $this->config['debug']);

        $this->httpClient = new HttpClient($client, $this->config['http_client'], $this->config['debug']);
    }

    public function setDebug($debug)
    {
        $this->debug = (bool) $debug;
    }

    public function getDebug()
    {
        return $this->debug;
    }

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
        $infos = $this->doFetchContent($url);

        $html = $infos['html'];

        // filter xss?
        if ($this->config['xss_filter']) {
            // debug('Filtering HTML to remove XSS');
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

        $url = filter_var($url, FILTER_SANITIZE_URL);
        $test = filter_var($url, FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED);

        if (false === $this->isUrlAllowed($url)) {
            throw new \Exception(sprintf('Url "%s" is not allowed to be parsed.', $url));
        }

        $response = $this->httpClient->fetch($url);

        $do_content_extraction = true;
        $extract_result = false;
        $text_sample = null;
        $permalink = $url;
        $extracted_title = '';

        $effective_url = $response['effective_url'];
        if (!$this->isUrlAllowed($effective_url)) {
            throw new \Exception(sprintf('Url "%s" is not allowed to be parsed.', $effective_url));
        }

        // check if action defined for returned Content-Type, like image, pdf, audio or video
        $mimeInfo = $this->getMimeActionInfo($response['headers']);
        $infos = $this->handleMimeAction($mimeInfo, $effective_url);
        if (is_array($infos)) {
            return $infos;
        }

        $html = Encoding::toUTF8($response['body']);

        // @TODO: log raw html + headers

        // check site config for single page URL - fetch it if found
        $is_single_page = false;
        if ($this->config['singlepage'] && ($single_page_response = $this->getSinglePage($html, $effective_url))) {
            $is_single_page = true;
            $effective_url = $single_page_response['effective_url'];

            // check if action defined for returned Content-Type
            $mimeInfo = $this->getMimeActionInfo($single_page_response['headers']);
            $infos = $this->handleMimeAction($mimeInfo, $effective_url);
            if (is_array($infos)) {
                return $infos;
            }

            $html = Encoding::toUTF8($single_page_response['body']);
            // debug("Retrieved single-page view from $effective_url");

            unset($single_page_response);
        }

        // debug('--------');
        // debug('Attempting to extract content');
        $extract_result = $this->extractor->process($html, $effective_url);
        $readability = $this->extractor->readability;

        // if user has asked to see parsed HTML, show it and exit.
        // @TODO: log parsed HTML
        // $readability->dom->saveXML($readability->dom->documentElement)

        $content_block = $this->extractor->getContent();
        $extracted_title = $this->extractor->getTitle();

        // Deal with multi-page articles
        //die('Next: '.$this->extractor->getNextPageUrl());
        $is_multi_page = (!$is_single_page && $extract_result && $this->extractor->getNextPageUrl());
        if ($this->config['multipage'] && $is_multi_page) {
            // debug('--------');
            // debug('Attempting to process multi-page article');
            $multi_page_urls = array();
            $multi_page_content = array();

            while ($next_page_url = $this->extractor->getNextPageUrl()) {
                // debug('--------');
                // debug('Processing next page: '.$next_page_url);
                // If we've got URL, resolve against $url
                $next_page_url = $this->makeAbsoluteStr($effective_url, $next_page_url);
                if (!$next_page_url) {
                    // debug('Failed to resolve against '.$effective_url);
                    $multi_page_content = array();
                    break;
                }

                // check it's not what we have already!
                if (in_array($next_page_url, $multi_page_urls)) {
                    // debug('URL already processed');
                    $multi_page_content = array();
                    break;
                }

                // it's not, so let's attempt to fetch it
                $multi_page_urls[] = $next_page_url;

                $response = $this->httpClient->fetch($url);

                // make sure mime type is not something with a different action associated
                $mimeInfo = $this->getMimeActionInfo($response['headers']);

                if (isset($mimeInfo['action'])) {
                    // debug('MIME type requires different action');
                    $multi_page_content = array();
                    break;
                }

                $extracSuccess = $this->extractor->process(
                    Encoding::toUTF8($response['body']),
                    $next_page_url
                );

                if (!$extracSuccess) {
                    // debug('Failed to extract content');
                    $multi_page_content = array();
                    break;
                }

                $multi_page_content[] = $this->extractor->getContent();
            }

            // did we successfully deal with this multi-page article?
            if (empty($multi_page_content)) {
                // debug('Failed to extract all parts of multi-page article, so not going to include them');
                $_page = $readability->dom->createElement('p');
                $_page->innerHTML = '<em>This article appears to continue on subsequent pages which we could not extract</em>';
                $multi_page_content[] = $_page;
            }

            foreach ($multi_page_content as $_page) {
                $_page = $content_block->ownerDocument->importNode($_page, true);
                $content_block->appendChild($_page);
            }

            unset($multi_page_urls, $multi_page_content, $page_mime_info, $next_page_url, $_page);
        }

        // if we failed to extract content...
        if (!$extract_result) {
            return array(
                'html' => $this->config['error_message'],
                'title' => $extracted_title,
                'url' => $effective_url,
            );
        }

        $readability->clean($content_block, 'select');

        if ($this->config['rewrite_relative_urls']) {
            $this->makeAbsolute($effective_url, $content_block);
        }

        // footnotes
        if (($this->config['content_links'] == 'footnotes') && (strpos($effective_url, 'wikipedia.org') === false)) {
            $readability->addFootnotes($content_block);
        }

        // normalise
        $content_block->normalize();
        // remove empty text nodes
        foreach ($content_block->childNodes as $_n) {
            if ($_n->nodeType === XML_TEXT_NODE && trim($_n->textContent) == '') {
                $content_block->removeChild($_n);
            }
        }

        // remove nesting: <div><div><div><p>test</p></div></div></div> = <p>test</p>
        while ($content_block->childNodes->length == 1 && $content_block->firstChild->nodeType === XML_ELEMENT_NODE) {
            // only follow these tag names
            if (!in_array(strtolower($content_block->tagName), array('div', 'article', 'section', 'header', 'footer'))) {
                break;
            }

            $content_block = $content_block->firstChild;
        }

        // convert content block to HTML string
        // Need to preserve things like body: //img[@id='feature']
        if (in_array(strtolower($content_block->tagName), array('div', 'article', 'section', 'header', 'footer', 'li', 'td'))) {
            $html = $content_block->innerHTML;
        //} elseif (in_array(strtolower($content_block->tagName), array('td', 'li'))) {
        //  $html = '<div>'.$content_block->innerHTML.'</div>';
        } else {
            $html = $content_block->ownerDocument->saveXML($content_block); // essentially outerHTML
        }

        unset($content_block);

        // post-processing cleanup
        $html = preg_replace('!<p>[\s\h\v]*</p>!u', '', $html);
        if ($this->config['content_links'] == 'remove') {
            $html = preg_replace('!</?a[^>]*>!', '', $html);
        }

        // debug('Done!');
        return array(
            'html' => $html,
            'title' => $extracted_title,
            'url' => $effective_url,
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
        $info = array();
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
     * @param array  $mimeInfo      From getMimeActionInfo() function
     * @param string $effective_url Current content url
     *
     * @return array|null
     */
    private function handleMimeAction($mimeInfo, $effective_url)
    {
        if (!isset($mimeInfo['action'])) {
            return;
        }

        switch ($mimeInfo['action']) {
            case 'exclude':
                throw new \Exception(sprintf('This is url "%s" is blocked by mime action.', $effective_url));

            case 'link':
                if ($mimeInfo['type'] == 'image') {
                    $html = '<a href="'.$effective_url.'"><img src="'.$effective_url.'" alt="'.$mimeInfo['name'].'" /></a>';
                } else {
                    $html = '<a href="'.$effective_url.'">Download '.$mimeInfo['name'].'</a>';
                }

                return array(
                    'title' => $mimeInfo['name'],
                    'html' => $html,
                    'url' => $effective_url,
                );
        }

        return;
    }

    /**
     * returns single page response, or false if not found.
     *
     * @param string $html
     * @param string $url
     *
     * @return string
     */
    private function getSinglePage($html, $url)
    {
        // debug('Looking for site config files to see if single page link exists');
        $site_config = $this->extractor->buildSiteConfig($url, $html);
        $splink = null;

        if (!empty($site_config->single_page_link)) {
            $splink = $site_config->single_page_link;
        } elseif (!empty($site_config->single_page_link_in_feed)) {
            // single page link xpath is targeted at feed
            $splink = $site_config->single_page_link_in_feed;
            // so let's replace HTML with feed item description
            // $html = $this->item->description;
            $html = '';
        }

        if (!isset($splink)) {
            return false;
        }

        // Build DOM tree from HTML
        $readability = new Readability($html, $url);
        $xpath = new \DOMXPath($readability->dom);
        // Loop through single_page_link xpath expressions
        $single_page_url = null;

        foreach ($splink as $pattern) {
            $elems = @$xpath->evaluate($pattern, $readability->dom);
            if (is_string($elems)) {
                $single_page_url = trim($elems);
                break;
            } elseif ($elems instanceof \DOMNodeList && $elems->length > 0) {
                foreach ($elems as $item) {
                    if ($item instanceof \DOMElement && $item->hasAttribute('href')) {
                        $single_page_url = $item->getAttribute('href');
                        break 2;
                    } elseif ($item instanceof \DOMAttr && $item->value) {
                        $single_page_url = $item->value;
                        break 2;
                    }
                }
            }
        }

        // If we've got URL, resolve against $url
        if (isset($single_page_url) && ($single_page_url = $this->makeAbsoluteStr($url, $single_page_url))) {
            // check it's not what we have already!
            if ($single_page_url != $url) {
                // it's not, so let's try to fetch it...
                $_prev_ref = $this->httpAgent->referer;
                $this->httpAgent->referer = $single_page_url;

                if (($response = $this->httpAgent->get($single_page_url, true)) && $response['status_code'] < 300) {
                    $this->httpAgent->referer = $_prev_ref;

                    return $response;
                }
                $this->httpAgent->referer = $_prev_ref;
            }
        }
    }

    private function makeAbsolute($base, $elem)
    {
        $base = new \SimplePie_IRI($base);
        // remove '//' in URL path (used to prevent URLs from resolving properly)
        if (isset($base->path)) {
            $base->path = str_replace('//', '/', $base->path);
        }

        foreach (array('a' => 'href', 'img' => 'src') as $tag => $attr) {
            $elems = $elem->getElementsByTagName($tag);

            for ($i = $elems->length - 1; $i >= 0; $i--) {
                $e = $elems->item($i);
                //$e->parentNode->replaceChild($articleContent->ownerDocument->createTextNode($e->textContent), $e);
                $this->makeAbsoluteAttr($base, $e, $attr);
            }

            if (strtolower($elem->tagName) == $tag) {
                $this->makeAbsoluteAttr($base, $elem, $attr);
            }
        }
    }

    private function makeAbsoluteAttr($base, $e, $attr)
    {
        if (!$e->hasAttribute($attr)) {
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

    private function makeAbsoluteStr($base, $url)
    {
        $base = new \SimplePie_IRI($base);

        // remove '//' in URL path (causes URLs not to resolve properly)
        if (isset($base->path)) {
            $base->path = preg_replace('!//+!', '/', $base->path);
        }

        if (preg_match('!^https?://!i', $url)) {
            // already absolute
            return $url;
        }

        if ($absolute = \SimplePie_IRI::absolutize($base, $url)) {
            return $absolute;
        }

        return false;
    }

    // Adapted from WordPress
    // http://core.trac.wordpress.org/browser/tags/3.5.1/wp-includes/formatting.php#L2173
    private function getExcerpt($text, $num_words = 55, $more = null)
    {
        if (null === $more) {
            $more = ' &hellip;';
        }

        $text = strip_tags($text);
        //TODO: Check if word count is based on single characters (East Asian characters)
        /*
        if (1==2) {
            $text = trim(preg_replace("/[\n\r\t ]+/", ' ', $text), ' ');
            preg_match_all('/./u', $text, $words_array);
            $words_array = array_slice($words_array[0], 0, $num_words + 1);
            $sep = '';
        } else {
            $words_array = preg_split("/[\n\r\t ]+/", $text, $num_words + 1, PREG_SPLIT_NO_EMPTY);
            $sep = ' ';
        }
        */
        $words_array = preg_split("/[\n\r\t ]+/", $text, $num_words + 1, PREG_SPLIT_NO_EMPTY);
        $sep = ' ';

        if (count($words_array) > $num_words) {
            array_pop($words_array);
            $text = implode($sep, $words_array);
            $text = $text.$more;
        } else {
            $text = implode($sep, $words_array);
        }

        // trim whitespace at beginning or end of string
        // See: http://stackoverflow.com/questions/4166896/trim-unicode-whitespace-in-php-5-2
        $text = preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $text);

        return $text;
    }
}
