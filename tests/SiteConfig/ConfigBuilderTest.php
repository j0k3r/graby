<?php

namespace Tests\Graby\SiteConfig;

use Graby\SiteConfig\ConfigBuilder;
use Graby\SiteConfig\SiteConfig;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class ConfigBuilderTest extends TestCase
{
    public function testConstructDefault(): void
    {
        $builder = new ConfigBuilder(['site_config' => [__DIR__]]);

        $this->assertInstanceOf('Graby\SiteConfig\ConfigBuilder', $builder);
    }

    public function testBuildFromArrayNoLines(): void
    {
        $configBuilder = new ConfigBuilder(['site_config' => [__DIR__]]);
        $configActual = $configBuilder->parseLines([]);

        $this->assertEqualsCanonicalizing($configBuilder->create(), $configActual);
    }

    public function testBuildFromArray(): void
    {
        $configBuilder = new ConfigBuilder(['site_config' => [__DIR__]]);
        $configActual = $configBuilder->parseLines([
            '# this is a comment and it will be removed',
            'no colon on this line, it will be removed',
            '   : empty value before colon, it will be removed',
            'title: hoho',
            'tidy: yes',
            'parser: bob',
            'src_lazy_load_attr: data-toto-src',
            'date: foo',
            'replace_string(toto): titi',
            'http_header(user-agent): my-user-agent',
            'http_header(referer): http://idontl.ie',
            'http_header(Cookie): GDPR_consent=1',
            'strip_attr: @class',
            'strip_attr: @style',
            'single_page_link: //canonical',
            'if_page_contains: //div/article/header',
        ]);

        $configExpected = new SiteConfig();
        $configExpected->title = ['hoho'];
        $configExpected->tidy = true;
        $configExpected->parser = 'bob';
        $configExpected->src_lazy_load_attr = 'data-toto-src';
        $configExpected->find_string = ['toto'];
        $configExpected->replace_string = ['titi'];
        $configExpected->http_header = [
            'user-agent' => 'my-user-agent',
            'referer' => 'http://idontl.ie',
            'cookie' => 'GDPR_consent=1',
        ];
        $configExpected->date = ['foo'];
        $configExpected->strip = ['@class', '@style'];
        $configExpected->single_page_link = ['//canonical'];
        $configExpected->if_page_contains = [
            'single_page_link' => [
                '//canonical' => '//div/article/header',
            ],
        ];

        $this->assertEqualsCanonicalizing($configExpected, $configActual);

        // without using default value
        $this->assertTrue(true === $configActual->tidy(false));
        $this->assertSame('bob', $configActual->parser(false));

        $this->assertNull($configActual->prune(false));
        $this->assertNull($configActual->autodetect_on_failure(false));

        // using default values
        $this->assertTrue(true === $configActual->tidy(true));
        $this->assertSame('bob', $configActual->parser(true));

        $this->assertTrue(true === $configActual->prune(true));
        $this->assertTrue(true === $configActual->autodetect_on_failure(true));
    }

    public function dataForAddToCache(): array
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
    public function testAddToCache(string $key, string $cachedKey, string $expectedKey): void
    {
        $configBuilder = new ConfigBuilder(['site_config' => [__DIR__]]);

        $config = $configBuilder->create();
        $config->body = ['//test'];
        if ($cachedKey) {
            $config->cache_key = $cachedKey;
        }

        $configBuilder->addToCache($key, $config);

        $this->assertSame($config, $configBuilder->getCachedVersion($expectedKey));
    }

    public function dataForCachedVersion(): array
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
    public function testCachedVersion(string $key, bool $cached): void
    {
        $config = false;
        $configBuilder = new ConfigBuilder(['site_config' => [__DIR__]]);

        if ($cached) {
            $config = $configBuilder->create();
            $config->body = ['//test'];

            $configBuilder->addToCache($key, $config);
        }

        $this->assertSame($config, $configBuilder->getCachedVersion($key));
    }

    public function testBuildOnCachedVersion(): void
    {
        $configBuilder = new ConfigBuilder(['site_config' => [__DIR__]]);
        $config1 = $configBuilder->buildForHost('www.host.io');

        $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $config1);

        $this->assertSame($config1, $configBuilder->getCachedVersion('host.io'));
        $this->assertSame($config1, $configBuilder->getCachedVersion('host.io.merged'));

        $config2 = $configBuilder->buildForHost('host.io');

        $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $config2);
        $this->assertSame($config1, $config2);
    }

    public function dataForBuild(): array
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
    public function testBuildSiteConfig(string $host, bool $expectedRes, ?string $matchedHost = null): void
    {
        $configBuilder = new ConfigBuilder([
            'site_config' => [__DIR__ . '/../fixtures/site_config'],
        ]);

        $res = $configBuilder->loadSiteConfig($host);

        if (false === $expectedRes) {
            $this->assertTrue(false === $res, 'No site config generated');
        } else {
            $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $res, 'Site config generated');
            $this->assertSame($matchedHost, $res->cache_key);
        }
    }

    public function testBuildWithCachedVersion(): void
    {
        $configBuilder = new ConfigBuilder([
            'site_config' => [__DIR__ . '/../fixtures/site_config'],
        ]);

        $res = $configBuilder->loadSiteConfig('fr.wikipedia.org');

        $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $res);

        $configBuilder->addToCache((string) $res->cache_key, $res);

        $res2 = $configBuilder->loadSiteConfig('fr.wikipedia.org');

        $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $res);
        $this->assertSame($res, $res2, 'Config retrieve from cache');
    }

    public function testLogMessage(): void
    {
        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $configBuilder = new ConfigBuilder([
            'site_config' => [__DIR__ . '/../fixtures/site_config'],
        ]);
        $configBuilder->setLogger($logger);

        $res = $configBuilder->buildFromUrl('https://fr.wikipedia.org/wiki/Wikip%C3%A9dia:Accueil_principal');

        $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $res);

        $records = $handler->getRecords();

        $this->assertGreaterThan(5, $records);
        $this->assertSame('. looking for site config for {host} in primary folder', $records[0]['message']);
        $this->assertSame('fr.wikipedia.org', $records[0]['context']['host']);
        $this->assertSame('... found site config {host}', $records[1]['message']);
        $this->assertSame('.wikipedia.org.txt', $records[1]['context']['host']);
        $this->assertSame('Appending site config settings from global.txt', $records[2]['message']);
    }

    public function testWithBadHost(): void
    {
        $configBuilder = new ConfigBuilder([
            'site_config' => [__DIR__ . '/../fixtures/site_config'],
        ]);

        $res = $configBuilder->buildFromUrl('http://user@:80/test');

        $this->assertInstanceOf('Graby\SiteConfig\SiteConfig', $res);
    }

    /**
     * Ensure merging config multiples times doesn't generate duplicate in replace_string / find_string.
     */
    public function testMergeConfigMultipleTimes(): void
    {
        $configBuilder = new ConfigBuilder([
            'site_config' => [__DIR__ . '/../fixtures/site_config'],
        ]);

        $config1 = new SiteConfig();
        $config1->find_string = ['toto'];
        $config1->replace_string = ['titi'];

        $config2 = new SiteConfig();
        $config2->find_string = ['papa'];
        $config2->replace_string = ['popo'];

        $config3 = $configBuilder->mergeConfig($config1, $config2);
        $config4 = $configBuilder->mergeConfig($config3, $config2);

        $this->assertCount(2, $config4->find_string);
        $this->assertCount(2, $config4->replace_string);
    }

    public function testCleanupFindReplaceString(): void
    {
        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $configBuilder = new ConfigBuilder(['site_config' => [__DIR__]]);
        $configBuilder->setLogger($logger);

        $configActual = $configBuilder->parseLines([
            'find_string: src="/assets/img/highlight_ph.png"',
        ]);

        $this->assertCount(0, $configActual->find_string);
        $this->assertCount(0, $configActual->replace_string);

        $records = $handler->getRecords();

        $this->assertSame('find_string & replace_string size mismatch, check the site config to fix it', $records[0]['message']);
        $this->assertCount(2, $records[0]['context']);
    }
}
