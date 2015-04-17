<?php

namespace Tests\FullText\Extractor;

use FullText\Extractor\ContentExtractor;

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
        $contentExtractor = new ContentExtractor(array('config_builder' => array('site_config_custom' => dirname(__FILE__).'/../../site_config/custom')));
        $res = $contentExtractor->buildSiteConfig('http://0.0.0.0');

        $this->assertInstanceOf('FullText\SiteConfig\SiteConfig', $res);

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
            '<html>&lt;iframe &gt;&lt;/iframe&gt;</html>',
            'https://vimeo.com/35941909'
        );

        $this->assertTrue($res);

        $content_block = $contentExtractor->getContent();

        $this->assertEquals('<iframe id="video" name="video"/>', $content_block->ownerDocument->saveXML($content_block));
    }
}
