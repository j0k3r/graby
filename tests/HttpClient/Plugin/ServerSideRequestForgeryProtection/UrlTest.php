<?php

namespace Tests\Graby\HttpClient\Plugin\ServerSideRequestForgeryProtection;

use Graby\HttpClient\Plugin\ServerSideRequestForgeryProtection\Exception\InvalidURLException;
use Graby\HttpClient\Plugin\ServerSideRequestForgeryProtection\Exception\InvalidURLException\InvalidDomainException;
use Graby\HttpClient\Plugin\ServerSideRequestForgeryProtection\Exception\InvalidURLException\InvalidIPException;
use Graby\HttpClient\Plugin\ServerSideRequestForgeryProtection\Exception\InvalidURLException\InvalidPortException;
use Graby\HttpClient\Plugin\ServerSideRequestForgeryProtection\Exception\InvalidURLException\InvalidSchemeException;
use Graby\HttpClient\Plugin\ServerSideRequestForgeryProtection\Options;
use Graby\HttpClient\Plugin\ServerSideRequestForgeryProtection\Url;

class UrlTest extends \PHPUnit_Framework_TestCase
{
    public function dataForValidate()
    {
        return [
            [null, InvalidURLException::class, 'Provided URL "" cannot be empty'],
            ['http://user@:80', InvalidURLException::class, 'Error parsing URL "http://user@:80"'],
            ['http:///example.com/', InvalidURLException::class, 'Error parsing URL "http:///example.com/"'],
            ['http://:80', InvalidURLException::class, 'Error parsing URL "http://:80"'],
            ['/nohost', InvalidURLException::class, 'Provided URL "/nohost" doesn\'t contain a hostname'],
            ['ftp://domain.io', InvalidURLException\InvalidSchemeException::class, 'Provided scheme "ftp" doesn\'t match whitelisted values: http, https'],
            ['http://domain.io:22', InvalidPortException::class, 'Provided port "22" doesn\'t match whitelisted values: 80, 443, 8080'],
            ['http://login:password@google.fr:80', InvalidURLException::class, 'Credentials passed in but "sendCredentials" is set to false'],
        ];
    }

    /**
     * @dataProvider dataForValidate
     */
    public function testValidateUrl($url, $exception, $message)
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        Url::validateUrl($url, new Options());
    }

    public function testValidateScheme()
    {
        $this->expectException(InvalidSchemeException::class);
        $this->expectExceptionMessage('Provided scheme "http" matches a blacklisted value');

        $options = new Options();
        $options->addToList('blacklist', 'scheme', 'http');

        Url::validateUrl('http://www.fin1te.net', $options);
    }

    public function testValidatePort()
    {
        $this->expectException(InvalidPortException::class);
        $this->expectExceptionMessage('Provided port "8080" matches a blacklisted value');

        $options = new Options();
        $options->addToList('blacklist', 'port', '8080');

        Url::validateUrl('http://www.fin1te.net:8080', $options);
    }

    public function testValidateHostBlacklist()
    {
        $this->expectException(InvalidDomainException::class);
        $this->expectExceptionMessage('Provided host "www.fin1te.net" matches a blacklisted value');

        $options = new Options();
        $options->addToList('blacklist', 'domain', '(.*)\.fin1te\.net');

        Url::validateUrl('http://www.fin1te.net', $options);
    }

    public function testValidateHostWhitelist()
    {
        $this->expectException(InvalidDomainException::class);
        $this->expectExceptionMessage('Provided host "www.google.fr" doesn\'t match whitelisted values: (.*)\.fin1te\.net');

        $options = new Options();
        $options->addToList('whitelist', 'domain', '(.*)\.fin1te\.net');

        Url::validateUrl('http://www.google.fr', $options);
    }

    public function testValidateHostWithnoip()
    {
        $this->expectException(InvalidDomainException::class);
        $this->expectExceptionMessage('Provided host "www.youpi.boom" doesn\'t resolve to an IP address');

        $options = new Options();

        Url::validateUrl('http://www.youpi.boom', $options);
    }

    public function testValidateHostWithWhitelistIp()
    {
        $this->expectException(InvalidIPException::class);
        $this->expectExceptionMessage('Provided host "2.2.2.2" resolves to "2.2.2.2", which doesn\'t match whitelisted values: 1.1.1.1');

        $options = new Options();
        $options->addToList('whitelist', 'ip', '1.1.1.1');

        Url::validateUrl('http://2.2.2.2', $options);
    }

    public function testValidateHostWithWhitelistIpOk()
    {
        $options = new Options();
        $options->addToList('whitelist', 'ip', '1.1.1.1');

        $res = Url::validateUrl('http://1.1.1.1', $options);

        $this->assertCount(3, $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('host', $res);
        $this->assertArrayHasKey('ips', $res);
        $this->assertArrayHasKey(0, $res['ips']);
    }

    public function testValidateHostWithBlacklistIp()
    {
        $this->expectException(InvalidIPException::class);
        $this->expectExceptionMessage('Provided host "1.1.1.1" resolves to "1.1.1.1", which matches a blacklisted value: 1.1.1.1');

        $options = new Options();
        $options->addToList('blacklist', 'ip', '1.1.1.1');

        Url::validateUrl('http://1.1.1.1', $options);
    }

    public function testValidateUrlOk()
    {
        $options = new Options();
        $options->enablePinDns();

        $res = Url::validateUrl('http://www.fin1te.net:8080', $options);

        $this->assertCount(3, $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('host', $res);
        $this->assertArrayHasKey('ips', $res);
        $this->assertArrayHasKey(0, $res['ips']);
        $this->assertEquals('http://146.185.175.109:8080', $res['url']);
        $this->assertEquals('www.fin1te.net', $res['host']);

        $res = Url::validateUrl('http://www.fin1te.net:8080', new Options());

        $this->assertCount(3, $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('host', $res);
        $this->assertArrayHasKey('ips', $res);
        $this->assertArrayHasKey(0, $res['ips']);
        $this->assertEquals('http://www.fin1te.net:8080', $res['url']);
        $this->assertEquals('www.fin1te.net', $res['host']);
    }
}
