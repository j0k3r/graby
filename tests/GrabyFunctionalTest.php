<?php

namespace Tests\Graby;

use Graby\Graby;
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
    public function testRealFetchContent()
    {
        $logger = new Logger('foo');
        $handler = new TestHandler($level = Logger::INFO);
        $logger->pushHandler($handler);

        $graby = new Graby(['debug' => true]);
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
        $this->assertContains('text/html', $res['headers']['content-type']);

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
        $this->assertSame('Data fetched: {data}', $records[18]['message']);
        $this->assertSame('Looking for site config files to see if single page link exists', $records[20]['message']);
    }

    public function testRealFetchContent2()
    {
        $graby = new Graby(['debug' => true]);
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
        $this->assertEmpty($res['language']);
        $this->assertSame('https://bjori.blogspot.com/2015/04/next-gen-mongodb-driver.html', $res['url']);
        $this->assertSame('Next Generation MongoDB Driver for PHP!', $res['title']);
        $this->assertContains('For the past few months I\'ve been working on a "next-gen" MongoDB driver for PHP', $res['html']);
        $this->assertContains('text/html', $res['headers']['content-type']);
    }

    public function testPdfFile()
    {
        $graby = new Graby(['debug' => true]);
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
        $this->assertContains('Free 2008', $res['html']);
        $this->assertContains('Free 2008', $res['summary']);
        $this->assertContains('application/pdf', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
    }

    public function testImageFile()
    {
        $graby = new Graby(['debug' => true]);
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
        $this->assertContains('image/jpeg', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
    }

    public function dataWithAccent()
    {
        return [
            // ['http://pérotin.com/post/2015/08/31/Le-cadran-solaire-amoureux'],
            ['https://en.wikipedia.org/wiki/Café'],
            ['http://www.atterres.org/article/budget-2016-les-10-méprises-libérales-du-gouvernement'],
        ];
    }

    /**
     * @dataProvider dataWithAccent
     */
    public function testAccentuedUrls($url)
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

    public function testYoutubeOembed()
    {
        $graby = new Graby(['debug' => true]);
        $res = $graby->fetchContent('http://www.youtube.com/oembed?url=https://www.youtube.com/watch?v=td0P8qrS8iI&format=xml');

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
        $this->assertSame('http://www.youtube.com/oembed?url=https://www.youtube.com/watch?v=td0P8qrS8iI&format=xml', $res['url']);
        $this->assertSame('[Review] The Matrix Falling (Rain) Source Code C++', $res['title']);
        $this->assertSame('<iframe id="video" width="480" height="270" src="https://www.youtube.com/embed/td0P8qrS8iI?feature=oembed" frameborder="0" allowfullscreen="allowfullscreen">[embedded content]</iframe>', $res['html']);
        $this->assertSame('[embedded content]', $res['summary']);
        $this->assertContains('text/xml', $res['headers']['content-type']);
        $this->assertEmpty($res['image']);
    }

    public function testEncodedUrl()
    {
        $this->markTestSkipped('Still need to find a way to handle / in query string (https://github.com/j0k3r/graby/pull/45).');

        $graby = new Graby(['debug' => true]);
        $res = $graby->fetchContent('http://blog.niqnutn.com/index.php?article49/commandes-de-base');

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

    public function testKoreanPage()
    {
        $graby = new Graby(['debug' => true]);
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
        $this->assertContains('에르보리앙', $res['title']);
        $this->assertContains('프랑스 현대적 자연주의 브랜드', $res['summary']);
        $this->assertContains('text/html', $res['headers']['content-type']);
    }

    public function testMultipage()
    {
        $graby = new Graby([
            'debug' => true,
            'extractor' => [
                'config_builder' => [
                    'site_config' => [__DIR__ . '/fixtures/site_config'],
                ],
            ],
        ]);
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
        $this->assertContains('Radeon HD 7750/7770', $res['title']);
        // which should be on the page 6
        $this->assertContains('2560x1600', $res['html']);
        $this->assertContains('text/html', $res['headers']['content-type']);
    }

    public function testCookie()
    {
        $graby = new Graby([
            'debug' => true,
            'extractor' => [
                'config_builder' => [
                    'site_config' => [__DIR__ . '/fixtures/site_config'],
                ],
            ],
        ]);
        $res = $graby->fetchContent('http://www.npr.org/sections/parallels/2017/05/19/529148729/michael-flynns-contradictory-line-on-russia');

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
}
