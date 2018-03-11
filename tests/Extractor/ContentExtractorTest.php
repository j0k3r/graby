<?php

namespace Tests\Graby\Extractor;

use Graby\Extractor\ContentExtractor;
use Graby\SiteConfig\SiteConfig;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class ContentExtractorTest extends TestCase
{
    protected static $contentExtractorConfig;

    public static function setUpBeforeClass()
    {
        self::$contentExtractorConfig = ['config_builder' => [
            'site_config' => [__DIR__ . '/../fixtures/site_config'],
        ]];
    }

    public function testConstructDefault()
    {
        $contentExtractor = new ContentExtractor(['config_builder' => ['site_config' => [__DIR__]]]);
        $contentExtractor->reset();

        $this->assertNull($contentExtractor->getContent());
        $this->assertNull($contentExtractor->getTitle());
        $this->assertNull($contentExtractor->getLanguage());
        $this->assertNull($contentExtractor->getDate());
        $this->assertSame([], $contentExtractor->getAuthors());
        $this->assertNull($contentExtractor->getSiteConfig());
        $this->assertNull($contentExtractor->getNextPageUrl());
    }

    public function dataFingerPrints()
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
        ];
    }

    /**
     * Test if fingerprints are well extract from meta node.
     *
     * @dataProvider dataFingerPrints
     */
    public function testFingerPrints($html, $fingerprints)
    {
        $contentExtractor = new ContentExtractor([
            'config_builder' => ['site_config' => [__DIR__]],
        ]);

        $res = $contentExtractor->findHostUsingFingerprints('');

        $this->assertFalse($res, 'Nothing host found because empty html');

        $res = $contentExtractor->findHostUsingFingerprints($html);

        $this->assertSame($fingerprints, $res);
    }

    /**
     * With a non-existent config directory, it fails.
     *
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage directory does not exist
     */
    public function testBuildSiteConfigUnknownSite()
    {
        $contentExtractor = new ContentExtractor(['config_builder' => [
            'site_config' => [__DIR__ . '/../../wrong_site_config'],
        ]]);
        $contentExtractor->buildSiteConfig('http://0.0.0.0');
    }

    /**
     * With a good configuration, SiteConfig must have some value defined.
     */
    public function testBuildSiteConfig()
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);
        $res = $contentExtractor->buildSiteConfig('https://www.en.wikipedia.org/wiki/Metallica');

        $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $res);

        foreach (['author', 'single_page_link', 'next_page_link'] as $value) {
            $this->assertEmpty($res->$value, 'Check empty value for: ' . $value);
        }

        foreach (['date', 'strip_image_src', 'http_header', 'find_string', 'replace_string'] as $value) {
            $this->assertNotEmpty($res->$value, 'Check not empty value for: ' . $value);
        }

        foreach (['title', 'body', 'strip', 'strip_id_or_class', 'test_url', 'date'] as $value) {
            $this->assertGreaterThan(0, count($res->$value), 'Check count XPath for: ' . $value);
        }
    }

    /**
     * Multiple call to a same SiteConfig will use the cached version.
     */
    public function testBuildSiteConfigCached()
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);
        $res = $contentExtractor->buildSiteConfig('https://nofailure.io/wiki/Metallica');

        $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $res);

        $res2 = $contentExtractor->buildSiteConfig('https://nofailure.io/wiki/Metallica');

        $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $res2);
        $this->assertSame($res, $res2);
    }

    /**
     * Test both fingerprint and custom SiteConfig for wordpress.
     */
    public function testWithFingerPrints()
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $res = $contentExtractor->buildSiteConfig(
            'https://en.blog.wordpress.com/2015/03/23/writing-101-registration/',
            '<html><meta name="generator" content="WordPress.com" /></html>'
        );

        foreach (['title', 'body', 'strip', 'strip_id_or_class', 'strip_image_src', 'author', 'date'] as $value) {
            $this->assertGreaterThan(0, count($res->$value), 'Check count XPath for: ' . $value);
        }
    }

    /**
     * Test config find_string / replace_string.
     */
    public function testProcessFindString()
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->body = ['//iframe'];
        $config->find_string = ['<html>&lt;iframe', '&gt;&lt;/iframe&gt;</html>'];
        $config->replace_string = ['<iframe class="video"', '></iframe>'];

        $res = $contentExtractor->process(
            '<html>&lt;iframe src=""&gt;&lt;/iframe&gt;</html> <a rel="author" href="/user8412228">CaTV</a>',
            'https://vimeo.com/35941909',
            $config
        );

        $this->assertTrue($res, 'Extraction went well');

        $content_block = $contentExtractor->getContent();

        $this->assertContains('<iframe class="video"', $content_block->ownerDocument->saveXML($content_block));
        $this->assertCount(1, $contentExtractor->getAuthors());
        $this->assertEquals('CaTV', $contentExtractor->getAuthors()[0]);
    }

    /**
     * Test config find_string / replace_string.
     * But with a count different between the two, so replacement will be skipped.
     */
    public function testProcessFindStringBadCount()
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->body = ['//iframe'];
        $config->find_string = ['one'];
        $config->replace_string = ['1', '2'];

        $res = $contentExtractor->process(
            '<html><iframe src=""></iframe></html>',
            'https://vimeo.com/35941909',
            $config
        );

        $this->assertTrue($res, 'Extraction went well');

        $content_block = $contentExtractor->getContent();

        $this->assertContains('<iframe src="">[embedded content]</iframe>', $content_block->ownerDocument->saveXML($content_block));
    }

    public function dataForNextPage()
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
    public function testExtractNextPageLink($pattern, $html, $urlExpected)
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->next_page_link = [$pattern];

        $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertSame($urlExpected, $contentExtractor->getNextPageUrl());
    }

    public function dataForTitle()
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
    public function testExtractTitle($pattern, $html, $titleExpected)
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->title = [$pattern];

        $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertSame($titleExpected, $contentExtractor->getTitle());
    }

    public function dataForAuthor()
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
    public function testExtractAuthor($pattern, $html, $authorExpected)
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->author = [$pattern];

        $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertSame($authorExpected, $contentExtractor->getAuthors());
    }

    public function dataForLanguage()
    {
        return [
            ['<html><meta name="DC.language" content="en" />from <a rel="author" href="/user8412228">CaTV</a></html>', 'en'],
            ['<html lang="de">from <a rel="author" href="/user8412228">CaTV</a></html>', 'de'],
        ];
    }

    /**
     * @dataProvider dataForLanguage
     */
    public function testExtractLanguage($html, $languageExpected)
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();

        $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertSame($languageExpected, $contentExtractor->getLanguage());
    }

    public function dataForDate()
    {
        return [
            // good time format
            ['//time[@pubdate or @pubDate]', '<html><time pubdate="2015-01-01">2015-01-01</time></html>', '2015-01-01'],
            // bad time format, null result
            ['//time[@pubdate or @pubDate]', '<html><time pubdate="2015-01-01">date</time></html>', null],
            // bad pattern but good @pubdate
            ['//date[@pubdate or @pubDate]', '<html><time pubdate="2015-01-01">2015-01-01</time></html>', '2015-01-01'],
            // good time format
            ['string(//time[@pubdate or @pubDate])', '<html><time pubdate="2015-01-01">2015-01-01</time></html>', '2015-01-01'],
        ];
    }

    /**
     * @dataProvider dataForDate
     */
    public function testExtractDate($pattern, $html, $dateExpected)
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->date = [$pattern];

        $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertSame($dateExpected, $contentExtractor->getDate());
    }

    public function dataForStrip()
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
    public function testApplyStrip($pattern, $html, $removedContent)
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->strip = [$pattern];

        $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $domElement = $contentExtractor->readability->getContent();
        $content = $domElement->ownerDocument->saveXML($domElement);

        $this->assertNotContains($removedContent, $content);
    }

    public function dataForStripIdOrClass()
    {
        return [
            ['commentlist', '<html><body><nav id="commentlist">hello !hello !hello !hello !hello !hello !hello !hello !hello !</nav><p>' . str_repeat('this is the best part of the show', 10) . '</p></body></html>', 'hello !'],
            ['related_post', '<html><body><nav id="high">' . str_repeat('hello !', 20) . '</nav><p class="related_post">' . str_repeat('this is the best part of the show', 10) . '</p></body></html>', 'this is the best part of the show'],
        ];
    }

    /**
     * @dataProvider dataForStripIdOrClass
     */
    public function testApplyStripIdOrClass($pattern, $html, $removedContent)
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->strip_id_or_class = [$pattern];

        $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $domElement = $contentExtractor->readability->getContent();
        $content = $domElement->ownerDocument->saveXML($domElement);

        $this->assertNotContains($removedContent, $content);
    }

    public function dataForStripImageSrc()
    {
        return [
            ['doubleclick.net', '<html><body><img src="https://www.doubleclick.net/pub.jpg"/></nav><p>' . str_repeat('this is the best part of the show', 10) . '</p></body></html>', 'https://www.doubleclick.net/pub.jpg'],
            // array('related_post', '<html><body><nav id="high">'.str_repeat('hello !', 20).'</nav><p class="related_post">'.str_repeat('this is the best part of the show', 10).'</p></body></html>', 'this is the best part of the show'),
        ];
    }

    /**
     * @dataProvider dataForStripImageSrc
     */
    public function testApplyStripImageSrc($pattern, $html, $removedContent)
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->strip_image_src = [$pattern];

        $res = $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertTrue($res, 'Extraction went well');

        $domElement = $contentExtractor->readability->getContent();
        $content = $domElement->ownerDocument->saveXML($domElement);

        $this->assertNotContains($removedContent, $content);
    }

    public function dataForStripDisplayNoneAndInstapaper()
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
    public function testApplyStripDisplayNoneAndInstapaper($html, $removedContent)
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();

        $res = $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertTrue($res, 'Extraction went well');

        $domElement = $contentExtractor->readability->getContent();
        $content = $domElement->ownerDocument->saveXML($domElement);

        $this->assertNotContains($removedContent, $content);
    }

    public function dataForStripAttr()
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
    public function testApplyStripAttr($patterns, $html, $assertions)
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->strip_attr = $patterns;

        $res = $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $domElement = $contentExtractor->readability->getContent();
        $content = $domElement->ownerDocument->saveXML($domElement);

        foreach ($assertions['removedContent'] as $removedContent) {
            $this->assertNotContains($removedContent, $content);
        }

        foreach ($assertions['keptContent'] as $keptContent) {
            $this->assertContains($keptContent, $content);
        }
    }

    public function dataForExtractBody()
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
    public function testExtractBody($pattern, $html, $expectedContent)
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->body = [$pattern];

        $res = $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertTrue($res, 'Extraction went well');

        $domElement = $contentExtractor->getContent();
        $content = $domElement->ownerDocument->saveXML($domElement);

        $this->assertSame($expectedContent, $content);
    }

    public function dataForExtractHNews()
    {
        return [
            // the all hNews tested
            [
                '<html><body><div class="hentry"><p class="entry-title">hello !</p><time pubdate="2015-01-01">2015-01-01</time><a class="vcard author">hello !</a>hello !hello !hello !hello !hello !hello !hello !<p class="entry-content">' . str_repeat('this is the best part of the show', 10) . '</p></div></body></html>',
                '<p class="entry-content">' . str_repeat('this is the best part of the show', 10) . '</p>',
                [
                    'title' => 'hello !',
                    'date' => '2015-01-01',
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
    public function testExtractHNews($html, $expectedContent, $expectedElements)
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();

        $res = $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertTrue($res, 'Extraction went well');

        $domElement = $contentExtractor->getContent();
        $content = $domElement->ownerDocument->saveXML($domElement);

        $this->assertSame($expectedContent, $content);

        foreach ($expectedElements as $key => $value) {
            $this->assertSame($contentExtractor->{'get' . ucfirst($key)}(), $value);
        }
    }

    /**
     * Extract content from instapaper class.
     */
    public function testExtractInstapaper()
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();

        $res = $contentExtractor->process(
            '<html><body><div><p class="instapaper_title">hello !</p>hello !hello !hello !hello !hello !hello !hello !<p class="instapaper_body">' . str_repeat('this is the best part of the show', 10) . '</p></div></body></html>',
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertTrue($res, 'Extraction went well');

        $domElement = $contentExtractor->getContent();
        $content = $domElement->ownerDocument->saveXML($domElement);

        $this->assertSame('<p class="instapaper_body">' . str_repeat('this is the best part of the show', 10) . '</p>', $content);
        $this->assertSame($contentExtractor->getTitle(), 'hello !');
    }

    public function dataForExtractSchemaOrg()
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
    public function testExtractSchemaOrg($html, $expectedContent)
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();

        $res = $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertTrue($res, 'Extraction went well');

        $domElement = $contentExtractor->getContent();
        $content = $domElement->ownerDocument->saveXML($domElement);

        $this->assertSame($expectedContent, $content);
    }

    /**
     * Test that if the first h* found in the body is the same as the extracted title, it'll be removed.
     */
    public function testRemoveHFromBody()
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->body = ['//div'];
        $config->title = ['//title'];

        $res = $contentExtractor->process(
            '<html><head><title>My Title</title></head><body><div><h3>My Title</h3>' . str_repeat('this is the best part of the show', 10) . '</div></body></html>',
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertTrue($res, 'Extraction went well');

        $domElement = $contentExtractor->getContent();
        $content = $domElement->ownerDocument->saveXML($domElement);

        $this->assertNotContains('My Title', $content);
        $this->assertSame('My Title', $contentExtractor->getTitle());
    }

    public function dataForlazyLoad()
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
        ];
    }

    /**
     * Test that if the first h* found in the body is the same as the extracted title, it'll be removed.
     *
     * @dataProvider dataForlazyLoad
     */
    public function testConvertLazyLoadImages($html, $htmlExpected)
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->body = ['//div'];
        $config->src_lazy_load_attr = 'data-toto-src';

        $res = $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertTrue($res, 'Extraction went well');

        $domElement = $contentExtractor->getContent();
        $content = $domElement->ownerDocument->saveXML($domElement);

        $this->assertContains($htmlExpected, $content);
    }

    public function testIframeEmbeddedContent()
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        // '//header' is a bad pattern, and it will jump to the next one
        $config->body = ['//header', '//div'];
        // obviously a bad parser which will be converted to use the default one
        $config->parser = 'toto';

        $res = $contentExtractor->process(
            '<div>' . str_repeat('this is the best part of the show', 10) . '</div><div class="video_player"><iframe src="http://www.dailymotion.com/embed/video/x2kjh59" frameborder="0" width="534" height="320"></iframe></div>',
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertTrue($res, 'Extraction went well');

        $domElement = $contentExtractor->getContent();
        $content = $domElement->ownerDocument->saveXML($domElement);

        $this->assertContains('<iframe src="http://www.dailymotion.com/embed/video/x2kjh59" frameborder="0" width="534" height="320">[embedded content]</iframe>', $content);
    }

    public function testLogMessage()
    {
        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);
        $contentExtractor->setLogger($logger);

        $config = new SiteConfig();

        $contentExtractor->process(
            '<html>&lt;iframe &gt;&lt;/iframe&gt;</html>',
            'https://vimeo.com/35941909',
            $config
        );

        $records = $handler->getRecords();

        $this->assertGreaterThanOrEqual(6, $records);
        $this->assertSame('Attempting to parse HTML with {parser}', $records[0]['message']);
        $this->assertSame('libxml', $records[0]['context']['parser']);
        $this->assertSame('Trying {pattern} for language', $records[1]['message']);
        $this->assertSame('Using Readability', $records[3]['message']);
        $this->assertSame('Detected title: {title}', $records[4]['message']);

        if (function_exists('tidy_parse_string')) {
            $this->assertSame('Trying again without tidy', $records[5]['message']);
        }
    }

    public function testWithCustomFiltersForReadability()
    {
        $contentExtractor = new ContentExtractor(
            self::$contentExtractorConfig
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
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertTrue($res, 'Extraction went well');

        $domElement = $contentExtractor->getContent();
        $content = $domElement->ownerDocument->saveXML($domElement);

        $this->assertNotContains('<head>', $content);
        $this->assertNotContains('<base>', $content);
    }

    public function testNativeAd()
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $res = $contentExtractor->process(
            ' <meta property="og:url" content="https://nativead.io/sponsored/woops"/><p><hihi/p>',
            'https://nativead.io/woops!'
        );

        $this->assertTrue($res, 'Extraction went well');

        $content_block = $contentExtractor->getContent();

        $this->assertTrue($contentExtractor->isNativeAd());
        $this->assertContains('<p><hihi/></p>', $content_block->ownerDocument->saveXML($content_block));
    }

    public function testJsonLd()
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $res = $contentExtractor->process(
            ' <script type="application/ld+json">{ "@context": "https:\/\/schema.org", "@type": "NewsArticle", "headline": "title !!", "mainEntityOfPage": "http:\/\/jsonld.io\/toto", "datePublished": "2017-10-23T16:05:38+02:00", "dateModified": "2017-10-23T16:06:28+02:00", "description": "it is describe", "articlebody": " my body", "relatedLink": "", "image": { "@type": "ImageObject", "url": "https:\/\/static.jsonld.io\/medias.jpg", "height": "830", "width": "532" }, "author": { "@type": "Person", "name": "bob", "sameAs": ["https:\/\/twitter.com\/bob"] }, "keywords": ["syndicat", "usine", "licenciement", "Emmanuel Macron", "creuse", "plan social", "Automobile"] }</script><p>hihi</p>',
            'https://nativead.io/jsonld'
        );

        $this->assertTrue($res, 'Extraction went well');

        $content_block = $contentExtractor->getContent();

        $this->assertSame('title !!', $contentExtractor->getTitle());
        $this->assertSame('2017-10-23T16:05:38+02:00', $contentExtractor->getDate());
        $this->assertContains('bob', $contentExtractor->getAuthors());
        $this->assertContains('<p>hihi</p>', $content_block->ownerDocument->saveXML($content_block));
    }
}
