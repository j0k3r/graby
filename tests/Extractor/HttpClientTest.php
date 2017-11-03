<?php

namespace Tests\Graby\Extractor;

use Graby\Extractor\HttpClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Response;
use Http\Adapter\Guzzle5\Client as GuzzleAdapter;
use Http\Mock\Client as HttpMockClient;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Http\Message\RequestInterface;

class HttpClientTest extends \PHPUnit_Framework_TestCase
{
    public function dataForFetchGet()
    {
        return [
            [
                'http://fr.m.wikipedia.org/wiki/Copyright#bottom',
                'http://fr.wikipedia.org/wiki/Copyright',
                [
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.2',
                        'Referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
                    ],
                ],
            ],
            [
                'http://bjori.blogspot.fr/2015/04/next-gen-mongodb-driver.html/#!test',
                'http://bjori.blogspot.fr/2015/04/next-gen-mongodb-driver.html/?_escaped_fragment_=test',
                [
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/535.2 (KHTML, like Gecko) Chrome/15.0.874.92 Safari/535.2',
                        'Referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
                    ],
                ],
            ],
            [
                'http://www.example.com/my-map.html',
                'http://www.example.com/my-map.html',
                [
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/535.2 (KHTML, like Gecko) Chrome/15.0.874.92 Safari/535.2',
                        'Referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
                    ],
                ],
            ],
        ];
    }

    /**
     * @dataProvider dataForFetchGet
     */
    public function testFetchGet($url, $urlEffective)
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, [], 'yay'));

        $http = new HttpClient($httpMockClient, ['user_agents' => ['.wikipedia.org' => 'Mozilla/5.2']]);
        $res = $http->fetch($url);

        $this->assertSame($urlEffective, $res['effective_url']);
        $this->assertSame('yay', $res['body']);
        $this->assertSame(200, $res['status']);
    }

    public function testFetchHeadGoodContentType()
    {
        $url = 'http://fr.wikipedia.org/wiki/Copyright.jpg';

        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'image/jpg'], 'yay'));

        $http = new HttpClient($httpMockClient, ['user_agents' => ['.wikipedia.org' => 'Mozilla/5.2']]);
        $res = $http->fetch($url);

        $this->assertCount(1, $httpMockClient->getRequests());
        /** @var RequestInterface $request */
        $request = $httpMockClient->getRequests()[0];

        $this->assertEquals('Mozilla/5.2', $request->getHeaderLine('User-Agent'));
        $this->assertEquals('http://www.google.co.uk/url?sa=t&source=web&cd=1', $request->getHeaderLine('Referer'));
        $this->assertSame($url, $res['effective_url']);
        $this->assertSame('yay', $res['body']);
        $this->assertSame('image/jpg', $res['headers']);
        $this->assertSame(200, $res['status']);
    }

    public function testFetchHeadBadContentType()
    {
        $url = 'http://fr.wikipedia.org/wiki/Copyright.jpg';

        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'text/html'], 'yay'));
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'text/html'], 'yay'));

        $http = new HttpClient($httpMockClient, ['user_agents' => ['.wikipedia.org' => 'Mozilla/5.2']]);
        $res = $http->fetch($url);

        $this->assertCount(2, $httpMockClient->getRequests());
        $this->assertEquals('HEAD', $httpMockClient->getRequests()[0]->getMethod(), 'first request is head because of the extension');
        $this->assertEquals('GET', $httpMockClient->getRequests()[1]->getMethod(), 'second request is get because the Content-Type wasn\'t a binary');

        $this->assertSame($url, $res['effective_url']);
        $this->assertSame('yay', $res['body']);
        $this->assertSame('text/html', $res['headers']);
        $this->assertSame(200, $res['status']);
    }

    public function testFetchHeadReallyBadContentType()
    {
        $url = 'http://fr.wikipedia.org/wiki/Copyright.jpg';

        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'fucked'], 'yay'));
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'fucked'], 'yay'));

        $http = new HttpClient($httpMockClient, ['user_agents' => ['.wikipedia.org' => 'Mozilla/5.2']]);
        $res = $http->fetch($url);

        $this->assertCount(2, $httpMockClient->getRequests());
        $this->assertEquals('HEAD', $httpMockClient->getRequests()[0]->getMethod(), 'first request should be HEAD because of the extension');
        $this->assertEquals('GET', $httpMockClient->getRequests()[1]->getMethod(), 'second request is GET because the Content-Type wasn\'t a binary');

        $this->assertSame($url, $res['effective_url']);
        $this->assertSame('yay', $res['body']);
        $this->assertSame('fucked', $res['headers']);
        $this->assertSame(200, $res['status']);
    }

    public function dataForMetaRefresh()
    {
        return [
            [
                'http://fr.wikipedia.org/wiki/Copyright',
                '<html><meta HTTP-EQUIV="REFRESH" content="0; url=http://www.bernama.com/bernama/v6/newsindex.php?id=943513"></html>',
                'http://www.bernama.com/bernama/v6/newsindex.php?id=943513',
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
    public function testFetchGetWithMetaRefresh($url, $body, $metaUrl)
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'text/html'], $body));
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'text/html'], ''));

        $http = new HttpClient($httpMockClient);
        $res = $http->fetch($url);

        $this->assertCount(2, $httpMockClient->getRequests());
        $this->assertEquals('GET', $httpMockClient->getRequests()[0]->getMethod());
        $this->assertEquals($url, (string) $httpMockClient->getRequests()[0]->getUri());
        $this->assertEquals('GET', $httpMockClient->getRequests()[1]->getMethod());
        $this->assertEquals($metaUrl, (string) $httpMockClient->getRequests()[1]->getUri());

        $this->assertSame($metaUrl, $res['effective_url']);
        $this->assertEmpty($res['body']);
        $this->assertSame('text/html', $res['headers']);
        $this->assertSame(200, $res['status']);
    }

    /**
     * This will force `SimplePie_IRI::absolutize` to return false because the relative url is wrong.
     */
    public function testFetchGetWithMetaRefreshBadBase()
    {
        $url = 'http://wikipedia.org/wiki/Copyright';
        $body = '<html><meta HTTP-EQUIV="REFRESH" content="0; url=::/bernama/v6/newsindex.php?id=943513"></html>';

        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'text/html'], $body));

        $http = new HttpClient($httpMockClient);
        $res = $http->fetch($url);

        $this->assertCount(1, $httpMockClient->getRequests());
        $this->assertEquals('GET', $httpMockClient->getRequests()[0]->getMethod());
        $this->assertSame($url, $res['effective_url']);
        $this->assertSame($body, $res['body']);
        $this->assertSame('text/html', $res['headers']);
        $this->assertSame(200, $res['status']);
    }

    public function testWith404ResponseWithResponse()
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(404, ['Content-Type' => 'text/html'], 'test'));

        $http = new HttpClient($httpMockClient);
        $res = $http->fetch('http://example.com/my-map.html');

        $this->assertSame('http://example.com/my-map.html', $res['effective_url']);
        $this->assertSame('test', $res['body']);
        $this->assertSame('text/html', $res['headers']);
        $this->assertSame(404, $res['status']);
    }

    public function testWithUrlencodedContentType()
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'image%2Fjpeg'], 'test'));

        $http = new HttpClient($httpMockClient);
        $res = $http->fetch('http://example.com/image.jpg');

        $this->assertSame('http://example.com/image.jpg', $res['effective_url']);
        $this->assertSame('test', $res['body']);
        $this->assertSame('image/jpeg', $res['headers']);
        $this->assertSame(200, $res['status']);
    }

    public function testWithUrlContainingPlusSymbol()
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200));

        $http = new HttpClient($httpMockClient);
        $res = $http->fetch('https://example.com/foo/+bar/baz/+quuz/corge');

        $this->assertSame('https://example.com/foo/+bar/baz/+quuz/corge', $res['effective_url']);
    }

    public function testWith404ResponseWithoutResponse()
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(404));

        $http = new HttpClient($httpMockClient);
        $res = $http->fetch('http://example.com');

        $this->assertSame('http://example.com', $res['effective_url']);
        $this->assertSame('', $res['body']);
        $this->assertSame('', $res['headers']);
        $this->assertSame(404, $res['status']);
    }

    public function testLogMessage()
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, [], 'yay'));

        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $http = new HttpClient($httpMockClient, ['user_agents' => ['.wikipedia.org' => 'Mozilla/5.2']]);
        $http->setLogger($logger);

        $res = $http->fetch('http://fr.m.wikipedia.org/wiki/Copyright#bottom');

        $this->assertSame('http://fr.wikipedia.org/wiki/Copyright', $res['effective_url']);
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
            'headers' => '',
            'all_headers' => [],
            'status' => 200,
        ], $records[3]['context']['data']);
    }

    public function testTimeout()
    {
        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $guzzle = new GuzzleClient(['defaults' => ['timeout' => 2]]);
        $adapter = new GuzzleAdapter($guzzle);
        $http = new HttpClient($adapter, [], $logger);

        $res = $http->fetch('http://blackhole.webpagetest.org/');

        $this->assertSame('http://blackhole.webpagetest.org/', $res['effective_url']);
        $this->assertSame(500, $res['status']);

        $records = $handler->getRecords();

        $this->assertSame('Request throw exception (with no response): {error_message}', $records[3]['message']);
        // cURL error 28 is: CURLE_OPERATION_TIMEDOUT
        $this->assertContains('cURL error 28', $records[3]['formatted']);
    }

    public function testNbRedirectsReached()
    {
        $maxRedirect = 3;
        $httpMockClient = new HttpMockClient();

        for ($i = 0; $i <= $maxRedirect; ++$i) {
            $httpMockClient->addResponse(new Response(
                308,
                [
                    'Location' => 'http://fr.wikipedia.org/wiki/Copyright?' . rand(),
                ],
                '<meta HTTP-EQUIV="REFRESH" content="0; url=http://fr.wikipedia.org/wiki/Copyright?' . rand() . '">'
            ));
        }

        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $http = new HttpClient($httpMockClient, ['max_redirect' => 3]);
        $http->setLogger($logger);

        $res = $http->fetch('http://fr.wikipedia.org/wiki/Copyright');

        $this->assertSame('http://fr.wikipedia.org/wiki/Copyright', $res['effective_url']);
        $this->assertSame(310, $res['status']);

        $records = $handler->getRecords();
        $record = end($records);

        $this->assertSame('Endless redirect: 4 on "{url}"', $record['message']);
    }

    public function dataForConditionalComments()
    {
        return [
            [
                'url' => 'http://osqledaren.se/ol-gor-bangladesh/',
                'html' => '<!DOCTYPE html>
<!--[if IE 6]>
<html id="ie6" >
<![endif]-->
<!--[if IE 7]>
<html id="ie7" >
<![endif]-->
<!--[if IE 8]>
<html id="ie8" >
<![endif]-->
<!--[if lte IE 8]>
<meta http-equiv="refresh" content="0; url=/ie.html" />
<![endif]-->
<!--[if !(IE 6) | !(IE 7) | !(IE 8)  ]><!-->
<html lang="sv-SE">
<!--<![endif]-->
<head>
<meta charset="UTF-8">
<meta name="description" content="Osqledaren">
<meta name="keywords" content="osqledaren, newspaper">
<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=1, minimum-scale=1, maximum-scale=1">',
                'expectedBody' => '<html lang="sv-SE"><head>',
            ],
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
                'expectedBody' => '<html lang="fr"><head>',
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
                'expectedBody' => '<html lang="en-US" prefix="og: http://ogp.me/ns# fb: http://ogp.me/ns/fb#"><head>',
            ],
        ];
    }

    /**
     * @dataProvider dataForConditionalComments
     */
    public function testWithMetaRefreshInConditionalComments($url, $html, $expectedBody)
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'text/html'], $html));

        $http = new HttpClient($httpMockClient);
        $res = $http->fetch($url);

        $this->assertSame($url, $res['effective_url']);
        $this->assertContains($expectedBody, $res['body']);
        $this->assertSame('text/html', $res['headers']);
        $this->assertSame(200, $res['status']);
    }

    public function dataForUserAgent()
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
    public function testUserAgent($url, $httpHeader, $expectedUa)
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

        $res = $http->fetch($url, false, $httpHeader);

        $records = $handler->getRecords();

        $this->assertSame($expectedUa, $records[1]['context']['user-agent']);
        $this->assertSame($url, $records[1]['context']['url']);
    }

    public function dataForReferer()
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
    public function testReferer($url, $httpHeader, $expectedReferer)
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

        $res = $http->fetch($url, false, $httpHeader);

        $records = $handler->getRecords();

        $this->assertSame($expectedReferer, $records[2]['context']['referer']);
        $this->assertSame($url, $records[2]['context']['url']);
    }
}
