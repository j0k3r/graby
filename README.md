# Graby

[![Join the chat at https://gitter.im/j0k3r/graby](https://badges.gitter.im/j0k3r/graby.svg)](https://gitter.im/j0k3r/graby?utm_source=badge&utm_medium=badge&utm_campaign=pr-badge&utm_content=badge)
[![Build Status](https://travis-ci.org/j0k3r/graby.svg?branch=master)](https://travis-ci.org/j0k3r/graby)
[![Coverage Status](https://coveralls.io/repos/j0k3r/graby/badge.svg?branch=master&service=github)](https://coveralls.io/github/j0k3r/graby?branch=master)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/j0k3r/graby/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/j0k3r/graby/?branch=master)

Graby helps you extract article content from web pages.

- it's based on [php-readability](https://github.com/j0k3r/php-readability)
- it uses [site_config](http://help.fivefilters.org/customer/portal/articles/223153-site-patterns) to extra content from websites
- it's a fork of Full-Text RSS v3.3 from [@fivefilters](http://fivefilters.org/)

## Why this fork ?

Full-Text RSS works great as a standalone application. But when you need to encapsulate it in your own library it's a mess. You need this kind of ugly thing:

```php
$article = 'http://www.bbc.com/news/entertainment-arts-32547474';
$request = 'http://example.org/full-text-rss/makefulltextfeed.php?format=json&url='.urlencode($article);
$result  = @file_get_contents($request);
```

Also, if you want to understand how things work internally, it's really hard to read and understand. And finally, there are **no tests** at all.

That's why I made this fork:

1. Easiest way to integrate it (using composer)
2. Fully tested
3. (hopefully) better to understand
4. A bit more decoupled

## How to use it

### Requirements

By default, this lib will use the [Tidy extension](https://github.com/htacg/tidy-html5) if it's available. Tidy is only used to cleanup the given HTML and avoid problems with bad HTML structure, etc .. It'll be suggested by Composer.

Also, if you got problem from parsing a content without Tidy installed, please install it and try again.

### Retrieve content from an url

Add the lib using composer:

    composer require j0k3r/graby

Use the class to retrieve content:

```php
use Graby\Graby;

$article = 'http://www.bbc.com/news/entertainment-arts-32547474';

$graby = new Graby();
$result = $graby->fetchContent($article);

var_dump($result);
/*
array (
  'status' => 200
  'html' => "Fetched and readable content"
  'title' => "Ben E King: R&B legend dies at 76"
  'language' => "en"
  'date' => NULL
  'authors' => array ()
  'url' => "http://www.bbc.com/news/entertainment-arts-32547474"
  'content_type' => "text/html"
  'open_graph' => array (
    'og_title' => 'Ben E King: R&B legend dies at 76 - BBC News'
    'og_type' => 'article'
    'og_description' => 'R&B and soul singer Ben E King, best known for the classic song Stand By Me, dies at the age of 76.'
    'og_site_name' => 'BBC News'
    'og_locale' => 'en_GB'
    'og_article_author' => 'BBC News'
    'og_article_section' => 'Entertainment & Arts'
    'og_url' => 'http://www.bbc.com/news/entertainment-arts-32547474'
    'og_image' => 'http://ichef-1.bbci.co.uk/news/1024/media/images/82695000/jpg/_82695869_kingap.jpg'
  )
  'summary' => "Ben E King received an award from the Songwriters Hall of Fame in &hellip;"
  'native_ad' => false
  'all_headers' => array (
    'server' => 'Apache'
    'content-type' => 'text/html; charset=utf-8'
    'x-news-data-centre' => 'cwwtf'
    'content-language' => 'en'
    'x-pal-host' => 'pal074.back.live.cwwtf.local:80'
    'x-news-cache-id' => '13648'
    'content-length' => '157341'
    'date' => 'Sat, 29 Apr 2017 07:35:39 GMT'
    'connection' => 'keep-alive'
    'cache-control' => 'private, max-age=60, stale-while-revalidate'
    'x-cache-action' => 'MISS'
    'x-cache-age' => '0'
    'x-lb-nocache' => 'true'
    'vary' => 'X-CDN,X-BBC-Edge-Cache,Accept-Encoding'
  )
)
*/
```

In case of error when fetching the url, graby won't throw an exception but will return information about the error (at least the status code):

```php
/*
array(
  'status' => 404
  'html' => "[unable to retrieve full-text content]"
  'title' => "No title found"
  'language' => NULL
  'date' => NULL
  'authors' => array()
  'url' => "http://www.bbc.com/404"
  'content_type' => "text/html"
  'open_graph' => array()
  'summary' => "[unable to retrieve full-text content]"
  'native_ad' => false
  'all_headers' => array()
)
*/
```

The `date` result is the same as displayed in the content. If `date` is not `null` in the result, we recommend you to parse it using [`date_parse`](http://php.net/date_parse) (this is what we are using to validate that the date is correct).

### Cleanup content

Since the 1.9.0 version, you can also send html content to be cleanup in the same way graby clean content retrieved from an url. The url is still needed to convert links to absolute, etc.

```php
use Graby\Graby;

$article = 'http://www.bbc.com/news/entertainment-arts-32547474';
// use your own way to retrieve html or to provide html
$html = ...

$graby = new Graby();
$result = $graby->cleanupHtml($html, $article);
```

### Use custom handler & formatter to see output log

You can use them to display graby output log to the end user.
It's aim to be used in a Symfony project using Monolog.

Define the graby handler service (somewhere in a `service.yml`):

```yaml
services:
    # ...
    graby.log_handler:
        class: Graby\Monolog\Handler\GrabyHandler
```

Then define the Monolog handler in your `app/config/config.yml`:

```yaml
monolog:
    handlers:
        graby:
            type: service
            id: graby.log_handler
            level: debug
            channels: ['graby']
```

You can then retrieve logs from graby in your controller using:

```php
$logs = $this->get('monolog.handler.graby')->getRecords();
```

## Full configuration

This is the full documented configuration and also the default one.

```php
$graby = new Graby(array(
    // Enable or disable debugging.
    // This will only generate log information in a file (log/graby.log)
    'debug' => false,
    // If enabled relative URLs found in the extracted content are automatically rewritten as absolute URLs.
    'rewrite_relative_urls' => true,
    // If enabled, we will try to follow single page links (e.g. print view) on multi-page articles.
    // Currently this only happens for sites where single_page_link has been defined
    // in a site config file.
    'singlepage' => true,
    // If enabled, we will try to follow next page links on multi-page articles.
    // Currently this only happens for sites where next_page_link has been defined
    // in a site config file.
    'multipage' => true,
    // Error message when content extraction fails
    'error_message' => '[unable to retrieve full-text content]',
    // Default title when we won't be able to extract a title
    'error_message_title' => 'No title found',
    // List of URLs (or parts of a URL) which will be accept.
    // If the list is empty, all URLs (except those specified in the blocked list below)
    // will be permitted.
    // Example: array('example.com', 'anothersite.org');
    'allowed_urls' => array(),
    // List of URLs (or parts of a URL) which will be not accept.
    // Note: this list is ignored if allowed_urls is not empty
    'blocked_urls' => array(),
    // If enabled, we'll pass retrieved HTML content through htmLawed with
    // safe flag on and style attributes denied, see
    // http://www.bioinformatics.org/phplabware/internal_utilities/htmLawed/htmLawed_README.htm#s3.6
    // Note: if enabled this will also remove certain elements you may want to preserve, such as iframes.
    'xss_filter' => true,
    // Here you can define different actions based on the Content-Type header returned by server.
    // MIME type as key, action as value.
    // Valid actions:
    // * 'exclude' - exclude this item from the result
    // * 'link' - create HTML link to the item
    'content_type_exc' => array(
       'application/pdf' => array('action' => 'link', 'name' => 'PDF'),
       'image'           => array('action' => 'link', 'name' => 'Image'),
       'audio'           => array('action' => 'link', 'name' => 'Audio'),
       'video'           => array('action' => 'link', 'name' => 'Video'),
    ),
    // How we handle link in content
    // Valid values :
    // * preserve: nothing is done
    // * footnotes: convert links as footnotes
    // * remove: remove all links
    'content_links' => 'preserve',
    'http_client' => array(
        // User-Agent used to fetch content
        'ua_browser' => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/535.2 (KHTML, like Gecko) Chrome/15.0.874.92 Safari/535.2',
        // default referer when fetching content
        'default_referer' => 'http://www.google.co.uk/url?sa=t&source=web&cd=1',
        // Currently allows simple string replace of URLs.
        // Useful for rewriting certain URLs to point to a single page or HTML view.
        // Although using the single_page_link site config instruction is the preferred way to do this, sometimes, as
        // with Google Docs URLs, it's not possible.
        'rewrite_url' => array(
            'docs.google.com'  => array('/Doc?' => '/View?'),
            'tnr.com'          => array('tnr.com/article/' => 'tnr.com/print/article/'),
            '.m.wikipedia.org' => array('.m.wikipedia.org' => '.wikipedia.org'),
            'm.vanityfair.com' => array('m.vanityfair.com' => 'www.vanityfair.com'),
        ),
        // Prevent certain file/mime types
        // HTTP responses which match these content types will
        // be returned without body.
        'header_only_types' => array(
           'application/pdf',
           'image',
           'audio',
           'video',
        ),
        // URLs ending with one of these extensions will
        // prompt Humble HTTP Agent to send a HEAD request first
        // to see if returned content type matches $headerOnlyTypes.
        'header_only_clues' => array('pdf', 'mp3', 'zip', 'exe', 'gif', 'gzip', 'gz', 'jpeg', 'jpg', 'mpg', 'mpeg', 'png', 'ppt', 'mov'),
        // User Agent strings - mapping domain names
        'user_agents' => array(),
        // AJAX triggers to search for.
        // for AJAX sites, e.g. Blogger with its dynamic views templates.
        'ajax_triggers' => array(
            "<meta name='fragment' content='!'",
            '<meta name="fragment" content="!"',
            "<meta content='!' name='fragment'",
            '<meta content="!" name="fragment"',
        ),
        // timeout of the request in seconds
        'timeout' => 10,
        // number of redirection allowed until we assume request won't be complete
        'max_redirect' => 10,
    ),
    'extractor' => array(
        'default_parser' => 'libxml',
        'allowed_parsers' => array('libxml', 'html5lib'),
        // key is fingerprint (fragment to find in HTML)
        // value is host name to use for site config lookup if fingerprint matches
        // \s* match anything INCLUDING new lines
        'fingerprints' => array(
            '/\<meta\s*content=\"blogger\"\s*name=\"generator\"/i' => 'fingerprint.blogspot.com',
            '/\<meta\s*name=\"generator\"\s*content=\"Blogger\"/i' => 'fingerprint.blogspot.com',
            '/\<meta\s*name=\"generator\"\s*content=\"WordPress/i' => 'fingerprint.wordpress.com',
        ),
        'config_builder' => array(
            // Array of directories path to extra site config folder WITHOUT trailing slash
            'site_config' => array(),
            'hostname_regex' => '/^(([a-zA-Z0-9-]*[a-zA-Z0-9])\.)*([A-Za-z0-9-]*[A-Za-z0-9])$/',
        ),
        'readability' => array(
            // filters might be like array('regex' => 'replace with')
            // for example, to remove script content: array('!<script[^>]*>(.*?)</script>!is' => '')
            'pre_filters' => array(),
            'post_filters' => array(),
        ),
        'src_lazy_load_attributes' => array(
            'data-src',
            'data-lazy-src',
            'data-original',
            'data-sources',
            'data-hi-res-src',
        ),
    ),
));
```
