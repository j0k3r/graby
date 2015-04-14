<?php

namespace FullText\Extractor;

use Symfony\Component\OptionsResolver\OptionsResolver;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class HttpClient
{
    private $debug = false;
    private $config = array();
    private $httpClient = null;

    /**
     * @param Client $client Guzzle client
     * @param array  $config
     */
    public function __construct(Client $client, $config = array(), $debug = false)
    {
        $this->httpClient = $client;

        $resolver = new OptionsResolver();
        $resolver->setDefaults(array(
            'ua_browser' => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/535.2 (KHTML, like Gecko) Chrome/15.0.874.92 Safari/535.2',
            'default_referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
            'rewrite_url' => array(
                'docs.google.com'  => array('/Doc?' => '/View?'),
                'tnr.com'          => array('tnr.com/article/' => 'tnr.com/print/article/'),
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
                'gawker.com'     => 'PHP/5.2',
                'deadspin.com'   => 'PHP/5.2',
                'kotaku.com'     => 'PHP/5.2',
                'jezebel.com'    => 'PHP/5.2',
                'io9.com'        => 'PHP/5.2',
                'jalopnik.com'   => 'PHP/5.2',
                'gizmodo.com'    => 'PHP/5.2',
                '.wikipedia.org' => 'Mozilla/5.2',
                '.fok.nl'        => 'Googlebot/2.1',
                'getpocket.com'  => 'PHP/5.2',
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
    }

    /**
     * Grab informations from an url:
     *     - final url (after potential redirection)
     *     - raw content
     *     - content type header.
     *
     * @param string $url
     *
     * @return array With keys effective_url, body & headers
     */
    public function fetch($url)
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
        if (!empty($this->config['header_only_types']) && $this->possibleUnsupportedType($url)) {
            $method = 'head';
        }

        $headers = array();
        $headers[] = $this->getUserAgent($url);
        // add referer for picky sites
        $headers[] = array('Referer' => $this->config['default_referer']);

        $response = $this->httpClient->$method(
            $url,
            array(
                'headers' => $headers,
            )
        );

        $body = (string) $response->getBody();
        $effectiveUrl = $response->getEffectiveUrl();

        if (!$this->headerOnlyType((string) $response->getHeader('Content-Type')) && 'head' === $method) {
            // the response content-type did not match our 'header only' types,
            // but we'd issues a HEAD request because we assumed it would. So
            // let's queue a proper GET request for this item...
            //
            // @TODO: re-queue the url as GET
        }

        if ('head' === $method && strpos($effectiveUrl, '_escaped_fragment_') === false) {
            $redirectURL = $this->getRedirectURLfromHTML($effectiveUrl, substr($body, 0, 4000));

            if ($redirectURL) {
                // @TODO: re-queue the url as GET
            }
        }

        if ('gzip' == $response->getHeader('Content-Encoding')) {
            $body = gzdecode($body);
        }

        // try {
        //     $response = $this->httpClient->get($url);
        //     $effectiveUrl = $response->getEffectiveUrl();
        // } catch (RequestException $e) {
        //     // catch timeout, ssl verification that failed, etc ...
        //     // so try an alternative using basic file_get_contents
        //     $content = @file_get_contents($url, false, stream_context_create(array(
        //         'http' => array('timeout' => 10),
        //     )));

        //     return array(
        //         'effective_url' => $url.(strpos($url, '?') ? '&' : '?').'not-changed',
        //         'body' => $content,
        //         'headers' => '',
        //     );
        // }

        // remove utm parameters & fragment
        $effectiveUrl = preg_replace('/((\?)?(&(amp;)?)?utm_(.*?)\=[^&]+)|(#(.*?)\=[^&]+)/', '', urldecode($effectiveUrl));

        return array(
            'effective_url' => $effectiveUrl,
            'body' => $body,
            'headers' => (string) $response->getHeader('Content-Type'),
        );
    }

    private function possibleUnsupportedType($url)
    {
        $path = parse_url($url, PHP_URL_PATH);

        if ($path && strpos($path, '.') !== false) {
            $ext = strtolower(trim(pathinfo($path, PATHINFO_EXTENSION)));

            return in_array($ext, $this->config['header_only_clues']);
        }

        return false;
    }

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

        return array('User-Agent' => $ua);
    }

    private function headerOnlyType($contentType)
    {
        if (preg_match('!\s*(([-\w]+)/([-\w\+]+))!im', strtolower($contentType), $match)) {
            // look for full mime type (e.g. image/jpeg) or just type (e.g. image)
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

    private function getRedirectURLfromHTML($url, $html)
    {
        $redirect_url = $this->getMetaRefreshURL($url, $html);

        if (!$redirect_url) {
            return $this->getUglyURL($url, $html);
        }

        return $redirect_url;
    }

    private function getMetaRefreshURL($url, $html)
    {
        if ($html == '') {
            return false;
        }

        // <meta HTTP-EQUIV="REFRESH" content="0; url=http://www.bernama.com/bernama/v6/newsindex.php?id=943513">
        if (!preg_match('!<meta http-equiv=["\']?refresh["\']? content=["\']?[0-9];\s*url=["\']?([^"\'>]+)["\']?!i', $html, $match)) {
            return false;
        }

        $redirect_url = $match[1];
        if (preg_match('!^https?://!i', $redirect_url)) {
            // already absolute
            // $this->debug('Meta refresh redirect found (http-equiv="refresh"), new URL: '.$redirect_url);
            return $redirect_url;
        }

        // absolutize redirect URL
        $base = new \SimplePie_IRI($url);
        // remove '//' in URL path (causes URLs not to resolve properly)
        if (isset($base->path)) {
            $base->path = str_replace('//', '/', $base->path);
        }

        if ($absolute = \SimplePie_IRI::absolutize($base, $redirect_url)) {
            // $this->debug('Meta refresh redirect found (http-equiv="refresh"), new URL: '.$absolute);
            return $absolute;
        }

        return false;
    }

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
