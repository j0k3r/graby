<?php

namespace Tests\Graby;

use Graby\Graby;
use Monolog\Logger;
use Monolog\Handler\TestHandler;

/**
 * Theses tests doesn't provide any mock to test graby *in real life*.
 * This means tests will fail if you don't have an internet connexion OR if the targetted url change...
 * which will require to update the test.
 */
class GrabyFunctionalTest extends \PHPUnit_Framework_TestCase
{
    public function testRealFetchContent()
    {
        $logger = new Logger('foo');
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $graby = new Graby(array('debug' => true));
        $graby->setLogger($logger);

        $res = $graby->fetchContent('http://www.lemonde.fr/actualite-medias/article/2015/04/12/radio-france-vers-une-sortie-du-conflit_4614610_3236.html');

        $this->assertCount(10, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);
        $this->assertArrayHasKey('native_ad', $res);

        $this->assertEquals(200, $res['status']);
        $this->assertEquals('fr', $res['language']);
        $this->assertEquals('http://www.lemonde.fr/actualite-medias/article/2015/04/12/radio-france-vers-une-sortie-du-conflit_4614610_3236.html', $res['url']);
        $this->assertEquals('Grève à Radio France : vers une sortie du conflit ?', $res['title']);
        $this->assertEquals('text/html', $res['content_type']);

        $this->assertArrayHasKey('og_site_name', $res['open_graph']);
        $this->assertArrayHasKey('og_locale', $res['open_graph']);
        $this->assertArrayHasKey('og_url', $res['open_graph']);
        $this->assertArrayHasKey('og_title', $res['open_graph']);
        $this->assertArrayHasKey('og_description', $res['open_graph']);
        $this->assertArrayHasKey('og_image', $res['open_graph']);
        $this->assertArrayHasKey('og_image_width', $res['open_graph']);
        $this->assertArrayHasKey('og_image_height', $res['open_graph']);
        $this->assertArrayHasKey('og_image_type', $res['open_graph']);
        $this->assertArrayHasKey('og_type', $res['open_graph']);

        $records = $handler->getRecords();

        $this->assertGreaterThan(30, $records);
        $this->assertEquals('Graby is ready to fetch', $records[0]['message']);
        $this->assertEquals('. looking for site config for {host} in primary folder', $records[1]['message']);
        $this->assertEquals('... found site config {host}', $records[2]['message']);
        $this->assertEquals('Appending site config settings from global.txt', $records[3]['message']);
        $this->assertEquals('. looking for site config for {host} in primary folder', $records[4]['message']);
        $this->assertEquals('... found site config {host}', $records[5]['message']);
        $this->assertEquals('Cached site config with key: {key}', $records[6]['message']);
        $this->assertEquals('. looking for site config for {host} in primary folder', $records[7]['message']);
        $this->assertEquals('... found site config {host}', $records[8]['message']);
        $this->assertEquals('Appending site config settings from global.txt', $records[9]['message']);
        $this->assertEquals('Cached site config with key: {key}', $records[10]['message']);
        $this->assertEquals('Cached site config with key: {key}', $records[11]['message']);
        $this->assertEquals('Fetching url: {url}', $records[12]['message']);
        $this->assertEquals('http://www.lemonde.fr/actualite-medias/article/2015/04/12/radio-france-vers-une-sortie-du-conflit_4614610_3236.html', $records[12]['context']['url']);
        $this->assertEquals('Trying using method "{method}" on url "{url}"', $records[13]['message']);
        $this->assertEquals('get', $records[13]['context']['method']);
        $this->assertEquals('Use default referer "{referer}" for url "{url}"', $records[15]['message']);
        $this->assertEquals('Data fetched: {data}', $records[16]['message']);
        $this->assertEquals('Opengraph data: {ogData}', $records[18]['message']);
    }

    public function testRealFetchContent2()
    {
        $graby = new Graby(array('debug' => true));
        $res = $graby->fetchContent('http://bjori.blogspot.fr/2015/04/next-gen-mongodb-driver.html');

        $this->assertCount(10, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);
        $this->assertArrayHasKey('native_ad', $res);

        $this->assertEquals(200, $res['status']);
        $this->assertEmpty($res['language']);
        $this->assertEquals('http://bjori.blogspot.fr/2015/04/next-gen-mongodb-driver.html?_escaped_fragment_=', $res['url']);
        $this->assertEquals('Next Generation MongoDB Driver for PHP!', $res['title']);
        $this->assertContains('For the past few months I\'ve been working on a "next-gen" MongoDB driver for PHP', $res['html']);
        $this->assertEquals('text/html', $res['content_type']);
    }

    public function testContentWithXSS()
    {
        $graby = new Graby(array('debug' => true));
        $res = $graby->fetchContent('https://gist.githubusercontent.com/nicosomb/e58ca3585324b124e5146500ab2ac45a/raw/53f53bb7e5a6f99f4e84d263e9dd36ab0e154ff8/html.txt');

        $this->assertNotContains('<script>', $res['html']);
    }

    public function testBadUrl()
    {
        $graby = new Graby(array('debug' => true));
        $res = $graby->fetchContent('http://bjori.blogspot.fr/201');

        $this->assertCount(10, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);
        $this->assertArrayHasKey('native_ad', $res);

        $this->assertEquals(404, $res['status']);
        $this->assertEmpty($res['language']);
        $this->assertEquals('http://bjori.blogspot.fr/201', $res['url']);
        $this->assertEquals('No title found', $res['title']);
        $this->assertEquals('[unable to retrieve full-text content]', $res['html']);
        $this->assertEquals('[unable to retrieve full-text content]', $res['summary']);
        $this->assertEquals('text/html', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
    }

    public function testPdfFile()
    {
        $graby = new Graby(array('debug' => true));
        $res = $graby->fetchContent('http://img3.free.fr/im_tv/telesites/documentation.pdf');

        $this->assertCount(10, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);
        $this->assertArrayHasKey('native_ad', $res);

        $this->assertEquals(200, $res['status']);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('http://img3.free.fr/im_tv/telesites/documentation.pdf', $res['url']);
        $this->assertEquals('PDF', $res['title']);
        $this->assertContains('Free 2008', $res['html']);
        $this->assertContains('Free 2008', $res['summary']);
        $this->assertEquals('application/pdf', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
    }

    public function testPdfFileBadEncoding()
    {
        $graby = new Graby(array('debug' => true));
        $res = $graby->fetchContent('http://a-eon.biz/PDF/News_Release_Developer.pdf');

        $this->assertCount(10, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);
        $this->assertArrayHasKey('native_ad', $res);

        $this->assertEquals(200, $res['status']);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('http://a-eon.biz/PDF/News_Release_Developer.pdf', $res['url']);
        $this->assertEquals('News_Release_Developer', $res['title']);
        $this->assertContains('Amiga developers and users', $res['html']);
        $this->assertContains('Kickstarting', $res['summary']);
        $this->assertEquals('application/pdf', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
    }

    public function testImageFile()
    {
        $graby = new Graby(array('debug' => true, 'xss_filter' => false));
        $res = $graby->fetchContent('http://i.imgur.com/w9n2ID2.jpg');

        $this->assertCount(10, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);
        $this->assertArrayHasKey('native_ad', $res);

        $this->assertEquals(200, $res['status']);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('http://i.imgur.com/w9n2ID2.jpg', $res['url']);
        $this->assertEquals('Image', $res['title']);
        $this->assertEquals('<a href="http://i.imgur.com/w9n2ID2.jpg"><img src="http://i.imgur.com/w9n2ID2.jpg" alt="Image" /></a>', $res['html']);
        $this->assertEmpty($res['summary']);
        $this->assertEquals('image/jpeg', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
    }

    public function dataDate()
    {
        return array(
            array('http://www.lemonde.fr/economie/article/2011/07/05/moody-s-abaisse-la-note-du-portugal-de-quatre-crans_1545237_3234.html', '2011-07-05T22:09:59+02:00'),
            array('https://www.reddit.com/r/LinuxActionShow/comments/1fccny/arch_linux_survival_guide/', '2013-05-30T16:01:58+00:00'),
        );
    }

    /**
     * @dataProvider dataDate
     */
    public function testDate($url, $expectedDate)
    {
        $graby = new Graby(array('debug' => true, 'xss_filter' => false));
        $res = $graby->fetchContent($url);

        $this->assertCount(10, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);
        $this->assertArrayHasKey('native_ad', $res);

        $this->assertEquals($expectedDate, $res['date']);
    }

    public function dataWithAccent()
    {
        return array(
            array('http://pérotin.com/post/2009/06/09/SAV-Free-un-sketch-kafkaien'),
            array('https://en.wikipedia.org/wiki/Café'),
            array('http://www.atterres.org/article/budget-2016-les-10-méprises-libérales-du-gouvernement'),
            array('http://www.pro-linux.de/news/1/23430/linus-torvalds-über-das-internet-der-dinge.html'),
        );
    }

    /**
     * @dataProvider dataWithAccent
     */
    public function testAccentuedUrls($url)
    {
        $graby = new Graby(array('debug' => true, 'xss_filter' => false));
        $res = $graby->fetchContent($url);

        $this->assertCount(10, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);
        $this->assertArrayHasKey('native_ad', $res);

        $this->assertEquals(200, $res['status']);
    }

    public function testYoutubeOembed()
    {
        $graby = new Graby(array('debug' => true, 'xss_filter' => false));
        $res = $graby->fetchContent('http://www.youtube.com/oembed?url=https://www.youtube.com/watch?v=td0P8qrS8iI&format=xml');

        $this->assertCount(10, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);
        $this->assertArrayHasKey('native_ad', $res);

        $this->assertEquals(200, $res['status']);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('http://www.youtube.com/oembed?url=https://www.youtube.com/watch?v=td0P8qrS8iI&format=xml', $res['url']);
        $this->assertEquals('[Review] The Matrix Falling (Rain) Source Code C++', $res['title']);
        $this->assertEquals('<iframe id="video" width="480" height="270" src="https://www.youtube.com/embed/td0P8qrS8iI?feature=oembed" frameborder="0" allowfullscreen="">[embedded content]</iframe>', $res['html']);
        $this->assertEquals('[embedded content]', $res['summary']);
        $this->assertEquals('text/xml', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
    }

    public function testEncodedUrl()
    {
        $this->markTestSkipped('Still need to find a way to handle / in query string (https://github.com/j0k3r/graby/pull/45).');

        $graby = new Graby(array('debug' => true));
        $res = $graby->fetchContent('http://blog.niqnutn.com/index.php?article49/commandes-de-base');

        $this->assertCount(10, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);
        $this->assertArrayHasKey('native_ad', $res);

        $this->assertEquals(200, $res['status']);
    }

    public function testUTF16Content()
    {
        $graby = new Graby(array('debug' => true));
        $res = $graby->fetchContent('http://www.ufocasebook.com/gulfbreeze.html');

        $this->assertCount(10, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);
        $this->assertArrayHasKey('native_ad', $res);

        $this->assertEquals(200, $res['status']);
        $this->assertContains('The Gulf Breeze, Florida UFOs (Ed Walters), UFO Casebook files', $res['title']);
        $this->assertContains('Location of last UFO sighting in Gulf Breeze on Soundside Drive', $res['summary']);
        $this->assertEquals('text/html', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
    }

    public function testKoreanPage()
    {
        $graby = new Graby(array('debug' => true, 'xss_filter' => false));
        $res = $graby->fetchContent('http://www.newstown.co.kr/news/articleView.html?idxno=243722');

        $this->assertCount(10, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);
        $this->assertArrayHasKey('native_ad', $res);

        $this->assertEquals(200, $res['status']);
        $this->assertContains('뉴스타운', $res['title']);
        $this->assertContains('프랑스 현대적 자연주의 브랜드', $res['summary']);
        $this->assertEquals('text/html', $res['content_type']);
    }

    public function testMultipage()
    {
        $graby = new Graby(array(
            'debug' => true,
            'extractor' => array(
                'config_builder' => array(
                    'site_config' => array(dirname(__FILE__).'/fixtures/site_config'),
                ),
            ),
        ));
        $res = $graby->fetchContent('http://www.journaldugamer.com/tests/rencontre-ils-bossaient-sur-une-exclu-kinect-qui-ne-sortira-jamais/');

        $this->assertCount(10, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('date', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);
        $this->assertArrayHasKey('native_ad', $res);

        $this->assertEquals(200, $res['status']);
        $this->assertContains('[Rencontre] Ils bossaient sur une exclu Kinect qui ne sortira jamais', $res['title']);
        $this->assertContains('Le Journal du Gamer', $res['summary']);
        $this->assertEquals('text/html', $res['content_type']);
    }
}
