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

        $this->assertCount(8, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);

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
        $this->assertEquals('Fetching url: {url}', $records[1]['message']);
        $this->assertEquals('http://www.lemonde.fr/actualite-medias/article/2015/04/12/radio-france-vers-une-sortie-du-conflit_4614610_3236.html', $records[1]['context']['url']);
        $this->assertEquals('Trying using method "{method}" on url "{url}"', $records[2]['message']);
        $this->assertEquals('get', $records[2]['context']['method']);
        $this->assertEquals('Data fetched: {data}', $records[3]['message']);
        $this->assertEquals('Opengraph data: {ogData}', $records[4]['message']);
    }

    public function testRealFetchContent2()
    {
        $graby = new Graby(array('debug' => true));
        $res = $graby->fetchContent('http://bjori.blogspot.fr/2015/04/next-gen-mongodb-driver.html');

        $this->assertCount(8, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);

        $this->assertEquals(200, $res['status']);
        $this->assertEmpty($res['language']);
        $this->assertEquals('http://bjori.blogspot.fr/2015/04/next-gen-mongodb-driver.html?_escaped_fragment_=', $res['url']);
        $this->assertEquals('Next Generation MongoDB Driver for PHP!', $res['title']);
        $this->assertContains('For the past few months I\'ve been working on a "next-gen" MongoDB driver for PHP', $res['html']);
        $this->assertEquals('text/html', $res['content_type']);
    }

    public function testBadUrl()
    {
        $graby = new Graby(array('debug' => true));
        $res = $graby->fetchContent('http://bjori.blogspot.fr/201');

        $this->assertCount(8, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);

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
        $res = $graby->fetchContent('http://www.relacweb.org/conferencia/images/documentos/Hoteles_cerca.pdf');

        $this->assertCount(8, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);

        $this->assertEquals(200, $res['status']);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('http://www.relacweb.org/conferencia/images/documentos/Hoteles_cerca.pdf', $res['url']);
        $this->assertEquals('1725.PDF', $res['title']);
        $this->assertContains('University of Liverpool', $res['html']);
        $this->assertContains('University of Liverpool', $res['summary']);
        $this->assertEquals('application/pdf', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
    }

    public function testImageFile()
    {
        $graby = new Graby(array('debug' => true));
        $res = $graby->fetchContent('http://i.imgur.com/w9n2ID2.jpg');

        $this->assertCount(8, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);

        $this->assertEquals(200, $res['status']);
        $this->assertEquals('', $res['language']);
        $this->assertEquals('http://i.imgur.com/w9n2ID2.jpg', $res['url']);
        $this->assertEquals('Image', $res['title']);
        $this->assertEquals('<a href="http://i.imgur.com/w9n2ID2.jpg"><img src="http://i.imgur.com/w9n2ID2.jpg" alt="Image" /></a>', $res['html']);
        $this->assertEmpty($res['summary']);
        $this->assertEquals('image/jpeg', $res['content_type']);
        $this->assertEquals(array(), $res['open_graph']);
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
        $graby = new Graby(array('debug' => true));
        $res = $graby->fetchContent($url);

        $this->assertCount(8, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);

        $this->assertEquals(200, $res['status']);
    }

    public function testYoutubeOembed()
    {
        $graby = new Graby(array('debug' => true));
        $res = $graby->fetchContent('http://www.youtube.com/oembed?url=https://www.youtube.com/watch?v=td0P8qrS8iI&format=xml');

        $this->assertCount(8, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);

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
        $graby = new Graby(array('debug' => true));
        $res = $graby->fetchContent('http://blog.niqnutn.com/index.php?article49/commandes-de-base');

        $this->assertCount(8, $res);

        $this->assertArrayHasKey('status', $res);
        $this->assertArrayHasKey('html', $res);
        $this->assertArrayHasKey('title', $res);
        $this->assertArrayHasKey('language', $res);
        $this->assertArrayHasKey('url', $res);
        $this->assertArrayHasKey('content_type', $res);
        $this->assertArrayHasKey('summary', $res);
        $this->assertArrayHasKey('open_graph', $res);

        $this->assertEquals(200, $res['status']);
    }
}
