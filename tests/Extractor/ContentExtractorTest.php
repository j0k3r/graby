<?php

namespace Tests\Graby\Extractor;

use Graby\Extractor\ContentExtractor;
use Graby\SiteConfig\SiteConfig;
use Monolog\Logger;
use Monolog\Handler\TestHandler;

class ContentExtractorTest extends \PHPUnit_Framework_TestCase
{
    protected static $contentExtractorConfig;

    public static function setUpBeforeClass()
    {
        self::$contentExtractorConfig = array('config_builder' => array(
            'site_config' => array(dirname(__FILE__).'/../fixtures/site_config'),
        ));
    }

    public function testConstructDefault()
    {
        $contentExtractor = new ContentExtractor(array('config_builder' => array('site_config' => array(dirname(__FILE__)))));
        $contentExtractor->reset();

        $this->assertEquals(null, $contentExtractor->getContent());
        $this->assertEquals(null, $contentExtractor->getTitle());
        $this->assertEquals(null, $contentExtractor->getLanguage());
        $this->assertEquals(null, $contentExtractor->getSiteConfig());
        $this->assertEquals(null, $contentExtractor->getNextPageUrl());
    }

    public function dataFingerPrints()
    {
        return array(
            'blogger double quote' => array(
                '<html><head><meta name="generator" content="Blogger" /></head></html>',
                'fingerprint.blogspot.com',
            ),
            'blogger simple quote' => array(
                "<html><head><meta content='blogger' name='generator'/></head></html>",
                'fingerprint.blogspot.com',
            ),
            'wordpress with version number' => array(
                '<html><head><meta name="generator" content="WordPress 4.4.2" /></head></html>',
                'fingerprint.wordpress.com',
            ),
        );
    }

    /**
     * Test if fingerprints are well extract from meta node.
     *
     * @dataProvider dataFingerPrints
     */
    public function testFingerPrints($html, $fingerprints)
    {
        $contentExtractor = new ContentExtractor(array(
            'config_builder' => array('site_config' => array(dirname(__FILE__))),
        ));

        $res = $contentExtractor->findHostUsingFingerprints('');

        $this->assertFalse($res, 'Nothing host found because empty html');

        $res = $contentExtractor->findHostUsingFingerprints($html);

        $this->assertEquals($fingerprints, $res);
    }

    /**
     * With a non-existent config directory, it fails.
     *
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage directory does not exist
     */
    public function testBuildSiteConfigUnknownSite()
    {
        $contentExtractor = new ContentExtractor(array('config_builder' => array(
            'site_config' => array(dirname(__FILE__).'/../../wrong_site_config'),
        )));
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

        foreach (array('http_header', 'single_page_link', 'next_page_link', 'find_string', 'replace_string') as $value) {
            $this->assertEmpty($res->$value, 'Check empty value for: '.$value);
        }

        foreach (array('strip_image_src') as $value) {
            $this->assertNotEmpty($res->$value, 'Check not empty value for: '.$value);
        }

        foreach (array('title', 'body', 'strip', 'strip_id_or_class', 'test_url') as $value) {
            $this->assertGreaterThan(0, count($res->$value), 'Check count XPatch for: '.$value);
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

        foreach (array('title', 'body', 'strip', 'strip_id_or_class', 'strip_image_src') as $value) {
            $this->assertGreaterThan(0, count($res->$value), 'Check count XPatch for: '.$value);
        }
    }

    /**
     * Test config find_string / replace_string.
     */
    public function testProcessFindString()
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->body = array('//iframe');
        $config->find_string = array('<html>&lt;iframe', '&gt;&lt;/iframe&gt;</html>');
        $config->replace_string = array('<iframe class="video"', '></iframe>');

        $res = $contentExtractor->process(
            '<html>&lt;iframe src=""&gt;&lt;/iframe&gt;</html>',
            'https://vimeo.com/35941909',
            $config
        );

        $this->assertTrue($res);

        $content_block = $contentExtractor->getContent();

        $this->assertContains('<iframe class="video"', $content_block->ownerDocument->saveXML($content_block));
    }

    /**
     * Test config find_string / replace_string.
     * But with a count different between the two, so replacement will be skipped.
     */
    public function testProcessFindStringBadCount()
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->body = array('//iframe');
        $config->find_string = array('one');
        $config->replace_string = array('1', '2');

        $res = $contentExtractor->process(
            '<html><iframe src=""></iframe></html>',
            'https://vimeo.com/35941909',
            $config
        );

        $this->assertTrue($res);

        $content_block = $contentExtractor->getContent();

        $this->assertContains('<iframe src="">[embedded content]</iframe>', $content_block->ownerDocument->saveXML($content_block));
    }

    public function dataForNextPage()
    {
        return array(
            // return the link as string
            array("string(//a[@class='next'])", '<html>here is a test zazaz<a class="next" href="https://lemonde.io/35941909?page=2">https://lemonde.io/35941909?page=2</a></html>', 'https://lemonde.io/35941909?page=2'),
            // will find the link using "href" attribute
            array("//a[@class='next']", '<html>here is a test zazaz<a class="next" href="https://lemonde.io/35941909?page=2">next page</a></html>', 'https://lemonde.io/35941909?page=2'),
            // will directly return the node attribute
            array("//a[@class='next']/@href", '<html>here is a test zazaz<a class="next" href="https://lemonde.io/35941909?page=2">next page</a></html>', 'https://lemonde.io/35941909?page=2'),
        );
    }

    /**
     * @dataProvider dataForNextPage
     */
    public function testExtractNextPageLink($pattern, $html, $urlExpected)
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->next_page_link = array($pattern);

        $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertEquals($urlExpected, $contentExtractor->getNextPageUrl());
    }

    public function dataForTitle()
    {
        return array(
            // return the link as string
            array('string(//title)', '<html><title>mon titre</title></html>', 'mon titre'),
            // return the DomElement link
            array('//title', '<html><title>mon titre</title></html>', 'mon titre'),
        );
    }

    /**
     * @dataProvider dataForTitle
     */
    public function testExtractTitle($pattern, $html, $titleExpected)
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->title = array($pattern);

        $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertEquals($titleExpected, $contentExtractor->getTitle());
    }

    public function dataForLanguage()
    {
        return array(
            array('<html><meta name="DC.language" content="en" />from CaTV</html>', 'en'),
            array('<html lang="de">from CaTV</html>', 'de'),
        );
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

        $this->assertEquals($languageExpected, $contentExtractor->getLanguage());
    }

    public function dataForStrip()
    {
        return array(
            // strip nav element and keep only the p
            array('//nav', '<html><body><nav id="high">hello !hello !hello !hello !hello !hello !hello !hello !hello !</nav><p>'.str_repeat('this is the best part of the show', 10).'</p></body></html>', 'hello !'),
            // strip p element and keep the nav
            array('//p', '<html><body><nav id="high">'.str_repeat('hello !', 20).'</nav><p>'.str_repeat('this is the best part of the show', 10).'</p></body></html>', 'this is the best part of the show'),
        );
    }

    /**
     * @dataProvider dataForStrip
     */
    public function testApplyStrip($pattern, $html, $removedContent)
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->strip = array($pattern);

        $res = $contentExtractor->process(
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
        return array(
            array('commentlist', '<html><body><nav id="commentlist">hello !hello !hello !hello !hello !hello !hello !hello !hello !</nav><p>'.str_repeat('this is the best part of the show', 10).'</p></body></html>', 'hello !'),
            array('related_post', '<html><body><nav id="high">'.str_repeat('hello !', 20).'</nav><p class="related_post">'.str_repeat('this is the best part of the show', 10).'</p></body></html>', 'this is the best part of the show'),
        );
    }

    /**
     * @dataProvider dataForStripIdOrClass
     */
    public function testApplyStripIdOrClass($pattern, $html, $removedContent)
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->strip_id_or_class = array($pattern);

        $res = $contentExtractor->process(
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
        return array(
            array('doubleclick.net', '<html><body><img src="https://www.doubleclick.net/pub.jpg"/></nav><p>'.str_repeat('this is the best part of the show', 10).'</p></body></html>', 'https://www.doubleclick.net/pub.jpg'),
            // array('related_post', '<html><body><nav id="high">'.str_repeat('hello !', 20).'</nav><p class="related_post">'.str_repeat('this is the best part of the show', 10).'</p></body></html>', 'this is the best part of the show'),
        );
    }

    /**
     * @dataProvider dataForStripImageSrc
     */
    public function testApplyStripImageSrc($pattern, $html, $removedContent)
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->strip_image_src = array($pattern);

        $res = $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $domElement = $contentExtractor->readability->getContent();
        $content = $domElement->ownerDocument->saveXML($domElement);

        $this->assertNotContains($removedContent, $content);
    }

    public function dataForStripDisplayNoneAndInstapaper()
    {
        return array(
            // remove element with class "instapaper_ignore"
            array('<html><body><p class="instapaper_ignore">hello !hello !hello !hello !hello !hello !hello !hello !hello !</p><p>'.str_repeat('this is the best part of the show', 10).'</p></body></html>', 'hello !'),
            // remove element with class "entry-unrelated"
            array('<html><body><p class="entry-unrelated">hello !hello !hello !hello !hello !hello !hello !hello !hello !</p><p>'.str_repeat('this is the best part of the show', 10).'</p></body></html>', 'hello !'),
        );
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

    public function dataForExtractBody()
    {
        return array(
            // extract one element
            array(
                "//p[@class='content']",
                '<html><body><p class="content">hello !hello !hello !hello !hello !hello !hello !hello !hello !</p><p>'.str_repeat('this is the best part of the show', 10).'</p></body></html>',
                '<p class="content">hello !hello !hello !hello !hello !hello !hello !hello !hello !</p>',
            ),
            // extract multiple element
            array(
                "//p[@class='content_wrapper']",
                '<html><body><p class="content_wrapper">hello !hello !hello !hello !hello !hello !hello !hello !hello !</p><p class="content_wrapper">'.str_repeat('this is the best part of the show', 5).'</p></body></html>',
                '<div><p class="content_wrapper">hello !hello !hello !hello !hello !hello !hello !hello !hello !</p><p class="content_wrapper">'.str_repeat('this is the best part of the show', 5).'</p></div>',
            ),
        );
    }

    /**
     * @dataProvider dataForExtractBody
     */
    public function testExtractBody($pattern, $html, $expectedContent)
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->body = array($pattern);

        $res = $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertTrue($res, 'Extraction went well');

        $domElement = $contentExtractor->getContent();
        $content = $domElement->ownerDocument->saveXML($domElement);

        $this->assertEquals($expectedContent, $content);
    }

    public function dataForExtractHNews()
    {
        return array(
            // the all hNews tested
            array(
                '<html><body><div class="hentry"><p class="entry-title">hello !</p><time pubdate="2015-01-01">2015-01-01</time>hello ! hello !hello !hello !hello !hello !hello !hello !<p class="entry-content">'.str_repeat('this is the best part of the show', 10).'</p></div></body></html>',
                '<p class="entry-content">'.str_repeat('this is the best part of the show', 10).'</p>',
                array(
                    'title' => 'hello !',
                ),
            ),
            // hNews with many content
            array(
                '<html><body><div class="hentry"><p class="entry-content">hello !hello !hello !hello !hello !hello !hello !</p><p class="entry-content">'.str_repeat('this is the best part of the show', 10).'</p></div></body></html>',
                '<div><p class="entry-content">hello !hello !hello !hello !hello !hello !hello !</p><p class="entry-content">'.str_repeat('this is the best part of the show', 10).'</p></div>',
                array(),
            ),
        );
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

        $this->assertEquals($expectedContent, $content);

        foreach ($expectedElements as $key => $value) {
            $this->assertEquals($contentExtractor->{'get'.ucfirst($key)}(), $value);
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
            '<html><body><div><p class="instapaper_title">hello !</p>hello !hello !hello !hello !hello !hello !hello !<p class="instapaper_body">'.str_repeat('this is the best part of the show', 10).'</p></div></body></html>',
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertTrue($res, 'Extraction went well');

        $domElement = $contentExtractor->getContent();
        $content = $domElement->ownerDocument->saveXML($domElement);

        $this->assertEquals('<p class="instapaper_body">'.str_repeat('this is the best part of the show', 10).'</p>', $content);
        $this->assertEquals($contentExtractor->getTitle(), 'hello !');
    }

    public function dataForExtractSchemaOrg()
    {
        return array(
            // articleBody on one element
            array(
                '<html><body><div>hello !hello !hello !hello !hello !hello !hello !<p itemprop="articleBody">'.str_repeat('this is the best part of the show', 10).'</p></div></body></html>',
                '<p itemprop="articleBody">'.str_repeat('this is the best part of the show', 10).'</p>',
            ),
            // articleBody on two elements
            array(
                '<html><body><div><p itemprop="articleBody">hello !hello !hello !hello !hello !hello !hello !</p><p itemprop="articleBody">'.str_repeat('this is the best part of the show', 10).'</p></div></body></html>',
                '<div><p itemprop="articleBody">hello !hello !hello !hello !hello !hello !hello !</p><p itemprop="articleBody">'.str_repeat('this is the best part of the show', 10).'</p></div>',
            ),
            // articleBody on img element
            array(
                '<html><body><div><p itemprop="articleBody"><img src="http://0.0.0.0/image.jpg" /></p></div></body></html>',
                '<p itemprop="articleBody"><img src="http://0.0.0.0/image.jpg"/></p>',
            ),
        );
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

        $this->assertEquals($expectedContent, $content);
    }

    /**
     * Test that if the first h* found in the body is the same as the extracted title, it'll be removed.
     */
    public function testRemoveH1FromBody()
    {
        $contentExtractor = new ContentExtractor(self::$contentExtractorConfig);

        $config = new SiteConfig();
        $config->body = array('//div');
        $config->title = array('//title');

        $res = $contentExtractor->process(
            '<html><body><title>My Title</title><div><h3>My Title</h3>'.str_repeat('this is the best part of the show', 10).'</div></body></html>',
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertTrue($res, 'Extraction went well');

        $domElement = $contentExtractor->getContent();
        $content = $domElement->ownerDocument->saveXML($domElement);

        $this->assertNotContains('My Title', $content);
        $this->assertEquals('My Title', $contentExtractor->getTitle());
    }

    public function dataForlazyLoad()
    {
        return array(
            // test with img attribute data-src
            array(
                '<div>'.str_repeat('this is the best part of the show', 10).'<img data-src="http://0.0.0.0/big_image.jpg" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="></div>',
                '<img src="http://0.0.0.0/big_image.jpg"',
            ),
            // test with img attribute data-lazy-src
            array(
                '<div>'.str_repeat('this is the best part of the show', 10).'<img data-lazy-src="http://0.0.0.0/big_image.jpg" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="></div>',
                '<img src="http://0.0.0.0/big_image.jpg"',
            ),
            // test with img attribute data-src and image in noscript
            array(
                '<div>'.str_repeat('this is the best part of the show', 10).'<img data-lazy-src="http://0.0.0.0/big_image.jpg" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="><noscript><img src="http://0.0.0.0/big_image_noscript.jpg"></noscript></div>',
                '<img src="http://0.0.0.0/big_image_noscript.jpg"',
            ),
            // test with img attribute data-original and image in noscript
            array(
                '<div>'.str_repeat('this is the best part of the show', 10).'<img src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" data-original="http://0.0.0.0/big_image.jpg" class="lazy"/></div>',
                '<img src="http://0.0.0.0/big_image.jpg"',
            ),
        );
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
        $config->body = array('//div');

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
        $config->body = array('//header', '//div');
        // obviously a bad parser which will be converted to use the default one
        $config->parser = 'toto';

        $res = $contentExtractor->process(
            '<div>'.str_repeat('this is the best part of the show', 10).'</div><div class="video_player"><iframe src="http://www.dailymotion.com/embed/video/x2kjh59" frameborder="0" width="534" height="320"></iframe></div>',
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

        $res = $contentExtractor->process(
            '<html>&lt;iframe &gt;&lt;/iframe&gt;</html>',
            'https://vimeo.com/35941909',
            $config
        );

        $records = $handler->getRecords();

        $this->assertGreaterThanOrEqual(6, $records);
        $this->assertEquals('Attempting to parse HTML with {parser}', $records[0]['message']);
        $this->assertEquals('libxml', $records[0]['context']['parser']);
        $this->assertEquals('Trying {pattern} for language', $records[1]['message']);
        $this->assertEquals('Using Readability', $records[3]['message']);
        $this->assertEquals('Detected title: {title}', $records[4]['message']);

        if (function_exists('tidy_parse_string')) {
            $this->assertEquals('Trying again without tidy', $records[5]['message']);
        }
    }
}
