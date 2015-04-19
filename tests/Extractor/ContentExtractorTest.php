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

        $this->assertContains('<iframe id="video" name="video"/>', $content_block->ownerDocument->saveXML($content_block));
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
            array('//time[@pubdate or @pubDate]', '<html><time pubdate="2015-01-01">2015-01-01</time></html>', strtotime('2015-01-01')),
            array('//time[@pubdate or @pubDate]', '<html><time pubdate="2015-01-01">date</time></html>', null),
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
}
