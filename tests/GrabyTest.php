<?php

namespace Tests\Graby;

use Graby\Graby;

class GrabyTest extends \PHPUnit_Framework_TestCase
{
    public function testConstructDefault()
    {
        $graby = new Graby(array('debug' => true));

        $this->assertTrue($graby->getConfig('debug'));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage No config found for key:
     */
    public function testGetBadConfig()
    {
        $graby = new Graby();

        $graby->getConfig('does_not_exists');
    }

    public function dataForConfigOverride()
    {
        return array(
            array('http_client', array('http_client' => array('rewrite_url' => array('dummy.io' => array('/foo' => '/bar'), 'docs.google.com' => array('/foo' => '/bar'))))),
        );
    }

    /**
     * @dataProvider dataForConfigOverride
     */
    public function testConfigOverride($key, $config)
    {
        $graby = new Graby($config);

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

            preg_match('/-----URL-----\s*(.*?)\s*-----URL_EFFECTIVE-----\s*(.*?)\s*-----HEADER-----\s*(.*?)\s*-----LANGUAGE-----\s*(.*?)\s*-----TITLE-----\s*(.*?)\s*-----SUMMARY-----\s*(.*?)\s*-----RAW_CONTENT-----\s*(.*?)\s*-----PARSED_CONTENT-----\s*(.*?)\s*-----PARSED_CONTENT_WITHOUT_TIDY-----\s*(.*)/sx', $test, $match);

            $tests[] = array(
                $match[1], // url
                $match[2], // url effective
                $match[3], // header
                $match[4], // language
                $match[5], // title
                $match[6], // summary
                $match[7], // raw content
                $match[8], // parsed content
                $match[9], // parsed content without tidy
            );
        }

        return $tests;
    }

    /**
     * @dataProvider dataForFetchContent
     */
    public function testFetchContent($url, $urlEffective, $header, $language, $title, $summary, $rawContent, $parsedContent, $parsedContentWithoutTidy)
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
            ->method('getBody')
            ->willReturn($rawContent);

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getHeader')
            ->will($this->returnCallback(function ($parameter) use ($header) {
                switch ($parameter) {
                    case 'Content-Type':
                        return $header;
                }
            }));

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->with($url)
            ->willReturn($response);

        $graby = new Graby(array('extractor' => array('config_builder' => array(
            'site_config' => array(dirname(__FILE__).'/fixtures/site_config'),
        ))), $client);

        $res = $graby->fetchContent($url);

        $this->assertCount(8, $res);
        $this->assertEquals($language, $res['language']);
        $this->assertEquals($urlEffective, $res['url'], 'Same url');
        $this->assertEquals($title, $res['title'], 'Same title');
        $this->assertEquals($summary, $res['summary'], 'Same summary');

        if (function_exists('tidy_parse_string')) {
            $this->assertEquals($parsedContent, $res['html'], 'Same html');
        } else {
            $this->assertEquals($parsedContentWithoutTidy, $res['html'], 'Same html');
        }

        $this->assertEquals('text/html', $res['content_type']);

        // blogger doesn't have OG data, but lemonde has
        if (empty($res['open_graph'])) {
            $this->assertEquals(array(), $res['open_graph']);
        } else {
            $this->assertArrayHasKey('og_site_name', $res['open_graph']);
            $this->assertArrayHasKey('og_locale', $res['open_graph']);
            $this->assertArrayHasKey('og_url', $res['open_graph']);
            $this->assertArrayHasKey('og_title', $res['open_graph']);
            $this->assertArrayHasKey('og_description', $res['open_graph']);
            $this->assertArrayHasKey('og_image', $res['open_graph']);
            $this->assertArrayHasKey('og_image_width', $res['open_graph']);
            $this->assertArrayHasKey('og_image_height', $res['open_graph']);
            $this->assertArrayHasKey('og_image_type', $res['open_graph']);
            $this->assertArrayHasKey('og_type', $res['open_graph']);
        }
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

        $graby = new Graby(array(
            'allowed_urls' => array('wikipedia.org', 'wikimedia.com'),
        ), $client);

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
        $graby = new Graby(array(
            'blocked_urls' => array('t411.io', 'lexpress.fr'),
        ));

        $graby->fetchContent($url);
    }

    public function dataForNotValid()
    {
        return array(
            array('http://lexpress devant.fr'),
            array('http://user@:80'),
            array('http://cest^long.fr'),
        );
    }

    /**
     * @dataProvider dataForNotValid
     *
     * @expectedException Exception
     * @expectedExceptionMessage is not valid.
     */
    public function testNotValidUrls($url)
    {
        $graby = new Graby();
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

        $graby = new Graby(array(
            'blocked_urls' => array('t411.io'),
        ), $client);

        $graby->fetchContent('lexpress.io');
    }

    public function testMimeTypeActionLink()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->once())
            ->method('getEffectiveUrl')
            ->willReturn('http://lexpress.io/my awesome image.jpg');

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('image/jpeg');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby(array(), $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(8, $res);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('Image', $res['title']);
        $this->assertEquals('<a href="http://lexpress.io/my%20awesome%20image.jpg"><img src="http://lexpress.io/my%20awesome%20image.jpg" alt="Image" /></a>', $res['html']);
        $this->assertEquals('http://lexpress.io/my%20awesome%20image.jpg', $res['url']);
        $this->assertEmpty($res['summary']);
        $this->assertEquals('image/jpeg', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
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

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

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

        $graby = new Graby(array(
            'content_type_exc' => array(
               'application/x-msdownload' => array('action' => 'exclude', 'name' => 'we do not want virus'),
            ),
        ), $client);

        $graby->fetchContent('http://lexpress.io/virus.exe');
    }

    public function dataForExtension()
    {
        return array(
            array('http://lexpress.io/test.jpg', 'image/jpeg', 'Image', '', '<a href="http://lexpress.io/test.jpg"><img src="http://lexpress.io/test.jpg" alt="Image" /></a>'),
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
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn($header);

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('head')
            ->willReturn($response);

        $graby = new Graby(array(), $client);

        $res = $graby->fetchContent($url);

        $this->assertCount(8, $res);
        $this->assertEquals('', $res['language']);
        $this->assertEquals($title, $res['title']);
        $this->assertEquals($html, $res['html']);
        $this->assertEquals($url, $res['url']);
        $this->assertEquals($summary, $res['summary']);
        $this->assertEquals($header, $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
    }

    public function testAssetExtensionPDF()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        // hacking stuff to avoid to mock the file_get_contents from PdfParser->parseFile()
        $response->expects($this->once())
            ->method('getEffectiveUrl')
            ->willReturn(dirname(__FILE__).'/fixtures/document1.pdf');

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('application/pdf');

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('head')
            ->willReturn($response);

        $graby = new Graby(array(), $client);

        $res = $graby->fetchContent('http://lexpress.io/test.pdf');

        $this->assertCount(8, $res);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('Document1', $res['title']);
        $this->assertContains('Document title', $res['html']);
        $this->assertContains('Morbi vulputate tincidunt ve nenatis.', $res['html']);
        $this->assertContains('fixtures/document1.pdf', $res['url']);
        $this->assertContains('Document title Calibri : Lorem ipsum dolor sit amet', $res['summary']);
        $this->assertEquals('application/pdf', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
    }

    public function testAssetExtensionZIP()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->exactly(2))
            ->method('getEffectiveUrl')
            ->willReturn('https://github.com/nathanaccidentally/Cydia-Repo-Template/archive/master.zip');

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('application/zip');

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('get')
            ->willReturn($response);

        $client->expects($this->once())
            ->method('head')
            ->willReturn($response);

        $graby = new Graby(array(), $client);

        $res = $graby->fetchContent('https://github.com/nathanaccidentally/Cydia-Repo-Template/archive/master.zip');

        $this->assertCount(8, $res);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('ZIP', $res['title']);
        $this->assertContains('<a href="https://github.com/nathanaccidentally/Cydia-Repo-Template/archive/master.zip">Download ZIP</a>', $res['html']);
        $this->assertEquals('application/zip', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
    }

    public function testAssetExtensionPDFWithArrayDetails()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        // hacking stuff to avoid to mock the file_get_contents from PdfParser->parseFile()
        $response->expects($this->once())
            ->method('getEffectiveUrl')
            ->willReturn(dirname(__FILE__).'/fixtures/Good_Product_Manager_Bad_Product_Manager_KV.pdf');

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('application/pdf');

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('head')
            ->willReturn($response);

        $graby = new Graby(array(), $client);

        $res = $graby->fetchContent('http://lexpress.io/test.pdf');

        $this->assertCount(8, $res);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('Microsoft Word - Good_Product_Manager_Bad_Product_Manager_KV.doc', $res['title']);
        $this->assertContains('Good Product Manager Bad Product Manager By Ben Horowitz and David Weiden', $res['html']);
        $this->assertContains('fixtures/Good_Product_Manager_Bad_Product_Manager_KV.pdf', $res['url']);
        $this->assertContains('Good Product Manager Bad Product Manager By Ben Horowitz and David Weiden', $res['summary']);
        $this->assertEquals('application/pdf', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
    }

    public function testAssetExtensionTXT()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->once())
            ->method('getEffectiveUrl')
            ->willReturn('http://lexpress.io/test.txt');

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('text/plain');

        $response->expects($this->any())
            ->method('getBody')
            ->willReturn('plain text :)');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby(array(), $client);

        $res = $graby->fetchContent('http://lexpress.io/test.txt');

        $this->assertCount(8, $res);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('Plain text', $res['title']);
        $this->assertEquals('<pre>plain text :)</pre>', $res['html']);
        $this->assertEquals('http://lexpress.io/test.txt', $res['url']);
        $this->assertEquals('plain text :)', $res['summary']);
        $this->assertEquals('text/plain', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
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
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getBody')
            ->willReturn('<html><h1 class="print-title">my title</h1><div class="print-submitted">my content</div><ul><li class="service-links-print"><a href="'.$singlePageUrl.'" class="service-links-print">printed view</a></li></ul></html>');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby(array('content_links' => 'footnotes', 'extractor' => array('config_builder' => array(
            'site_config' => array(dirname(__FILE__).'/fixtures/site_config'),
        ))), $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(8, $res);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('my title', $res['title']);
        $this->assertEquals('my content', $res['html']);
        $this->assertEquals('http://'.$url, $res['url']);
        $this->assertEquals('my content', $res['summary']);
        $this->assertEquals('text/html', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
    }

    public function testSinglePageMimeAction()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->any())
            ->method('getEffectiveUrl')
            ->willReturn('http://singlepage1.com/data.jpg');

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->exactly(2))
            ->method('getHeader')
            ->will($this->onConsecutiveCalls(
                'text/html',
                'image/jpeg'
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

        $graby = new Graby(array('extractor' => array('config_builder' => array(
            'site_config' => array(dirname(__FILE__).'/fixtures/site_config'),
        ))), $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(8, $res);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('Image', $res['title']);
        $this->assertEquals('<a href="http://singlepage1.com/data.jpg"><img src="http://singlepage1.com/data.jpg" alt="Image" /></a>', $res['html']);
        $this->assertEquals('http://singlepage1.com/data.jpg', $res['url']);
        $this->assertEquals('', $res['summary']);
        $this->assertEquals('image/jpeg', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
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
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('text/html');

        $response->expects($this->any())
            ->method('getBody')
            ->will($this->onConsecutiveCalls(
                '<html><h2 class="primary">my title</h2><div class="story">my content</div><ul><li class="next"><a href="multiplepage1.com">next page</a></li></ul></html>',
                '<html><h2 class="primary">my title</h2><div class="story">my content</div></html>'
            ));

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby(array('content_links' => 'footnotes', 'extractor' => array('config_builder' => array(
            'site_config' => array(dirname(__FILE__).'/fixtures/site_config'),
        ))), $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(8, $res);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('my title', $res['title']);
        $this->assertEquals('my content<div class="story">my content</div>', $res['html']);
        $this->assertEquals('http://multiplepage1.com', $res['url']);
        $this->assertEquals('my content my content', $res['summary']);
        $this->assertEquals('text/html', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
    }

    public function testMultiplePageMimeAction()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->exactly(2))
            ->method('getEffectiveUrl')
            ->willReturn('http://multiplepage1.com');

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->exactly(2))
            ->method('getHeader')
            ->will($this->onConsecutiveCalls(
                'text/html',
                'application/pdf'
            ));

        $response->expects($this->exactly(2))
            ->method('getBody')
            ->will($this->onConsecutiveCalls(
                '<html><h2 class="primary">my title</h2><div class="story">my content</div><ul><li class="next"><a href="multiplepage1.com/data.pdf">next page</a></li></ul></html>',
                '<html><h2 class="primary">my title</h2><div class="story">my content</div></html>'
            ));

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('get')
            ->willReturn($response);

        $client->expects($this->once())
            ->method('head')
            ->willReturn($response);

        $graby = new Graby(array('content_links' => 'footnotes', 'extractor' => array('config_builder' => array(
            'site_config' => array(dirname(__FILE__).'/fixtures/site_config'),
        ))), $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(8, $res);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('my title', $res['title']);
        $this->assertContains('This article appears to continue on subsequent pages which we could not extract', $res['html']);
        $this->assertEquals('http://multiplepage1.com', $res['url']);
        $this->assertEquals('my content This article appears to continue on subsequent pages which we could not extract', $res['summary']);
        $this->assertEquals('application/pdf', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
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
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('text/html');

        $response->expects($this->any())
            ->method('getBody')
            ->will($this->onConsecutiveCalls(
                '<html><h2 class="primary">my title</h2><div class="story">my content</div><ul><li class="next"><a href="multiplepage1.com">next page</a></li></ul></html>',
                ''
            ));

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby(array('content_links' => 'footnotes', 'extractor' => array('config_builder' => array(
            'site_config' => array(dirname(__FILE__).'/fixtures/site_config'),
        ))), $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(8, $res);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('my title', $res['title']);
        $this->assertContains('This article appears to continue on subsequent pages which we could not extract', $res['html']);
        $this->assertEquals('http://multiplepage1.com', $res['url']);
        $this->assertEquals('my content This article appears to continue on subsequent pages which we could not extract', $res['summary']);
        $this->assertEquals('text/html', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
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
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('text/html');

        $response->expects($this->any())
            ->method('getBody')
            ->will($this->onConsecutiveCalls(
                '<html><h2 class="primary">my title</h2><div class="story">my content</div><ul><li class="next"><a href="/:/">next page</a></li></ul></html>',
                '<html><h2 class="primary">my title</h2><div class="story">my content</div></html>'
            ));

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby(array('content_links' => 'footnotes', 'extractor' => array('config_builder' => array(
            'site_config' => array(dirname(__FILE__).'/fixtures/site_config'),
        ))), $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(8, $res);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('my title', $res['title']);
        $this->assertContains('This article appears to continue on subsequent pages which we could not extract', $res['html']);
        $this->assertEquals('http://multiplepage1.com', $res['url']);
        $this->assertEquals('my content This article appears to continue on subsequent pages which we could not extract', $res['summary']);
        $this->assertEquals('text/html', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
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
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('text/html');

        $response->expects($this->any())
            ->method('getBody')
            ->will($this->onConsecutiveCalls(
                '<html><h2 class="primary">my title</h2><div class="story">my content</div><ul><li class="next"><a href="http://multiplepage1.com">next page</a></li></ul></html>',
                '<html><h2 class="primary">my title</h2><div class="story">my content</div><ul><li class="next"><a href="http://multiplepage1.com">next page</a></li></ul></html>'
            ));

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby(array('content_links' => 'footnotes', 'extractor' => array('config_builder' => array(
            'site_config' => array(dirname(__FILE__).'/fixtures/site_config'),
        ))), $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(8, $res);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('my title', $res['title']);
        $this->assertContains('This article appears to continue on subsequent pages which we could not extract', $res['html']);
        $this->assertEquals('http://multiplepage1.com', $res['url']);
        $this->assertEquals('my content This article appears to continue on subsequent pages which we could not extract', $res['summary']);
        $this->assertEquals('text/html', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
    }

    public function dataForExcerpt()
    {
        return array(
            array('hello you are fine', 35, null, 'hello you are fine'),
            array('hello you are fine ok ?', 14, null, 'hello you are fine'),
            // breakpoint in on the last word, won't add separator
            array('hello you are fine', 16, '...', 'hello you are fine'),
            array('hello "you" are fine', 15, '...', 'hello "you" are...'),
            array('hello <p>you</p> are fine', 13, '...', 'hello you are...'),
            array("hello you\n are fine", 13, '...', 'hello you are...'),
            array(chr(0xc2).chr(0xa0).'hello you are fine', 13, '...', 'hello you are...'),
            array('hello you are fine'.chr(0xc2).chr(0xa0), 13, '...', 'hello you are...'),
        );
    }

    /**
     * @dataProvider dataForExcerpt
     */
    public function testGetExcerpt($text, $length, $separator, $expectedResult)
    {
        $graby = new Graby();

        $reflection = new \ReflectionClass(get_class($graby));
        $method = $reflection->getMethod('getExcerpt');
        $method->setAccessible(true);

        $res = $method->invokeArgs($graby, array($text, $length, $separator));

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
        $graby = new Graby();

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
            array('http://example.org', '<iframe src="/lol" />', 'src', 'src', 'http://example.org/lol'),
        );
    }

    /**
     * @dataProvider dataForMakeAbsoluteAttr
     */
    public function testMakeAbsoluteAttr($base, $string, $attr, $expectedAttr, $expectedResult)
    {
        $graby = new Graby();

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
            array('http://example.org', '<img src="//domain.com/lol.jpg">test</img>', 'src', 'http://domain.com/lol.jpg'),
            array('http://example.org', '<img src=" /path/to/image.jpg" />', 'src', 'http://example.org/path/to/image.jpg'),
            array('http://example.org', '<a href="/lol">test</a>', 'src', ''),
        );
    }

    /**
     * @dataProvider dataForMakeAbsolute
     */
    public function testMakeAbsolute($base, $string, $expectedAttr, $expectedResult)
    {
        $graby = new Graby();

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
        $graby = new Graby();

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

    public function testAvoidDataUriImageInOpenGraph()
    {
        $graby = new Graby();

        $reflection = new \ReflectionClass(get_class($graby));
        $method = $reflection->getMethod('extractOpenGraph');
        $method->setAccessible(true);

        $ogData = $method->invokeArgs(
            $graby,
            array(
                '<html><meta content="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" property="og:image" /><meta content="http://www.io.lol" property="og:url"/></html>',
                'http://www.io.lol',
            )
        );

        $this->assertCount(1, $ogData);
        $this->assertArrayHasKey('og_url', $ogData);
        $this->assertEquals('http://www.io.lol', $ogData['og_url']);
        $this->assertFalse(isset($ogData['og_image']), 'og_image key does not exist');
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
            ->method('getStatusCode')
            ->willReturn(200);

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

        $graby = new Graby(array('content_links' => 'remove'), $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(8, $res);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('No title found', $res['title']);
        $this->assertContains('<p>'.str_repeat('This is an awesome text with some links, here there are the awesome', 7).' links :)</p>', $res['html']);
        $this->assertEquals('http://removelinks.io', $res['url']);
        $this->assertEquals('This is an awesome text with some links, here there are the awesomeThis is an awesome text with some links, here there are the awesomeThis is an awesome text with some links, here there are the awesomeThis is an awesome text with some links, here there &hellip;', $res['summary']);
        $this->assertEquals('text/html', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
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
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('application/pdf');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby(array('content_type_exc' => array('application/pdf' => array('action' => 'delete', 'name' => 'PDF'))), $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(8, $res);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('No title found', $res['title']);
        $this->assertEquals('[unable to retrieve full-text content]', $res['html']);
        $this->assertEquals('http://lexpress.io', $res['url']);
        $this->assertEquals('[unable to retrieve full-text content]', $res['summary']);
        $this->assertEquals('application/pdf', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
    }

    public function dataForSafeCurl()
    {
        return array(
            array('http://0.0.0.0:123'),
            array('http://127.0.0.1/server-status'),
            array('file:///etc/passwd'),
            array('ssh://localhost'),
            array('gopher://localhost'),
            array('telnet://localhost:25'),
            array('http://169.254.169.254/latest/meta-data/'),
            array('ftp://myhost.com'),
        );
    }

    /**
     * @dataProvider dataForSafeCurl
     */
    public function testBlockedUrlBySafeCurl($url)
    {
        $graby = new Graby();
        $res = $graby->fetchContent($url);

        $this->assertCount(8, $res);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('No title found', $res['title']);
        $this->assertEquals('[unable to retrieve full-text content]', $res['html']);
        $this->assertEquals('[unable to retrieve full-text content]', $res['summary']);
        $this->assertEquals('', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
        $this->assertEquals(500, $res['status']);
    }

    public function testErrorMessages()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->once())
            ->method('getEffectiveUrl')
            ->willReturn('http://lexpress.io');

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(400);

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby(array(
            'error_message' => 'Nothing found, hu?',
            'error_message_title' => 'No title detected',
        ), $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(8, $res);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('No title detected', $res['title']);
        $this->assertEquals('Nothing found, hu?', $res['html']);
        $this->assertEquals('http://lexpress.io', $res['url']);
        $this->assertEquals('Nothing found, hu?', $res['summary']);
        $this->assertEquals('', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
    }

    public function dataWithAccent()
    {
        return array(
            'host with accent' => array('http://pérotin.com/post/2009/06/09/SAV-Free-un-sketch-kafkaien', 'http://xn--protin-bva.com/post/2009/06/09/SAV-Free-un-sketch-kafkaien'),
            'url with accent 1' => array('https://en.wikipedia.org/wiki/Café', 'https://en.wikipedia.org/wiki/Caf%C3%A9'),
            'url with accent 2' => array('http://www.atterres.org/article/budget-2016-les-10-méprises-libérales-du-gouvernement', 'http://www.atterres.org/article/budget-2016-les-10-m%C3%A9prises-lib%C3%A9rales-du-gouvernement'),
            'url with accent 3' => array('http://www.pro-linux.de/news/1/23430/linus-torvalds-über-das-internet-der-dinge.html', 'http://www.pro-linux.de/news/1/23430/linus-torvalds-%C3%BCber-das-internet-der-dinge.html'),
        );
    }

    /**
     * @dataProvider dataWithAccent
     */
    public function testUrlWithAccent($url, $urlExpected)
    {
        $graby = new Graby();

        $reflection = new \ReflectionClass(get_class($graby));
        $method = $reflection->getMethod('validateUrl');
        $method->setAccessible(true);

        $res = $method->invokeArgs($graby, array($url));

        $this->assertEquals($urlExpected, $res);
    }

    public function testAbsolutePreviewInOgImage()
    {
        $graby = new Graby();

        $reflection = new \ReflectionClass(get_class($graby));
        $method = $reflection->getMethod('extractOpenGraph');
        $method->setAccessible(true);

        $ogData = $method->invokeArgs(
            $graby,
            array(
                '<html><meta content="/assets/lol.jpg" property="og:image" /><meta content="http://www.io.lol" property="og:url"/></html>',
                'http://www.io.lol',
            )
        );

        $this->assertCount(2, $ogData);
        $this->assertArrayHasKey('og_url', $ogData);
        $this->assertEquals('http://www.io.lol', $ogData['og_url']);
        $this->assertEquals('http://www.io.lol/assets/lol.jpg', $ogData['og_image']);
    }
}
