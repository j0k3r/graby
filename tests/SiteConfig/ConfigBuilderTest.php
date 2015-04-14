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

        $this->assertEquals(new SiteConfig(), $configActual);
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
}
