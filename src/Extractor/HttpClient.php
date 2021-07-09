<?php

namespace Graby\Extractor;

use Graby\HttpClient\Plugin\History;
use Graby\HttpClient\Plugin\ServerSideRequestForgeryProtection\ServerSideRequestForgeryProtectionPlugin;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Http\Client\Common\Exception\LoopException;
use Http\Client\Common\HttpMethodsClient;
use Http\Client\Common\Plugin;
use Http\Client\Common\Plugin\ErrorPlugin;
use Http\Client\Common\Plugin\RedirectPlugin;
use Http\Client\Common\PluginClient;
use Http\Client\Exception\TransferException;
use Http\Client\HttpClient as Client;
use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * HttpClient will make sure to retrieve the right content with the right url.
 */
class HttpClient
{
    /**
     * @var array
     */
    private $config;
    /**
     * @var HttpMethodsClient
     */
    private $client;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var History
     */
    private $responseHistory;

    /**
     * @param Client $client Http client
     * @param array  $config
     */
    public function __construct(Client $client, $config = [], LoggerInterface $logger = null)
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'ua_browser' => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/535.2 (KHTML, like Gecko) Chrome/15.0.874.92 Safari/535.2',
            'default_referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
            'rewrite_url' => [
                'docs.google.com' => ['/Doc?' => '/View?'],
                'tnr.com' => ['tnr.com/article/' => 'tnr.com/print/article/'],
                '.m.wikipedia.org' => ['.m.wikipedia.org' => '.wikipedia.org'],
                'm.vanityfair.com' => ['m.vanityfair.com' => 'www.vanityfair.com'],
            ],
            // Prevent certain file/mime types
            // HTTP responses which match these content types will
            // be returned without body.
            'header_only_types' => [
                'image',
                'audio',
                'video',
            ],
            // URLs ending with one of these extensions will
            // prompt client to send a HEAD request first
            // to see if returned content type matches $headerOnlyTypes.
            'header_only_clues' => ['mp3', 'zip', 'exe', 'gif', 'gzip', 'gz', 'jpeg', 'jpg', 'mpg', 'mpeg', 'png', 'ppt', 'mov'],
            // User Agent strings - mapping domain names
            'user_agents' => [],
            // AJAX triggers to search for.
            // for AJAX sites, e.g. Blogger with its dynamic views templates.
            'ajax_triggers' => [
                "<meta name='fragment' content='!'",
                '<meta name="fragment" content="!"',
                "<meta content='!' name='fragment'",
                '<meta content="!" name="fragment"',
            ],
            // number of redirection allowed until we assume request won't be complete
            'max_redirect' => 10,
        ]);

        $this->config = $resolver->resolve($config);

        if (null === $logger) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;

        $this->responseHistory = new History();
        $this->client = new HttpMethodsClient(
            new PluginClient(
                $client,
                [
                    new ServerSideRequestForgeryProtectionPlugin(),
                    new RedirectPlugin(),
                    new Plugin\HistoryPlugin($this->responseHistory),
                    new ErrorPlugin(),
                ],
                [
                    'max_restarts' => $this->config['max_redirect'],
                ]
            ),
            Psr17FactoryDiscovery::findRequestFactory()
        );
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Grab informations from an url:
     *     - final url (after potential redirection)
     *     - raw content
     *     - content type header.
     *
     * @param string $url
     * @param bool   $skipTypeVerification Avoid mime detection which means, force GET instead of potential HEAD
     * @param array  $httpHeader           Custom HTTP Headers from SiteConfig
     *
     * @return array With keys effective_url, body & headers
     */
    public function fetch($url, $skipTypeVerification = false, $httpHeader = [])
    {
        $url = $this->cleanupUrl($url);

        $method = 'get';
        if (!$skipTypeVerification && !empty($this->config['header_only_types']) && $this->possibleUnsupportedType($url)) {
            $method = 'head';
        }

        $this->logger->info('Trying using method "{method}" on url "{url}"', ['method' => $method, 'url' => $url]);

        $headers = [
            'User-Agent' => $this->getUserAgent($url, $httpHeader),
            // add referer for picky sites
            'Referer' => $this->getReferer($url, $httpHeader),
        ];

        // don't add an empty line with cookie if none are defined
        $cookie = $this->getCookie($url, $httpHeader);

        if ($cookie) {
            $headers['Cookie'] = $cookie;
        }

        $accept = $this->getAccept($url, $httpHeader);
        if ($accept) {
            $headers['Accept'] = $accept;
        }

        try {
            /** @var ResponseInterface $response */
            $response = $this->client->$method($url, $headers);
        } catch (LoopException $e) {
            $this->logger->info('Endless redirect: ' . ($this->config['max_redirect'] + 1) . ' on "{url}"', ['url' => $url]);

            return [
                'effective_url' => $url,
                'body' => '',
                'headers' => [],
                // Too many Redirects
                'status' => 310,
            ];
        } catch (TransferException $e) {
            if (method_exists($e, 'getRequest')) {
                $url = (string) $e->getRequest()->getUri();
            }

            // no response attached to the exception, we won't be able to retrieve content from it
            $data = [
                'effective_url' => $url,
                'body' => '',
                'headers' => [],
                'status' => 500,
            ];
            $message = 'Request throw exception (with no response): {error_message}';

            if (method_exists($e, 'getResponse')) {
                // exception has a response which means we might be able to retrieve content from it, log it and continue
                $response = $e->getResponse();
                $headers = $this->formatHeaders($response);

                $data = [
                    'effective_url' => $url,
                    'body' => (string) $response->getBody(),
                    'headers' => $headers,
                    'status' => $response->getStatusCode(),
                ];
                $message = 'Request throw exception (with a response): {error_message}';
            }

            $this->logger->warning($message, ['error_message' => $e->getMessage()]);
            $this->logger->info('Data fetched: {data}', ['data' => $data]);

            return $data;
        }

        $effectiveUrl = $url;
        if (null !== $this->responseHistory->getLastRequest()) {
            $effectiveUrl = (string) $this->responseHistory->getLastRequest()->getUri();
        }

        $headers = $this->formatHeaders($response);

        // if response give us a refresh header it means we need to follow the given url
        if (!empty($headers['refresh']) && 1 === preg_match('![0-9];\s*url=["\']?([^"\'>]+)!i', $headers['refresh'], $match)) {
            return $this->fetch($match[1], true, $httpHeader);
        }

        // the response content-type did not match our 'header only' types,
        // but we'd issues a HEAD request because we assumed it would. So
        // let's queue a proper GET request for this item...
        if ('head' === $method && !$this->headerOnlyType($headers)) {
            return $this->fetch($effectiveUrl, true, $httpHeader);
        }

        $body = (string) $response->getBody();

        // be sure to remove conditional comments for IE around the html tag
        // we only remove conditional comments until we found the <head> tag
        // they usually contains the <html> tag which we try to found and replace the last occurence
        // with the whole conditional comments
        preg_match('/^\<!--\[if(\X+)\<!\[endif\]--\>(\X+)\<head\>$/mi', $body, $matchesConditional);

        if (\count($matchesConditional) > 1) {
            preg_match_all('/\<html([\sa-z0-9\=\"\"\-:\/\.\#]+)\>$/mi', $matchesConditional[0], $matchesHtml);

            if (\count($matchesHtml) > 1) {
                $htmlTag = end($matchesHtml[0]);

                if (!empty($htmlTag)) {
                    $body = str_replace($matchesConditional[0], $htmlTag . '<head>', $body);
                }
            }
        }

        // be sure to remove ALL other conditional comments for IE
        // (regex found here: https://stackoverflow.com/a/137831/569101)
        preg_match_all('/<!--\[if\s(?:[^<]+|<(?!!\[endif\]-->))*<!\[endif\]-->/mi', $body, $matchesConditional);

        if (isset($matchesConditional[0]) && \count($matchesConditional[0]) > 1) {
            foreach ($matchesConditional as $conditionalComment) {
                $body = str_replace($conditionalComment, '', $body);
            }
        }

        // check for <meta name='fragment' content='!'/>
        // for AJAX sites, e.g. Blogger with its dynamic views templates.
        // Based on Google's spec: https://developers.google.com/webmasters/ajax-crawling/docs/specification
        if (false === strpos($effectiveUrl, '_escaped_fragment_')) {
            $redirectURL = $this->getMetaRefreshURL($effectiveUrl, $body) ?: $this->getUglyURL($effectiveUrl, $body);

            if (false !== $redirectURL) {
                return $this->fetch($redirectURL, true, $httpHeader);
            }
        }

        // remove utm parameters & fragment
        $uri = new Uri($effectiveUrl);
        parse_str($uri->getQuery(), $query);
        $queryParameters = array_filter($query, function ($k) {
            return !(0 === stripos($k, 'utm_'));
        }, \ARRAY_FILTER_USE_KEY);
        $effectiveUrl = (string) Uri::withQueryValues(new Uri($uri->withFragment('')->withQuery('')), $queryParameters);

        $this->logger->info('Data fetched: {data}', ['data' => [
            'effective_url' => $effectiveUrl,
            'body' => '(only length for debug): ' . \strlen($body),
            'headers' => $headers,
            'status' => $response->getStatusCode(),
        ]]);

        return [
            'effective_url' => $effectiveUrl,
            'body' => $body,
            'headers' => $headers,
            'status' => $response->getStatusCode(),
        ];
    }

    /**
     * Cleanup URL and retrieve the final url to be called.
     *
     * @param string $url
     *
     * @return string
     */
    private function cleanupUrl($url)
    {
        // rewrite part of urls to something more readable
        foreach ($this->config['rewrite_url'] as $find => $action) {
            if (false !== strpos($url, $find) && \is_array($action)) {
                $url = strtr($url, $action);
            }
        }

        // convert fragment to actual query parameters
        if ($fragmentPos = strpos($url, '#!')) {
            $fragment = parse_url($url, \PHP_URL_FRAGMENT);
            // strip '!'
            $fragment = substr((string) $fragment, 1);
            $query = ['_escaped_fragment_' => $fragment];

            // url without fragment
            $url = substr($url, 0, $fragmentPos);
            $url .= parse_url($url, \PHP_URL_QUERY) ? '&' : '?';
            // needed for some sites
            $url .= str_replace('%2F', '/', http_build_query($query));
        }

        // remove fragment
        if ($pos = strpos($url, '#')) {
            $url = substr($url, 0, $pos);
        }

        return $url;
    }

    /**
     * Try to determine if the url is a direct link to a binary resource
     * by checking the extension.
     *
     * @param string $url Absolute url
     *
     * @return bool
     */
    private function possibleUnsupportedType($url)
    {
        $ext = strtolower(trim(pathinfo($url, \PATHINFO_EXTENSION)));

        if (!$ext) {
            return false;
        }

        return \in_array($ext, $this->config['header_only_clues'], true);
    }

    /**
     * Find a UserAgent for this url.
     * Based on the config, it will try to find a UserAgent from an host.
     * Otherwise it will use the default one.
     *
     * @param string $url        Absolute url
     * @param array  $httpHeader Custom HTTP Headers from SiteConfig
     *
     * @return string
     */
    private function getUserAgent($url, array $httpHeader = [])
    {
        $ua = $this->config['ua_browser'];

        if (!empty($httpHeader['user-agent'])) {
            $this->logger->info('Found user-agent "{user-agent}" for url "{url}" from site config', ['user-agent' => $httpHeader['user-agent'], 'url' => $url]);

            return $httpHeader['user-agent'];
        }

        $host = parse_url($url, \PHP_URL_HOST);

        if ('www.' === strtolower(substr((string) $host, 0, 4))) {
            $host = substr((string) $host, 4);
        }

        $try = [$host];
        $split = explode('.', (string) $host);

        if (\count($split) > 1) {
            // remove first subdomain
            array_shift($split);
            $try[] = '.' . implode('.', $split);
        }

        foreach ($try as $h) {
            if (isset($this->config['user_agents'][$h])) {
                $this->logger->info('Found user-agent "{user-agent}" for url "{url}" from config', ['user-agent' => $this->config['user_agents'][$h], 'url' => $url]);

                return $this->config['user_agents'][$h];
            }
        }

        $this->logger->info('Use default user-agent "{user-agent}" for url "{url}"', ['user-agent' => $ua, 'url' => $url]);

        return $ua;
    }

    /**
     * Find a Referer for this url.
     * Based on the site config, it will return the Referer if any.
     * Otherwise it will use the default one.
     *
     * @param string $url        Absolute url
     * @param array  $httpHeader Custom HTTP Headers from SiteConfig
     *
     * @return string
     */
    private function getReferer($url, $httpHeader = [])
    {
        $default_referer = $this->config['default_referer'];

        if (!empty($httpHeader['referer'])) {
            $this->logger->info('Found referer "{referer}" for url "{url}" from site config', ['referer' => $httpHeader['referer'], 'url' => $url]);

            return $httpHeader['referer'];
        }

        $this->logger->info('Use default referer "{referer}" for url "{url}"', ['referer' => $default_referer, 'url' => $url]);

        return $default_referer;
    }

    /**
     * Find a cookie for this url.
     *
     * Based on the site config, it will return a string that can
     * be passed to Cookie request header, if any.
     *
     * @param string $url        Absolute url
     * @param array  $httpHeader Custom HTTP Headers from SiteConfig
     *
     * @return string|null
     */
    private function getCookie($url, $httpHeader = [])
    {
        if (!empty($httpHeader['cookie'])) {
            $this->logger->info('Found cookie "{cookie}" for url "{url}" from site config', ['cookie' => $httpHeader['cookie'], 'url' => $url]);

            $cookies = [];
            $pieces = array_filter(array_map('trim', explode(';', $httpHeader['cookie'])));

            foreach ($pieces as $part) {
                $cookieParts = explode('=', $part, 2);
                $key = trim($cookieParts[0]);

                if (1 === \count($cookieParts)) {
                    // Can be a single value (e.g. secure, httpOnly)
                    $value = true;
                } else {
                    // Be sure to strip wrapping quotes
                    $value = trim($cookieParts[1], " \n\r\t\0\x0B\"");
                }

                $cookies[$key] = $value;
            }

            // see https://tools.ietf.org/html/rfc6265.html#section-4.2.1
            return implode('; ', array_map(function ($name) use ($cookies) {
                return $name . '=' . $cookies[$name];
            }, array_keys($cookies)));
        }

        return null;
    }

    /**
     * Find an accept header for this url.
     * Based on the site config, it will return the accept if any.
     * Otherwise it will return false.
     *
     * @param string $url        Absolute url
     * @param array  $httpHeader Custom HTTP Headers from SiteConfig
     *
     * @return string|false
     */
    private function getAccept($url, $httpHeader = [])
    {
        if (!empty($httpHeader['accept'])) {
            $this->logger->info('Found accept header "{accept}" for url "{url}" from site config', ['accept' => $httpHeader['accept'], 'url' => $url]);

            return $httpHeader['accept'];
        }

        return false;
    }

    /**
     * Look for full mime type (e.g. image/jpeg) or just type (e.g. image)
     * to determine if the request is a binary resource.
     *
     * Since the request is now done we directly check the Content-Type header
     *
     * @param array $headers All headers from the request
     *
     * @return bool
     */
    private function headerOnlyType(array $headers)
    {
        $contentType = isset($headers['content-type']) ? $headers['content-type'] : '';

        if (!preg_match('!\s*(([-\w]+)/([-\w\+]+))!im', strtolower($contentType), $match)) {
            return false;
        }

        $match[1] = strtolower(trim($match[1]));
        $match[2] = strtolower(trim($match[2]));

        foreach ([$match[1], $match[2]] as $mime) {
            if (\in_array($mime, $this->config['header_only_types'], true)) {
                return true;
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
        if ('' === $html) {
            return false;
        }

        // <meta HTTP-EQUIV="REFRESH" content="0; url=http://www.bernama.com/bernama/v6/newsindex.php?id=943513">
        if (!preg_match('!<meta http-equiv=["\']?refresh["\']? content=["\']?[0-9];\s*url=["\']?([^"\'>]+)["\']?!i', $html, $match)) {
            // let's try in a reverse mode (switch content & http-equiv attributes)
            if (!preg_match('!<meta content=["\']?[0-9];\s*url=["\']?([^"\'>]+)["\']? http-equiv=["\']?refresh["\']?!i', $html, $match)) {
                return false;
            }
        }

        $redirectUrl = str_replace('&amp;', '&', trim($match[1]));
        if (preg_match('!^https?://!i', $redirectUrl)) {
            // already absolute
            $this->logger->info('Meta refresh redirect found (http-equiv="refresh"), new URL: ' . $redirectUrl);

            return $redirectUrl;
        }

        return (string) UriResolver::resolve(new Uri($url), new Uri($redirectUrl));
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

        $this->logger->info('Added escaped fragment to url');

        $query = ['_escaped_fragment_' => ''];

        // add fragment to url
        $url .= parse_url($url, \PHP_URL_QUERY) ? '&' : '?';
        // needed for some sites
        $url .= str_replace('%2F', '/', http_build_query($query));

        return $url;
    }

    /**
     * Format all headers to avoid unecessary array level.
     * Also lower the header name.
     *
     * @return array
     */
    private function formatHeaders(ResponseInterface $response)
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $value) {
            // some Content-Type are urlencoded like: image%2Fjpeg
            $headers[strtolower($name)] = urldecode(implode(', ', $value));
        }

        return $headers;
    }
}
