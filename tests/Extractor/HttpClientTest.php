<?php

declare(strict_types=1);

namespace Tests\Graby\Extractor;

use Graby\Extractor\HttpClient;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use Http\Mock\Client as HttpMockClient;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class HttpClientTest extends TestCase
{
    public function dataForFetchGet(): array
    {
        return [
            [
                'http://fr.m.wikipedia.org/wiki/Copyright#bottom',
                'http://fr.wikipedia.org/wiki/Copyright',
            ],
            [
                'http://bjori.blogspot.fr/2015/04/next-gen-mongodb-driver.html/#!test',
                'http://bjori.blogspot.fr/2015/04/next-gen-mongodb-driver.html/?_escaped_fragment_=test',
            ],
            [
                'http://www.example.com/my-map.html',
                'http://www.example.com/my-map.html',
            ],
        ];
    }

    /**
     * @dataProvider dataForFetchGet
     */
    public function testFetchGet(string $url, string $urlEffective): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, [], 'yay'));

        $http = new HttpClient($httpMockClient, ['user_agents' => ['.wikipedia.org' => 'Mozilla/5.2']]);
        $res = $http->fetch(new Uri($url));

        $this->assertSame($urlEffective, (string) $res['effective_url']);
        $this->assertSame('yay', $res['body']);
        $this->assertSame(200, $res['status']);
    }

    public function testFetchHeadGoodContentType(): void
    {
        $url = 'http://fr.wikipedia.org/wiki/Copyright.jpg';

        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'image/jpg'], 'yay'));

        $http = new HttpClient($httpMockClient, ['user_agents' => ['.wikipedia.org' => 'Mozilla/5.2']]);
        $res = $http->fetch(new Uri($url));

        $this->assertCount(1, $httpMockClient->getRequests());
        /** @var RequestInterface $request */
        $request = $httpMockClient->getRequests()[0];

        $this->assertSame('Mozilla/5.2', $request->getHeaderLine('User-Agent'));
        $this->assertSame('http://www.google.co.uk/url?sa=t&source=web&cd=1', $request->getHeaderLine('Referer'));
        $this->assertSame($url, (string) $res['effective_url']);
        $this->assertSame('yay', $res['body']);
        $this->assertSame('image/jpg', $res['headers']['content-type']);
        $this->assertSame(200, $res['status']);
    }

    public function testFetchHeadBadContentType(): void
    {
        $url = 'http://fr.wikipedia.org/wiki/Copyright.jpg';

        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'text/html'], 'yay'));
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'text/html'], 'yay'));

        $http = new HttpClient($httpMockClient, ['user_agents' => ['.wikipedia.org' => 'Mozilla/5.2']]);
        $res = $http->fetch(new Uri($url));

        $this->assertCount(2, $httpMockClient->getRequests());
        $this->assertSame('HEAD', $httpMockClient->getRequests()[0]->getMethod(), 'first request is head because of the extension');
        $this->assertSame('GET', $httpMockClient->getRequests()[1]->getMethod(), 'second request is get because the Content-Type wasn\'t a binary');

        $this->assertSame($url, (string) $res['effective_url']);
        $this->assertSame('yay', $res['body']);
        $this->assertSame('text/html', $res['headers']['content-type']);
        $this->assertSame(200, $res['status']);
    }

    public function testFetchHeadReallyBadContentType(): void
    {
        $url = 'http://fr.wikipedia.org/wiki/Copyright.jpg';

        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'fucked'], 'yay'));
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'fucked'], 'yay'));

        $http = new HttpClient($httpMockClient, ['user_agents' => ['.wikipedia.org' => 'Mozilla/5.2']]);
        $res = $http->fetch(new Uri($url));

        $this->assertCount(2, $httpMockClient->getRequests());
        $this->assertSame('HEAD', $httpMockClient->getRequests()[0]->getMethod(), 'first request should be HEAD because of the extension');
        $this->assertSame('GET', $httpMockClient->getRequests()[1]->getMethod(), 'second request is GET because the Content-Type wasn\'t a binary');

        $this->assertSame($url, (string) $res['effective_url']);
        $this->assertSame('yay', $res['body']);
        $this->assertSame('fucked', $res['headers']['content-type']);
        $this->assertSame(200, $res['status']);
    }

    public function dataForMetaRefresh(): array
    {
        return [
            [
                'http://fr.wikipedia.org/wiki/Copyright',
                '<html><meta HTTP-EQUIV="REFRESH" content="0; url=http://www.bernama.com/bernama/v6/newsindex.php?id=943513"></html>',
                'http://www.bernama.com/bernama/v6/newsindex.php?id=943513',
            ],
            [
                'https://www.google.com/url?sa=t&source=web&rct=j&url=https://databox.com/google-my-business-seo',
                '<html><meta content="0;url=https://databox.com/google-my-business-seo" http-equiv="refresh"></html>',
                'https://databox.com/google-my-business-seo',
            ],
            [
                'http://www.example.com/wiki/Copyright',
                '<html><meta HTTP-EQUIV="REFRESH" content="0; url=/bernama/v6/newsindex.php?id=943513"></html>',
                'http://www.example.com/bernama/v6/newsindex.php?id=943513',
            ],
            [
                'http://fr.wikipedia.org/wiki/Copyright',
                '<html><meta name="fragment" content="!"></html>',
                'http://fr.wikipedia.org/wiki/Copyright?_escaped_fragment_=',
            ],
        ];
    }

    /**
     * @dataProvider dataForMetaRefresh
     */
    public function testFetchGetWithMetaRefresh(string $url, string $body, string $metaUrl): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'text/html'], $body));
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'text/html'], ''));

        $http = new HttpClient($httpMockClient);
        $res = $http->fetch(new Uri($url));

        $this->assertCount(2, $httpMockClient->getRequests());
        $this->assertSame('GET', $httpMockClient->getRequests()[0]->getMethod());
        $this->assertSame($url, (string) $httpMockClient->getRequests()[0]->getUri());
        $this->assertSame('GET', $httpMockClient->getRequests()[1]->getMethod());
        $this->assertSame($metaUrl, (string) $httpMockClient->getRequests()[1]->getUri());

        $this->assertSame($metaUrl, (string) $res['effective_url']);
        $this->assertEmpty($res['body']);
        $this->assertSame('text/html', $res['headers']['content-type']);
        $this->assertSame(200, $res['status']);
    }

    public function testFetchGetWithHeaderRefresh(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'text/html', 'refresh' => '0; url=http://example.com/my-new-map.html'], ''));
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'text/html'], 'data'));

        $http = new HttpClient($httpMockClient);
        $res = $http->fetch(new Uri('http://example.com/my-map.html'));

        $this->assertSame('http://example.com/my-new-map.html', (string) $res['effective_url']);
        $this->assertSame('data', $res['body']);
        $this->assertSame('text/html', $res['headers']['content-type']);
        $this->assertSame(200, $res['status']);
    }

    public function testWith404ResponseWithResponse(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(404, ['Content-Type' => 'text/html'], 'test'));

        $http = new HttpClient($httpMockClient);
        $res = $http->fetch(new Uri('http://example.com/my-map.html'));

        $this->assertSame('http://example.com/my-map.html', (string) $res['effective_url']);
        $this->assertSame('test', $res['body']);
        $this->assertSame('text/html', $res['headers']['content-type']);
        $this->assertSame(404, $res['status']);
    }

    public function testWithUrlContainingPlusSymbol(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200));

        $http = new HttpClient($httpMockClient);
        $res = $http->fetch(new Uri('https://example.com/foo/+bar/baz/+quuz/corge'));

        $this->assertSame('https://example.com/foo/+bar/baz/+quuz/corge', (string) $res['effective_url']);
    }

    public function testWith404ResponseWithoutResponse(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(404));

        $http = new HttpClient($httpMockClient);
        $res = $http->fetch(new Uri('http://example.com'));

        $this->assertSame('http://example.com', (string) $res['effective_url']);
        $this->assertSame('', $res['body']);
        $this->assertEmpty($res['headers']);
        $this->assertSame(404, $res['status']);
    }

    public function testLogMessage(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, [], 'yay'));

        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $http = new HttpClient($httpMockClient, ['user_agents' => ['.wikipedia.org' => 'Mozilla/5.2']]);
        $http->setLogger($logger);

        $res = $http->fetch(new Uri('http://fr.m.wikipedia.org/wiki/Copyright#bottom'));

        $this->assertSame('http://fr.wikipedia.org/wiki/Copyright', (string) $res['effective_url']);
        $this->assertSame('yay', $res['body']);
        $this->assertSame(200, $res['status']);

        $records = $handler->getRecords();

        $this->assertCount(4, $records);
        $this->assertSame('Trying using method "{method}" on url "{url}"', $records[0]['message']);
        $this->assertSame('get', $records[0]['context']['method']);
        $this->assertSame('http://fr.wikipedia.org/wiki/Copyright', $records[0]['context']['url']);
        $this->assertSame('Use default referer "{referer}" for url "{url}"', $records[2]['message']);
        $this->assertSame('Data fetched: {data}', $records[3]['message']);
        $this->assertSame([
            'effective_url' => 'http://fr.wikipedia.org/wiki/Copyright',
            'body' => '(only length for debug): 3',
            'headers' => [],
            'status' => 200,
        ], $records[3]['context']['data']);
    }

    public function testTimeout(): void
    {
        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $isCurl = false;
        $isGuzzle = false;

        // find with adapter is installed
        if (class_exists('Http\Adapter\Guzzle7\Client')) {
            $isGuzzle = true;
            $guzzle = new \GuzzleHttp\Client(['timeout' => 2]);
            $adapter = new \Http\Adapter\Guzzle7\Client($guzzle);
        } elseif (class_exists('Http\Adapter\Guzzle6\Client')) {
            $isGuzzle = true;
            $guzzle = new \GuzzleHttp\Client(['timeout' => 2]);
            $adapter = new \Http\Adapter\Guzzle6\Client($guzzle);
        } elseif (class_exists('Http\Adapter\Guzzle5\Client')) {
            $isGuzzle = true;
            $guzzle = new \GuzzleHttp\Client(['defaults' => ['timeout' => 2]]);
            $adapter = new \Http\Adapter\Guzzle5\Client($guzzle);
        } elseif (class_exists('Http\Client\Curl\Client')) {
            $isCurl = true;
            $adapter = new \Http\Client\Curl\Client(
                null,
                null,
                [
                    \CURLOPT_TIMEOUT => 2,
                ]
            );
        } else {
            $this->markTestSkipped('No Guzzle adapter defined ?');
        }

        $http = new HttpClient($adapter, [], $logger);

        $res = $http->fetch(new Uri('http://blackhole.webpagetest.org/'));

        $this->assertSame('http://blackhole.webpagetest.org/', (string) $res['effective_url']);
        $this->assertSame(500, $res['status']);

        $records = $handler->getRecords();

        $this->assertSame('Request throw exception (with no response): {error_message}', $records[3]['message']);
        // cURL error 28 is: CURLE_OPERATION_TIMEDOUT
        // "cURL error 28: Connection timed out after"
        if ($isGuzzle) {
            $this->assertStringContainsString('cURL error 28', $records[3]['context']['error_message']);
        } else {
            $this->assertStringContainsString('Connection timed out after', $records[3]['context']['error_message']);
        }
    }

    public function testNbRedirectsReached(): void
    {
        $maxRedirect = 3;
        $httpMockClient = new HttpMockClient();

        for ($i = 0; $i <= $maxRedirect; ++$i) {
            $httpMockClient->addResponse(new Response(
                308,
                [
                    'Location' => 'http://fr.wikipedia.org/wiki/Copyright?' . random_int(0, mt_getrandmax()),
                ],
                '<meta HTTP-EQUIV="REFRESH" content="0; url=http://fr.wikipedia.org/wiki/Copyright?' . random_int(0, mt_getrandmax()) . '">'
            ));
        }

        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $http = new HttpClient($httpMockClient, ['max_redirect' => 3]);
        $http->setLogger($logger);

        $res = $http->fetch(new Uri('http://fr.wikipedia.org/wiki/Copyright'));

        $this->assertSame('http://fr.wikipedia.org/wiki/Copyright', (string) $res['effective_url']);
        $this->assertSame(310, $res['status']);
        $this->assertSame('Endless redirect: 4 on "{url}"', $handler->getRecords()[3]['message']);
    }

    public function dataForConditionalComments(): array
    {
        return [
            [
                'url' => 'http://www.lemonde.fr/actualite-medias/article/2015/04/12/radio-france-vers-une-sortie-du-conflit_4614610_3236.html',
                'html' => '<!doctype html>
<!--[if lt IE 9]><html class="ie"><![endif]-->
<!--[if IE 9]><html class="ie9"><![endif]-->
<!--[if gte IE 9]><!-->
<html lang="fr">
<!--<![endif]-->

<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">',
                'removeData' => '<html class="ie9">',
            ],
            [
                'url' => 'https://venngage.com/blog/hashtags-are-worthless/',
                'html' => '<!DOCTYPE html>
<!--[if IE 7]>
<html class="ie ie7" lang="en-US" prefix="og: http://ogp.me/ns# fb: http://ogp.me/ns/fb#">
<![endif]-->
<!--[if IE 8]>
<html class="ie ie8" lang="en-US" prefix="og: http://ogp.me/ns# fb: http://ogp.me/ns/fb#">
<![endif]-->
<!--[if !(IE 7) | !(IE 8)  ]><!-->
<html lang="en-US" prefix="og: http://ogp.me/ns# fb: http://ogp.me/ns/fb#">
<!--<![endif]-->
        <head>
                <meta charset="UTF-8" />',
                'removeData' => '<html class="ie ie7" lang="en-US" prefix="og: http://ogp.me/ns# fb: http://ogp.me/ns/fb#">',
            ],
            [
                'url' => 'https://edition.cnn.com/2012/05/13/us/new-york-police-policy/index.html',
                'html' => '<!DOCTYPE html><html class="no-js"><head><meta content="IE=edge,chrome=1" http-equiv="X-UA-Compatible"><meta charset="utf-8"><meta content="text/html" http-equiv="Content-Type"><meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0"><link href="/favicon.ie9.ico" rel="Shortcut Icon" type="image/x-icon"/><link href="//cdn.cnn.com/cnn/.e/img/3.0/global/misc/apple-touch-icon.png" rel="apple-touch-icon" type="image/png"/><!--[if lte IE 9]><meta http-equiv="refresh" content="1;url=/2.85.0/static/unsupp.html" /><![endif]--><!--[if gt IE 9><!--><!--<![endif]--><title>New York police tout improving crime numbers to defend frisking policy  - CNN</title><meta content="us" name="section"><meta name="referrer" content="unsafe-url"><meta content="2012-05-13T21:22:42Z" property="og:pubdate"><meta content="2012-05-13T21:22:42Z" name="pubdate"><meta content="2012-05-14T02:34:10Z" name="lastmod"><meta content="https://www.cnn.com/2012/05/13/us/new-york-police-policy/index.html" property="og:url"><meta content="By the CNN Wire Staff" name="author">',
                'removeData' => '<meta http-equiv="refresh" content="1;url=/2.85.0/static/unsupp.html" />',
            ],
        ];
    }

    /**
     * @dataProvider dataForConditionalComments
     */
    public function testWithMetaRefreshInConditionalComments(string $url, string $html, string $removeData): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'text/html'], $html));

        $http = new HttpClient($httpMockClient);
        $res = $http->fetch(new Uri($url));

        $this->assertSame($url, (string) $res['effective_url']);
        $this->assertStringNotContainsString($removeData, $res['body']);
        $this->assertStringNotContainsString('<!--[if ', $res['body']);
        $this->assertStringNotContainsString('endif', $res['body']);
        $this->assertSame('text/html', $res['headers']['content-type']);
        $this->assertSame(200, $res['status']);
    }

    public function dataForUserAgent(): array
    {
        return [
            [
                'url' => 'http://fr.wikipedia.org/wiki/Copyright',
                'httpHeader' => [],
                'expectedUa' => 'UA/Default',
            ],
            [
                'url' => 'http://fr.wikipedia.org/wiki/Copyright',
                'httpHeader' => ['user-agent' => null],
                'expectedUa' => 'UA/Default',
            ],
            [
                'url' => 'http://example.com/foo',
                'httpHeader' => ['user-agent' => ''],
                'expectedUa' => 'UA/Config',
            ],
            [
                'url' => 'http://example.com/foo',
                'httpHeader' => ['user-agent' => 'UA/SiteConfig'],
                'expectedUa' => 'UA/SiteConfig',
            ],
        ];
    }

    /**
     * @dataProvider dataForUserAgent
     */
    public function testUserAgent(string $url, array $httpHeader, string $expectedUa): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, [], ''));

        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $http = new HttpClient($httpMockClient, [
            'ua_browser' => 'UA/Default',
            'user_agents' => [
                'example.com' => 'UA/Config',
            ],
        ]);
        $http->setLogger($logger);

        $http->fetch(new Uri($url), false, $httpHeader);

        $records = $handler->getRecords();

        $this->assertSame($expectedUa, $records[1]['context']['user-agent']);
        $this->assertSame($url, $records[1]['context']['url']);
    }

    public function dataForReferer(): array
    {
        return [
            [
                'url' => 'http://www.google.com',
                'httpHeader' => [],
                'expectedReferer' => 'http://defaultreferer.local',
            ],
            [
                'url' => 'http://www.mozilla.org',
                'httpHeader' => ['referer' => null],
                'expectedReferer' => 'http://defaultreferer.local',
            ],
            [
                'url' => 'http://fr.wikipedia.org/wiki/Copyright',
                'httpHeader' => ['referer' => ''],
                'expectedReferer' => 'http://defaultreferer.local',
            ],
            [
                'url' => 'http://fr.wikipedia.org/wiki/Copyright',
                'httpHeader' => ['referer' => 'http://fr.wikipedia.org/wiki/Accueil'],
                'expectedReferer' => 'http://fr.wikipedia.org/wiki/Accueil',
            ],
        ];
    }

    /**
     * @dataProvider dataForReferer
     */
    public function testReferer(string $url, array $httpHeader, string $expectedReferer): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, [], ''));

        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $http = new HttpClient($httpMockClient, [
            'default_referer' => 'http://defaultreferer.local',
        ]);
        $http->setLogger($logger);

        $http->fetch(new Uri($url), false, $httpHeader);

        $records = $handler->getRecords();

        $this->assertSame($expectedReferer, $records[2]['context']['referer']);
        $this->assertSame($url, $records[2]['context']['url']);
    }

    public function dataForCookie(): array
    {
        return [
            [
                'url' => 'http://www.google.com',
                'httpHeader' => [],
                'expectedCookie' => null,
            ],
            [
                'url' => 'http://www.mozilla.org',
                'httpHeader' => ['cookie' => null],
                'expectedCookie' => null,
            ],
            [
                'url' => 'http://fr.wikipedia.org/wiki/Copyright',
                'httpHeader' => ['cookie' => ''],
                'expectedCookie' => null,
            ],
            [
                'url' => 'http://fr.wikipedia.org/wiki/Copyright',
                'httpHeader' => ['cookie' => 'GDPR_consent=1'],
                'expectedCookie' => 'GDPR_consent=1',
            ],
        ];
    }

    /**
     * @dataProvider dataForCookie
     */
    public function testCookie(string $url, array $httpHeader, ?string $expectedCookie): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, [], ''));

        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $http = new HttpClient($httpMockClient);
        $http->setLogger($logger);

        $http->fetch(new Uri($url), false, $httpHeader);

        $records = $handler->getRecords();

        // if cookie is enable, a log will be available, otherwise not
        if (null !== $expectedCookie) {
            $this->assertSame($expectedCookie, $records[3]['context']['cookie']);
            $this->assertSame($url, $records[3]['context']['url']);
        } else {
            $this->assertArrayNotHasKey('cookie', $records[3]['context']);
        }
    }

    public function dataForAccept(): array
    {
        return [
            [
                'url' => 'http://www.google.com',
                'httpHeader' => [],
                'expectedAccept' => false,
            ],
            [
                'url' => 'http://www.mozilla.org',
                'httpHeader' => ['accept' => null],
                'expectedAccept' => false,
            ],
            [
                'url' => 'http://fr.wikipedia.org/wiki/Copyright',
                'httpHeader' => ['accept' => ''],
                'expectedAccept' => false,
            ],
            [
                'url' => 'http://fr.wikipedia.org/wiki/Copyright',
                'httpHeader' => ['accept' => '*/*'],
                'expectedAccept' => '*/*',
            ],
        ];
    }

    /**
     * @dataProvider dataForAccept
     *
     * @param string|false $expectedAccept
     */
    public function testAccept(string $url, array $httpHeader, $expectedAccept): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, [], ''));

        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $http = new HttpClient($httpMockClient);
        $http->setLogger($logger);

        $http->fetch(new Uri($url), false, $httpHeader);

        $records = $handler->getRecords();

        // if accept is enable, a log will be available, otherwise not
        if ($expectedAccept) {
            $this->assertSame($expectedAccept, $records[3]['context']['accept']);
            $this->assertSame($url, $records[3]['context']['url']);
        } else {
            $this->assertArrayNotHasKey('accept', $records[3]['context']);
        }
    }

    public function dataForWithUrlContainingQueryAndFragment(): array
    {
        return [
            [
                'url' => 'https://example.com/foo?utm_content=111315005&utm_medium=social&utm_source=twitter&hss_channel=tw-hello&foo[]=bar&foo[]=qux',
                'expectedUrl' => 'https://example.com/foo?hss_channel=tw-hello&foo%5B%5D=bar&foo%5B%5D=qux',
            ],
            [
                'url' => 'https://example.com/foo?hss_channel=tw-hello#fragment',
                'expectedUrl' => 'https://example.com/foo?hss_channel=tw-hello',
            ],
            [
                'url' => 'https://example.com/foo?utm_content=111315005',
                'expectedUrl' => 'https://example.com/foo',
            ],
        ];
    }

    /**
     * @dataProvider dataForWithUrlContainingQueryAndFragment
     */
    public function testWithUrlContainingQueryAndFragment(string $url, string $expectedUrl): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200));

        $http = new HttpClient($httpMockClient);
        $res = $http->fetch(new Uri($url));

        $this->assertSame($expectedUrl, (string) $res['effective_url']);
    }
}
