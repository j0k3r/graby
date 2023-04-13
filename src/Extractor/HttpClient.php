<?php

declare(strict_types=1);

namespace Graby\Extractor;

use Graby\HttpClient\EffectiveResponse;
use Graby\HttpClient\Plugin\History;
use Graby\HttpClient\Plugin\ServerSideRequestForgeryProtection\ServerSideRequestForgeryProtectionPlugin;
use GuzzleHttp\Psr7\UriResolver;
use Http\Client\Common\Exception\LoopException;
use Http\Client\Common\Plugin;
use Http\Client\Common\Plugin\ErrorPlugin;
use Http\Client\Common\Plugin\RedirectPlugin;
use Http\Client\Common\PluginClient;
use Http\Client\Exception\TransferException;
use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * HttpClient will make sure to retrieve the right content with the right url.
 */
class HttpClient
{
    private HttpClientConfig $config;
    private PluginClient $client;
    private LoggerInterface $logger;
    private RequestFactoryInterface $requestFactory;
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;
    private UriFactoryInterface $uriFactory;
    private History $responseHistory;
    private ?ContentExtractor $extractor;

    /**
     * @param ClientInterface $client Http client
     */
    public function __construct(ClientInterface $client, array $config = [], ?LoggerInterface $logger = null, ?ContentExtractor $extractor = null)
    {
        $this->config = new HttpClientConfig($config);

        if (null === $logger) {
            $logger = new NullLogger();
        }

        $this->logger = $logger;
        $this->extractor = $extractor;

        $this->requestFactory = Psr17FactoryDiscovery::findRequestFactory();
        $this->responseFactory = Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = Psr17FactoryDiscovery::findStreamFactory();

        $this->responseHistory = new History();
        $this->client = new PluginClient(
            $client,
            [
                new ServerSideRequestForgeryProtectionPlugin(),
                new RedirectPlugin(),
                new Plugin\HistoryPlugin($this->responseHistory),
                new ErrorPlugin(),
            ],
            [
                'max_restarts' => $this->config->getMaxRedirect(),
            ]
        );

        $this->uriFactory = Psr17FactoryDiscovery::findUriFactory();
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Grab informations from an url:
     *     - final url (after potential redirection)
     *     - raw content
     *     - content type header.
     *
     * @param bool                  $skipTypeVerification Avoid mime detection which means, force GET instead of potential HEAD
     * @param array<string, string> $httpHeader           Custom HTTP Headers from SiteConfig
     */
    public function fetch(UriInterface $url, bool $skipTypeVerification = false, array $httpHeader = []): EffectiveResponse
    {
        $url = $this->cleanupUrl($url);

        $method = 'get';
        if (!$skipTypeVerification && !empty($this->config->getHeaderOnlyTypes()) && $this->possibleUnsupportedType($url)) {
            $method = 'head';
        }

        $this->logger->info('Trying using method "{method}" on url "{url}"', ['method' => $method, 'url' => (string) $url]);

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
        if (null !== $accept) {
            $headers['Accept'] = $accept;
        }

        $request = $this->requestFactory->createRequest($method, $url);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            /** @var ResponseInterface $response */
            $response = $this->client->sendRequest($request);
        } catch (LoopException $e) {
            $this->logger->info('Endless redirect: ' . ($this->config->getMaxRedirect() + 1) . ' on "{url}"', ['url' => (string) $url]);

            return new EffectiveResponse(
                $url,
                // Too many Redirects
                $this->responseFactory->createResponse(310)
            );
        } catch (TransferException $e) {
            if (method_exists($e, 'getRequest')) {
                $url = $e->getRequest()->getUri();
            }

            // no response attached to the exception, we won't be able to retrieve content from it
            $data = new EffectiveResponse(
                $url,
                $this->responseFactory->createResponse(500)
            );
            $message = 'Request throw exception (with no response): {error_message}';

            if (method_exists($e, 'getResponse')) {
                // exception has a response which means we might be able to retrieve content from it, log it and continue
                $response = $e->getResponse();

                $data = new EffectiveResponse(
                    $url,
                    $response
                );
                $message = 'Request throw exception (with a response): {error_message}';
            }

            $this->logger->warning($message, ['error_message' => $e->getMessage()]);
            $this->logger->info('Data fetched: {data}', ['data' => $data]);

            return $data;
        }

        $effectiveUrl = $url;
        if (null !== $this->responseHistory->getLastRequest()) {
            $effectiveUrl = $this->responseHistory->getLastRequest()->getUri();
        }

        $refresh = $response->getHeaderLine('refresh');
        // if response give us a refresh header it means we need to follow the given url
        if (!empty($refresh) && 1 === preg_match('![0-9];\s*url=["\']?([^"\'>]+)!i', $refresh, $match)) {
            return $this->fetch($this->uriFactory->createUri($match[1]), true, $httpHeader);
        }

        // the response content-type did not match our 'header only' types,
        // but we'd issues a HEAD request because we assumed it would. So
        // let's queue a proper GET request for this item...
        if ('head' === $method && !$this->headerOnlyType($response)) {
            return $this->fetch($effectiveUrl, true, $httpHeader);
        }

        $body = (string) $response->getBody();

        // be sure to remove ALL other conditional comments for IE
        // (regex inspired from here: https://stackoverflow.com/a/55083809/954513)
        preg_match_all('/<!--(?:\[| ?<!).+?-->/mis', $body, $matchesConditional);

        if (isset($matchesConditional[0]) && (is_countable($matchesConditional[0]) ? \count($matchesConditional[0]) : 0) > 1) {
            foreach ($matchesConditional as $conditionalComment) {
                $body = str_replace($conditionalComment, '', $body);
            }
        }

        if (null !== $this->extractor) {
            $body = $this->extractor->processStringReplacements($body, $effectiveUrl);
        }

        // check for <meta name='fragment' content='!'/>
        // for AJAX sites, e.g. Blogger with its dynamic views templates.
        // Based on Google's spec: https://developers.google.com/webmasters/ajax-crawling/docs/specification
        if (!str_contains((string) $effectiveUrl, '_escaped_fragment_')) {
            $redirectURL = $this->getMetaRefreshURL($effectiveUrl, $body) ?? $this->getUglyURL($effectiveUrl, $body);

            if (null !== $redirectURL) {
                return $this->fetch($redirectURL, true, $httpHeader);
            }
        }

        // remove utm parameters & fragment
        $effectiveUrl = $this->removeTrackersFromUrl($this->uriFactory->createUri(str_replace('&amp;', '&', (string) $effectiveUrl)));

        $this->logger->info('Data fetched: {data}', ['data' => [
            'effective_url' => (string) $effectiveUrl,
            'body' => '(only length for debug): ' . \strlen($body),
            'headers' => $response->getHeaders(),
            'status' => $response->getStatusCode(),
        ]]);

        return new EffectiveResponse(
            $effectiveUrl,
            $response->withBody($this->streamFactory->createStream($body))
        );
    }

    /**
     * Cleanup URL and retrieve the final url to be called.
     */
    private function cleanupUrl(UriInterface $uri): UriInterface
    {
        $url = (string) $uri;

        // rewrite part of urls to something more readable
        foreach ($this->config->getRewriteUrl() as $find => $action) {
            if (str_contains($url, (string) $find)) {
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

        return $this->uriFactory->createUri($url);
    }

    /**
     * Try to determine if the url is a direct link to a binary resource
     * by checking the extension.
     */
    private function possibleUnsupportedType(UriInterface $url): bool
    {
        $ext = strtolower(trim(pathinfo($url->getPath(), \PATHINFO_EXTENSION)));

        if (!$ext) {
            return false;
        }

        return \in_array($ext, $this->config->getHeaderOnlyClues(), true);
    }

    /**
     * Find a UserAgent for this url.
     * Based on the config, it will try to find a UserAgent from an host.
     * Otherwise it will use the default one.
     *
     * @param array<string, string> $httpHeader Custom HTTP Headers from SiteConfig
     */
    private function getUserAgent(UriInterface $url, array $httpHeader = []): string
    {
        $ua = $this->config->getUaBrowser();

        if (!empty($httpHeader['user-agent'])) {
            $this->logger->info('Found user-agent "{user-agent}" for url "{url}" from site config', ['user-agent' => $httpHeader['user-agent'], 'url' => (string) $url]);

            return $httpHeader['user-agent'];
        }

        $host = $url->getHost();

        if ('www.' === strtolower(substr($host, 0, 4))) {
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
            if (isset($this->config->getUserAgents()[$h])) {
                $this->logger->info('Found user-agent "{user-agent}" for url "{url}" from config', ['user-agent' => $this->config->getUserAgents()[$h], 'url' => (string) $url]);

                return $this->config->getUserAgents()[$h];
            }
        }

        $this->logger->info('Use default user-agent "{user-agent}" for url "{url}"', ['user-agent' => $ua, 'url' => (string) $url]);

        return $ua;
    }

    /**
     * Find a Referer for this url.
     * Based on the site config, it will return the Referer if any.
     * Otherwise it will use the default one.
     *
     * @param array<string, string> $httpHeader Custom HTTP Headers from SiteConfig
     */
    private function getReferer(UriInterface $url, array $httpHeader = []): string
    {
        $default_referer = $this->config->getDefaultReferer();

        if (!empty($httpHeader['referer'])) {
            $this->logger->info('Found referer "{referer}" for url "{url}" from site config', ['referer' => $httpHeader['referer'], 'url' => (string) $url]);

            return $httpHeader['referer'];
        }

        $this->logger->info('Use default referer "{referer}" for url "{url}"', ['referer' => $default_referer, 'url' => (string) $url]);

        return $default_referer;
    }

    /**
     * Find a cookie for this url.
     *
     * Based on the site config, it will return a string that can
     * be passed to Cookie request header, if any.
     *
     * @param array<string, string> $httpHeader Custom HTTP Headers from SiteConfig
     */
    private function getCookie(UriInterface $url, array $httpHeader = []): ?string
    {
        if (!empty($httpHeader['cookie'])) {
            $this->logger->info('Found cookie "{cookie}" for url "{url}" from site config', ['cookie' => $httpHeader['cookie'], 'url' => (string) $url]);

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
            return implode('; ', array_map(fn ($name) => $name . '=' . $cookies[$name], array_keys($cookies)));
        }

        return null;
    }

    /**
     * Find an accept header for this url.
     * Based on the site config, it will return the accept if any.
     * Otherwise it will return null.
     *
     * @param array<string, string> $httpHeader Custom HTTP Headers from SiteConfig
     */
    private function getAccept(UriInterface $url, array $httpHeader = []): ?string
    {
        if (!empty($httpHeader['accept'])) {
            $this->logger->info('Found accept header "{accept}" for url "{url}" from site config', ['accept' => $httpHeader['accept'], 'url' => (string) $url]);

            return $httpHeader['accept'];
        }

        return null;
    }

    /**
     * Look for full mime type (e.g. image/jpeg) or just type (e.g. image)
     * to determine if the request is a binary resource.
     *
     * Since the request is now done we directly check the Content-Type header
     */
    private function headerOnlyType(ResponseInterface $response): bool
    {
        $contentType = $response->getHeaderLine('content-type');

        if (!preg_match('!\s*(([-\w]+)/([-\w\+]+))!im', strtolower($contentType), $match)) {
            return false;
        }

        $match[1] = strtolower(trim($match[1]));
        $match[2] = strtolower(trim($match[2]));

        foreach ([$match[1], $match[2]] as $mime) {
            if (\in_array($mime, $this->config->getHeaderOnlyTypes(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Try to find the refresh url from the meta.
     *
     * @param string $html First characters of the response (hopefully it'll be enough to find some meta)
     */
    private function getMetaRefreshURL(UriInterface $url, string $html): ?UriInterface
    {
        if ('' === $html) {
            return null;
        }

        // <meta HTTP-EQUIV="REFRESH" content="0; url=http://www.bernama.com/bernama/v6/newsindex.php?id=943513">
        if (!preg_match('!<meta http-equiv=["\']?refresh["\']? content=["\']?[0-9];\s*url=["\']?([^"\'>]+)["\']?!i', $html, $match)) {
            // let's try in a reverse mode (switch content & http-equiv attributes)
            if (!preg_match('!<meta content=["\']?[0-9];\s*url=["\']?([^"\'>]+)["\']? http-equiv=["\']?refresh["\']?!i', $html, $match)) {
                return null;
            }
        }

        $redirectUrl = str_replace('&amp;', '&', trim($match[1]));
        if (preg_match('!^https?://!i', $redirectUrl)) {
            // already absolute
            $this->logger->info('Meta refresh redirect found (http-equiv="refresh"), new URL: ' . $redirectUrl);

            return $this->uriFactory->createUri($redirectUrl);
        }

        return UriResolver::resolve($url, $this->uriFactory->createUri($redirectUrl));
    }

    /**
     * Some website (like Blogger) define an alternative url used by robots
     * so that they can crawl the website which is usually full JS.
     *
     * And adding `_escaped_fragment_` to the request will force the HTML version of the url instead of the full JS
     *
     * @param string $html First characters of the response (hopefully it'll be enough to find some meta)
     */
    private function getUglyURL(UriInterface $url, string $html): ?UriInterface
    {
        $found = false;
        foreach ($this->config->getAjaxTriggers() as $string) {
            if (stripos($html, $string)) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            return null;
        }

        $this->logger->info('Added escaped fragment to url');

        $query = ['_escaped_fragment_' => ''];

        // add fragment to url
        $qs = $url->getQuery();
        if ('' !== $qs) {
            $qs .= '&';
        }
        // needed for some sites
        $qs .= str_replace('%2F', '/', http_build_query($query));

        return $url->withQuery($qs);
    }

    /**
     * Remove trackers from url.
     *
     * @author Jean Baptiste Favre
     *
     * @see https://github.com/fossar/selfoss/blob/0d7bde56e502f7d79bfb38dcdd657c7da89cf1f1/src/spouts/rss/fulltextrss.php#L120
     */
    private function removeTrackersFromUrl(UriInterface $uri): UriInterface
    {
        // Query string
        $query = $uri->getQuery();
        if ('' !== $query) {
            $q_array = explode('&', $query);
            // Remove utm_* parameters
            $clean_query = array_filter(
                $q_array,
                fn (string $param): bool => !str_starts_with($param, 'utm_')
            );
            $uri = $uri->withQuery(implode('&', $clean_query));
        }
        // Fragment
        $fragment = $uri->getFragment();
        if ('' !== $fragment) {
            // Remove xtor=RSS anchor
            if (str_contains($fragment, 'xtor=RSS')) {
                $uri = $uri->withFragment('');
            }
        }

        return $uri;
    }
}
