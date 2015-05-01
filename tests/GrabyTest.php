<?php

namespace Tests\Graby;

use Graby\Graby;
use GuzzleHttp\Client;

class GrabyTest extends \PHPUnit_Framework_TestCase
{
    public function testConstructDefault()
    {
        $graby = new Graby(new Client(), array('debug' => true));

        $this->assertTrue($graby->getDebug());
        $this->assertTrue($graby->getConfig('debug'));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage No config found for key:
     */
    public function testGetBadConfig()
    {
        $graby = new Graby(new Client(), array());

        $graby->getConfig('does_not_exists');
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
        $graby = new Graby(new Client(), $config);

        $this->assertEquals($config[$key], $graby->getConfig($key));
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

        $graby = new Graby($client);

        $res = $graby->fetchContent($url);

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

        $graby = new Graby($client, array(
            'allowed_urls' => array('wikipedia.org', 'wikimedia.com'),
        ));

        $graby->fetchContent($url);
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

        $graby = new Graby($client, array(
            'blocked_urls' => array('t411.io', 'lexpress.fr'),
        ));

        $graby->fetchContent($url);
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

        $graby = new Graby($client, array(
            'blocked_urls' => array('t411.io'),
        ));

        $graby->fetchContent('lexpress.io');
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

        $graby = new Graby($client);

        $res = $graby->fetchContent('lexpress.io');

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

        $graby = new Graby($client, array(
            'content_type_exc' => array(
               'application/x-msdownload' => array('action' => 'exclude', 'name' => 'we do not want virus'),
            ),
        ));

        $res = $graby->fetchContent('http://lexpress.io/virus.exe');

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

        $graby = new Graby($client);

        $res = $graby->fetchContent($url);

        $this->assertEquals($title, $res['title']);
        $this->assertEquals($html, $res['html']);
        $this->assertEquals($url, $res['url']);
        $this->assertEquals($summary, $res['summary']);
    }

    public function dataForSinglePage()
    {
        return array(
            // single_page_link will return a string
            array('singlepage1.com'),
            // single_page_link will return the a node
            array('singlepage2.com'),
            // single_page_link will return the href from a node
            array('singlepage3.com'),
            // single_page_link will return nothing useful
            array('singlepage4.com'),
            // single_page_link will return the href from a node BUT the single page url will be the same
            array('singlepage3.com', 'http://singlepage3.com'),
        );
    }

    /**
     * @dataProvider dataForSinglePage
     */
    public function testSinglePage($url, $singlePageUrl = 'http://moreintelligentlife.com/print/content')
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->any())
            ->method('getEffectiveUrl')
            ->willReturn('http://'.$url);

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('text/html');

        $response->expects($this->any())
            ->method('getBody')
            ->willReturn('<html><h1 class="print-title">my title</h1><div class="print-submitted">my content</div><ul><li class="service-links-print"><a href="'.$singlePageUrl.'" class="service-links-print">printed view</a></li></ul></html>');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby($client, array('content_links' => 'footnotes', 'extractor' => array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/fixtures/site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/fixtures/site_config/standard',
        ))));

        $res = $graby->fetchContent('lexpress.io');

        $this->assertEquals('my title', $res['title']);
        $this->assertEquals('my content', $res['html']);
        $this->assertEquals('http://'.$url, $res['url']);
        $this->assertEquals('my content', $res['summary']);
    }

    public function testSinglePageMimeAction()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->any())
            ->method('getEffectiveUrl')
            ->willReturn('http://singlepage1.com/data.pdf');

        $response->expects($this->exactly(4))
            ->method('getHeader')
            ->will($this->onConsecutiveCalls(
                'text/html',
                '',
                'application/pdf',
                ''
            ));

        $response->expects($this->any())
            ->method('getBody')
            ->willReturn('<html><h1 class="print-title">my title</h1><div class="print-submitted">my content</div><ul><li class="service-links-print"><a href="http://moreintelligentlife.com/print/content" class="service-links-print">printed view</a></li></ul></html>');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby($client, array('extractor' => array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/fixtures/site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/fixtures/site_config/standard',
        ))));

        $res = $graby->fetchContent('lexpress.io');

        $this->assertEquals('PDF', $res['title']);
        $this->assertEquals('<a href="http://singlepage1.com/data.pdf">Download PDF</a>', $res['html']);
        $this->assertEquals('http://singlepage1.com/data.pdf', $res['url']);
        $this->assertEquals('Download PDF', $res['summary']);
    }

    public function testMultiplePageOk()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->any())
            ->method('getEffectiveUrl')
            ->willReturn('http://multiplepage1.com');

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('text/html');

        $response->expects($this->any())
            ->method('getBody')
            ->will($this->onConsecutiveCalls(
                '<html><h2 class="primary">my title</h2><div id="story">my content</div><ul><li class="next"><a href="multiplepage1.com">next page</a></li></ul></html>',
                '<html><h2 class="primary">my title</h2><div id="story">my content</div></html>'
            ));

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby($client, array('content_links' => 'footnotes', 'extractor' => array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/fixtures/site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/fixtures/site_config/standard',
        ))));

        $res = $graby->fetchContent('lexpress.io');

        $this->assertEquals('my title', $res['title']);
        $this->assertEquals('my content<div id="story">my content</div>', $res['html']);
        $this->assertEquals('http://multiplepage1.com', $res['url']);
    }

    public function testMultiplePageMimeAction()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->any())
            ->method('getEffectiveUrl')
            ->willReturn('http://multiplepage1.com');

        $response->expects($this->exactly(4))
            ->method('getHeader')
            ->will($this->onConsecutiveCalls(
                'text/html',
                '',
                'application/pdf',
                ''
            ));

        $response->expects($this->any())
            ->method('getBody')
            ->will($this->onConsecutiveCalls(
                '<html><h2 class="primary">my title</h2><div id="story">my content</div><ul><li class="next"><a href="multiplepage1.com/data.pdf">next page</a></li></ul></html>',
                '<html><h2 class="primary">my title</h2><div id="story">my content</div></html>'
            ));

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby($client, array('content_links' => 'footnotes', 'extractor' => array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/fixtures/site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/fixtures/site_config/standard',
        ))));

        $res = $graby->fetchContent('lexpress.io');

        $this->assertEquals('my title', $res['title']);
        $this->assertContains('This article appears to continue on subsequent pages which we could not extract', $res['html']);
        $this->assertEquals('http://multiplepage1.com', $res['url']);
    }

    public function testMultiplePageExtractFailed()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->any())
            ->method('getEffectiveUrl')
            ->willReturn('http://multiplepage1.com');

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('text/html');

        $response->expects($this->any())
            ->method('getBody')
            ->will($this->onConsecutiveCalls(
                '<html><h2 class="primary">my title</h2><div id="story">my content</div><ul><li class="next"><a href="multiplepage1.com">next page</a></li></ul></html>',
                ''
            ));

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby($client, array('content_links' => 'footnotes', 'extractor' => array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/fixtures/site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/fixtures/site_config/standard',
        ))));

        $res = $graby->fetchContent('lexpress.io');

        $this->assertEquals('my title', $res['title']);
        $this->assertContains('This article appears to continue on subsequent pages which we could not extract', $res['html']);
        $this->assertEquals('http://multiplepage1.com', $res['url']);
    }

    public function testMultiplePageBadAbsoluteUrl()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->any())
            ->method('getEffectiveUrl')
            ->willReturn('http://multiplepage1.com');

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('text/html');

        $response->expects($this->any())
            ->method('getBody')
            ->will($this->onConsecutiveCalls(
                '<html><h2 class="primary">my title</h2><div id="story">my content</div><ul><li class="next"><a href=".//oops :)">next page</a></li></ul></html>',
                '<html><h2 class="primary">my title</h2><div id="story">my content</div></html>'
            ));

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby($client, array('content_links' => 'footnotes', 'extractor' => array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/fixtures/site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/fixtures/site_config/standard',
        ))));

        $res = $graby->fetchContent('lexpress.io');

        $this->assertEquals('my title', $res['title']);
        $this->assertContains('This article appears to continue on subsequent pages which we could not extract', $res['html']);
        $this->assertEquals('http://multiplepage1.com', $res['url']);
    }

    public function testMultiplePageSameUrl()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->any())
            ->method('getEffectiveUrl')
            ->willReturn('http://multiplepage1.com');

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('text/html');

        $response->expects($this->any())
            ->method('getBody')
            ->will($this->onConsecutiveCalls(
                '<html><h2 class="primary">my title</h2><div id="story">my content</div><ul><li class="next"><a href="http://multiplepage1.com">next page</a></li></ul></html>',
                '<html><h2 class="primary">my title</h2><div id="story">my content</div><ul><li class="next"><a href="http://multiplepage1.com">next page</a></li></ul></html>'
            ));

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby($client, array('content_links' => 'footnotes', 'extractor' => array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/fixtures/site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/fixtures/site_config/standard',
        ))));

        $res = $graby->fetchContent('lexpress.io');

        $this->assertEquals('my title', $res['title']);
        $this->assertContains('This article appears to continue on subsequent pages which we could not extract', $res['html']);
        $this->assertEquals('http://multiplepage1.com', $res['url']);
    }

    public function dataForExcerpt()
    {
        return array(
            array('hello you are fine', 35, null, 'hello you are fine'),
            array('hello you are fine', 3, null, 'hello you are &hellip;'),
            array('hello "you" are fine', 3, '...', 'hello "you" are...'),
            array('hello <p>you</p> are fine', 3, '...', 'hello you are...'),
            array("hello you\n are fine", 3, '...', 'hello you are...'),
            array(chr(0xc2).chr(0xa0).'hello you are fine', 3, '...', 'hello you are...'),
            array('hello you are fine'.chr(0xc2).chr(0xa0), 3, '...', 'hello you are...'),
        );
    }

    /**
     * @dataProvider dataForExcerpt
     */
    public function testGetExcerpt($text, $words, $more, $expectedResult)
    {
        $graby = new Graby(new Client());

        $reflection = new \ReflectionClass(get_class($graby));
        $method = $reflection->getMethod('getExcerpt');
        $method->setAccessible(true);

        $res = $method->invokeArgs($graby, array($text, $words, $more));

        $this->assertEquals($expectedResult, $res);
    }

    public function dataForMakeAbsoluteStr()
    {
        return array(
            array('example.org', '/test', false),
            array('http://example.org', '/test', 'http://example.org/test'),
            array('http://example.org', '', false),
            array('http://example.org//test', 'super', 'http://example.org/super'),
            array('http://example.org//test', 'http://sample.com', 'http://sample.com'),
        );
    }

    /**
     * @dataProvider dataForMakeAbsoluteStr
     */
    public function testMakeAbsoluteStr($base, $url, $expectedResult)
    {
        $graby = new Graby(new Client());

        $reflection = new \ReflectionClass(get_class($graby));
        $method = $reflection->getMethod('makeAbsoluteStr');
        $method->setAccessible(true);

        $res = $method->invokeArgs($graby, array($base, $url));

        $this->assertEquals($expectedResult, $res);
    }

    public function dataForMakeAbsoluteAttr()
    {
        return array(
            array('http://example.org', '<a href="/lol">test</a>', 'href', 'href', 'http://example.org/lol'),
            array('http://example.org', '<img src="/lol.jpg">test</img>', 'src', 'src', 'http://example.org/lol.jpg'),
            array('http://example.org', '<img src=" /path/to/image.jpg" />', 'src', 'src', 'http://example.org/path/to/image.jpg'),
            array('http://example.org', '<a href="/lol">test</a>', 'src', 'src', ''),
        );
    }

    /**
     * @dataProvider dataForMakeAbsoluteAttr
     */
    public function testMakeAbsoluteAttr($base, $string, $attr, $expectedAttr, $expectedResult)
    {
        $graby = new Graby(new Client());

        $doc = new \DomDocument();
        $doc->loadXML($string);

        $e = $doc->firstChild;

        $reflection = new \ReflectionClass(get_class($graby));
        $method = $reflection->getMethod('makeAbsoluteAttr');
        $method->setAccessible(true);

        $method->invokeArgs($graby, array($base, $e, $attr));

        $this->assertEquals($expectedResult, $e->getAttribute($expectedAttr));
    }

    public function dataForMakeAbsolute()
    {
        return array(
            array('http://example.org', '<a href="/lol">test</a>', 'href', 'http://example.org/lol'),
            array('http://example.org', '<img src="/lol.jpg">test</img>', 'src', 'http://example.org/lol.jpg'),
            array('http://example.org', '<img src=" /path/to/image.jpg" />', 'src', 'http://example.org/path/to/image.jpg'),
            array('http://example.org', '<a href="/lol">test</a>', 'src', ''),
        );
    }

    /**
     * @dataProvider dataForMakeAbsolute
     */
    public function testMakeAbsolute($base, $string, $expectedAttr, $expectedResult)
    {
        $graby = new Graby(new Client());

        $doc = new \DomDocument();
        $doc->loadXML($string);

        $e = $doc->firstChild;

        $reflection = new \ReflectionClass(get_class($graby));
        $method = $reflection->getMethod('makeAbsolute');
        $method->setAccessible(true);

        $method->invokeArgs($graby, array($base, $e));

        $this->assertEquals($expectedResult, $e->getAttribute($expectedAttr));
    }

    /**
     * Test on nested element: image inside a link.
     */
    public function testMakeAbsoluteMultiple()
    {
        $graby = new Graby(new Client());

        $doc = new \DomDocument();
        $doc->loadXML('<a href="/lol"><img src=" /path/to/image.jpg" /></a>');

        $e = $doc->firstChild;

        $reflection = new \ReflectionClass(get_class($graby));
        $method = $reflection->getMethod('makeAbsolute');
        $method->setAccessible(true);

        $method->invokeArgs($graby, array('http://example.org', $e));

        $this->assertEquals('http://example.org/lol', $e->getAttribute('href'));
        $this->assertEquals('http://example.org/path/to/image.jpg', $e->firstChild->getAttribute('src'));
    }

    public function testContentLinksRemove()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->any())
            ->method('getEffectiveUrl')
            ->willReturn('http://removelinks.io');

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('text/html');

        $response->expects($this->any())
            ->method('getBody')
            ->willReturn('<article><p>'.str_repeat('This is an awesome text with some links, here there are the awesome', 7).' <a href="#links">links :)</a></p></article>');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby($client, array('content_links' => 'remove'));

        $res = $graby->fetchContent('lexpress.io');

        $this->assertEquals('<p>'.str_repeat('This is an awesome text with some links, here there are the awesome', 7).' links :)</p>', $res['html']);
        $this->assertEquals('http://removelinks.io', $res['url']);
        $this->assertEquals('This is an awesome text with some links, here there are the awesomeThis is an awesome text with some links, here there are the awesomeThis is an awesome text with some links, here there are the awesomeThis is an awesome text with some links, here there are the awesomeThis is an awesome text with some &hellip;', $res['summary']);
    }

    public function testMimeActionNotDefined()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->once())
            ->method('getEffectiveUrl')
            ->willReturn('http://lexpress.io');

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('application/pdf');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby($client, array('content_type_exc' => array('application/pdf' => array('action' => 'delete', 'name' => 'PDF'))));

        $res = $graby->fetchContent('lexpress.io');

        $this->assertEquals('', $res['title']);
        $this->assertEquals('[unable to retrieve full-text content]', $res['html']);
        $this->assertEquals('http://lexpress.io', $res['url']);
        $this->assertEquals('[unable to retrieve full-text content]', $res['summary']);
    }
}
