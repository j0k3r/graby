<?php

namespace Tests\Graby\SiteConfig;

use Graby\SiteConfig\ConfigBuilder;
use Graby\SiteConfig\SiteConfig;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

class ConfigBuilderTest extends \PHPUnit_Framework_TestCase
{
    public function testConstructDefault()
    {
        $configBuilder = new ConfigBuilder(['site_config' => [dirname(__FILE__)]]);
    }

    public function testBuildFromArrayNoLines()
    {
        $configBuilder = new ConfigBuilder(['site_config' => [dirname(__FILE__)]]);
        $configActual = $configBuilder->parseLines([]);

        $this->assertEquals($configBuilder->create(), $configActual);
    }

    public function testBuildFromArray()
    {
        $configBuilder = new ConfigBuilder(['site_config' => [dirname(__FILE__)]]);
        $configActual = $configBuilder->parseLines([
            '# this is a comment and it will be removed',
            'no colon on this line, it will be removed',
            '   : empty value before colon, it will be removed',
            'title: hoho',
            'tidy: yes',
            'parser: bob',
            'date: foo',
            'replace_string(toto): titi',
            'http_header(user-agent): my-user-agent',
            'http_header(referer): http://idontl.ie',
        ]);

        $configExpected = new SiteConfig();
        $configExpected->title = ['hoho'];
        $configExpected->tidy = true;
        $configExpected->parser = 'bob';
        $configExpected->find_string = ['toto'];
        $configExpected->replace_string = ['titi'];
        $configExpected->http_header = [
            'user-agent' => 'my-user-agent',
            'referer' => 'http://idontl.ie',
        ];
        $configExpected->date = ['foo'];

        $this->assertEquals($configExpected, $configActual);

        // without using default value
        $this->assertTrue($configActual->tidy(false));
        $this->assertSame('bob', $configActual->parser(false));

        $this->assertNull($configActual->prune(false));
        $this->assertNull($configActual->autodetect_on_failure(false));

        // using default values
        $this->assertTrue($configActual->tidy(true));
        $this->assertSame('bob', $configActual->parser(true));

        $this->assertTrue($configActual->prune(true));
        $this->assertTrue($configActual->autodetect_on_failure(true));
    }

    public function dataForAddToCache()
    {
        return [
            ['mykey', '', 'mykey'],
            ['mykey', 'cachedkeyhihi', 'cachedkeyhihi'],
            ['www.localhost.dev', '', 'localhost.dev'],
        ];
    }

    /**
     * @dataProvider dataForAddToCache
     */
    public function testAddToCache($key, $cachedKey, $expectedKey)
    {
        $configBuilder = new ConfigBuilder(['site_config' => [dirname(__FILE__)]]);

        $config = $configBuilder->create();
        $config->body = ['//test'];
        if ($cachedKey) {
            $config->cache_key = $cachedKey;
        }

        $configBuilder->addToCache($key, $config);

        $this->assertEquals($config, $configBuilder->getCachedVersion($expectedKey));
    }

    public function dataForCachedVersion()
    {
        return [
            ['mykey', false],
            ['mykey', true],
            ['www.localhost.dev', true],
        ];
    }

    /**
     * @dataProvider dataForCachedVersion
     */
    public function testCachedVersion($key, $cached)
    {
        $config = false;
        $configBuilder = new ConfigBuilder(['site_config' => [dirname(__FILE__)]]);

        if ($cached) {
            $config = $configBuilder->create();
            $config->body = ['//test'];

            $configBuilder->addToCache($key, $config);
        }

        $this->assertEquals($config, $configBuilder->getCachedVersion($key));
    }

    public function testBuildOnCachedVersion()
    {
        $configBuilder = new ConfigBuilder(['site_config' => [dirname(__FILE__)]]);
        $config1 = $configBuilder->buildForHost('www.host.io');

        $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $config1);

        $this->assertEquals($config1, $configBuilder->getCachedVersion('host.io'));
        $this->assertEquals($config1, $configBuilder->getCachedVersion('host.io.merged'));

        $config2 = $configBuilder->buildForHost('host.io');

        $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $config2);
        $this->assertEquals($config1, $config2);
    }

    public function dataForBuild()
    {
        return [
            // bar hostname
            ['youknownothing/johnsnow', false],
            ['www.' . str_repeat('yay', 70) . '.com', false],
            // no config file found (global to the rescue)
            ['fr.m.localhost.dev', true],
            // config exists
            ['fr.wikipedia.org', true, '.wikipedia.org'],
            // config exists
            ['ted.com', true, 'ted.com'],
            // config exists
            ['stackoverflow.com', true, 'stackoverflow.com'],
            // config with no lines
            ['emptylines.com', false],
            // config with no auto_failure
            ['nofailure.io', true, 'nofailure.io'],
            // config with no lines
            ['emptylines.net', false],
        ];
    }

    /**
     * @dataProvider dataForBuild
     */
    public function testBuildSiteConfig($host, $expectedRes, $matchedHost = null)
    {
        $configBuilder = new ConfigBuilder([
            'site_config' => [dirname(__FILE__) . '/../fixtures/site_config'],
        ]);

        $res = $configBuilder->loadSiteConfig($host);

        if (false === $expectedRes) {
            $this->assertFalse($res, 'No site config generated');
        } else {
            $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $res, 'Site config generated');
            $this->assertSame($matchedHost, $res->cache_key);
        }
    }

    public function testBuildWithCachedVersion()
    {
        $configBuilder = new ConfigBuilder([
            'site_config' => [dirname(__FILE__) . '/../fixtures/site_config'],
        ]);

        $res = $configBuilder->loadSiteConfig('fr.wikipedia.org');

        $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $res);

        $configBuilder->addToCache($res->cache_key, $res);

        $res2 = $configBuilder->loadSiteConfig('fr.wikipedia.org');

        $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $res);
        $this->assertEquals($res, $res2, 'Config retrieve from cache');
    }

    public function testLogMessage()
    {
        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $configBuilder = new ConfigBuilder([
            'site_config' => [dirname(__FILE__) . '/../fixtures/site_config'],
        ]);
        $configBuilder->setLogger($logger);

        $res = $configBuilder->buildFromUrl('https://fr.wikipedia.org/wiki/Wikip%C3%A9dia:Accueil_principal');

        $records = $handler->getRecords();

        $this->assertGreaterThan(5, $records);
        $this->assertSame('. looking for site config for {host} in primary folder', $records[0]['message']);
        $this->assertSame('fr.wikipedia.org', $records[0]['context']['host']);
        $this->assertSame('... found site config {host}', $records[1]['message']);
        $this->assertSame('.wikipedia.org.txt', $records[1]['context']['host']);
        $this->assertSame('Appending site config settings from global.txt', $records[2]['message']);
    }
}
