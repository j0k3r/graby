<?php

namespace Tests\Graby;

use Graby\Graby;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as HttpMockClient;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Theses tests doesn't provide any mock to test graby *in real life*.
 * This means tests will fail if you don't have an internet connexion OR if the targetted url change...
 * which will require to update the test.
 */
class GrabyFunctionalTest extends TestCase
{
    public function testRealFetchContent(): void
    {
        $logger = new Logger('foo');
        $handler = new TestHandler($level = Logger::INFO);
        $logger->pushHandler($handler);
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Connection' => ['keep-alive'], 'Content-Type' => ['text/html; charset=UTF-8'], 'X-Protected-By' => ['Sqreen'], 'Set-Cookie' => ['critical-article-free-desktop=f63cb50f6847971760d55dff34849007; expires=Thu, 10-Feb-2022 19:11:31 GMT; Max-Age=2592000; path=/; secure'], 'X-Frame-Options' => ['SAMEORIGIN'], 'X-XSS-Protection' => ['1; mode=block'], 'Via' => ['1.1 google, 1.1 varnish, 1.1 varnish'], 'Cache-Control' => ['private, max-age=0'], 'Accept-Ranges' => ['bytes'], 'Date' => ['Tue, 11 Jan 2022 19:11:31 GMT'], 'X-Served-By' => ['cache-cdg20730-CDG, cache-fra19144-FRA'], 'X-Cache' => ['MISS, MISS'], 'X-Cache-Hits' => ['0, 0'], 'X-Timer' => ['S1641928291.389187,VS0,VE95'], 'Vary' => ['Accept-Encoding'], 'Strict-Transport-Security' => ['max-age=31557600'], 'transfer-encoding' => ['chunked']], (string) file_get_contents(__DIR__ . '/fixtures/content/https___www.lemonde.fr_actualite-medias_article_2015_04_12_radio-france-vers-une-sortie-du-conflit_4614610_3236.html')));

        $graby = new Graby(['debug' => true], $httpMockClient);
        $graby->setLogger($logger);

        $res = $graby->fetchContent('https://www.lemonde.fr/actualite-medias/article/2015/04/12/radio-france-vers-une-sortie-du-conflit_4614610_3236.html');

        $this->assertCount(11, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('authors', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('image', $res);
        $this->assertArrayHasKey('native_ad', $res);
        $this->assertArrayHasKey('headers', $res);

        $this->assertSame(200, $res['status']);
        $this->assertSame('fr', $res['language']);
        $this->assertSame('https://www.lemonde.fr/actualite-medias/article/2015/04/12/radio-france-vers-une-sortie-du-conflit_4614610_3236.html', $res['url']);
        $this->assertSame('Grève à Radio France : vers une sortie du conflit ?', $res['title']);
        $this->assertStringContainsString('text/html', $res['headers']['content-type']);

        $records = $handler->getRecords();

        $this->assertGreaterThan(30, $records);
        $this->assertSame('Graby is ready to fetch', $records[0]['message']);
        $this->assertSame('. looking for site config for {host} in primary folder', $records[1]['message']);
        $this->assertSame('... found site config {host}', $records[2]['message']);
        $this->assertSame('Appending site config settings from global.txt', $records[3]['message']);
        $this->assertSame('. looking for site config for {host} in primary folder', $records[4]['message']);
        $this->assertSame('... found site config {host}', $records[5]['message']);
        $this->assertSame('Cached site config with key: {key}', $records[6]['message']);
        $this->assertSame('. looking for site config for {host} in primary folder', $records[7]['message']);
        $this->assertSame('... found site config {host}', $records[8]['message']);
        $this->assertSame('Appending site config settings from global.txt', $records[9]['message']);
        $this->assertSame('Cached site config with key: {key}', $records[10]['message']);
        $this->assertSame('Cached site config with key: {key}', $records[11]['message']);
        $this->assertSame('Fetching url: {url}', $records[12]['message']);
        $this->assertSame('https://www.lemonde.fr/actualite-medias/article/2015/04/12/radio-france-vers-une-sortie-du-conflit_4614610_3236.html', $records[12]['context']['url']);
        $this->assertSame('Trying using method "{method}" on url "{url}"', $records[13]['message']);
        $this->assertSame('get', $records[13]['context']['method']);
        $this->assertSame('Use default referer "{referer}" for url "{url}"', $records[15]['message']);
        $this->assertSame('Data fetched: {data}', $records[16]['message']);
        $this->assertSame('Looking for site config files to see if single page link exists', $records[18]['message']);
    }

    public function testRealFetchContent2(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => ['text/html; charset=UTF-8'], 'Expires' => ['Tue, 11 Jan 2022 19:11:31 GMT'], 'Date' => ['Tue, 11 Jan 2022 19:11:31 GMT'], 'Cache-Control' => ['private, max-age=0'], 'Last-Modified' => ['Mon, 06 Dec 2021 04:36:10 GMT'], 'X-Content-Type-Options' => ['nosniff'], 'X-XSS-Protection' => ['1; mode=block'], 'Server' => ['GSE'], 'Alt-Svc' => ['h3=":443"; ma=2592000,h3-29=":443"; ma=2592000,h3-Q050=":443"; ma=2592000,h3-Q046=":443"; ma=2592000,h3-Q043=":443"; ma=2592000,quic=":443"; ma=2592000; v="46,43"'], 'Accept-Ranges' => ['none'], 'Vary' => ['Accept-Encoding'], 'Transfer-Encoding' => ['chunked']], (string) file_get_contents(__DIR__ . '/fixtures/content/https___bjori.blogspot.com_2015_04_next-gen-mongodb-driver.html')));
        $graby = new Graby(['debug' => true], $httpMockClient);
        $res = $graby->fetchContent('https://bjori.blogspot.com/2015/04/next-gen-mongodb-driver.html');

        $this->assertCount(11, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('authors', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('image', $res);
        $this->assertArrayHasKey('native_ad', $res);
        $this->assertArrayHasKey('headers', $res);

        $this->assertSame(200, $res['status']);
        $this->assertSame(['bjori'], $res['authors']);
        $this->assertSame('en', $res['language']);
        $this->assertSame('https://bjori.blogspot.com/2015/04/next-gen-mongodb-driver.html', $res['url']);
        $this->assertSame('Next Generation MongoDB Driver for PHP!', $res['title']);
        $this->assertStringContainsString('For the past few months I\'ve been working on a "next-gen" MongoDB driver for PHP', $res['html']);
        $this->assertStringContainsString('text/html', $res['headers']['content-type']);
    }

    public function testPdfFile(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Server' => ['nginx/1.14.2'], 'Date' => ['Tue, 11 Jan 2022 19:11:32 GMT'], 'Content-Type' => ['application/pdf'], 'Content-Length' => ['414203'], 'Last-Modified' => ['Thu, 06 Mar 2008 11:04:29 GMT'], 'Connection' => ['keep-alive'], 'ETag' => ['"47cfcfbd-651fb"'], 'Accept-Ranges' => ['bytes']], (string) file_get_contents(__DIR__ . '/fixtures/content/http___img3.free.fr_im_tv_telesites_documentation.pdf')));
        $graby = new Graby(['debug' => true], $httpMockClient);
        $res = $graby->fetchContent('http://img3.free.fr/im_tv/telesites/documentation.pdf');

        $this->assertCount(11, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('authors', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('image', $res);
        $this->assertArrayHasKey('native_ad', $res);
        $this->assertArrayHasKey('headers', $res);

        $this->assertSame(200, $res['status']);
        $this->assertEmpty($res['language']);
        $this->assertEmpty($res['authors']);
        $this->assertSame('2008-03-05T17:56:07+01:00', $res['date']);
        $this->assertSame('http://img3.free.fr/im_tv/telesites/documentation.pdf', $res['url']);
        $this->assertSame('PDF', $res['title']);
        $this->assertStringContainsString('Free 2008', $res['html']);
        $this->assertStringContainsString('Free 2008', $res['summary']);
        $this->assertStringContainsString('application/pdf', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
    }

    public function testImageFile(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Connection' => ['keep-alive'], 'Content-Length' => ['158175'], 'Last-Modified' => ['Thu, 17 May 2018 20:44:59 GMT'], 'ETag' => ['"c2fb1185cf396855391e593d56c135fb"'], 'x-amz-storage-class' => ['STANDARD_IA'], 'Content-Type' => ['image/jpeg'], 'cache-control' => ['public, max-age=31536000'], 'Accept-Ranges' => ['bytes'], 'Date' => ['Tue, 11 Jan 2022 19:11:32 GMT'], 'Age' => ['5057089'], 'X-Served-By' => ['cache-bwi5144-BWI, cache-iad-kcgs7200076-IAD, cache-fra19179-FRA'], 'X-Cache' => ['HIT, HIT, HIT'], 'X-Cache-Hits' => ['1, 1, 2'], 'X-Timer' => ['S1641928293.732848,VS0,VE1'], 'Strict-Transport-Security' => ['max-age=300'], 'Access-Control-Allow-Methods' => ['GET, OPTIONS'], 'Access-Control-Allow-Origin' => ['*'], 'Server' => ['cat factory 1.0'], 'X-Content-Type-Options' => ['nosniff']], (string) file_get_contents(__DIR__ . '/fixtures/content/https___i.imgur.com_KQQ7D9z.jpg')));
        $graby = new Graby(['debug' => true], $httpMockClient);
        $res = $graby->fetchContent('https://i.imgur.com/KQQ7D9z.jpg');

        $this->assertCount(11, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('authors', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('image', $res);
        $this->assertArrayHasKey('native_ad', $res);
        $this->assertArrayHasKey('headers', $res);

        $this->assertSame(200, $res['status']);
        $this->assertEmpty($res['language']);
        $this->assertEmpty($res['authors']);
        $this->assertSame('https://i.imgur.com/KQQ7D9z.jpg', $res['url']);
        $this->assertSame('Image', $res['title']);
        $this->assertSame('<a href="https://i.imgur.com/KQQ7D9z.jpg"><img src="https://i.imgur.com/KQQ7D9z.jpg" alt="image" /></a>', $res['html']);
        $this->assertEmpty($res['summary']);
        $this->assertStringContainsString('image/jpeg', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
    }

    public function dataWithAccent(): array
    {
        return [
            // ['http://pérotin.com/post/2015/08/31/Le-cadran-solaire-amoureux'],
            ['https://en.wikipedia.org/wiki/Café'],
            // ['http://www.atterres.org/article/budget-2016-les-10-méprises-libérales-du-gouvernement'],
        ];
    }

    /**
     * @dataProvider dataWithAccent
     */
    public function testAccentuedUrls(string $url): void
    {
        $graby = new Graby(['debug' => true]);
        $res = $graby->fetchContent($url);

        $this->assertCount(11, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('authors', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('image', $res);
        $this->assertArrayHasKey('native_ad', $res);
        $this->assertArrayHasKey('headers', $res);

        $this->assertSame(200, $res['status']);
    }

    /**
     * Sometimes YouTube return an html response instead of a xml response.
     * The iframe return (when html) is bad:
     * <iframe id="video" width="&quot;480&quot;" height="&quot;270&quot;" src="https://www.youtube.com/%22https://www.youtube.com/embed/td0P8qrS8iI?feature=oembed%22" frameborder="&quot;0&quot;" allowfullscreen="allowfullscreen">[embedded content]</iframe>.
     *
     * That's why some assertion are commented
     */
    public function testYoutubeOembed(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Content-Type' => ['application/xml'], 'Vary' => ['X-Origin', 'Referer', 'Origin,Accept-Encoding'], 'Date' => ['Tue, 11 Jan 2022 19:11:32 GMT'], 'Server' => ['scaffolding on HTTPServer2'], 'Cache-Control' => ['private'], 'X-XSS-Protection' => ['0'], 'X-Frame-Options' => ['SAMEORIGIN'], 'X-Content-Type-Options' => ['nosniff'], 'Alt-Svc' => ['h3=":443"; ma=2592000,h3-29=":443"; ma=2592000,h3-Q050=":443"; ma=2592000,h3-Q046=":443"; ma=2592000,h3-Q043=":443"; ma=2592000,quic=":443"; ma=2592000; v="46,43"'], 'Accept-Ranges' => ['none'], 'Transfer-Encoding' => ['chunked']], (string) file_get_contents(__DIR__ . '/fixtures/content/https___www.youtube.com_oembed_url_https___www.youtube.com_watch_v_td0P8qrS8iI_format_xml.html')));
        $graby = new Graby(['debug' => true], $httpMockClient);
        $res = $graby->fetchContent('https://www.youtube.com/oembed?url=https://www.youtube.com/watch?v=td0P8qrS8iI&format=xml');

        $this->assertCount(11, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('authors', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('image', $res);
        $this->assertArrayHasKey('native_ad', $res);
        $this->assertArrayHasKey('headers', $res);

        $this->assertSame(200, $res['status']);
        $this->assertEmpty($res['language']);
        $this->assertSame('https://www.youtube.com/oembed?url=https://www.youtube.com/watch?v%3Dtd0P8qrS8iI&format=xml', $res['url']);
        $this->assertSame('[Review] The Matrix Falling (Rain) Source Code C++', $res['title']);
        // $this->assertSame('<iframe id="video" width="480" height="270" src="https://www.youtube.com/embed/td0P8qrS8iI?feature=oembed" frameborder="0" allowfullscreen="allowfullscreen">[embedded content]</iframe>', $res['html']);
        $this->assertSame('[embedded content]', $res['summary']);
        // $this->assertStringContainsString('text/xml', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
    }

    public function testEncodedUrl(): void
    {
        $this->markTestSkipped('Still need to find a way to handle / in query string (https://github.com/j0k3r/graby/pull/45).');

        // $graby = new Graby(['debug' => true]);
        // $res = $graby->fetchContent('http://blog.niqnutn.com/index.php?article49/commandes-de-base');

        // $this->assertCount(11, $res);

        // $this->assertArrayHasKey('status', $res);
        // $this->assertArrayHasKey('html', $res);
        // $this->assertArrayHasKey('title', $res);
        // $this->assertArrayHasKey('language', $res);
        // $this->assertArrayHasKey('date', $res);
        // $this->assertArrayHasKey('authors', $res);
        // $this->assertArrayHasKey('url', $res);
        // $this->assertArrayHasKey('summary', $res);
        // $this->assertArrayHasKey('image', $res);
        // $this->assertArrayHasKey('native_ad', $res);
        // $this->assertArrayHasKey('headers', $res);

        // $this->assertSame(200, $res['status']);
    }

    public function testKoreanPage(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Date' => ['Tue, 11 Jan 2022 19:11:33 GMT'], 'Server' => ['Apache/2.4.34 (Unix) OpenSSL/1.0.1e-fips PHP/5.6.30'], 'X-Powered-By' => ['PHP/5.6.30'], 'Set-Cookie' => ['PHPSESSID=ba04gseore7a1mdmsfrkek9gf6; path=/'], 'Vary' => ['Accept-Encoding'], 'Connection' => ['close'], 'Transfer-Encoding' => ['chunked'], 'Content-Type' => ['text/html; charset=UTF-8']], (string) file_get_contents(__DIR__ . '/fixtures/content/http___www.newstown.co.kr_news_articleView.html_idxno_243722.html')));
        $graby = new Graby(['debug' => true], $httpMockClient);
        $res = $graby->fetchContent('http://www.newstown.co.kr/news/articleView.html?idxno=243722');

        $this->assertCount(11, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('authors', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('image', $res);
        $this->assertArrayHasKey('native_ad', $res);
        $this->assertArrayHasKey('headers', $res);

        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('에르보리앙', $res['title']);
        $this->assertStringContainsString('프랑스 현대적 자연주의 브랜드', $res['summary']);
        $this->assertStringContainsString('text/html', $res['headers']['content-type']);
    }

    public function testMultipage(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['content-type' => ['text/html; charset=UTF-8'], 'vary' => ['Accept-Encoding'], 'cache-control' => ['max-age=3600, public'], 'date' => ['Tue, 11 Jan 2022 18:55:00 GMT'], 'x-frame-options' => ['deny'], 'x-content-type-options' => ['nosniff'], 'referrer-policy' => ['origin'], 'etag' => ['W/"5b3a8365e4e2dedd7836d180efd99752"'], 'x-via-popn' => ['front02'], 'strict-transport-security' => ['max-age=63072000; includeSubDomains; preload'], 'x-cacheable' => ['yes'], 'x-varnish' => ['613638104 613760736'], 'age' => ['995'], 'x-via-popv' => ['front02'], 'x-cache' => ['HIT'], 'accept-ranges' => ['bytes'], 'content-length' => ['252617'], 'x-via-poph' => ['front03']], (string) file_get_contents(__DIR__ . '/fixtures/content/https___www.clubic.com_carte-graphique_carte-graphique-amd_article-478936-1-radeon-hd-7750-7770.html')));
        $graby = new Graby([
            'debug' => true,
            'extractor' => [
                'config_builder' => [
                    'site_config' => [__DIR__ . '/fixtures/site_config'],
                ],
            ],
        ], $httpMockClient);
        $res = $graby->fetchContent('https://www.clubic.com/carte-graphique/carte-graphique-amd/article-478936-1-radeon-hd-7750-7770.html');

        $this->assertCount(11, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('authors', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('image', $res);
        $this->assertArrayHasKey('native_ad', $res);
        $this->assertArrayHasKey('headers', $res);

        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('Radeon HD 7750/7770', $res['title']);
        // which should be on the page 6
        $this->assertStringContainsString('2560x1600', $res['html']);
        $this->assertStringContainsString('text/html', $res['headers']['content-type']);
    }

    public function testCookie(): void
    {
        // Rector: do not add mock client – we are testing if the cookie is set.
        $graby = new Graby([
            'debug' => true,
            'extractor' => [
                'config_builder' => [
                    'site_config' => [__DIR__ . '/fixtures/site_config'],
                ],
            ],
        ]);
        $res = $graby->fetchContent('https://www.npr.org/sections/parallels/2017/05/19/529148729/michael-flynns-contradictory-line-on-russia');

        $this->assertCount(11, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('authors', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('image', $res);
        $this->assertArrayHasKey('native_ad', $res);
        $this->assertArrayHasKey('headers', $res);

        $this->assertSame(200, $res['status']);
        // if the cookie wasn't taking into account, it'll be "NPR Choice page"
        $this->assertSame('Michael Flynn\'s Contradictory Line On Russia', $res['title']);
    }

    public function testSaveXmlUnknownEncoding(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Server' => ['nginx'], 'Date' => ['Mon, 24 Jan 2022 14:01:07 GMT'], 'Content-Type' => ['text/html; charset=UTF-8'], 'Content-Length' => ['191168'], 'Connection' => ['keep-alive'], 'X-hacker' => ['If you\'re reading this, you should visit wpvip.com/careers and apply to join the fun, mention this header.'], 'X-Powered-By' => ['WordPress VIP <https://wpvip.com>'], 'Host-Header' => ['a9130478a60e5f9135f765b23f26593b'], 'Link' => ['<https://www.motherjones.com/wp-json/>; rel="https://api.w.org/"', '<https://www.motherjones.com/wp-json/wp/v2/posts/161491>; rel="alternate"; type="application/json"', '<https://www.motherjones.com/?p=161491>; rel=shortlink'], 'X-rq' => ['cdg1 0 4 9980'], 'Cache-Control' => ['max-age=300, must-revalidate'], 'Age' => ['1025'], 'X-Cache' => ['hit'], 'Vary' => ['Accept-Encoding'], 'Accept-Ranges' => ['bytes'], 'Strict-Transport-Security' => ['max-age=31536000;includeSubdomains']], (string) file_get_contents(__DIR__ . '/fixtures/content/https___www.motherjones.com_politics_2012_02_mac-mcclelland-free-online-shipping-warehouses-labor_.html')));
        $graby = new Graby([
            'debug' => true,
            'extractor' => [
                'config_builder' => [
                    'site_config' => [__DIR__ . '/fixtures/site_config'],
                ],
            ],
        ], $httpMockClient);
        $res = $graby->fetchContent('https://www.motherjones.com/politics/2012/02/mac-mcclelland-free-online-shipping-warehouses-labor/');

        $this->assertCount(11, $res);
        $this->assertSame(200, $res['status']);
    }

    public function testWithEmptyReplaceString(): void
    {
        $httpMockClient = new HttpMockClient();
        $httpMockClient->addResponse(new Response(200, ['Date' => ['Mon, 24 Jan 2022 14:01:08 GMT'], 'Server' => ['Apache'], 'Cache-Control' => ['no-cache'], 'Vary' => ['User-Agent,Accept-Encoding'], 'Content-Type' => ['text/html; charset=utf-8'], 'Set-Cookie' => ['PortalPortalDeDst=216508608.20992.0000; path=/; Httponly; Secure']], (string) file_get_contents(__DIR__ . '/fixtures/content/https___www.presseportal.de_pm_103258_2930232.html')));
        $graby = new Graby([
            'debug' => true,
            'extractor' => [
                'config_builder' => [
                    'site_config' => [__DIR__ . '/fixtures/site_config'],
                ],
            ],
        ], $httpMockClient);
        $res = $graby->fetchContent('https://www.presseportal.de/pm/103258/2930232');

        $this->assertCount(11, $res);
        $this->assertSame(200, $res['status']);
    }
}
