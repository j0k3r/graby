<?php

declare(strict_types=1);

namespace Tests\Graby\Extractor;

use Graby\Extractor\ContentExtractor;
use Graby\SiteConfig\SiteConfig;
use GuzzleHttp\Psr7\Uri;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Readability\Readability;

class ContentExtractorTest extends TestCase
{
    private const CONTENT_EXTRACTOR_CONFIG = [
        'config_builder' => [
            'site_config' => [__DIR__ . '/../fixtures/site_config'],
        ],
    ];

    public function testConstructDefault(): void
    {
        $contentExtractor = new ContentExtractor(['config_builder' => ['site_config' => [__DIR__]]]);
        $contentExtractor->reset();

        $this->assertNull($contentExtractor->getContent());
        $this->assertNull($contentExtractor->getTitle());
        $this->assertNull($contentExtractor->getLanguage());
        $this->assertNull($contentExtractor->getDate());
        $this->assertNull($contentExtractor->getImage());
        $this->assertSame([], $contentExtractor->getAuthors());
        $this->assertNull($contentExtractor->getSiteConfig());
        $this->assertNull($contentExtractor->getNextPageUrl());
    }

    public function dataFingerPrints(): array
    {
        return [
            'blogger double quote' => [
                '<html><head><meta name="generator" content="Blogger" /></head></html>',
                'fingerprint.blogspot.com',
            ],
            'blogger simple quote' => [
                "<html><head><meta content='blogger' name='generator'/></head></html>",
                'fingerprint.blogspot.com',
            ],
            'wordpress with version number' => [
                '<html><head><meta name="generator" content="WordPress 4.4.2" /></head></html>',
                'fingerprint.wordpress.com',
            ],
            'ippen.media' => [
                '<html><div class="id-SiteBEEPWrap"><span class="id-SitePosHelperWrap"><span id="id-SitePosHelper" class="id-SitePosHelper"></span></span><div class="id-SiteWrap idjs-SiteWrap" data-id-module="jumpToWithOffset"></div></html>',
                'fingerprint.ippen.media',
            ],
        ];
    }

    /**
     * Test if fingerprints are well extract from meta node.
     *
     * @dataProvider dataFingerPrints
     */
    public function testFingerPrints(string $html, string $fingerprints): void
    {
        $contentExtractor = new ContentExtractor([
            'config_builder' => ['site_config' => [__DIR__]],
        ]);

        $res = $contentExtractor->findHostUsingFingerprints('');

        $this->assertNotSame($fingerprints, $res, 'Nothing host found because empty html');

        $res = $contentExtractor->findHostUsingFingerprints($html);

        $this->assertSame($fingerprints, $res);
    }

    /**
     * With a non-existent config directory, it fails.
     */
    public function testBuildSiteConfigUnknownSite(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('directory does not exist');

        $contentExtractor = new ContentExtractor(['config_builder' => [
            'site_config' => [__DIR__ . '/../../wrong_site_config'],
        ]]);
        $contentExtractor->buildSiteConfig(new Uri('http://0.0.0.0'));
    }

    /**
     * With a good configuration, SiteConfig must have some value defined.
     */
    public function testBuildSiteConfig(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);
        $res = $contentExtractor->buildSiteConfig(new Uri('https://www.en.wikipedia.org/wiki/Metallica'));

        $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $res);

        foreach (['author', 'single_page_link', 'next_page_link'] as $value) {
            $this->assertEmpty($res->$value, 'Check empty value for: ' . $value);
        }

        foreach (['date', 'strip_image_src', 'http_header', 'find_string', 'replace_string'] as $value) {
            $this->assertNotEmpty($res->$value, 'Check not empty value for: ' . $value);
        }

        foreach (['title', 'body', 'strip', 'strip_id_or_class', 'test_url', 'date'] as $value) {
            $this->assertGreaterThan(0, is_countable($res->$value) ? \count($res->$value) : 0, 'Check count XPath for: ' . $value);
        }
    }

    /**
     * Multiple call to a same SiteConfig will use the cached version.
     */
    public function testBuildSiteConfigCached(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);
        $res = $contentExtractor->buildSiteConfig(new Uri('https://nofailure.io/wiki/Metallica'));

        $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $res);

        $res2 = $contentExtractor->buildSiteConfig(new Uri('https://nofailure.io/wiki/Metallica'));

        $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $res2);
        $this->assertSame($res, $res2);
    }

    /**
     * Test both fingerprint and custom SiteConfig for wordpress.
     */
    public function testWithFingerPrints(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $res = $contentExtractor->buildSiteConfig(
            new Uri('https://en.blog.wordpress.com/2015/03/23/writing-101-registration/'),
            '<html><meta name="generator" content="WordPress.com" /></html>'
        );

        foreach (['title', 'body', 'strip', 'strip_id_or_class', 'strip_image_src', 'author', 'date'] as $value) {
            $this->assertGreaterThan(0, is_countable($res->$value) ? \count($res->$value) : 0, 'Check count XPath for: ' . $value);
        }
    }

    /**
     * Test config find_string / replace_string.
     */
    public function testProcessFindString(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();
        $config->body = ['//iframe'];
        $config->find_string = ['<html>&lt;iframe', '&gt;&lt;/iframe&gt;</html>'];
        $config->replace_string = ['<iframe class="video"', '></iframe>'];

        $res = $contentExtractor->process(
            '<html>&lt;iframe src=""&gt;&lt;/iframe&gt;</html> <a rel="author" href="/user8412228">CaTV</a>',
            new Uri('https://vimeo.com/35941909'),
            $config
        );

        $this->assertTrue($res, 'Extraction went well');

        $this->assertStringContainsString('<iframe class="video"', $this->getXmlContent($contentExtractor));
        $this->assertCount(1, (array) $contentExtractor->getAuthors());
        $this->assertSame('CaTV', (string) ((array) $contentExtractor->getAuthors())[0]);
    }

    /**
     * Test config find_string / replace_string.
     * But with a count different between the two, so replacement will be skipped.
     */
    public function testProcessFindStringBadCount(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();
        $config->body = ['//iframe'];
        $config->find_string = ['one'];
        $config->replace_string = ['1', '2'];

        $res = $contentExtractor->process(
            '<html><iframe src=""></iframe></html>',
            new Uri('https://vimeo.com/35941909'),
            $config
        );

        $this->assertTrue($res, 'Extraction went well');

        $this->assertStringContainsString('<iframe src="">[embedded content]</iframe>', $this->getXmlContent($contentExtractor));
    }

    public function dataForNextPage(): array
    {
        return [
            // return the link as string
            ["string(//a[@class='next'])", '<html>here is a test zazaz<a class="next" href="https://lemonde.io/35941909?page=2">https://lemonde.io/35941909?page=2</a></html>', 'https://lemonde.io/35941909?page=2'],
            // will find the link using "href" attribute
            ["//a[@class='next']", '<html>here is a test zazaz<a class="next" href="https://lemonde.io/35941909?page=2">next page</a></html>', 'https://lemonde.io/35941909?page=2'],
            // will directly return the node attribute
            ["//a[@class='next']/@href", '<html>here is a test zazaz<a class="next" href="https://lemonde.io/35941909?page=2">next page</a></html>', 'https://lemonde.io/35941909?page=2'],
        ];
    }

    /**
     * @dataProvider dataForNextPage
     */
    public function testExtractNextPageLink(string $pattern, string $html, string $urlExpected): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();
        $config->next_page_link = [$pattern];

        $contentExtractor->process(
            $html,
            new Uri('https://lemonde.io/35941909'),
            $config
        );

        $this->assertSame($urlExpected, $contentExtractor->getNextPageUrl());
    }

    public function dataForTitle(): array
    {
        return [
            // return the link as string
            ['string(//title)', '<html><title>mon titre</title></html>', 'mon titre'],
            // return the DomElement link
            ['//title', '<html><title>mon titre</title></html>', 'mon titre'],
        ];
    }

    /**
     * @dataProvider dataForTitle
     */
    public function testExtractTitle(string $pattern, string $html, string $titleExpected): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();
        $config->title = [$pattern];

        $contentExtractor->process(
            $html,
            new Uri('https://lemonde.io/35941909'),
            $config
        );

        $this->assertSame($titleExpected, $contentExtractor->getTitle());
    }

    public function dataForAuthor(): array
    {
        return [
            // return author node
            ['//*[(@rel = "author")]', '<html>from <a rel="author" href="/user8412228">CaTV</a></html>', ['CaTV']],
            // return author as a string
            ['string(//*[(@rel = "author")])', '<html>from <a rel="author" href="/user8412228">CaTV</a></html>', ['CaTV']],
            // return nothing because the rel="author" does not exist
            ['string(//*[(@rel = "author")])', '<html>from <a href="/user8412228">CaTV</a></html>', []],
        ];
    }

    /**
     * @dataProvider dataForAuthor
     */
    public function testExtractAuthor(string $pattern, string $html, array $authorExpected): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();
        $config->author = [$pattern];

        $contentExtractor->process(
            $html,
            new Uri('https://lemonde.io/35941909'),
            $config
        );

        $this->assertSame($authorExpected, $contentExtractor->getAuthors());
    }

    public function dataForLanguage(): array
    {
        return [
            ['<html><meta name="DC.language" content="en" />from <a rel="author" href="/user8412228">CaTV</a></html>', 'en'],
            ['<html lang="de">from <a rel="author" href="/user8412228">CaTV</a></html>', 'de'],
        ];
    }

    /**
     * @dataProvider dataForLanguage
     */
    public function testExtractLanguage(string $html, string $languageExpected): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();

        $contentExtractor->process(
            $html,
            new Uri('https://lemonde.io/35941909'),
            $config
        );

        $this->assertSame($languageExpected, $contentExtractor->getLanguage());
    }

    public function dataForDate(): array
    {
        return [
            // good time format
            ['//time[@pubdate or @pubDate]', '<html><time pubdate="2015-01-01">2015-01-01</time></html>', '2015-01-01T00:00:00+01:00'],
            // bad time format, null result
            ['//time[@pubdate or @pubDate]', '<html><time pubdate="2015-01-01">date</time></html>', null],
            // bad pattern but good @pubdate
            ['//date[@pubdate or @pubDate]', '<html><time pubdate="2015-01-01">2015-01-01</time></html>', '2015-01-01T00:00:00+01:00'],
            // good time format
            ['string(//time[@pubdate or @pubDate])', '<html><time pubdate="2015-01-01">2015-01-01</time></html>', '2015-01-01T00:00:00+01:00'],
        ];
    }

    /**
     * @dataProvider dataForDate
     */
    public function testExtractDate(string $pattern, string $html, ?string $dateExpected): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();
        $config->date = [$pattern];

        $contentExtractor->process(
            $html,
            new Uri('https://lemonde.io/35941909'),
            $config
        );

        $this->assertSame($dateExpected, $contentExtractor->getDate());
    }

    public function dataForStrip(): array
    {
        return [
            // strip nav element and keep only the p
            ['//nav', '<html><body><nav id="high">hello !hello !hello !hello !hello !hello !hello !hello !hello !</nav><p>' . str_repeat('this is the best part of the show', 10) . '</p></body></html>', 'hello !'],
            // strip p element and keep the nav
            ['//p', '<html><body><nav id="high">' . str_repeat('hello !', 20) . '</nav><p>' . str_repeat('this is the best part of the show', 10) . '</p></body></html>', 'this is the best part of the show'],
        ];
    }

    /**
     * @dataProvider dataForStrip
     */
    public function testApplyStrip(string $pattern, string $html, string $removedContent): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();
        $config->strip = [$pattern];

        $contentExtractor->process(
            $html,
            new Uri('https://lemonde.io/35941909'),
            $config
        );

        $this->assertStringNotContainsString($removedContent, $this->getReadabilityContent($contentExtractor));
    }

    public function dataForStripIdOrClass(): array
    {
        return [
            ['commentlist', '<html><body><nav id="commentlist">hello !hello !hello !hello !hello !hello !hello !hello !hello !</nav><p>' . str_repeat('this is the best part of the show', 10) . '</p></body></html>', 'hello !'],
            ['related_post', '<html><body><nav id="high">' . str_repeat('hello !', 20) . '</nav><p class="related_post">' . str_repeat('this is the best part of the show', 10) . '</p></body></html>', 'this is the best part of the show'],
            ['related', '<html><body><nav id="high">' . str_repeat('lorem ipsum dolor', 20) . '</nav><p class="related_post">' . str_repeat('this is the best part of the show', 10) . '</p></body></html>', null, 'class="related_post"'],
        ];
    }

    /**
     * @dataProvider dataForStripIdOrClass
     */
    public function testApplyStripIdOrClass(string $pattern, string $html, ?string $removedContent, ?string $matchContent = null): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();
        $config->strip_id_or_class = [$pattern];

        $contentExtractor->process(
            $html,
            new Uri('https://lemonde.io/35941909'),
            $config
        );

        $content = $this->getReadabilityContent($contentExtractor);

        if (null === $removedContent) {
            $this->assertStringContainsString((string) $matchContent, $content);
        } else {
            $this->assertStringNotContainsString($removedContent, $content);
        }
    }

    public function dataForStripImageSrc(): array
    {
        return [
            ['doubleclick.net', '<html><body><img src="https://www.doubleclick.net/pub.jpg"/></nav><p>' . str_repeat('this is the best part of the show', 10) . '</p></body></html>', 'https://www.doubleclick.net/pub.jpg'],
            // array('related_post', '<html><body><nav id="high">'.str_repeat('hello !', 20).'</nav><p class="related_post">'.str_repeat('this is the best part of the show', 10).'</p></body></html>', 'this is the best part of the show'),
        ];
    }

    /**
     * @dataProvider dataForStripImageSrc
     */
    public function testApplyStripImageSrc(string $pattern, string $html, string $removedContent): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();
        $config->strip_image_src = [$pattern];

        $res = $contentExtractor->process(
            $html,
            new Uri('https://lemonde.io/35941909'),
            $config
        );

        $this->assertTrue($res, 'Extraction went well');
        $this->assertStringNotContainsString($removedContent, $this->getReadabilityContent($contentExtractor));
    }

    public function dataForStripDisplayNoneAndInstapaper(): array
    {
        return [
            // remove element with class "instapaper_ignore"
            ['<html><body><p class="instapaper_ignore">hello !hello !hello !hello !hello !hello !hello !hello !hello !</p><p>' . str_repeat('this is the best part of the show', 10) . '</p></body></html>', 'hello !'],
            // remove element with class "entry-unrelated"
            ['<html><body><p class="entry-unrelated">hello !hello !hello !hello !hello !hello !hello !hello !hello !</p><p>' . str_repeat('this is the best part of the show', 10) . '</p></body></html>', 'hello !'],
        ];
    }

    /**
     * @dataProvider dataForStripDisplayNoneAndInstapaper
     */
    public function testApplyStripDisplayNoneAndInstapaper(string $html, string $removedContent): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();

        $res = $contentExtractor->process(
            $html,
            new Uri('https://lemonde.io/35941909'),
            $config
        );

        $this->assertTrue($res, 'Extraction went well');
        $this->assertStringNotContainsString($removedContent, $this->getReadabilityContent($contentExtractor));
    }

    public function dataForStripAttr(): array
    {
        return [
            [['//*/@class'], '<html><body><div class="hello world"><i class="class">bar</i>class="foo"' . str_repeat('this is the best part of the show', 10) . ' <a class="hc" href="void">link</a></div></body></html>', [
                    'removedContent' => ['class="class"', 'class="hello world"', 'class="hc"'],
                    'keptContent' => ['class="foo"', '<a href="void"', '<em>bar'],
                ],
            ],
            [['//img/@class', '//p/@class'], '<html><body><img class="bar-class" src="void" /><a class="hello" href="void">link</a> <p class="yes">' . str_repeat('this is the best part of the show', 10) . '</p></body></html>', [
                    'removedContent' => ['class="bar-class"', 'class="yes"'],
                    'keptContent' => ['class="hello"'],
                ],
            ],
        ];
    }

    /**
     * @dataProvider dataForStripAttr
     */
    public function testApplyStripAttr(array $patterns, string $html, array $assertions): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();
        $config->strip = $patterns;

        $res = $contentExtractor->process(
            $html,
            new Uri('https://lemonde.io/35941909'),
            $config
        );

        $content = $this->getReadabilityContent($contentExtractor);

        foreach ($assertions['removedContent'] as $removedContent) {
            $this->assertStringNotContainsString($removedContent, $content);
        }

        foreach ($assertions['keptContent'] as $keptContent) {
            $this->assertStringContainsString($keptContent, $content);
        }
    }

    public function dataForExtractBody(): array
    {
        return [
            // extract one element
            [
                "//p[@class='content']",
                '<html><body><p class="content">hello !hello !hello !hello !hello !hello !hello !hello !hello !</p><p>' . str_repeat('this is the best part of the show', 10) . '</p></body></html>',
                '<p class="content">hello !hello !hello !hello !hello !hello !hello !hello !hello !</p>',
            ],
            // extract multiple element
            [
                "//p[@class='content_wrapper']",
                '<html><body><p class="content_wrapper">hello !hello !hello !hello !hello !hello !hello !hello !hello !</p><p class="content_wrapper">' . str_repeat('this is the best part of the show', 5) . '</p></body></html>',
                '<div><p class="content_wrapper">hello !hello !hello !hello !hello !hello !hello !hello !hello !</p><p class="content_wrapper">' . str_repeat('this is the best part of the show', 5) . '</p></div>',
            ],
        ];
    }

    /**
     * @dataProvider dataForExtractBody
     */
    public function testExtractBody(string $pattern, string $html, string $expectedContent): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();
        $config->body = [$pattern];

        $res = $contentExtractor->process(
            $html,
            new Uri('https://lemonde.io/35941909'),
            $config
        );

        $this->assertTrue($res, 'Extraction went well');

        $this->assertSame($expectedContent, $this->getXmlContent($contentExtractor));
    }

    public function dataForExtractHNews(): array
    {
        return [
            // the all hNews tested
            [
                '<html><body><div class="hentry"><p class="entry-title">hello !</p><time pubdate="2015-01-01">2015-01-01</time><a class="vcard author">hello !</a>hello !hello !hello !hello !hello !hello !hello !<p class="entry-content">' . str_repeat('this is the best part of the show', 10) . '</p></div></body></html>',
                '<p class="entry-content">' . str_repeat('this is the best part of the show', 10) . '</p>',
                [
                    'title' => 'hello !',
                    'date' => '2015-01-01T00:00:00+01:00',
                    'authors' => ['hello !'],
                ],
            ],
            // hNews with bad date
            [
                '<html><body><div class="hentry"><time pubdate="2015-01-01">aweomse!</time>hello !hello !hello !hello !hello !hello !hello !<p class="entry-content">' . str_repeat('this is the best part of the show', 10) . '</p></div></body></html>',
                '<p class="entry-content">' . str_repeat('this is the best part of the show', 10) . '</p>',
                [
                    'date' => null,
                ],
            ],
            // hNews with many authors
            [
                '<html><body><div class="hentry"><p class="vcard author"><a class="fn">first boy</a><a class="fn">first girl</a></p>hello !hello !hello !hello !hello !hello !hello !<p class="entry-content">' . str_repeat('this is the best part of the show', 10) . '</p></div></body></html>',
                '<p class="entry-content">' . str_repeat('this is the best part of the show', 10) . '</p>',
                [
                    'authors' => ['first boy', 'first girl'],
                ],
            ],
            // hNews with many content
            [
                '<html><body><div class="hentry"><p class="entry-content">hello !hello !hello !hello !hello !hello !hello !</p><p class="entry-content">' . str_repeat('this is the best part of the show', 10) . '</p></div></body></html>',
                '<div><p class="entry-content">hello !hello !hello !hello !hello !hello !hello !</p><p class="entry-content">' . str_repeat('this is the best part of the show', 10) . '</p></div>',
                [],
            ],
        ];
    }

    /**
     * @dataProvider dataForExtractHNews
     */
    public function testExtractHNews(string $html, string $expectedContent, array $expectedElements): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();

        $res = $contentExtractor->process(
            $html,
            new Uri('https://lemonde.io/35941909'),
            $config
        );

        $this->assertTrue($res, 'Extraction went well');

        $this->assertSame($expectedContent, $this->getXmlContent($contentExtractor));

        foreach ($expectedElements as $key => $value) {
            $this->assertSame($contentExtractor->{'get' . ucfirst($key)}(), $value);
        }
    }

    /**
     * Extract content from instapaper class.
     */
    public function testExtractInstapaper(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();

        $res = $contentExtractor->process(
            '<html><body><div><p class="instapaper_title">hello !</p>hello !hello !hello !hello !hello !hello !hello !<p class="instapaper_body">' . str_repeat('this is the best part of the show', 10) . '</p></div></body></html>',
            new Uri('https://lemonde.io/35941909'),
            $config
        );

        $this->assertTrue($res, 'Extraction went well');
        $this->assertSame('<p class="instapaper_body">' . str_repeat('this is the best part of the show', 10) . '</p>', $this->getXmlContent($contentExtractor));
        $this->assertSame($contentExtractor->getTitle(), 'hello !');
    }

    public function dataForExtractSchemaOrg(): array
    {
        return [
            // articleBody on one element
            [
                '<html><body><div>hello !hello !hello !hello !hello !hello !hello !<p itemprop="articleBody">' . str_repeat('this is the best part of the show', 10) . '</p></div></body></html>',
                '<p itemprop="articleBody">' . str_repeat('this is the best part of the show', 10) . '</p>',
            ],
            // articleBody on two elements
            [
                '<html><body><div><p itemprop="articleBody">hello !hello !hello !hello !hello !hello !hello !</p><p itemprop="articleBody">' . str_repeat('this is the best part of the show', 10) . '</p></div></body></html>',
                '<div><p itemprop="articleBody">hello !hello !hello !hello !hello !hello !hello !</p><p itemprop="articleBody">' . str_repeat('this is the best part of the show', 10) . '</p></div>',
            ],
            // articleBody on img element
            [
                '<html><body><div><p itemprop="articleBody"><img src="http://0.0.0.0/image.jpg" /></p></div></body></html>',
                '<p itemprop="articleBody"><img src="http://0.0.0.0/image.jpg"/></p>',
            ],
        ];
    }

    /**
     * @dataProvider dataForExtractSchemaOrg
     */
    public function testExtractSchemaOrg(string $html, string $expectedContent): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();

        $res = $contentExtractor->process(
            $html,
            new Uri('https://lemonde.io/35941909'),
            $config
        );

        $this->assertTrue($res, 'Extraction went well');
        $this->assertSame($expectedContent, $this->getXmlContent($contentExtractor));
    }

    /**
     * Test that if the first h* found in the body is the same as the extracted title, it'll be removed.
     */
    public function testRemoveHFromBody(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();
        $config->body = ['//div'];
        $config->title = ['//title'];

        $res = $contentExtractor->process(
            '<html><head><title>My Title</title></head><body><div><h3>My Title</h3>' . str_repeat('this is the best part of the show', 10) . '</div></body></html>',
            new Uri('https://lemonde.io/35941909'),
            $config
        );

        $this->assertTrue($res, 'Extraction went well');
        $this->assertStringNotContainsString('My Title', $this->getXmlContent($contentExtractor));
        $this->assertSame('My Title', $contentExtractor->getTitle());
    }

    public function dataForlazyLoad(): array
    {
        return [
            // test with img attribute data-src
            [
                '<div>' . str_repeat('this is the best part of the show', 10) . '<img data-src="http://0.0.0.0/big_image.jpg" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="></div>',
                '<img src="http://0.0.0.0/big_image.jpg"',
            ],
            // test with img attribute data-lazy-src
            [
                '<div>' . str_repeat('this is the best part of the show', 10) . '<img data-lazy-src="http://0.0.0.0/big_image.jpg" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="></div>',
                '<img src="http://0.0.0.0/big_image.jpg"',
            ],
            // test with img attribute data-src and image in noscript
            [
                '<div>' . str_repeat('this is the best part of the show', 10) . '<img data-lazy-src="http://0.0.0.0/big_image.jpg" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="><noscript><img src="http://0.0.0.0/big_image_noscript.jpg"></noscript></div>',
                '<img src="http://0.0.0.0/big_image_noscript.jpg"',
            ],
            // test with img attribute data-original
            [
                '<div>' . str_repeat('this is the best part of the show', 10) . '<img src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" data-original="http://0.0.0.0/big_image.jpg" class="lazy"/></div>',
                '<img src="http://0.0.0.0/big_image.jpg"',
            ],
            // test with img attribute data-sources
            [
                '<div>' . str_repeat('this is the best part of the show', 10) . '<img src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" data-sources="http://0.0.0.0/big_image.jpg"/></div>',
                '<img src="http://0.0.0.0/big_image.jpg"',
            ],
            // test with img attribute from site config
            [
                '<div>' . str_repeat('this is the best part of the show', 10) . '<img src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" data-toto-src="http://0.0.0.0/big_image.jpg"/></div>',
                '<img src="http://0.0.0.0/big_image.jpg"',
            ],
            // test with img attribute data-srcset
            [
                '<div>' . str_repeat('this is the best part of the show', 10) . '<img data-src="http://0.0.0.0/src.jpg" data-srcset="http://0.0.0.0/srcset1 680w, http://0.0.0.0/srcset2 1536w"/></div>',
                '<img src="http://0.0.0.0/src.jpg" srcset="http://0.0.0.0/srcset1 680w, http://0.0.0.0/srcset2 1536w"/>',
            ],
            // test with img attribute data-srcset empty
            [
                '<div>' . str_repeat('this is the best part of the show', 10) . '<img data-src="http://0.0.0.0/src.jpg" data-srcset=""/></div>',
                '<img src="http://0.0.0.0/src.jpg"/>',
            ],
        ];
    }

    /**
     * Test that if the first h* found in the body is the same as the extracted title, it'll be removed.
     *
     * @dataProvider dataForlazyLoad
     */
    public function testConvertLazyLoadImages(string $html, string $htmlExpected): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();
        $config->body = ['//div'];
        $config->src_lazy_load_attr = 'data-toto-src';

        $res = $contentExtractor->process(
            $html,
            new Uri('https://lemonde.io/35941909'),
            $config
        );

        $this->assertTrue($res, 'Extraction went well');
        $this->assertStringContainsString($htmlExpected, $this->getXmlContent($contentExtractor));
    }

    public function testIframeEmbeddedContent(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();
        // '//header' is a bad pattern, and it will jump to the next one
        $config->body = ['//header', '//div'];
        // obviously a bad parser which will be converted to use the default one
        $config->parser = 'toto';

        $res = $contentExtractor->process(
            '<div>' . str_repeat('this is the best part of the show', 10) . '</div><div class="video_player"><iframe src="http://www.dailymotion.com/embed/video/x2kjh59" frameborder="0" width="534" height="320"></iframe></div>',
            new Uri('https://lemonde.io/35941909'),
            $config
        );

        $this->assertTrue($res, 'Extraction went well');
        $this->assertStringContainsString('<iframe src="http://www.dailymotion.com/embed/video/x2kjh59" frameborder="0" width="534" height="320">[embedded content]</iframe>', $this->getXmlContent($contentExtractor));
    }

    public function testLogMessage(): void
    {
        $logger = new Logger('foo');
        $handler = new TestHandler($level = Logger::INFO);
        $logger->pushHandler($handler);

        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);
        $contentExtractor->setLogger($logger);

        $config = new SiteConfig();

        $contentExtractor->process(
            '<html>&lt;iframe &gt;&lt;/iframe&gt;</html>',
            new Uri('https://vimeo.com/35941909'),
            $config
        );

        $records = $handler->getRecords();

        $this->assertGreaterThanOrEqual(6, $records);
        $this->assertSame('Attempting to parse HTML with {parser}', $records[0]['message']);
        $this->assertSame('libxml', $records[0]['context']['parser']);
        $this->assertSame('Opengraph "og:" data: {ogData}', $records[2]['message']);
        $this->assertSame('Opengraph "article:" data: {ogData}', $records[3]['message']);
        $this->assertSame('Trying {pattern} for language', $records[4]['message']);
        $this->assertSame('Trying {pattern} for language', $records[5]['message']);
        $this->assertSame('Using Readability', $records[6]['message']);
        $this->assertSame('Attempting to parse HTML with {parser}', $records[8]['message']);
    }

    public function testWithCustomFiltersForReadability(): void
    {
        $contentExtractor = new ContentExtractor(
            self::CONTENT_EXTRACTOR_CONFIG
            + ['readability' => [
                'post_filters' => ['!<head[^>]*>(.*?)</head>!is' => ''],
                'pre_filters' => ['!</?noscript>!is' => ''],
            ]]
        );

        $config = new SiteConfig();

        $res = $contentExtractor->process(
            '<!DOCTYPE html>
<html lang="fr" dir="ltr">
<head>
<base href="http://www.lhc-france.fr/" />
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="generator" content="SPIP 3.0.17 [21515]" />
<link rel="shortcut icon" href="squelettes/favicon.ico" />
<script type=\'text/javascript\'>
document.createElement("header");document.createElement("footer");document.createElement("section");document.createElement("aside");document.createElement("nav");document.createElement("article");document.createElement("time");
</script>
<!--[if lt IE 9]>
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <script type="text/javascript" src="http://www.lhc-france.fr/squelettes/js/ie.js"></script>
<![endif]-->

<script type="text/javascript" src="http://www.lhc-france.fr/squelettes/js/modernizr.js"></script>
<script type="text/javascript">
function handleError(){return true;}
window.onerror = handleError;
dossier_squelettes = \'squelettes\';
secteurid=6;articleid=907;article_jour=19;article_mois=12;article_annee=2016;
</script>

<link rel="alternate" type="application/rss+xml" title="Actualit��s du LHC" href="http://feeds.feedburner.com/lhcfranceactus?format=xml" />
<link rel="alternate" type="application/rss+xml" title="La BD du LHC" href="http://www.lhc-france.fr/?page=backend&id_rubrique=65" />

<link rel="stylesheet" href="http://www.lhc-france.fr/local/cache-css/styles-urlabs-b1fc-urlabs-b1fc-minify-3f10.css" type="text/css" media="all" />
<link rel="stylesheet" href="http://www.lhc-france.fr/local/cache-css/milkbox-urlabs-fe01-urlabs-fe01-minify-1d16.css" media="screen" />
<link rel="stylesheet" href="http://www.lhc-france.fr/local/cache-css/styles.print-urlabs-2157-urlabs-2157-minify-d3e7.css" type="text/css" media="print" />
<link rel="stylesheet" href="http://www.lhc-france.fr/squelettes/styles.rouge.css" type="text/css" media="all" />

<script type="text/javascript" src="http://www.lhc-france.fr/local/cache-js/AC_RunActiveContent-minify-d850.js"></script>
<title>Novembre 2016 - Je voudrais de la mati��re noire �� No��l... | LHC France</title>
<meta name="robots" content="index, follow, all" />
<meta name="description" content="La contribution du CNRS et du CEA au LHC, un instrument international de physique des particules situ�� au Cern. Avec toute l\'actualit�� du projet et la BD du LHC." />
<meta name="keywords" content="LHC,Higgs,Atlas,CMS,Alice,LHCb,acc��l��rateur,particule,Cern,grille,d��tecteur,exp��riences,boson de higgs" />

<meta name="verify-v1" content="WWk3UJy6FdmEUs2ZATuUi6+OQnIL3Sci3WmPHmaWQWs=" />
<meta name="verify-v1" content="VAs7L6UxdHUoi699A76rt8aDBfL4c6hBE3vJw2SRbh4=" />
<meta property="og:image" content="http://www.lhc-france.fr/IMG/arton907.jpg" />
<meta property="fb:admins" content="thomas.diluccio,proyoledegieux"/>
</head>
<body class="rouge "><p>' . str_repeat('This is important. ', 20) . '</p></body></html>',
            new Uri('https://lemonde.io/35941909'),
            $config
        );

        $this->assertTrue($res, 'Extraction went well');
        $this->assertStringNotContainsString('<head>', $this->getXmlContent($contentExtractor));
        $this->assertStringNotContainsString('<base>', $this->getXmlContent($contentExtractor));
    }

    public function testNativeAd(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $res = $contentExtractor->process(
            ' <meta property="og:url" content="https://nativead.io/sponsored/woops"/><p>hihi</p>',
            new Uri('https://nativead.io/woops!')
        );

        $this->assertTrue($res, 'Extraction went well');
        $this->assertTrue($contentExtractor->isNativeAd());
        $this->assertStringContainsString('<p>hihi</p>', $this->getXmlContent($contentExtractor));
    }

    public function testJsonLd(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $res = $contentExtractor->process(
            ' <script type="application/ld+json">{ "@context": "https:\/\/schema.org", "@type": "NewsArticle", "headline": "title !!", "mainEntityOfPage": "http:\/\/jsonld.io\/toto", "datePublished": "2017-10-23T16:05:38+02:00", "dateModified": "2017-10-23T16:06:28+02:00", "description": "it is describe", "articlebody": " my body", "relatedLink": "", "image": { "@type": "ImageObject", "url": "https:\/\/static.jsonld.io\/medias.jpg", "height": "830", "width": "532" }, "author": { "@type": "Person", "name": "bob", "sameAs": ["https:\/\/twitter.com\/bob"] }, "keywords": ["syndicat", "usine", "licenciement", "Emmanuel Macron", "creuse", "plan social", "Automobile"] }</script><p>hihi</p>',
            new Uri('https://nativead.io/jsonld')
        );

        $this->assertTrue($res, 'Extraction went well');
        $this->assertSame('title !!', $contentExtractor->getTitle());
        $this->assertSame('2017-10-23T16:05:38+02:00', $contentExtractor->getDate());
        $this->assertStringContainsString('bob', (string) ((array) $contentExtractor->getAuthors())[0]);
        $this->assertSame('https://static.jsonld.io/medias.jpg', $contentExtractor->getImage());
        $this->assertStringContainsString('<p>hihi</p>', $this->getXmlContent($contentExtractor));
    }

    public function testJsonLdWithMultipleAuthors(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $res = $contentExtractor->process(
            '<script type="application/ld+json">{"@context":"https://schema.org","@type":"NewsArticle","author":[{"@type":"Person","name":"Elisa Thevenet"},{"@type":"Person","name":"Humphrey Bogart"}]}</script>',
            new Uri('https://nativead.io/jsonld')
        );

        /** @var \DOMNode */
        $contentBlock = $contentExtractor->getContent();

        $this->assertSame([
            'Elisa Thevenet',
            'Humphrey Bogart',
        ], $contentExtractor->getAuthors());
    }

    public function testJsonLdWithAuthorWithNameList(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $res = $contentExtractor->process(
            '<script type="application/ld+json">{"@context":"https://schema.org","@type":"NewsArticle","author":{"@type":"Person","name":["Greg Myre"]}}</script>',
            new Uri('https://www.npr.org/sections/parallels/2017/05/19/529148729/michael-flynns-contradictory-line-on-russia')
        );

        $this->assertSame([
            'Greg Myre',
        ], $contentExtractor->getAuthors());
    }

    public function testNoDefinedHtml(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $res = $contentExtractor->process('', new Uri('https://nativead.io/jsonld'));

        $this->assertFalse($res);

        $this->assertEmpty($contentExtractor->getImage());
    }

    public function testOpenGraph(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $res = $contentExtractor->process(
            ' <meta property="og:title" content="title !!"/>
            <meta property="og:site_name" content="opengraph.io" />
            <meta property="og:type" content="article"/>
            <meta property="og:locale" content="fr_FR"/>
            <meta property="og:url" content="//opengraph.io/1954872.html"/>
            <meta property="article:published_time" content="2017-10-23T17:04:21Z-09:00"/>
            <meta property="article:modified_time" content="2017-10-23T17:04:17Z-09:00"/>
            <meta property="og:image" content="http://static.opengraph.io/medias_11570.jpg"/>
            <meta property="og:image:url" content="http://static.opengraph.io/medias_11570.jpg"/>
            <meta property="og:image:secure_url" content="https://static.opengraph.io/medias_11570.jpg"/>
            <p>hihi</p>',
            new Uri('https://nativead.io/opengraph')
        );

        $this->assertTrue($res);
        $this->assertSame('title !!', $contentExtractor->getTitle());
        $this->assertSame('2017-10-23T17:04:21+00:00', $contentExtractor->getDate());
        $this->assertSame('fr_FR', $contentExtractor->getLanguage());
        $this->assertSame('https://static.opengraph.io/medias_11570.jpg', $contentExtractor->getImage());
        $this->assertStringContainsString('<p>hihi</p>', $this->getXmlContent($contentExtractor));
    }

    public function testAvoidDataUriImageInOpenGraph(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $res = $contentExtractor->process(
            ' <html><meta content="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" property="og:image" /><meta content="http://www.io.lol" property="og:url"/><p>hihi</p></html>',
            new Uri('https://nativead.io/opengraph')
        );

        $this->assertTrue($res);
        $this->assertEmpty($contentExtractor->getImage());
        $this->assertStringContainsString('<p>hihi</p>', $this->getXmlContent($contentExtractor));
    }

    public function testJsonLdIgnoreList(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $res = $contentExtractor->process(
            '<html><body><script type="application/ld+json">{ "@context": "http:\/\/schema.org", "@type": "NewsArticle", "publisher": { "@type": "Organization", "name": "Foobar Company" }, "description": "A method for fooling tools", "mainEntityOfPage": { "@type": "WebPage", "@id": "https:\/\/www.example.com/foobar" }, "headline": "The Foobar Company is launching globally", "datePublished": "2019-01-14T16:02:00.000+00:00", "dateModified": "2019-01-14T13:25:09.980+00:00", "author": { "@type": "Person", "name": "Foobar CEO" } }</script> <script type="application/ld+json">{ "@context": "http:\/\/schema.org", "@type": "Organization", "name": "Foobar Company", "url": "https:\/\/www.example.com" }</script><p>' . str_repeat('this is the best part of the show', 10) . '</p></body></html>',
            new Uri('https://example.com/jsonld')
        );

        $this->assertTrue($res, 'Extraction went well');

        $this->assertSame('The Foobar Company is launching globally', $contentExtractor->getTitle());
        $this->assertStringContainsString('Foobar CEO', (string) ((array) $contentExtractor->getAuthors())[0]);
    }

    public function testJsonLdIgnoreListWithPeriodical(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $res = $contentExtractor->process(
            '<html><body><script type="application/ld+json">{ "@context": "http:\/\/schema.org", "@type": "Periodical", "publisher": { "@type": "Organization", "name": "Foobar Company" }, "description": "A method for fooling tools", "mainEntityOfPage": { "@type": "WebPage", "@id": "https:\/\/www.example.com/foobar" }, "name": "Foobar Company", "datePublished": "2019-01-14T16:02:00.000+00:00", "dateModified": "2019-01-14T13:25:09.980+00:00", "author": { "@type": "Person", "name": "Foobar CEO" } }</script> <script type="application/ld+json">{ "@context": "http:\/\/schema.org", "@type": "Organization", "name": "Foobar Company", "url": "https:\/\/www.example.com" }</script><h1>Hello world, this is title</h1><p>' . str_repeat('this is the best part of the show', 10) . '</p></body></html>',
            new Uri('https://example.com/jsonld')
        );

        $this->assertTrue($res, 'Extraction went well');

        $this->assertSame('Hello world, this is title', $contentExtractor->getTitle());
    }

    public function testJsonLdSkipper(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();
        $config->skip_json_ld = true;

        $res = $contentExtractor->process(
            '<html><script type="application/ld+json">{ "@context": "https:\/\/schema.org", "@type": "NewsArticle", "headline": "title !!", "mainEntityOfPage": "http:\/\/jsonld.io\/toto", "datePublished": "2017-10-23T16:05:38+02:00", "dateModified": "2017-10-23T16:06:28+02:00", "description": "it is describe", "articlebody": " my body", "relatedLink": "", "image": { "@type": "ImageObject", "url": "https:\/\/static.jsonld.io\/medias.jpg", "height": "830", "width": "532" }, "author": { "@type": "Person", "name": "bob", "sameAs": ["https:\/\/twitter.com\/bob"] }, "keywords": ["syndicat", "usine", "licenciement", "Emmanuel Macron", "creuse", "plan social", "Automobile"] }</script><body><div>hello !hello !hello !hello !hello !hello !hello !<p itemprop="articleBody">' . str_repeat('this is the best part of the show', 10) . '</p></div></body></html>',
            new Uri('https://skipjsonld.io/jsonld'),
            $config
        );

        $this->assertTrue($res, 'Extraction went well');
        $this->assertEmpty($contentExtractor->getTitle());
        $this->assertNull($contentExtractor->getDate());
        $this->assertEmpty($contentExtractor->getAuthors());
        $this->assertStringContainsString('this is the best part of the show', $this->getXmlContent($contentExtractor));
    }

    public function testJsonLdName(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $res = $contentExtractor->process(
            ' <script type="application/ld+json">{ "@context": "https:\/\/schema.org", "@type": "NewsArticle", "headline": "title !!", "name": "name !!", "mainEntityOfPage": "http:\/\/jsonld.io\/toto", "datePublished": "2017-10-23T16:05:38+02:00", "dateModified": "2017-10-23T16:06:28+02:00", "description": "it is describe", "articlebody": " my body", "relatedLink": "", "image": { "@type": "ImageObject", "url": "https:\/\/static.jsonld.io\/medias.jpg", "height": "830", "width": "532" }, "author": { "@type": "Person", "name": "bob", "sameAs": ["https:\/\/twitter.com\/bob"] }, "keywords": ["syndicat", "usine", "licenciement", "Emmanuel Macron", "creuse", "plan social", "Automobile"] }</script><p>hihi</p>',
            new Uri('https://nativead.io/jsonld')
        );

        $this->assertSame('name !!', $contentExtractor->getTitle());
    }

    public function testJsonLdDateArray(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $res = $contentExtractor->process(
            ' <script type="application/ld+json">{ "@context": "http://schema.org", "@type": "NewsArticle", "description": "Smoke rises from the 998-tonne fuel tanker Shoko Maru after it exploded off the coast of Himeji, western Japan, in this photo taken and released May 29, 2014.  REUTERS/5th Regional Coast Guard Headqua", "headline": "Editor&#039;s choice", "url": "https://www.reuters.com/news/picture/editors-choice-idUSRTR3RD95", "thumbnailUrl": "https://s3.reutersmedia.net/resources/r/?m=02&d=20140529&t=2&i=901254582&w=&fh=810&fw=545&ll=&pl=&sq=&r=2014-05-29T132753Z_2_GM1EA5T1BTD01_RTRMADP_0_JAPAN", "dateCreated": "2014-05-29T13:27:53+0000", "dateModified": "2014-05-29T13:27:53+0000", "articleSection": "RCOMUS_24", "creator": ["JaShong King"], "keywords": ["24 HOURS IN PICTURES", "Slideshow"], "about": "Slideshow", "author": ["JaShong King"], "datePublished": ["05/29/2014"] }</script><p>hihi</p>',
            new Uri('https://nativead.io/jsonld')
        );

        $this->assertSame('2014-05-29T00:00:00+02:00', $contentExtractor->getDate());
    }

    public function testJsonLdImageUrlArray(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $res = $contentExtractor->process(
            ' <script type="application/ld+json">{ "@context": "http://schema.org", "@type": "NewsArticle", "description": "Smoke rises from the 998-tonne fuel tanker Shoko Maru after it exploded off the coast of Himeji, western Japan, in this photo taken and released May 29, 2014.  REUTERS/5th Regional Coast Guard Headqua", "headline": "Editor&#039;s choice", "url": "https://www.reuters.com/news/picture/editors-choice-idUSRTR3RD95", "thumbnailUrl": "https://s3.reutersmedia.net/resources/r/?m=02&d=20140529&t=2&i=901254582&w=&fh=810&fw=545&ll=&pl=&sq=&r=2014-05-29T132753Z_2_GM1EA5T1BTD01_RTRMADP_0_JAPAN", "dateCreated": "2014-05-29T13:27:53+0000", "dateModified": "2014-05-29T13:27:53+0000", "articleSection": "RCOMUS_24", "creator": ["JaShong King"], "keywords": ["24 HOURS IN PICTURES", "Slideshow"], "about": "Slideshow", "author": ["JaShong King"], "datePublished": ["05/29/2014"], "image": { "@type": "ImageObject", "url": [ "https://statics.estadao.com.br/s2016/portal/img/json-ld/estadao_1x1.png", "https://statics.estadao.com.br/s2016/portal/img/json-ld/estadao_4x3.png", "https://statics.estadao.com.br/s2016/portal/img/json-ld/estadao_16x9.png" ]} }</script><p>hihi</p>',
            new Uri('https://nativead.io/jsonld')
        );

        $this->assertSame('https://statics.estadao.com.br/s2016/portal/img/json-ld/estadao_1x1.png', $contentExtractor->getImage());
    }

    public function testUniqueAuthors(): void
    {
        $url = new Uri('https://www.lemonde.fr/pixels/article/2018/05/30/bloodstained-curse-of-the-moon-delicieux-jeu-de-vampires-a-la-mode-des-annees-1980_5307173_4408996.html');
        $html = '<script type="application/ld+json">{"author":{"@type":"Person","name":"William Audureau"}}</script><a class="auteur" target="_blank" href="/journaliste/william-audureau/">William Audureau</a>';

        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);
        $siteConfig = $contentExtractor->buildSiteConfig($url);

        $contentExtractor->process(
            $html,
            $url,
            $siteConfig
        );
        $authors = (array) $contentExtractor->getAuthors();
        $authorsUnique = array_unique($authors);

        $this->assertTrue(\count($authors) === \count($authorsUnique), 'There is no duplicate authors');
    }

    public function testBodyAsDomAttribute(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();
        // a xpath retrieving a dom attribute
        $config->body = ['//iframe/@src'];

        $res = $contentExtractor->process(
            '   <iframe src="blog_0x34.md.html" frameborder="0" style="overflow:hidden; display:block; position: absolute; height: 80%; width:100%;"></iframe>',
            new Uri('https://domattr.io/woops!'),
            $config
        );

        $this->assertFalse($res, 'Extraction failed');
    }

    public function testBadDate(): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $res = $contentExtractor->process(
            '   <meta property="article:published_time" content="-0001-11-304T00:00:00+00:00" /> <p>' . str_repeat('this is the best part of the show', 10) . '</p> ',
            new Uri('https://domattr.io/woops!')
        );

        $this->assertTrue($res, 'Extraction went fine');
        $this->assertNull($contentExtractor->getDate(), 'Date got vanish because it was wrong');
    }

    public function dataForProcessWrapIn(): array
    {
        return [
            // blockquote with a nested div
            [
                [
                    'blockquote' => "//div[@class='cond1']",
                ],
                "//blockquote/div[@class='cond1']/p",
            ],
            [
                [
                    'blockquote' => "//div[@class='cond1']/p",
                ],
                "//div[@class='cond1']/blockquote/p",
            ],
        ];
    }

    /**
     * Test config wrap_in.
     *
     * @dataProvider dataForProcessWrapIn
     */
    public function testProcessWrapIn(array $wrapIn, string $xpathQuery): void
    {
        $contentExtractor = new ContentExtractor(self::CONTENT_EXTRACTOR_CONFIG);

        $config = new SiteConfig();
        $config->body = ['//article'];
        $config->wrap_in = $wrapIn;

        $res = $contentExtractor->process(
            '<html><article><div class="cond1"><p>Hello world</p></div></article></html>',
            new Uri('https://example.com/wrapin'),
            $config
        );

        $this->assertTrue($res, 'Extraction went well');

        /** @var \DOMNode */
        $contentBlock = $contentExtractor->getContent();
        $doc = new \DOMDocument();
        $doc->loadXML($contentBlock->innerHTML);
        $xpath = new \DOMXPath($doc);

        $el = $xpath->query($xpathQuery);
        $this->assertCount(1, $el ?: []);
    }

    private function getXmlContent(ContentExtractor $contentExtractor): string
    {
        /** @var \DOMNode */
        $contentBlock = $contentExtractor->getContent();
        /** @var \DOMDocument */
        $ownerDocument = $contentBlock->ownerDocument;

        return (string) $ownerDocument->saveXML($contentBlock);
    }

    private function getReadabilityContent(ContentExtractor $contentExtractor): string
    {
        /** @var Readability */
        $readability = $contentExtractor->readability;
        $domElement = $readability->getContent();
        /** @var \DOMDocument */
        $ownerDocument = $domElement->ownerDocument;

        return (string) $ownerDocument->saveXML($domElement);
    }
}
