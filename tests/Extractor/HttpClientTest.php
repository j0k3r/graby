<?php

namespace Tests\Graby\Extractor;

use Graby\Extractor\HttpClient;

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
            ->method('getBody')
            ->willReturn('yay');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('get')
            ->with(
                $this->equalTo($urlRewritten),
                $this->equalTo(array('headers' => $headers, 'cookies' => true))
            )
            ->willReturn($response);

        $http = new HttpClient($client);
        $res = $http->fetch($url);

        $this->assertEquals($urlEffective, $res['effective_url']);
        $this->assertEquals('yay', $res['body']);
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
            ->method('getBody')
            ->willReturn('yay');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('head')
            ->with(
                $this->equalTo($url),
                $this->equalTo(array('headers' => $headers, 'cookies' => true))
            )
            ->willReturn($response);

        $http = new HttpClient($client);
        $res = $http->fetch($url);

        $this->assertEquals($url, $res['effective_url']);
        $this->assertEquals('yay', $res['body']);
        $this->assertEquals('image/jpg', $res['headers']);
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
                $this->equalTo(array('headers' => $headers, 'cookies' => true))
            )
            ->willReturn($response);

        // second request is get because the Content-Type wasn't a binary
        $client->expects($this->once())
            ->method('get')
            ->with(
                $this->equalTo($url),
                $this->equalTo(array('headers' => $headers, 'cookies' => true))
            )
            ->willReturn($response);

        $http = new HttpClient($client);
        $res = $http->fetch($url);

        $this->assertEquals($url, $res['effective_url']);
        $this->assertEquals('yay', $res['body']);
        $this->assertEquals('text/html', $res['headers']);
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
    }

    public function testFetchGzip()
    {
        $url = 'http://fr.wikipedia.org/wiki/Copyright';
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
            ->willReturn('gzip');

        $response->expects($this->any())
            ->method('getBody')
            ->willReturn(gzencode('yay'));

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('get')
            ->with(
                $this->equalTo($url),
                $this->equalTo(array('headers' => $headers, 'cookies' => true))
            )
            ->willReturn($response);

        $http = new HttpClient($client);
        $res = $http->fetch($url);

        $this->assertEquals($url, $res['effective_url']);
        $this->assertEquals('yay', $res['body']);
    }
}
