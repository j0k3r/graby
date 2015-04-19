<?php

namespace Tests\FullText\Extractor;

use FullText\Extractor\ContentExtractor;
use FullText\SiteConfig\SiteConfig;

class ContentExtractorTest extends \PHPUnit_Framework_TestCase
{
    public function testConstructDefault()
    {
        $contentExtractor = new ContentExtractor(array('config_builder' => array('site_config_custom' => dirname(__FILE__))));
        $contentExtractor->reset();

        $this->assertEquals(null, $contentExtractor->getContent());
        $this->assertEquals(null, $contentExtractor->getTitle());
        $this->assertEquals(array(), $contentExtractor->getAuthors());
        $this->assertEquals(null, $contentExtractor->getLanguage());
        $this->assertEquals(null, $contentExtractor->getDate());
        $this->assertEquals(null, $contentExtractor->getSiteConfig());
        $this->assertEquals(null, $contentExtractor->getNextPageUrl());
    }

    public function testFingerPrints()
    {
        $contentExtractor = new ContentExtractor(array(
            'config_builder' => array('site_config_custom' => dirname(__FILE__)),
        ));

        $res = $contentExtractor->findHostUsingFingerprints('');

        $this->assertFalse($res, 'Nothing host found because empty html');

        $res = $contentExtractor->findHostUsingFingerprints('<html><head><meta name="generator" content="Blogger" /></head></html>');

        $this->assertEquals('fingerprint.blogspot.com', $res);
    }

    public function testBuildSiteConfigUnknownSite()
    {
        $contentExtractor = new ContentExtractor(array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/../../site_config/custom',
            'site_config_standard' => dirname(__FILE__),
        )));
        $res = $contentExtractor->buildSiteConfig('http://0.0.0.0');

        $this->assertInstanceOf('FullText\SiteConfig\SiteConfig', $res);

        // everything is empty because the standard config folder was wrong, otherwise, the global.txt file will load some data
        foreach (array('title', 'body', 'author', 'date', 'strip', 'strip_id_or_class', 'strip_image_src', 'http_header', 'test_url', 'single_page_link', 'next_page_link', 'single_page_link_in_feed', 'find_string', 'replace_string') as $value) {
            $this->assertEmpty($res->$value, 'Check empty value for: '.$value);
        }
    }

    public function testBuildSiteConfig()
    {
        $contentExtractor = new ContentExtractor(array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/../../site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/../../site_config/standard',
        )));
        $res = $contentExtractor->buildSiteConfig('https://www.en.wikipedia.org/wiki/Metallica');

        $this->assertInstanceOf('FullText\SiteConfig\SiteConfig', $res);

        foreach (array('author', 'http_header', 'single_page_link', 'next_page_link', 'single_page_link_in_feed', 'find_string', 'replace_string') as $value) {
            $this->assertEmpty($res->$value, 'Check empty value for: '.$value);
        }

        foreach (array('date', 'strip_image_src') as $value) {
            $this->assertNotEmpty($res->$value, 'Check not empty value for: '.$value);
        }

        foreach (array('title', 'body', 'strip', 'strip_id_or_class', 'test_url') as $value) {
            $this->assertGreaterThan(0, count($res->$value), 'Check count XPatch for: '.$value);
        }
    }

    public function testBuildSiteConfigCached()
    {
        $contentExtractor = new ContentExtractor(array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/../../site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/../../site_config/standard',
        )));
        $res = $contentExtractor->buildSiteConfig('https://www.en.wikipedia.org/wiki/Metallica');

        $this->assertInstanceOf('FullText\SiteConfig\SiteConfig', $res);

        $res2 = $contentExtractor->buildSiteConfig('https://www.en.wikipedia.org/wiki/Metallica');

        $this->assertInstanceOf('FullText\SiteConfig\SiteConfig', $res2);
    }

    public function testWithFingerPrints()
    {
        $contentExtractor = new ContentExtractor(array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/../../site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/../../site_config/standard',
        )));

        $res = $contentExtractor->buildSiteConfig(
            'https://en.blog.wordpress.com/2015/03/23/writing-101-registration/',
            '<html><meta name="generator" content="WordPress.com" /></html>'
        );

        foreach (array('title', 'body', 'strip', 'strip_id_or_class', 'author', 'date', 'strip_image_src') as $value) {
            $this->assertGreaterThan(0, count($res->$value), 'Check count XPatch for: '.$value);
        }
    }

    public function testProcessFindString()
    {
        $contentExtractor = new ContentExtractor(array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/../../site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/../../site_config/standard',
        )));

        $res = $contentExtractor->process(
            '<html>&lt;iframe &gt;&lt;/iframe&gt;</html> <a rel="author" href="/user8412228">CaTV</a>',
            'https://vimeo.com/35941909'
        );

        $this->assertTrue($res);

        $content_block = $contentExtractor->getContent();

        $this->assertContains('<iframe id="video"/>', $content_block->ownerDocument->saveXML($content_block));
        $this->assertCount(1, $contentExtractor->getAuthors());
        $this->assertEquals('CaTV', $contentExtractor->getAuthors()[0]);
    }

    public function dataForNextPage()
    {
        return array(
            array("string(//a[@class='next'])", '<html>here is a test zazaz<a class="next" href="https://lemonde.io/35941909?page=2">https://lemonde.io/35941909?page=2</a></html>', 'https://lemonde.io/35941909?page=2'),
            array("//a[@class='next']", '<html>here is a test zazaz<a class="next" href="https://lemonde.io/35941909?page=2">next page</a></html>', 'https://lemonde.io/35941909?page=2'),
            array("//a[@class='next']/@href", '<html>here is a test zazaz<a class="next" href="https://lemonde.io/35941909?page=2">next page</a></html>', 'https://lemonde.io/35941909?page=2'),
        );
    }

    /**
     * @dataProvider dataForNextPage
     */
    public function testExtractNextPageLink($pattern, $html, $urlExpected)
    {
        $contentExtractor = new ContentExtractor(array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/../../site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/../../site_config/standard',
        )));

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
            array('string(//title)', '<html><title>mon titre</title></html>', 'mon titre'),
            array('//title', '<html><title>mon titre</title></html>', 'mon titre'),
        );
    }

    /**
     * @dataProvider dataForTitle
     */
    public function testExtractTitle($pattern, $html, $titleExpected)
    {
        $contentExtractor = new ContentExtractor(array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/../../site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/../../site_config/standard',
        )));

        $config = new SiteConfig();
        $config->title = array($pattern);

        $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertEquals($titleExpected, $contentExtractor->getTitle());
    }

    public function dataForAuthor()
    {
        return array(
            array('//*[(@rel = "author")]', '<html>from <a rel="author" href="/user8412228">CaTV</a></html>', array('CaTV')),
            array('string(//*[(@rel = "author")])', '<html>from <a rel="author" href="/user8412228">CaTV</a></html>', array('CaTV')),
            array('string(//*[(@rel = "author")])', '<html>from <a href="/user8412228">CaTV</a></html>', array()),
        );
    }

    /**
     * @dataProvider dataForAuthor
     */
    public function testExtractAuthor($pattern, $html, $authorExpected)
    {
        $contentExtractor = new ContentExtractor(array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/../../site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/../../site_config/standard',
        )));

        $config = new SiteConfig();
        $config->author = array($pattern);

        $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertEquals($authorExpected, $contentExtractor->getAuthors());
    }

    public function dataForLanguage()
    {
        return array(
            array('<html><meta name="DC.language" content="en" />from <a rel="author" href="/user8412228">CaTV</a></html>', 'en'),
        );
    }

    /**
     * @dataProvider dataForLanguage
     */
    public function testExtractLanguage($html, $languageExpected)
    {
        $contentExtractor = new ContentExtractor(array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/../../site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/../../site_config/standard',
        )));

        $config = new SiteConfig();

        $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertEquals($languageExpected, $contentExtractor->getLanguage());
    }

    public function dataForDate()
    {
        return array(
            // good time format
            array('//time[@pubdate or @pubDate]', '<html><time pubdate="2015-01-01">2015-01-01</time></html>', strtotime('2015-01-01')),
            // bad time format, null result
            array('//time[@pubdate or @pubDate]', '<html><time pubdate="2015-01-01">date</time></html>', null),
            // bad pattern but good @pubdate
            array('//date[@pubdate or @pubDate]', '<html><time pubdate="2015-01-01">2015-01-01</time></html>', strtotime('2015-01-01')),
            // good time format
            array('string(//time[@pubdate or @pubDate])', '<html><time pubdate="2015-01-01">2015-01-01</time></html>', strtotime('2015-01-01')),
        );
    }

    /**
     * @dataProvider dataForDate
     */
    public function testExtractDate($pattern, $html, $dateExpected)
    {
        $contentExtractor = new ContentExtractor(array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/../../site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/../../site_config/standard',
        )));

        $config = new SiteConfig();
        $config->date = array($pattern);

        $contentExtractor->process(
            $html,
            'https://lemonde.io/35941909',
            $config
        );

        $this->assertEquals($dateExpected, $contentExtractor->getDate());
    }

    public function dataForStrip()
    {
        return array(
            array('//nav', '<html><body><nav id="high">hello !hello !hello !hello !hello !hello !hello !hello !hello !</nav><p>'.str_repeat('this is the best part of the show', 10).'</p></body></html>', 'hello !'),
            array('//p', '<html><body><nav id="high">'.str_repeat('hello !', 20).'</nav><p>'.str_repeat('this is the best part of the show', 10).'</p></body></html>', 'this is the best part of the show'),
        );
    }

    /**
     * @dataProvider dataForStrip
     */
    public function testApplyStrip($pattern, $html, $removedContent)
    {
        $contentExtractor = new ContentExtractor(array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/../../site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/../../site_config/standard',
        )));

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
        $contentExtractor = new ContentExtractor(array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/../../site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/../../site_config/standard',
        )));

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
        $contentExtractor = new ContentExtractor(array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/../../site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/../../site_config/standard',
        )));

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
            array('<html><body><p class="instapaper_ignore">hello !hello !hello !hello !hello !hello !hello !hello !hello !</p><p>'.str_repeat('this is the best part of the show', 10).'</p></body></html>', 'hello !'),
            array('<html><body><p class="entry-unrelated">hello !hello !hello !hello !hello !hello !hello !hello !hello !</p><p>'.str_repeat('this is the best part of the show', 10).'</p></body></html>', 'hello !'),
        );
    }

    /**
     * @dataProvider dataForStripDisplayNoneAndInstapaper
     */
    public function testApplyStripDisplayNoneAndInstapaper($html, $removedContent)
    {
        $contentExtractor = new ContentExtractor(array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/../../site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/../../site_config/standard',
        )));

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
            array(
                "//p[@id='content']",
                '<html><body><p id="content">hello !hello !hello !hello !hello !hello !hello !hello !hello !</p><p>'.str_repeat('this is the best part of the show', 10).'</p></body></html>',
                '<p id="content">hello !hello !hello !hello !hello !hello !hello !hello !hello !</p>'
            ),
            array(
                "//p[@class='content_wrapper']",
                '<html><body><p class="content_wrapper">hello !hello !hello !hello !hello !hello !hello !hello !hello !</p><p class="content_wrapper">'.str_repeat('this is the best part of the show', 5).'</p></body></html>',
                '<div><p class="content_wrapper">hello !hello !hello !hello !hello !hello !hello !hello !hello !</p><p class="content_wrapper">'.str_repeat('this is the best part of the show', 5).'</p></div>'
            ),
        );
    }

    /**
     * @dataProvider dataForExtractBody
     */
    public function testExtractBody($pattern, $html, $expectedContent)
    {
        $contentExtractor = new ContentExtractor(array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/../../site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/../../site_config/standard',
        )));

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
                '<html><body><div class="hentry"><p class="entry-title">hello !</p><time pubdate="2015-01-01">2015-01-01</time><a class="vcard author">hello !</a>hello !hello !hello !hello !hello !hello !hello !<p class="entry-content">'.str_repeat('this is the best part of the show', 10).'</p></div></body></html>',
                '<p class="entry-content">'.str_repeat('this is the best part of the show', 10).'</p>',
                array(
                    'title' => 'hello !',
                    'date' => strtotime('2015-01-01'),
                    'authors' => array('hello !'),
                ),
            ),
            // hNews with bad date
            array(
                '<html><body><div class="hentry"><time pubdate="2015-01-01">aweomse!</time>hello !hello !hello !hello !hello !hello !hello !<p class="entry-content">'.str_repeat('this is the best part of the show', 10).'</p></div></body></html>',
                '<p class="entry-content">'.str_repeat('this is the best part of the show', 10).'</p>',
                array(
                    'date' => null,
                )
            ),
            // hNews with many authors
            array(
                '<html><body><div class="hentry"><p class="vcard author"><a class="fn">first boy</a><a class="fn">first girl</a></p>hello !hello !hello !hello !hello !hello !hello !<p class="entry-content">'.str_repeat('this is the best part of the show', 10).'</p></div></body></html>',
                '<p class="entry-content">'.str_repeat('this is the best part of the show', 10).'</p>',
                array(
                    'authors' => array('first boy', 'first girl'),
                )
            ),
            // hNews with many content
            array(
                '<html><body><div class="hentry"><p class="entry-content">hello !hello !hello !hello !hello !hello !hello !</p><p class="entry-content">'.str_repeat('this is the best part of the show', 10).'</p></div></body></html>',
                '<div><p class="entry-content">hello !hello !hello !hello !hello !hello !hello !</p><p class="entry-content">'.str_repeat('this is the best part of the show', 10).'</p></div>',
                array()
            ),
        );
    }

    /**
     * @dataProvider dataForExtractHNews
     */
    public function testExtractHNews($html, $expectedContent, $expectedElements)
    {
        $contentExtractor = new ContentExtractor(array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/../../site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/../../site_config/standard',
        )));

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

    public function testExtractInstapaper()
    {
        $contentExtractor = new ContentExtractor(array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/../../site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/../../site_config/standard',
        )));

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
        );
    }

    /**
     * @dataProvider dataForExtractSchemaOrg
     */
    public function testExtractSchemaOrg($html, $expectedContent)
    {
        $contentExtractor = new ContentExtractor(array('config_builder' => array(
            'site_config_custom' => dirname(__FILE__).'/../../site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/../../site_config/standard',
        )));

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
}
