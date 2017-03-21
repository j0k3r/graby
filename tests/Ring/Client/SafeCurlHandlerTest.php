<?php

namespace Graby\tests\Ring\Client;

require __DIR__ . '/../../../vendor/guzzlehttp/ringphp/tests/Client/Server.php';

use Graby\Ring\Client\SafeCurlHandler;
use GuzzleHttp\Tests\Ring\Client\Server;

class SafeCurlHandlerTest extends \PHPUnit_Framework_TestCase
{
    protected function setUp()
    {
        if (!function_exists('curl_reset')) {
            $this->markTestSkipped('curl_reset() is not available');
        }
    }

    public function testCreatesCurlErrors()
    {
        $handler = new SafeCurlHandler();
        $response = $handler([
            'http_method' => 'GET',
            'uri' => '/',
            'headers' => ['host' => ['localhost:123']],
            'client' => ['timeout' => 0.001, 'connect_timeout' => 0.001],
        ]);
        $this->assertNull($response['status']);
        $this->assertNull($response['reason']);
        $this->assertSame([], $response['headers']);
        $this->assertInstanceOf(
            'GuzzleHttp\Ring\Exception\RingException',
            $response['error']
        );

        $this->assertSame('Provided port "123" doesn\'t match whitelisted values: 80, 443, 8080', $response['error']->getMessage());
    }

    public function testReusesHandles()
    {
        Server::flush();
        $response = ['status' => 200];
        Server::enqueue([$response, $response]);
        $a = new SafeCurlHandler();
        $request = [
            'http_method' => 'GET',
            'headers' => ['host' => [Server::$host]],
        ];
        $a($request);
        $a($request);
    }

    protected function getHandler($factory = null, $options = [])
    {
        return new SafeCurlHandler($options);
    }
}
