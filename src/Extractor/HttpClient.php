<?php

namespace Graby\Extractor;

use Symfony\Component\OptionsResolver\OptionsResolver;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;

/**
 * HttpClient will make sure to retrieve the right content with the right url.
 *
 * @todo catch Guzzle exception
 */
class HttpClient
{
    private $config = array();
    private $client = null;
    private $logger = null;

    /**
     * @param Client               $client Guzzle client
     * @param array                $config
     * @param LoggerInterface|null $logger
     */
    public function __construct(Client $client, $config = array(), LoggerInterface $logger = null)
    {
        $this->client = $client;

        $resolver = new OptionsResolver();
        $resolver->setDefaults(array(
            'ua_browser' => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/535.2 (KHTML, like Gecko) Chrome/15.0.874.92 Safari/535.2',
            'default_referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
            'rewrite_url' => array(
                'docs.google.com' => array('/Doc?' => '/View?'),
                'tnr.com' => array('tnr.com/article/' => 'tnr.com/print/article/'),
                '.m.wikipedia.org' => array('.m.wikipedia.org' => '.wikipedia.org'),
                'm.vanityfair.com' => array('m.vanityfair.com' => 'www.vanityfair.com'),
            ),
            // Prevent certain file/mime types
            // HTTP responses which match these content types will
            // be returned without body.
            'header_only_types' => array(
                'application/pdf',
                'image',
                'audio',
                'video',
            ),
            // URLs ending with one of these extensions will
            // prompt Humble HTTP Agent to send a HEAD request first
            // to see if returned content type matches $headerOnlyTypes.
            'header_only_clues' => array('pdf', 'mp3', 'zip', 'exe', 'gif', 'gzip', 'gz', 'jpeg', 'jpg', 'mpg', 'mpeg', 'png', 'ppt', 'mov'),
            // User Agent strings - mapping domain names
            'user_agents' => array(
                'lifehacker.com' => 'PHP/5.2',
                'gawker.com' => 'PHP/5.2',
                'deadspin.com' => 'PHP/5.2',
                'kotaku.com' => 'PHP/5.2',
                'jezebel.com' => 'PHP/5.2',
                'io9.com' => 'PHP/5.2',
                'jalopnik.com' => 'PHP/5.2',
                'gizmodo.com' => 'PHP/5.2',
                '.wikipedia.org' => 'Mozilla/5.2',
                '.fok.nl' => 'Googlebot/2.1',
                'getpocket.com' => 'PHP/5.2',
            ),
            // AJAX triggers to search for.
            // for AJAX sites, e.g. Blogger with its dynamic views templates.
            'ajax_triggers' => array(
                "<meta name='fragment' content='!'",
                '<meta name="fragment" content="!"',
                "<meta content='!' name='fragment'",
                '<meta content="!" name="fragment"',
            ),
        ));

        $this->config = $resolver->resolve($config);

        $this->logger = $logger;
        if (null === $logger) {
            $this->logger = new NullLogger();
        }
    }

    /**
     * Grab informations from an url:
     *     - final url (after potential redirection)
     *     - raw content
     *     - content type header.
     *
     * @param string $url
     * @param bool   $skipTypeVerification Avoid mime detection which means, force GET instead of potential HEAD
     *
     * @return array With keys effective_url, body & headers
     */
    public function fetch($url, $skipTypeVerification = false)
    {
        // rewrite part of urls to something more readable
        foreach ($this->config['rewrite_url'] as $find => $action) {
            if (strpos($url, $find) !== false && is_array($action)) {
                $url = strtr($url, $action);
            }
        }

        // convert fragment to actual query parameters
        if ($fragmentPos = strpos($url, '#!')) {
            $fragment = parse_url($url, PHP_URL_FRAGMENT);
            // strip '!'
            $fragment = substr($fragment, 1);
            $query = array('_escaped_fragment_' => $fragment);

            // url without fragment
            $url = substr($url, 0, $fragmentPos);
            $url .= parse_url($url, PHP_URL_QUERY) ? '&' : '?';
            // needed for some sites
            $url .= str_replace('%2F', '/', http_build_query($query));
        }

        // remove fragment
        if ($pos = strpos($url, '#')) {
            $url = substr($url, 0, $pos);
        }

        $method = 'get';
        if (!$skipTypeVerification && !empty($this->config['header_only_types']) && $this->possibleUnsupportedType($url)) {
            $method = 'head';
        }

        try {
            $response = $this->client->$method(
                $url,
                array(
                    'headers' => array(
                        'User-Agent' => $this->getUserAgent($url),
                        // add referer for picky sites
                        'Referer' => $this->config['default_referer'],
                    ),
                    'cookies' => true,
                )
            );
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();

                return array(
                    'effective_url' => $response->getEffectiveUrl(),
                    'body' => '',
                    'headers' => (string) $response->getHeader('Content-Type'),
                    'status' => $response->getStatusCode(),
                );
            }
            return array(
                'effective_url' => $url,
                'body' => '',
                'headers' => '',
                'status' => 500,
            );
        }

        $effectiveUrl = $response->getEffectiveUrl();

        $contentType = (string) $response->getHeader('Content-Type');

        // the response content-type did not match our 'header only' types,
        // but we'd issues a HEAD request because we assumed it would. So
        // let's queue a proper GET request for this item...
        if ('head' === $method && !$this->headerOnlyType($contentType)) {
            return $this->fetch($effectiveUrl, true);
        }

        $body = (string) $response->getBody();

        // check for <meta name='fragment' content='!'/>
        // for AJAX sites, e.g. Blogger with its dynamic views templates.
        // Based on Google's spec: https://developers.google.com/webmasters/ajax-crawling/docs/specification
        if (strpos($effectiveUrl, '_escaped_fragment_') === false) {
            $html = substr($body, 0, 4000);

            $redirectURL = $this->getMetaRefreshURL($effectiveUrl, $html) ?: $this->getUglyURL($effectiveUrl, $html);

            if (false !== $redirectURL) {
                return $this->fetch($redirectURL, true);
            }
        }

        if ('gzip' == $response->getHeader('Content-Encoding')) {
            $body = gzdecode($body);
        }

        // remove utm parameters & fragment
        $effectiveUrl = preg_replace('/((\?)?(&(amp;)?)?utm_(.*?)\=[^&]+)|(#(.*?)\=[^&]+)/', '', urldecode($effectiveUrl));

        return array(
            'effective_url' => $effectiveUrl,
            'body' => $body,
            'headers' => $contentType,
            'status' => $response->getStatusCode(),
        );
    }

    /**
     * Try to determine if the url is a direct link to a binary resource
     * by checking the extension.
     *
     * @param string $url Absolute url
     *
     * @return string|false
     */
    private function possibleUnsupportedType($url)
    {
        $ext = strtolower(trim(pathinfo($url, PATHINFO_EXTENSION)));

        if (!$ext) {
            return false;
        }

        return in_array($ext, $this->config['header_only_clues']);
    }

    /**
     * Find a UserAgent for this url.
     * Based on the config, it will try to find a UserAgent from an host.
     * Otherwise it will use the default one.
     *
     * @param string $url Absolute url
     *
     * @return string
     */
    private function getUserAgent($url)
    {
        $ua = $this->config['ua_browser'];
        $host = parse_url($url, PHP_URL_HOST);

        if (strtolower(substr($host, 0, 4)) == 'www.') {
            $host = substr($host, 4);
        }

        if ($host) {
            $try = array($host);
            $split = explode('.', $host);

            if (count($split) > 1) {
                array_shift($split);
                $try[] = '.'.implode('.', $split);
            }

            foreach ($try as $h) {
                if (isset($this->config['user_agents'][$h])) {
                    $ua = $this->config['user_agents'][$h];
                    break;
                }
            }
        }

        return $ua;
    }

    /**
     * Look for full mime type (e.g. image/jpeg) or just type (e.g. image)
     * to determine if the request is a binary resource.
     *
     * Since the request is now done we directly check the Content-Type header
     *
     * @param string $contentType Content-Type from the request
     *
     * @return bool
     */
    private function headerOnlyType($contentType)
    {
        if (preg_match('!\s*(([-\w]+)/([-\w\+]+))!im', strtolower($contentType), $match)) {
            $match[1] = strtolower(trim($match[1]));
            $match[2] = strtolower(trim($match[2]));

            foreach (array($match[1], $match[2]) as $mime) {
                if (in_array($mime, $this->config['header_only_types'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Try to find the refresh url from the meta.
     *
     * @param string $url  Absolute url
     * @param string $html First characters of the response (hopefully it'll be enough to find some meta)
     *
     * @return false|string
     */
    private function getMetaRefreshURL($url, $html)
    {
        if ($html == '') {
            return false;
        }

        // <meta HTTP-EQUIV="REFRESH" content="0; url=http://www.bernama.com/bernama/v6/newsindex.php?id=943513">
        if (!preg_match('!<meta http-equiv=["\']?refresh["\']? content=["\']?[0-9];\s*url=["\']?([^"\'>]+)["\']?!i', $html, $match)) {
            return false;
        }

        $redirect_url = trim($match[1]);
        if (preg_match('!^https?://!i', $redirect_url)) {
            // already absolute
            $this->logger->log('debug', 'Meta refresh redirect found (http-equiv="refresh"), new URL: '.$redirect_url);

            return $redirect_url;
        }

        // absolutize redirect URL
        $base = new \SimplePie_IRI($url);
        // remove '//' in URL path (causes URLs not to resolve properly)
        if (isset($base->ipath)) {
            $base->ipath = str_replace('//', '/', $base->ipath);
        }

        if ($absolute = \SimplePie_IRI::absolutize($base, $redirect_url)) {
            $this->logger->log('debug', 'Meta refresh redirect found (http-equiv="refresh"), new URL: '.$absolute);

            return $absolute->get_iri();
        }

        return false;
    }

    /**
     * Some website (like Blogger) define an alternative url used by robots
     * so that they can crawl the website which is usually full JS.
     *
     * And adding `_escaped_fragment_` to the request will force the HTML version of the url instead of the full JS
     *
     * @param string $url  Absolute url
     * @param string $html First characters of the response (hopefully it'll be enough to find some meta)
     *
     * @return false|string
     */
    private function getUglyURL($url, $html)
    {
        if ($html == '') {
            return false;
        }

        $found = false;
        foreach ($this->config['ajax_triggers'] as $string) {
            if (stripos($html, $string)) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            return false;
        }

        $query = array('_escaped_fragment_' => '');

        // add fragment to url
        $url .= parse_url($url, PHP_URL_QUERY) ? '&' : '?';
        // needed for some sites
        $url .= str_replace('%2F', '/', http_build_query($query));

        return $url;
    }
}
