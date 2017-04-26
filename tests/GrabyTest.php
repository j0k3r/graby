<?php

namespace Tests\Graby;

use Graby\Graby;

class GrabyTest extends \PHPUnit_Framework_TestCase
{
    public function testConstructDefault()
    {
        $graby = new Graby(['debug' => true]);

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
        return [
            ['http_client', ['http_client' => ['rewrite_url' => ['dummy.io' => ['/foo' => '/bar'], 'docs.google.com' => ['/foo' => '/bar']]]]],
        ];
    }

    /**
     * @dataProvider dataForConfigOverride
     */
    public function testConfigOverride($key, $config)
    {
        $graby = new Graby($config);

        $this->assertSame($config[$key], $graby->getConfig($key));
    }

    /**
     * Parsing method inspired from Twig_Test_IntegrationTestCase.
     */
    public function dataForFetchContent()
    {
        $tests = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/fixtures/sites/'), \RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            if (!preg_match('/\.test$/', $file)) {
                continue;
            }

            $test = file_get_contents($file->getRealpath());

            preg_match('/-----URL-----\s*(.*?)\s*-----URL_EFFECTIVE-----\s*(.*?)\s*-----HEADER-----\s*(.*?)\s*-----LANGUAGE-----\s*(.*?)\s*-----AUTHOR-----\s*(.*?)\s*-----TITLE-----\s*(.*?)\s*-----SUMMARY-----\s*(.*?)\s*-----RAW_CONTENT-----\s*(.*?)\s*-----PARSED_CONTENT-----\s*(.*?)\s*-----PARSED_CONTENT_WITHOUT_TIDY-----\s*(.*)/sx', $test, $match);

            $tests[] = [
                $match[1], // url
                $match[2], // url effective
                $match[3], // header
                $match[4], // language
                $match[5], // author
                $match[6], // title
                $match[7], // summary
                $match[8], // raw content
                $match[9], // parsed content
                $match[10], // parsed content without tidy
            ];
        }

        return $tests;
    }

    /**
     * @dataProvider dataForFetchContent
     */
    public function testFetchContent($url, $urlEffective, $header, $language, $author, $title, $summary, $rawContent, $parsedContent, $parsedContentWithoutTidy)
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

        $graby = new Graby([
            'xss_filter' => false,
            'extractor' => [
                'config_builder' => [
                    'site_config' => [
                        __DIR__ . '/fixtures/site_config',
                    ],
                ],
            ],
        ], $client);

        $res = $graby->fetchContent($url);

        $this->assertCount(12, $res);

        if ($language) {
            $this->assertSame($language, $res['language']);
        } else {
            $this->assertEmpty($res['language']);
        }

        if ($author) {
            $this->assertSame([$author], $res['authors']);
        } else {
            $this->assertEmpty($res['authors']);
        }

        $this->assertSame($urlEffective, $res['url'], 'Same url');
        $this->assertSame($title, $res['title'], 'Same title');
        $this->assertSame($summary, $res['summary'], 'Same summary');

        if (function_exists('tidy_parse_string')) {
            $this->assertSame($parsedContent, $res['html'], 'Same html');
        } else {
            $this->assertSame($parsedContentWithoutTidy, $res['html'], 'Same html');
        }

        $this->assertSame('text/html', $res['content_type']);

        // blogger doesn't have OG data, but lemonde has
        if (empty($res['open_graph'])) {
            $this->assertSame([], $res['open_graph']);
            $this->assertFalse($res['native_ad']);
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
        return [
            ['feed://wikipedia.org', 'http://wikipedia.org'],
            ['www.wikipedia.org', 'http://www.wikipedia.org'],
        ];
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

        $graby = new Graby([
            'allowed_urls' => ['wikipedia.org', 'wikimedia.com'],
        ], $client);

        $graby->fetchContent($url);
    }

    public function dataForBlocked()
    {
        return [
            ['feed://lexpress.fr'],
            ['www.t411.io'],
        ];
    }

    /**
     * @dataProvider dataForBlocked
     *
     * @expectedException \Exception
     * @expectedExceptionMessage is not allowed to be parsed.
     */
    public function testBlockedUrls($url)
    {
        $graby = new Graby([
            'blocked_urls' => ['t411.io', 'lexpress.fr'],
        ]);

        $graby->fetchContent($url);
    }

    public function dataForNotValid()
    {
        return [
            ['http://lexpress devant.fr'],
            ['http://user@:80'],
            ['http://cest^long.fr'],
        ];
    }

    /**
     * @dataProvider dataForNotValid
     *
     * @expectedException \Exception
     * @expectedExceptionMessage is not valid.
     */
    public function testNotValidUrls($url)
    {
        $graby = new Graby();
        $graby->fetchContent($url);
    }

    /**
     * @expectedException \Exception
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

        $graby = new Graby([
            'blocked_urls' => ['t411.io'],
        ], $client);

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

        $graby = new Graby(['xss_filter' => false], $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(12, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('Image', $res['title']);
        $this->assertSame('<a href="http://lexpress.io/my%20awesome%20image.jpg"><img src="http://lexpress.io/my%20awesome%20image.jpg" alt="Image" /></a>', $res['html']);
        $this->assertSame('http://lexpress.io/my%20awesome%20image.jpg', $res['url']);
        $this->assertEmpty($res['summary']);
        $this->assertSame('image/jpeg', $res['content_type']);
        $this->assertSame([], $res['open_graph']);
        $this->assertFalse($res['native_ad']);
        $this->assertFalse($res['native_ad']);
    }

    /**
     * @expectedException \Exception
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

        $graby = new Graby([
            'content_type_exc' => [
               'application/x-msdownload' => ['action' => 'exclude', 'name' => 'we do not want virus'],
            ],
        ], $client);

        $graby->fetchContent('http://lexpress.io/virus.exe');
    }

    public function dataForExtension()
    {
        return [
            ['http://lexpress.io/test.jpg', 'image/jpeg', 'Image', '', '<a href="http://lexpress.io/test.jpg"><img src="http://lexpress.io/test.jpg" alt="Image" /></a>'],
        ];
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

        $graby = new Graby(['xss_filter' => false], $client);

        $res = $graby->fetchContent($url);

        $this->assertCount(12, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame($title, $res['title']);
        $this->assertSame($html, $res['html']);
        $this->assertSame($url, $res['url']);
        $this->assertSame($summary, $res['summary']);
        $this->assertSame($header, $res['content_type']);
        $this->assertSame([], $res['open_graph']);
        $this->assertFalse($res['native_ad']);
    }

    public function testAssetExtensionPDF()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->once())
            ->method('getEffectiveUrl')
            ->willReturn('http://lexpress.io/test.pdf');

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('application/pdf');

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getBody')
            ->willReturn(file_get_contents(__DIR__ . '/fixtures/document1.pdf'));

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby([], $client);

        $res = $graby->fetchContent('http://lexpress.io/test.pdf');

        $this->assertCount(12, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('Document1', $res['title']);
        $this->assertContains('Document title', $res['html']);
        $this->assertContains('Morbi vulputate tincidunt ve nenatis.', $res['html']);
        $this->assertContains('http://lexpress.io/test.pdf', $res['url']);
        $this->assertContains('Document title Calibri : Lorem ipsum dolor sit amet', $res['summary']);
        $this->assertSame('application/pdf', $res['content_type']);
        $this->assertSame([], $res['open_graph']);
        $this->assertFalse($res['native_ad']);
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

        $graby = new Graby([], $client);

        $res = $graby->fetchContent('https://github.com/nathanaccidentally/Cydia-Repo-Template/archive/master.zip');

        $this->assertCount(12, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('ZIP', $res['title']);
        $this->assertContains('<a href="https://github.com/nathanaccidentally/Cydia-Repo-Template/archive/master.zip">Download ZIP</a>', $res['html']);
        $this->assertSame('application/zip', $res['content_type']);
        $this->assertSame([], $res['open_graph']);
        $this->assertFalse($res['native_ad']);
    }

    public function testAssetExtensionPDFWithArrayDetails()
    {
        $response = $this->getMockBuilder('GuzzleHttp\Message\Response')
            ->disableOriginalConstructor()
            ->getMock();

        $response->expects($this->once())
            ->method('getEffectiveUrl')
            ->willReturn('http://lexpress.io/test.pdf');

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('application/pdf');

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getBody')
            ->willReturn(file_get_contents(__DIR__ . '/fixtures/Good_Product_Manager_Bad_Product_Manager_KV.pdf'));

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->once())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby([], $client);

        $res = $graby->fetchContent('http://lexpress.io/test.pdf');

        $this->assertCount(12, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('2011-12-20T21:58:48+00:00', $res['date']);
        $this->assertSame(['David Baca'], $res['authors']);
        $this->assertSame('Microsoft Word - Good_Product_Manager_Bad_Product_Manager_KV.doc', $res['title']);
        $this->assertContains('Good Product Manager Bad Product Manager By Ben Horowitz and David Weiden', $res['html']);
        $this->assertContains('http://lexpress.io/test.pdf', $res['url']);
        $this->assertContains('Good Product Manager Bad Product Manager By Ben Horowitz and David Weiden', $res['summary']);
        $this->assertSame('application/pdf', $res['content_type']);
        $this->assertSame([], $res['open_graph']);
        $this->assertFalse($res['native_ad']);
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

        $graby = new Graby([], $client);

        $res = $graby->fetchContent('http://lexpress.io/test.txt');

        $this->assertCount(12, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('Plain text', $res['title']);
        $this->assertSame('<pre>plain text :)</pre>', $res['html']);
        $this->assertSame('http://lexpress.io/test.txt', $res['url']);
        $this->assertSame('plain text :)', $res['summary']);
        $this->assertSame('text/plain', $res['content_type']);
        $this->assertSame([], $res['open_graph']);
        $this->assertFalse($res['native_ad']);
    }

    public function dataForSinglePage()
    {
        return [
            // single_page_link will return a string
            ['singlepage1.com'],
            // single_page_link will return the a node
            ['singlepage2.com'],
            // single_page_link will return the href from a node
            ['singlepage3.com'],
            // single_page_link will return nothing useful
            ['singlepage4.com'],
            // single_page_link will return the href from a node BUT the single page url will be the same
            ['singlepage3.com', 'http://singlepage3.com'],
        ];
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
            ->willReturn('http://' . $url);

        $response->expects($this->any())
            ->method('getHeader')
            ->willReturn('text/html');

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->any())
            ->method('getBody')
            ->willReturn('<html><h1 class="print-title">my title</h1><div class="print-submitted">my content</div><ul><li class="service-links-print"><a href="' . $singlePageUrl . '" class="service-links-print">printed view</a></li></ul></html>');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby(['content_links' => 'footnotes', 'extractor' => ['config_builder' => [
            'site_config' => [__DIR__ . '/fixtures/site_config'],
        ]]], $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(12, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('my title', $res['title']);
        $this->assertSame('my content', $res['html']);
        $this->assertSame('http://' . $url, $res['url']);
        $this->assertSame('my content', $res['summary']);
        $this->assertSame('text/html', $res['content_type']);
        $this->assertSame([], $res['open_graph']);
        $this->assertFalse($res['native_ad']);
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

        $graby = new Graby(['xss_filter' => false, 'extractor' => ['config_builder' => [
            'site_config' => [__DIR__ . '/fixtures/site_config'],
        ]]], $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(12, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('Image', $res['title']);
        $this->assertSame('<a href="http://singlepage1.com/data.jpg"><img src="http://singlepage1.com/data.jpg" alt="Image" /></a>', $res['html']);
        $this->assertSame('http://singlepage1.com/data.jpg', $res['url']);
        $this->assertSame('', $res['summary']);
        $this->assertSame('image/jpeg', $res['content_type']);
        $this->assertSame([], $res['open_graph']);
        $this->assertFalse($res['native_ad']);
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

        $graby = new Graby(['content_links' => 'footnotes', 'extractor' => ['config_builder' => [
            'site_config' => [__DIR__ . '/fixtures/site_config'],
        ]]], $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(12, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('my title', $res['title']);
        $this->assertSame('my content<div class="story">my content</div>', $res['html']);
        $this->assertSame('http://multiplepage1.com', $res['url']);
        $this->assertSame('my content my content', $res['summary']);
        $this->assertSame('text/html', $res['content_type']);
        $this->assertSame([], $res['open_graph']);
        $this->assertFalse($res['native_ad']);
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

        $client->expects($this->exactly(2))
            ->method('get')
            ->willReturn($response);

        $graby = new Graby(['content_links' => 'footnotes', 'extractor' => ['config_builder' => [
            'site_config' => [__DIR__ . '/fixtures/site_config'],
        ]]], $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(12, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('my title', $res['title']);
        $this->assertContains('This article appears to continue on subsequent pages which we could not extract', $res['html']);
        $this->assertSame('http://multiplepage1.com', $res['url']);
        $this->assertSame('my content This article appears to continue on subsequent pages which we could not extract', $res['summary']);
        $this->assertSame('application/pdf', $res['content_type']);
        $this->assertSame([], $res['open_graph']);
        $this->assertFalse($res['native_ad']);
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

        $graby = new Graby(['content_links' => 'footnotes', 'extractor' => ['config_builder' => [
            'site_config' => [__DIR__ . '/fixtures/site_config'],
        ]]], $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(12, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('my title', $res['title']);
        $this->assertContains('This article appears to continue on subsequent pages which we could not extract', $res['html']);
        $this->assertSame('http://multiplepage1.com', $res['url']);
        $this->assertSame('my content This article appears to continue on subsequent pages which we could not extract', $res['summary']);
        $this->assertSame('text/html', $res['content_type']);
        $this->assertSame([], $res['open_graph']);
        $this->assertFalse($res['native_ad']);
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

        $graby = new Graby(['content_links' => 'footnotes', 'extractor' => ['config_builder' => [
            'site_config' => [__DIR__ . '/fixtures/site_config'],
        ]]], $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(12, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('my title', $res['title']);
        $this->assertContains('This article appears to continue on subsequent pages which we could not extract', $res['html']);
        $this->assertSame('http://multiplepage1.com', $res['url']);
        $this->assertSame('my content This article appears to continue on subsequent pages which we could not extract', $res['summary']);
        $this->assertSame('text/html', $res['content_type']);
        $this->assertSame([], $res['open_graph']);
        $this->assertFalse($res['native_ad']);
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

        $graby = new Graby(['content_links' => 'footnotes', 'extractor' => ['config_builder' => [
            'site_config' => [__DIR__ . '/fixtures/site_config'],
        ]]], $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(12, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('my title', $res['title']);
        $this->assertContains('This article appears to continue on subsequent pages which we could not extract', $res['html']);
        $this->assertSame('http://multiplepage1.com', $res['url']);
        $this->assertSame('my content This article appears to continue on subsequent pages which we could not extract', $res['summary']);
        $this->assertSame('text/html', $res['content_type']);
        $this->assertSame([], $res['open_graph']);
        $this->assertFalse($res['native_ad']);
    }

    public function dataForExcerpt()
    {
        return [
            ['hello you are fine', 35, null, 'hello you are fine'],
            ['hello you are fine ok ?', 14, null, 'hello you are fine'],
            // breakpoint in on the last word, won't add separator
            ['hello you are fine', 16, '...', 'hello you are fine'],
            ['hello "you" are fine', 15, '...', 'hello "you" are...'],
            ['hello <p>you</p> are fine', 13, '...', 'hello you are...'],
            ["hello you\n are fine", 13, '...', 'hello you are...'],
            [chr(0xc2) . chr(0xa0) . 'hello you are fine', 13, '...', 'hello you are...'],
            ['hello you are fine' . chr(0xc2) . chr(0xa0), 13, '...', 'hello you are...'],
        ];
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

        $res = $method->invokeArgs($graby, [$text, $length, $separator]);

        $this->assertSame($expectedResult, $res);
    }

    public function dataForMakeAbsoluteStr()
    {
        return [
            ['example.org', '/test', false],
            ['http://example.org', '/test', 'http://example.org/test'],
            ['http://example.org', '', false],
            ['http://example.org//test', 'super', 'http://example.org/super'],
            ['http://example.org//test', 'http://sample.com', 'http://sample.com'],
        ];
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

        $res = $method->invokeArgs($graby, [$base, $url]);

        $this->assertSame($expectedResult, $res);
    }

    public function dataForMakeAbsoluteAttr()
    {
        return [
            ['http://example.org', '<a href="/lol">test</a>', 'href', 'href', 'http://example.org/lol'],
            ['http://example.org', '<img src="/lol.jpg">test</img>', 'src', 'src', 'http://example.org/lol.jpg'],
            ['http://example.org', '<img src=" /path/to/image.jpg" />', 'src', 'src', 'http://example.org/path/to/image.jpg'],
            ['http://example.org', '<a href="/lol">test</a>', 'src', 'src', ''],
            ['http://example.org', '<iframe src="/lol" />', 'src', 'src', 'http://example.org/lol'],
        ];
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

        $method->invokeArgs($graby, [$base, $e, $attr]);

        $this->assertSame($expectedResult, $e->getAttribute($expectedAttr));
    }

    public function dataForMakeAbsolute()
    {
        return [
            ['http://example.org', '<a href="/lol">test</a>', 'href', 'http://example.org/lol'],
            ['http://example.org', '<img src="/lol.jpg">test</img>', 'src', 'http://example.org/lol.jpg'],
            ['http://example.org', '<img src="//domain.com/lol.jpg">test</img>', 'src', 'http://domain.com/lol.jpg'],
            ['http://example.org', '<img src=" /path/to/image.jpg" />', 'src', 'http://example.org/path/to/image.jpg'],
            ['http://example.org', '<a href="/lol">test</a>', 'src', ''],
        ];
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

        $method->invokeArgs($graby, [$base, $e]);

        $this->assertSame($expectedResult, $e->getAttribute($expectedAttr));
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

        $method->invokeArgs($graby, ['http://example.org', $e]);

        $this->assertSame('http://example.org/lol', $e->getAttribute('href'));
        $this->assertSame('http://example.org/path/to/image.jpg', $e->firstChild->getAttribute('src'));
    }

    public function testAvoidDataUriImageInOpenGraph()
    {
        $graby = new Graby();

        $reflection = new \ReflectionClass(get_class($graby));
        $method = $reflection->getMethod('extractOpenGraph');
        $method->setAccessible(true);

        $ogData = $method->invokeArgs(
            $graby,
            [
                '<html><meta content="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" property="og:image" /><meta content="http://www.io.lol" property="og:url"/></html>',
                'http://www.io.lol',
            ]
        );

        $this->assertCount(1, $ogData);
        $this->assertArrayHasKey('og_url', $ogData);
        $this->assertSame('http://www.io.lol', $ogData['og_url']);
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
            ->willReturn('<article><p>' . str_repeat('This is an awesome text with some links, here there are the awesome', 7) . ' <a href="#links">links :)</a></p></article>');

        $client = $this->getMockBuilder('GuzzleHttp\Client')
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->willReturn($response);

        $graby = new Graby(['content_links' => 'remove'], $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(12, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('No title found', $res['title']);
        $this->assertContains('<p>' . str_repeat('This is an awesome text with some links, here there are the awesome', 7) . ' links :)</p>', $res['html']);
        $this->assertSame('http://removelinks.io', $res['url']);
        $this->assertSame('This is an awesome text with some links, here there are the awesomeThis is an awesome text with some links, here there are the awesomeThis is an awesome text with some links, here there are the awesomeThis is an awesome text with some links, here there &hellip;', $res['summary']);
        $this->assertSame('text/html', $res['content_type']);
        $this->assertSame([], $res['open_graph']);
        $this->assertFalse($res['native_ad']);
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

        $graby = new Graby(['content_type_exc' => ['application/pdf' => ['action' => 'delete', 'name' => 'PDF']]], $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(12, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('No title found', $res['title']);
        $this->assertSame('[unable to retrieve full-text content]', $res['html']);
        $this->assertSame('http://lexpress.io', $res['url']);
        $this->assertSame('[unable to retrieve full-text content]', $res['summary']);
        $this->assertSame('application/pdf', $res['content_type']);
        $this->assertSame([], $res['open_graph']);
        $this->assertFalse($res['native_ad']);
    }

    public function dataForSafeCurl()
    {
        return [
            ['http://0.0.0.0:123'],
            ['http://127.0.0.1/server-status'],
            ['file:///etc/passwd'],
            ['ssh://localhost'],
            ['gopher://localhost'],
            ['telnet://localhost:25'],
            ['http://169.254.169.254/latest/meta-data/'],
            ['ftp://myhost.com'],
        ];
    }

    /**
     * @dataProvider dataForSafeCurl
     */
    public function testBlockedUrlBySafeCurl($url)
    {
        $graby = new Graby();
        $res = $graby->fetchContent($url);

        $this->assertCount(12, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('No title found', $res['title']);
        $this->assertSame('[unable to retrieve full-text content]', $res['html']);
        $this->assertSame('[unable to retrieve full-text content]', $res['summary']);
        $this->assertSame('', $res['content_type']);
        $this->assertSame([], $res['open_graph']);
        $this->assertFalse($res['native_ad']);
        $this->assertSame(500, $res['status']);
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

        $graby = new Graby([
            'error_message' => 'Nothing found, hu?',
            'error_message_title' => 'No title detected',
        ], $client);

        $res = $graby->fetchContent('lexpress.io');

        $this->assertCount(12, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('No title detected', $res['title']);
        $this->assertSame('Nothing found, hu?', $res['html']);
        $this->assertSame('http://lexpress.io', $res['url']);
        $this->assertSame('Nothing found, hu?', $res['summary']);
        $this->assertSame('', $res['content_type']);
        $this->assertSame([], $res['open_graph']);
        $this->assertFalse($res['native_ad']);
    }

    public function dataWithAccent()
    {
        return [
            'host with accent' => ['http://pérotin.com/post/2009/06/09/SAV-Free-un-sketch-kafkaien', 'http://xn--protin-bva.com/post/2009/06/09/SAV-Free-un-sketch-kafkaien'],
            'url with accent 1' => ['https://en.wikipedia.org/wiki/Café', 'https://en.wikipedia.org/wiki/Caf%C3%A9'],
            'url with accent 2' => ['http://www.atterres.org/article/budget-2016-les-10-méprises-libérales-du-gouvernement', 'http://www.atterres.org/article/budget-2016-les-10-m%C3%A9prises-lib%C3%A9rales-du-gouvernement'],
            'url with accent 3' => ['http://www.pro-linux.de/news/1/23430/linus-torvalds-über-das-internet-der-dinge.html', 'http://www.pro-linux.de/news/1/23430/linus-torvalds-%C3%BCber-das-internet-der-dinge.html'],
        ];
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

        $res = $method->invokeArgs($graby, [$url]);

        $this->assertSame($urlExpected, $res);
    }

    public function testAbsolutePreviewInOgImage()
    {
        $graby = new Graby();

        $reflection = new \ReflectionClass(get_class($graby));
        $method = $reflection->getMethod('extractOpenGraph');
        $method->setAccessible(true);

        $ogData = $method->invokeArgs(
            $graby,
            [
                '<html><meta content="/assets/lol.jpg" property="og:image" /><meta content="http://www.io.lol" property="og:url"/></html>',
                'http://www.io.lol',
            ]
        );

        $this->assertCount(2, $ogData);
        $this->assertArrayHasKey('og_url', $ogData);
        $this->assertSame('http://www.io.lol', $ogData['og_url']);
        $this->assertSame('http://www.io.lol/assets/lol.jpg', $ogData['og_image']);
    }
}
