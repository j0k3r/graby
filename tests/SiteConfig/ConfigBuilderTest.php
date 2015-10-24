<?php

namespace Tests\Graby\SiteConfig;

use Graby\SiteConfig\SiteConfig;
use Graby\SiteConfig\ConfigBuilder;
use Monolog\Logger;
use Monolog\Handler\TestHandler;

class ConfigBuilderTest extends \PHPUnit_Framework_TestCase
{
    public function testConstructDefault()
    {
        $configBuilder = new ConfigBuilder(array('site_config' => array(dirname(__FILE__))));
    }

    public function testBuildFromArrayNoLines()
    {
        $configBuilder = new ConfigBuilder(array('site_config' => array(dirname(__FILE__))));
        $configActual = $configBuilder->parseLines(array());

        $this->assertEquals($configBuilder->create(), $configActual);
    }

    public function testBuildFromArray()
    {
        $configBuilder = new ConfigBuilder(array('site_config' => array(dirname(__FILE__))));
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
        $configBuilder = new ConfigBuilder(array('site_config' => array(dirname(__FILE__))));

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
        $configBuilder = new ConfigBuilder(array('site_config' => array(dirname(__FILE__))));

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
            // no config file found (global to the rescue)
            array('fr.m.localhost.dev', true),
            // config exists
            array('fr.wikipedia.org', true, '.wikipedia.org'),
            // config exists
            array('ted.com', true, 'ted.com'),
            // config exists
            array('stackoverflow.com', true, 'stackoverflow.com'),
            // config with no lines
            array('emptylines.com', false),
            // config with no auto_failure
            array('nofailure.io', true, 'nofailure.io'),
            // config with no lines
            array('emptylines.net', false),
        );
    }

    /**
     * @dataProvider dataForBuild
     */
    public function testBuildSiteConfig($host, $expectedRes, $matchedHost = false)
    {
        $configBuilder = new ConfigBuilder(array(
            'site_config' => array(dirname(__FILE__).'/../fixtures/site_config'),
        ));

        $res = $configBuilder->build($host);

        if (false === $expectedRes) {
            $this->assertFalse($res, 'No site config generated');
        } else {
            $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $res, 'Site config generated');
            $this->assertEquals($matchedHost, $res->cache_key);
        }
    }

    public function testBuildWithCachedVersion()
    {
        $configBuilder = new ConfigBuilder(array(
            'site_config' => array(dirname(__FILE__).'/../fixtures/site_config'),
        ));

        $res = $configBuilder->build('fr.wikipedia.org');

        $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $res);

        $configBuilder->addToCache($res->cache_key, $res);

        $res2 = $configBuilder->build('fr.wikipedia.org');

        $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $res);
        $this->assertEquals($res, $res2, 'Config retrieve from cache');
    }

    public function testLogMessage()
    {
        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $configBuilder = new ConfigBuilder(array(
            'site_config' => array(dirname(__FILE__).'/../fixtures/site_config'),
        ));
        $configBuilder->setLogger($logger);

        $res = $configBuilder->build('fr.wikipedia.org');

        $records = $handler->getRecords();

        $this->assertCount(5, $records);
        $this->assertEquals('. looking for site config for {host} in primary folder', $records[0]['message']);
        $this->assertEquals('fr.wikipedia.org', $records[0]['context']['host']);
        $this->assertEquals('... found site config {host}', $records[1]['message']);
        $this->assertEquals('.wikipedia.org.txt', $records[1]['context']['host']);
        $this->assertEquals('Appending site config settings from global.txt', $records[2]['message']);
    }
}
