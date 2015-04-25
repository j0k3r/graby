<?php

namespace Tests\FullText;

use FullText\FullText;
use GuzzleHttp\Client;

class FullTextTest extends \PHPUnit_Framework_TestCase
{
    public function testConstructDefault()
    {
        $fullText = new FullText(new Client(), array('debug' => true));

        $this->assertTrue($fullText->getDebug());
        $this->assertTrue($fullText->getConfig('debug'));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage No config found for key:
     */
    public function testGetBadConfig()
    {
        $fullText = new FullText(new Client(), array());

        $fullText->getConfig('does_not_exists');
    }

    public function dataForConfigOverride()
    {
        return array(
            array('http_client', array('http_client' => array('rewrite_url' => array('dummy.io' => array('/foo' => '/bar'),'docs.google.com' => array('/foo' => '/bar'))))),
        );
    }

    /**
     * @dataProvider dataForConfigOverride
     */
    public function testConfigOverride($key, $config)
    {
        $fullText = new FullText(new Client(), $config);

        $this->assertEquals($config[$key], $fullText->getConfig($key));
    }

    /**
     * Parsing method inspired from Twig_Test_IntegrationTestCase.
     */
    public function dataForFetchContent()
    {
        $tests = array();

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/fixtures/sites/'), \RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            if (!preg_match('/\.test$/', $file)) {
                continue;
            }

            $test = file_get_contents($file->getRealpath());

            preg_match('/-----URL-----\s*(.*?)\s*-----URL_EFFECTIVE-----\s*(.*?)\s*-----HEADER-----\s*(.*?)\s*-----TITLE-----\s*(.*?)\s*-----SUMMARY-----\s*(.*?)\s*-----RAW_CONTENT-----\s*(.*?)\s*-----PARSED_CONTENT-----\s*(.*)/sx', $test, $match);

            $tests[] = array($match[1], $match[2], $match[3], $match[4], $match[5], $match[6], $match[7]);
        }

        return $tests;
    }

    /**
     * @dataProvider dataForFetchContent
     */
    public function testFetchContent($url, $urlEffective, $header, $title, $summary, $rawContent, $parsedContent)
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->any())
            ->method('getEffectiveUrl')
            ->willReturn($urlEffective);

        $response->expects($this->any())
            ->method('getBody')
            ->willReturn($rawContent);

        $response->expects($this->any())
            ->method('getHeader')
            ->will($this->returnCallback(function ($parameter) use ($header) {
                switch ($parameter) {
                    case 'Content-Type':
                        return $header;

                    case 'Content-Encoding':
                        return 'text';
                }
            }));

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->with($url)
            ->willReturn($response);

        $fullText = new FullText($client);

        $res = $fullText->fetchContent($url);

        $this->assertEquals($urlEffective, $res['url'], 'Same url');
        $this->assertEquals($title, $res['title'], 'Same title');
        $this->assertEquals($summary, $res['summary'], 'Same summary');
        $this->assertEquals($parsedContent, $res['html'], 'Same html');
    }

    public function dataForAllowed()
    {
        return array(
            array('feed://wikipedia.org', 'http://wikipedia.org'),
            array('www.wikipedia.org', 'http://www.wikipedia.org'),
        );
    }

    /**
     * @dataProvider dataForAllowed
     */
    public function testAllowedUrls($url, $urlChanged)
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('get')
            ->with($urlChanged)
            ->willReturn($response);

        $fullText = new FullText($client, array(
            'allowed_urls' => array('wikipedia.org', 'wikimedia.com'),
        ));

        $fullText->fetchContent($url);
    }

    public function dataForBlocked()
    {
        return array(
            array('feed://lexpress.fr'),
            array('www.t411.io'),
        );
    }

    /**
     * @dataProvider dataForBlocked
     *
     * @expectedException Exception
     * @expectedExceptionMessage is not allowed to be parsed.
     */
    public function testBlockedUrls($url)
    {
        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $fullText = new FullText($client, array(
            'blocked_urls' => array('t411.io', 'lexpress.fr'),
        ));

        $fullText->fetchContent($url);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage is not allowed to be parsed.
     */
    public function testBlockedUrlsAfterFetch()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->once())
            ->method('getEffectiveUrl')
            ->willReturn('t411.io');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('get')
            ->willReturn($response);

        $fullText = new FullText($client, array(
            'blocked_urls' => array('t411.io'),
        ));

        $fullText->fetchContent('lexpress.io');
    }

    public function testMimeTypeActionLink()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->once())
            ->method('getEffectiveUrl')
            ->willReturn('http://lexpress.io');

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('image/jpeg');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('get')
            ->willReturn($response);

        $fullText = new FullText($client);

        $res = $fullText->fetchContent('lexpress.io');

        $this->assertEquals('Image', $res['title']);
        $this->assertEquals('<a href="http://lexpress.io"><img src="http://lexpress.io" alt="Image" /></a>', $res['html']);
        $this->assertEquals('http://lexpress.io', $res['url']);
        $this->assertEmpty($res['summary']);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage is blocked by mime action.
     */
    public function testMimeTypeActionExclude()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('application/x-msdownload');

        $response->expects($this->exactly(2))
            ->method('getEffectiveUrl')
            ->willReturn('http://lexpress.io/virus.exe');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('head')
            ->willReturn($response);

        $client->expects($this->once())
            ->method('get')
            ->willReturn($response);

        $fullText = new FullText($client, array(
            'content_type_exc' => array(
               'application/x-msdownload' => array('action' => 'exclude', 'name' => 'we do not want virus'),
            ),
        ));

        $res = $fullText->fetchContent('http://lexpress.io/virus.exe');

        $this->assertEquals('Image', $res['title']);
        $this->assertEquals('<a href="http://lexpress.io"><img src="http://lexpress.io" alt="Image" /></a>', $res['html']);
        $this->assertEquals('http://lexpress.io', $res['url']);
        $this->assertEmpty($res['summary']);
    }

    public function dataForExtension()
    {
        return array(
            array('http://lexpress.io/test.jpg', 'image/jpeg', 'Image', '', '<a href="http://lexpress.io/test.jpg"><img src="http://lexpress.io/test.jpg" alt="Image" /></a>'),
            array('http://lexpress.io/test.pdf', 'application/pdf', 'PDF', 'Download PDF', '<a href="http://lexpress.io/test.pdf">Download PDF</a>'),
        );
    }

    /**
     * @dataProvider dataForExtension
     */
    public function testAssetExtension($url, $header, $title, $summary, $html)
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->once())
            ->method('getEffectiveUrl')
            ->willReturn($url);

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn($header);

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('head')
            ->willReturn($response);

        $fullText = new FullText($client);

        $res = $fullText->fetchContent($url);

        $this->assertEquals($title, $res['title']);
        $this->assertEquals($html, $res['html']);
        $this->assertEquals($url, $res['url']);
        $this->assertEquals($summary, $res['summary']);
    }
}
