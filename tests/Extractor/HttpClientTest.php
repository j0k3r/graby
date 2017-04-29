<?php

namespace Tests\Graby\Extractor;

use Graby\Extractor\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Subscriber\Mock;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

class HttpClientTest extends \PHPUnit_Framework_TestCase
{
    public function dataForFetchGet()
    {
        return [
            [
                'http://fr.m.wikipedia.org/wiki/Copyright#bottom',
                'http://fr.wikipedia.org/wiki/Copyright',
                'http://fr.wikipedia.org/wiki/Copyright',
                [
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.2',
                        'Referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
                    ],
                    'timeout' => 10,
                    'connect_timeout' => 10,
                ],
            ],
            [
                'http://bjori.blogspot.fr/2015/04/next-gen-mongodb-driver.html/#!test',
                'http://bjori.blogspot.fr/2015/04/next-gen-mongodb-driver.html/?_escaped_fragment_=test',
                'http://bjori.blogspot.fr/2015/04/next-gen-mongodb-driver.html',
                [
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/535.2 (KHTML, like Gecko) Chrome/15.0.874.92 Safari/535.2',
                        'Referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
                    ],
                    'timeout' => 10,
                    'connect_timeout' => 10,
                ],
            ],
            [
                'http://www.lexpress.io/my-map.html',
                'http://www.lexpress.io/my-map.html',
                'http://www.lexpress.io/my-map.html',
                [
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/535.2 (KHTML, like Gecko) Chrome/15.0.874.92 Safari/535.2',
                        'Referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
                    ],
                    'timeout' => 10,
                    'connect_timeout' => 10,
                ],
            ],
        ];
    }

    /**
     * @dataProvider dataForFetchGet
     */
    public function testFetchGet($url, $urlRewritten, $urlEffective, $options)
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->once())
            ->method('getEffectiveUrl')
            ->willReturn($urlEffective);

        $response->expects($this->any())
            ->method('getHeaders')
            ->willReturn([]);

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getBody')
            ->willReturn('yay');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('get')
            ->with(
                $this->equalTo($urlRewritten),
                $this->equalTo($options)
            )
            ->willReturn($response);

        $http = new HttpClient($client, ['user_agents' => ['.wikipedia.org' => 'Mozilla/5.2']]);
        $res = $http->fetch($url);

        $this->assertSame($urlEffective, $res['effective_url']);
        $this->assertSame('yay', $res['body']);
        $this->assertSame(200, $res['status']);
    }

    public function testFetchHeadGoodContentType()
    {
        $url = 'http://fr.wikipedia.org/wiki/Copyright.jpg';
        $options = [
            'headers' => [
                'User-Agent' => 'Mozilla/5.2',
                'Referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
            ],
            'timeout' => 10,
            'connect_timeout' => 10,
        ];

        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->once())
            ->method('getEffectiveUrl')
            ->willReturn($url);

        $response->expects($this->any())
            ->method('getHeaders')
            ->willReturn(['Content-Type' => 'image/jpg']);

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getBody')
            ->willReturn('yay');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('head')
            ->with(
                $this->equalTo($url),
                $this->equalTo($options)
            )
            ->willReturn($response);

        $http = new HttpClient($client, ['user_agents' => ['.wikipedia.org' => 'Mozilla/5.2']]);
        $res = $http->fetch($url);

        $this->assertSame($url, $res['effective_url']);
        $this->assertSame('yay', $res['body']);
        $this->assertSame('image/jpg', $res['headers']);
        $this->assertSame(200, $res['status']);
    }

    public function testFetchHeadBadContentType()
    {
        $url = 'http://fr.wikipedia.org/wiki/Copyright.jpg';
        $options = [
            'headers' => [
                'User-Agent' => 'Mozilla/5.2',
                'Referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
            ],
            'timeout' => 10,
            'connect_timeout' => 10,
        ];

        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        // called twice because of the second try
        $response->expects($this->exactly(2))
            ->method('getEffectiveUrl')
            ->willReturn($url);

        $response->expects($this->any())
            ->method('getHeaders')
            ->willReturn(['Content-Type' => 'text/html']);

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getBody')
            ->willReturn('yay');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        // first request is head because of the extension
        $client->expects($this->once())
            ->method('head')
            ->with(
                $this->equalTo($url),
                $this->equalTo($options)
            )
            ->willReturn($response);

        // second request is get because the Content-Type wasn't a binary
        $client->expects($this->once())
            ->method('get')
            ->with(
                $this->equalTo($url),
                $this->equalTo($options)
            )
            ->willReturn($response);

        $http = new HttpClient($client, ['user_agents' => ['.wikipedia.org' => 'Mozilla/5.2']]);
        $res = $http->fetch($url);

        $this->assertSame($url, $res['effective_url']);
        $this->assertSame('yay', $res['body']);
        $this->assertSame('text/html', $res['headers']);
        $this->assertSame(200, $res['status']);
    }

    public function testFetchHeadReallyBadContentType()
    {
        $url = 'http://fr.wikipedia.org/wiki/Copyright.jpg';
        $options = [
            'headers' => [
                'User-Agent' => 'Mozilla/5.2',
                'Referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
            ],
            'timeout' => 10,
            'connect_timeout' => 10,
        ];

        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        // called twice because of the second try
        $response->expects($this->exactly(2))
            ->method('getEffectiveUrl')
            ->willReturn($url);

        $response->expects($this->any())
            ->method('getHeaders')
            ->willReturn(['Content-Type' => 'fucked']);

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getBody')
            ->willReturn('yay');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        // first request is head because of the extension
        $client->expects($this->once())
            ->method('head')
            ->with(
                $this->equalTo($url),
                $this->equalTo($options)
            )
            ->willReturn($response);

        // second request is get because the Content-Type wasn't a binary
        $client->expects($this->once())
            ->method('get')
            ->with(
                $this->equalTo($url),
                $this->equalTo($options)
            )
            ->willReturn($response);

        $http = new HttpClient($client, ['user_agents' => ['.wikipedia.org' => 'Mozilla/5.2']]);
        $res = $http->fetch($url);

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
                'http://fr.wikipedia.org/wiki/Copyright',
                '<html><meta HTTP-EQUIV="REFRESH" content="0; url=/bernama/v6/newsindex.php?id=943513"></html>',
                'http://www.bernama.com/bernama/v6/newsindex.php?id=943513',
            ],
            [
                'http://fr.wikipedia.org/wiki/Copyright',
                '<html><meta name="fragment" content="!"></html>',
                'http://www.bernama.com/bernama/v6/newsindex.php?id=943513',
            ],
            [
                'http://fr.wikipedia.org/wiki/Copyright',
                '<html><body ng-controller="MyCtrl"></body></html>',
                'http://www.bernama.com/bernama/v6/newsindex.php?id=943513',
            ],
        ];
    }

    /**
     * @dataProvider dataForMetaRefresh
     */
    public function testFetchGetWithMetaRefresh($url, $body, $metaUrl)
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->exactly(2))
            ->method('getEffectiveUrl')
            ->will($this->onConsecutiveCalls($url, $metaUrl));

        $response->expects($this->any())
            ->method('getHeaders')
            ->willReturn(['Content-Type' => 'text/html']);

        $response->expects($this->any())
            ->method('getBody')
            ->will($this->onConsecutiveCalls($body, ''));

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->exactly(2))
            ->method('get')
            ->willReturn($response);

        $http = new HttpClient($client);
        $res = $http->fetch($url);

        $this->assertSame($metaUrl, $res['effective_url']);
        $this->assertEmpty($res['body']);
        $this->assertSame('text/html', $res['headers']);
        $this->assertSame(200, $res['status']);
    }

    /**
     * This will force `SimplePie_IRI::absolutize` to return false because the "base" is wrong.
     * It means there is no real host name.
     */
    public function testFetchGetWithMetaRefreshBadBase()
    {
        $url = 'wikipedia.org/wiki/Copyright';
        $body = '<html><meta HTTP-EQUIV="REFRESH" content="0; url=/bernama/v6/newsindex.php?id=943513"></html>';

        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->once())
            ->method('getEffectiveUrl')
            ->willReturn($url);

        $response->expects($this->any())
            ->method('getHeaders')
            ->willReturn(['Content-Type' => 'text/html']);

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getBody')
            ->willReturn($body);

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('get')
            ->willReturn($response);

        $http = new HttpClient($client);
        $res = $http->fetch($url);

        $this->assertSame($url, $res['effective_url']);
        $this->assertSame($body, $res['body']);
        $this->assertSame('text/html', $res['headers']);
        $this->assertSame(200, $res['status']);
    }

    public function testWith404ResponseWithResponse()
    {
        $client = new Client();

        $mock = new Mock([
            new Response(404, ['Content-Type' => 'text/html'], Stream::factory('test')),
        ]);

        $client->getEmitter()->attach($mock);

        $http = new HttpClient($client);
        $res = $http->fetch('http://www.lexpress.io/my-map.html');

        $this->assertSame('http://www.lexpress.io/my-map.html', $res['effective_url']);
        $this->assertSame('', $res['body']);
        $this->assertSame('text/html', $res['headers']);
        $this->assertSame(404, $res['status']);
    }

    public function testWithUrlencodedContentType()
    {
        $client = new Client();

        $mock = new Mock([
            new Response(200, ['Content-Type' => 'image%2Fjpeg'], Stream::factory('test')),
        ]);

        $client->getEmitter()->attach($mock);

        $http = new HttpClient($client);
        $res = $http->fetch('http://www.lexpress.io/image.jpg');

        $this->assertSame('http://www.lexpress.io/image.jpg', $res['effective_url']);
        $this->assertSame('test', $res['body']);
        $this->assertSame('image/jpeg', $res['headers']);
        $this->assertSame(200, $res['status']);
    }

    public function testWith404ResponseWithoutResponse()
    {
        $request = $this->getMockBuilder('GuzzleHttp\Message\Request')
            ->disableOriginalConstructor()
            ->getMock();

        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('get')
            ->willThrowException(new RequestException('oops', $request));

        $http = new HttpClient($client);
        $res = $http->fetch('http://0.0.0.0');

        $this->assertSame('http://0.0.0.0', $res['effective_url']);
        $this->assertSame('', $res['body']);
        $this->assertSame('', $res['headers']);
        $this->assertSame(500, $res['status']);
    }

    public function testLogMessage()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->once())
            ->method('getEffectiveUrl')
            ->willReturn('http://fr.wikipedia.org/wiki/Copyright');

        $response->expects($this->any())
            ->method('getHeaders')
            ->willReturn([]);

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getBody')
            ->willReturn('yay');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('get')
            ->with(
                $this->equalTo('http://fr.wikipedia.org/wiki/Copyright'),
                $this->equalTo([
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.2',
                        'Referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
                    ],
                    'timeout' => 10,
                    'connect_timeout' => 10,
                ])
            )
            ->willReturn($response);

        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $http = new HttpClient($client, ['user_agents' => ['.wikipedia.org' => 'Mozilla/5.2']]);
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

        $client = new Client();
        $http = new HttpClient($client, ['timeout' => 2], $logger);

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
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->any())
            ->method('getEffectiveUrl')
            ->willReturn('http://fr.wikipedia.org/wiki/Copyright');

        $response->expects($this->any())
            ->method('getHeaders')
            ->willReturn([]);

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getBody')
            ->willReturn('<meta HTTP-EQUIV="REFRESH" content="0; url=http://fr.wikipedia.org/wiki/Copyright?' . rand() . '">');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->willReturn($response);

        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $http = new HttpClient($client);
        $http->setLogger($logger);

        $res = $http->fetch('http://fr.wikipedia.org/wiki/Copyright');

        $this->assertSame('http://fr.wikipedia.org/wiki/Copyright', $res['effective_url']);
        $this->assertSame(310, $res['status']);

        $records = $handler->getRecords();
        $record = end($records);

        $this->assertSame('Endless redirect: 11 on "{url}"', $record['message']);
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
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->once())
            ->method('getEffectiveUrl')
            ->willReturn($url);

        $response->expects($this->any())
            ->method('getHeaders')
            ->willReturn(['Content-Type' => 'text/html']);

        $response->expects($this->any())
            ->method('getBody')
            ->willReturn($html);

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('get')
            ->willReturn($response);

        $http = new HttpClient($client);
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
                'url' => 'http://customua.com/foo',
                'httpHeader' => ['user-agent' => ''],
                'expectedUa' => 'UA/Config',
            ],
            [
                'url' => 'http://customua.com/foo',
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
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->any())
            ->method('getEffectiveUrl')
            ->willReturn($url);

        $response->expects($this->any())
            ->method('getHeaders')
            ->willReturn([]);

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getBody')
            ->willReturn('');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->willReturn($response);

        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $http = new HttpClient($client, [
            'ua_browser' => 'UA/Default',
            'user_agents' => [
                'customua.com' => 'UA/Config',
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
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->any())
            ->method('getEffectiveUrl')
            ->willReturn($url);

        $response->expects($this->any())
            ->method('getHeaders')
            ->willReturn([]);

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getBody')
            ->willReturn('');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->willReturn($response);

        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $http = new HttpClient($client, [
            'default_referer' => 'http://defaultreferer.local',
        ]);
        $http->setLogger($logger);

        $res = $http->fetch($url, false, $httpHeader);

        $records = $handler->getRecords();

        $this->assertSame($expectedReferer, $records[2]['context']['referer']);
        $this->assertSame($url, $records[2]['context']['url']);
    }
}
