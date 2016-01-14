<?php

namespace Tests\Graby\Extractor;

use Graby\Extractor\HttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Subscriber\Mock;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Exception\RequestException;
use Monolog\Logger;
use Monolog\Handler\TestHandler;

class HttpClientTest extends \PHPUnit_Framework_TestCase
{
    public function dataForFetchGet()
    {
        return array(
            array(
                'http://fr.m.wikipedia.org/wiki/Copyright#bottom',
                'http://fr.wikipedia.org/wiki/Copyright',
                'http://fr.wikipedia.org/wiki/Copyright',
                array(
                    'User-Agent' => 'Mozilla/5.2',
                    'Referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
                ),
            ),
            array(
                'http://bjori.blogspot.fr/2015/04/next-gen-mongodb-driver.html/#!test',
                'http://bjori.blogspot.fr/2015/04/next-gen-mongodb-driver.html/?_escaped_fragment_=test',
                'http://bjori.blogspot.fr/2015/04/next-gen-mongodb-driver.html',
                array(
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/535.2 (KHTML, like Gecko) Chrome/15.0.874.92 Safari/535.2',
                    'Referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
                ),
            ),
            array(
                'http://www.lexpress.io/my-map.html',
                'http://www.lexpress.io/my-map.html',
                'http://www.lexpress.io/my-map.html',
                array(
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/535.2 (KHTML, like Gecko) Chrome/15.0.874.92 Safari/535.2',
                    'Referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
                ),
            ),
        );
    }

    /**
     * @dataProvider dataForFetchGet
     */
    public function testFetchGet($url, $urlRewritten, $urlEffective, $headers)
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->once())
            ->method('getEffectiveUrl')
            ->willReturn($urlEffective);

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('');

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
                $this->equalTo(array('headers' => $headers))
            )
            ->willReturn($response);

        $http = new HttpClient($client);
        $res = $http->fetch($url);

        $this->assertEquals($urlEffective, $res['effective_url']);
        $this->assertEquals('yay', $res['body']);
        $this->assertEquals(200, $res['status']);
    }

    public function testFetchHeadGoodContentType()
    {
        $url = 'http://fr.wikipedia.org/wiki/Copyright.jpg';
        $headers = array(
            'User-Agent' => 'Mozilla/5.2',
            'Referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
        );

        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->once())
            ->method('getEffectiveUrl')
            ->willReturn($url);

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('image/jpg');

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
                $this->equalTo(array('headers' => $headers))
            )
            ->willReturn($response);

        $http = new HttpClient($client);
        $res = $http->fetch($url);

        $this->assertEquals($url, $res['effective_url']);
        $this->assertEquals('yay', $res['body']);
        $this->assertEquals('image/jpg', $res['headers']);
        $this->assertEquals(200, $res['status']);
    }

    public function testFetchHeadBadContentType()
    {
        $url = 'http://fr.wikipedia.org/wiki/Copyright.jpg';
        $headers = array(
            'User-Agent' => 'Mozilla/5.2',
            'Referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
        );

        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        // called twice because of the second try
        $response->expects($this->exactly(2))
            ->method('getEffectiveUrl')
            ->willReturn($url);

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('text/html');

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
                $this->equalTo(array('headers' => $headers))
            )
            ->willReturn($response);

        // second request is get because the Content-Type wasn't a binary
        $client->expects($this->once())
            ->method('get')
            ->with(
                $this->equalTo($url),
                $this->equalTo(array('headers' => $headers))
            )
            ->willReturn($response);

        $http = new HttpClient($client);
        $res = $http->fetch($url);

        $this->assertEquals($url, $res['effective_url']);
        $this->assertEquals('yay', $res['body']);
        $this->assertEquals('text/html', $res['headers']);
        $this->assertEquals(200, $res['status']);
    }

    public function testFetchHeadReallyBadContentType()
    {
        $url = 'http://fr.wikipedia.org/wiki/Copyright.jpg';
        $headers = array(
            'User-Agent' => 'Mozilla/5.2',
            'Referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
        );

        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        // called twice because of the second try
        $response->expects($this->exactly(2))
            ->method('getEffectiveUrl')
            ->willReturn($url);

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('fucked');

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
                $this->equalTo(array('headers' => $headers))
            )
            ->willReturn($response);

        // second request is get because the Content-Type wasn't a binary
        $client->expects($this->once())
            ->method('get')
            ->with(
                $this->equalTo($url),
                $this->equalTo(array('headers' => $headers))
            )
            ->willReturn($response);

        $http = new HttpClient($client);
        $res = $http->fetch($url);

        $this->assertEquals($url, $res['effective_url']);
        $this->assertEquals('yay', $res['body']);
        $this->assertEquals('fucked', $res['headers']);
        $this->assertEquals(200, $res['status']);
    }

    public function dataForMetaRefresh()
    {
        return array(
            array(
                'http://fr.wikipedia.org/wiki/Copyright',
                '<html><meta HTTP-EQUIV="REFRESH" content="0; url=http://www.bernama.com/bernama/v6/newsindex.php?id=943513"></html>',
                'http://www.bernama.com/bernama/v6/newsindex.php?id=943513',
            ),
            array(
                'http://fr.wikipedia.org/wiki/Copyright',
                '<html><meta HTTP-EQUIV="REFRESH" content="0; url=/bernama/v6/newsindex.php?id=943513"></html>',
                'http://www.bernama.com/bernama/v6/newsindex.php?id=943513',
            ),
            array(
                'http://fr.wikipedia.org/wiki/Copyright',
                '<html><meta name="fragment" content="!"></html>',
                'http://www.bernama.com/bernama/v6/newsindex.php?id=943513',
            ),
            array(
                'http://fr.wikipedia.org/wiki/Copyright',
                '<html><body ng-controller="MyCtrl"></body></html>',
                'http://www.bernama.com/bernama/v6/newsindex.php?id=943513',
            ),
        );
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
            ->method('getHeader')
            ->willReturn('text/html');

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

        $this->assertEquals($metaUrl, $res['effective_url']);
        $this->assertEmpty($res['body']);
        $this->assertEquals('text/html', $res['headers']);
        $this->assertEquals(200, $res['status']);
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
            ->method('getHeader')
            ->willReturn('text/html');

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

        $this->assertEquals($url, $res['effective_url']);
        $this->assertEquals($body, $res['body']);
        $this->assertEquals('text/html', $res['headers']);
        $this->assertEquals(200, $res['status']);
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

        $this->assertEquals('http://www.lexpress.io/my-map.html', $res['effective_url']);
        $this->assertEquals('', $res['body']);
        $this->assertEquals('text/html', $res['headers']);
        $this->assertEquals(404, $res['status']);
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

        $this->assertEquals('http://0.0.0.0', $res['effective_url']);
        $this->assertEquals('', $res['body']);
        $this->assertEquals('', $res['headers']);
        $this->assertEquals(500, $res['status']);
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
            ->method('getHeader')
            ->willReturn('');

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
                $this->equalTo(array('headers' => array(
                    'User-Agent' => 'Mozilla/5.2',
                    'Referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
                )))
            )
            ->willReturn($response);

        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $http = new HttpClient($client);
        $http->setLogger($logger);

        $res = $http->fetch('http://fr.m.wikipedia.org/wiki/Copyright#bottom');

        $this->assertEquals('http://fr.wikipedia.org/wiki/Copyright', $res['effective_url']);
        $this->assertEquals('yay', $res['body']);
        $this->assertEquals(200, $res['status']);

        $records = $handler->getRecords();

        $this->assertCount(2, $records);
        $this->assertEquals('Trying using method "{method}" on url "{url}"', $records[0]['message']);
        $this->assertEquals('get', $records[0]['context']['method']);
        $this->assertEquals('http://fr.wikipedia.org/wiki/Copyright', $records[0]['context']['url']);
        $this->assertEquals('Data fetched: {data}', $records[1]['message']);
        $this->assertEquals(array(
            'effective_url' => 'http://fr.wikipedia.org/wiki/Copyright',
            'body' => '(only length for debug): 3',
            'headers' => '',
            'status' => 200,
        ), $records[1]['context']['data']);
    }
}
