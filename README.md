# Graby

[![Build Status](https://travis-ci.org/j0k3r/graby.svg?branch=master)](https://travis-ci.org/j0k3r/graby)
[![Code Coverage](https://scrutinizer-ci.com/g/j0k3r/graby/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/j0k3r/graby/?branch=master)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/j0k3r/graby/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/j0k3r/graby/?branch=master)

Graby helps you extract article content from web pages.
This is a fork of Full-Text RSS v3.3 from [@fivefilters](http://fivefilters.org/).

## Why this fork ?

Full-Text RSS works great as a standalone application. But when you need to encapsulate it in your own library it's a mess. You need this kind of ugly thing:

```php
$article = 'http://www.bbc.com/news/entertainment-arts-32547474';
$request = 'http://example.org/full-text-rss/makefulltextfeed.php?format=json&url='.urlencode($article);
$result  = @file_get_contents($request);
```

Also, if you want to understand how things work internally, it's really hard to read and understand. And finally, there are **not tests** at all.

That's why I made this fork:

1. Easiest way to integrate it (using composer)
2. Fully tested
3. (hopefully) better to understand
4. A bit more decoupled

## How to use it

Add the lib using composer:

    composer require j0k3r/graby~1.0

Use the class to retrieve content:

```php
use Graby\Graby;

$article = 'http://www.bbc.com/news/entertainment-arts-32547474';

$graby = new Graby();
$result = $graby->fetchContent($article);

var_dump($result);
/*
array(4) {
  'html' =>
  string() "Fetched and readable content"
  'title' =>
  string() "Ben E King: R&B legend dies at 76"
  'url' =>
  string() "http://www.bbc.com/news/entertainment-arts-32547474"
  'summary' =>
  string() "Ben E King received an award from the Songwriters Hall of Fame in &hellip;"
}
*/
```

## Full configuration

This is the full documented configuration and also the default one.

```php
$graby = new Graby(
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
        'user_agents' => array(
            'lifehacker.com' => 'PHP/5.2',
            'gawker.com'     => 'PHP/5.2',
            'deadspin.com'   => 'PHP/5.2',
            'kotaku.com'     => 'PHP/5.2',
            'jezebel.com'    => 'PHP/5.2',
            'io9.com'        => 'PHP/5.2',
            'jalopnik.com'   => 'PHP/5.2',
            'gizmodo.com'    => 'PHP/5.2',
            '.wikipedia.org' => 'Mozilla/5.2',
            '.fok.nl'        => 'Googlebot/2.1',
            'getpocket.com'  => 'PHP/5.2',
        ),
        // AJAX triggers to search for.
        // for AJAX sites, e.g. Blogger with its dynamic views templates.
        'ajax_triggers' => array(
            "<meta name='fragment' content='!'",
            '<meta name="fragment" content="!"',
            "<meta content='!' name='fragment'",
            '<meta content="!" name="fragment"',
        ),
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
            // Directory path to site config folder WITHOUT trailing slash
            'site_config' => dirname(__FILE__).'/../../site_config',
            'hostname_regex' => '/^(([a-zA-Z0-9-]*[a-zA-Z0-9])\.)*([A-Za-z0-9-]*[A-Za-z0-9])$/',
        ),
    ),
);
```
