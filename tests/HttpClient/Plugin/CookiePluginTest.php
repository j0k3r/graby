<?php

declare(strict_types=1);

namespace Tests\Graby\HttpClient\Plugin;

use Graby\HttpClient\Plugin\CookiePlugin;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Http\Message\CookieJar;
use PHPUnit\Framework\TestCase;

class CookiePluginTest extends TestCase
{
    public function testStoreSetCookiesIgnoresInvalidCookie(): void
    {
        $cookieJar = new CookieJar();
        $plugin = new CookiePlugin($cookieJar);
        $request = new Request('GET', 'https://www.tagesanzeiger.ch/article');
        $response = new Response(302, ['Set-Cookie' => 'invalid-cookie-without-value']);

        $plugin->storeSetCookies($request, $response);

        $this->assertCount(0, $cookieJar->getCookies());
    }

    public function testStoreSetCookiesIgnoresCookieFromAnotherDomain(): void
    {
        $cookieJar = new CookieJar();
        $plugin = new CookiePlugin($cookieJar);
        $request = new Request('GET', 'https://www.tagesanzeiger.ch/article');
        $response = new Response(302, [
            'Set-Cookie' => 'entitlementToken=test-token; Domain=.example.com; Path=/; Secure',
        ]);

        $plugin->storeSetCookies($request, $response);

        $this->assertCount(0, $cookieJar->getCookies());
    }
}
