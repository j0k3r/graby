<?php

namespace Tests\Graby\HttpClient\Plugin\ServerSideRequestForgeryProtection;

use Graby\HttpClient\Plugin\ServerSideRequestForgeryProtection\Options;
use Graby\HttpClient\Plugin\ServerSideRequestForgeryProtection\ServerSideRequestForgeryProtectionPlugin;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Http\Client\Common\Plugin\RedirectPlugin;
use Http\Client\Common\PluginClient;
use Http\Client\Exception\RequestException;
use Http\Mock\Client;

class ServerSideRequestForgeryProtectionPluginTest extends \PHPUnit_Framework_TestCase
{
    public function testGET()
    {
        $mockClient = new Client();
        $mockClient->addResponse(new Response(200));
        $client = new PluginClient($mockClient, [new ServerSideRequestForgeryProtectionPlugin()]);

        $response = $client->sendRequest(new Request('GET', 'http://www.google.com'));

        $this->assertNotEmpty($response);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function dataForBlockedUrl()
    {
        return [
            ['http://0.0.0.0:123', 'Provided port "123" doesn\'t match whitelisted values: 80, 443, 8080'],
            ['http://127.0.0.1/server-status', 'Provided host "127.0.0.1" resolves to "127.0.0.1", which matches a blacklisted value: 127.0.0.0/8'],
            ['file:///etc/passwd', 'Provided URL "file:///etc/passwd" doesn\'t contain a hostname'],
            ['ssh://localhost', 'Provided scheme "ssh" doesn\'t match whitelisted values: http, https'],
            ['gopher://localhost', 'Provided scheme "gopher" doesn\'t match whitelisted values: http, https'],
            ['telnet://localhost:25', 'Provided scheme "telnet" doesn\'t match whitelisted values: http, https'],
            ['http://169.254.169.254/latest/meta-data/', 'Provided host "169.254.169.254" resolves to "169.254.169.254", which matches a blacklisted value: 169.254.0.0/16'],
            ['ftp://myhost.com', 'Provided scheme "ftp" doesn\'t match whitelisted values: http, https'],
            ['http://user:pass@safecurl.fin1te.net?@google.com/', 'Credentials passed in but "sendCredentials" is set to false'],
        ];
    }

    /**
     * @dataProvider dataForBlockedUrl
     */
    public function testBlockedUrl($url, $message)
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage($message);

        $mockClient = new Client();
        $mockClient->addResponse(new Response(200));
        $client = new PluginClient($mockClient, [new ServerSideRequestForgeryProtectionPlugin()]);

        $client->sendRequest(new Request('GET', $url));
    }

    public function dataForBlockedUrlByOptions()
    {
        return [
            ['http://login:password@google.fr', 'Credentials passed in but "sendCredentials" is set to false'],
            ['http://safecurl.fin1te.net', 'Provided host "safecurl.fin1te.net" matches a blacklisted value'],
        ];
    }

    /**
     * @dataProvider dataForBlockedUrlByOptions
     */
    public function testBlockedUrlByOptions($url, $message)
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage($message);

        $options = new Options();
        $options->addToList('blacklist', 'domain', '(.*)\.fin1te\.net');
        $options->addToList('whitelist', 'scheme', 'ftp');
        $options->disableSendCredentials();

        $mockClient = new Client();
        $mockClient->addResponse(new Response(200));
        $client = new PluginClient($mockClient, [new ServerSideRequestForgeryProtectionPlugin($options)]);

        $client->sendRequest(new Request('GET', $url));
    }

    public function testWithPinDnsEnabled()
    {
        $options = new Options();
        $options->enablePinDns();

        $mockClient = new Client();
        $mockClient->addResponse(new Response(200));
        $client = new PluginClient($mockClient, [new ServerSideRequestForgeryProtectionPlugin($options)]);

        $response = $client->sendRequest(new Request('GET', 'http://google.com'));

        $this->assertNotEmpty($response);
    }

    public function testWithFollowLocationLeadingToABlockedUrl()
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Provided port "123" doesn\'t match whitelisted values: 80, 443, 8080');

        $options = new Options();
        $mockClient = new Client();
        $mockClient->addResponse(new Response(301, ['Location' => 'http://0.0.0.0:123/']));
        $mockClient->addResponse(new Response(200));
        $client = new PluginClient($mockClient, [
            new ServerSideRequestForgeryProtectionPlugin($options),
            new RedirectPlugin(),
        ]);

        $client->sendRequest(new Request('GET', 'http://google.com'));
    }
}
