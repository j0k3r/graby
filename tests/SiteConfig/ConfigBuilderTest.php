<?php

namespace Tests\FullText\SiteConfig;

use FullText\SiteConfig\SiteConfig;
use FullText\SiteConfig\ConfigBuilder;

class ConfigBuilderTest extends \PHPUnit_Framework_TestCase
{
    public function testConstructDefault()
    {
        $configBuilder = new ConfigBuilder(array('site_config_custom' => dirname(__FILE__)));
    }

    public function testBuildFromArrayNoLines()
    {
        $configBuilder = new ConfigBuilder(array('site_config_custom' => dirname(__FILE__)));
        $configActual = $configBuilder->parseLines(array());

        $this->assertEquals($configBuilder->create(), $configActual);
    }

    public function testBuildFromArray()
    {
        $configBuilder = new ConfigBuilder(array('site_config_custom' => dirname(__FILE__)));
        $configActual = $configBuilder->parseLines(array(
            '# this is a comment and it will be removed',
            'no colon on this line, it will be removed',
            '   : empty value before colon, it will be removed',
            'title: hoho',
            'tidy: yes',
            'parser: bob',
            'replace_string(toto): titi',
        ));

        $configExpected = new SiteConfig();
        $configExpected->title = array('hoho');
        $configExpected->tidy = true;
        $configExpected->parser = 'bob';
        $configExpected->find_string = array('toto');
        $configExpected->replace_string = array('titi');

        $this->assertEquals($configExpected, $configActual);

        // without using default value
        $this->assertTrue($configActual->tidy(false));
        $this->assertEquals('bob', $configActual->parser(false));

        $this->assertNull($configActual->prune(false));
        $this->assertNull($configActual->autodetect_on_failure(false));

        // using default values
        $this->assertTrue($configActual->tidy(true));
        $this->assertEquals('bob', $configActual->parser(true));

        $this->assertTrue($configActual->prune(true));
        $this->assertTrue($configActual->autodetect_on_failure(true));
    }

    public function dataForAddToCache()
    {
        return array(
            array('mykey', '', 'mykey'),
            array('mykey', 'cachedkeyhihi', 'cachedkeyhihi'),
            array('www.localhost.dev', '', 'localhost.dev'),
        );
    }

    /**
     * @dataProvider dataForAddToCache
     */
    public function testAddToCache($key, $cachedKey, $expectedKey)
    {
        $configBuilder = new ConfigBuilder(array('site_config_custom' => dirname(__FILE__)));

        $config = $configBuilder->create();
        $config->body = array('//test');
        if ($cachedKey) {
            $config->cache_key = $cachedKey;
        }

        $configBuilder->addToCache($key, $config);

        $this->assertEquals($config, $configBuilder->getCachedVersion($expectedKey));
    }

    public function dataForCachedVersion()
    {
        return array(
            array('mykey', false),
            array('mykey', true),
            array('www.localhost.dev', true),
        );
    }

    /**
     * @dataProvider dataForCachedVersion
     */
    public function testCachedVersion($key, $cached)
    {
        $config = false;
        $configBuilder = new ConfigBuilder(array('site_config_custom' => dirname(__FILE__).'/custom'));

        if ($cached) {
            $config = $configBuilder->create();
            $config->body = array('//test');

            $configBuilder->addToCache($key, $config);
        }

        $this->assertEquals($config, $configBuilder->getCachedVersion($key));
    }

    public function dataForBuild()
    {
        return array(
            // bar hostname
            array('youknownothing/johnsnow', false),
            array('www.'.str_repeat('yay', 70).'.com', false),
            // no config file found
            array('fr.m.localhost.dev', false),
            // config in existing standard folder
            array('fr.wikipedia.org', true, '.wikipedia.org'),
            // config in existing custom folder
            array('ted.com', true, 'ted.com'),
            // config in existing custom & standard folder – config will be merged
            array('stackoverflow.com', true, 'stackoverflow.com'),
            // custom config but with no lines
            array('emptylines.com', false),
            // custom config but no auto_failure
            array('nofailure.io', true, 'nofailure.io'),
            // standard config but no lines
            array('emptylines.net', false),
        );
    }

    /**
     * @dataProvider dataForBuild
     */
    public function testBuildSiteConfig($host, $expectedRes, $matchedHost = false)
    {
        $configBuilder = new ConfigBuilder(array(
            'site_config_custom' => dirname(__FILE__).'/../fixtures/site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/../fixtures/site_config/standard',
        ));

        $res = $configBuilder->build($host);

        if (false === $expectedRes) {
            $this->assertFalse($res, 'No site config generated');
        } else {
            $this->assertInstanceOf('FullText\SiteConfig\SiteConfig', $res, 'Site config generated');
            $this->assertEquals($matchedHost, $res->cache_key);
        }
    }

    public function testBuildWithCachedVersion()
    {
        $configBuilder = new ConfigBuilder(array(
            'site_config_custom' => dirname(__FILE__).'/../fixtures/site_config/custom',
            'site_config_standard' => dirname(__FILE__).'/../fixtures/site_config/standard',
        ));

        $res = $configBuilder->build('fr.wikipedia.org');

        $this->assertInstanceOf('FullText\SiteConfig\SiteConfig', $res);

        $configBuilder->addToCache($res->cache_key, $res);

        $res2 = $configBuilder->build('fr.wikipedia.org');

        $this->assertInstanceOf('FullText\SiteConfig\SiteConfig', $res);
        $this->assertEquals($res, $res2, 'Config retrieve from cache');
    }
}
