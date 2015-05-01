# Graby

This is a fork of Full-Text RSS v3.3 from [@fivefilters](http://fivefilters.org/).

## Why this fork ?

Full-Text RSS works great as a standalone application. But when you need to encapsulate it in your own library it's a mess. You need this kind of ugly thing:

```php
$article = 'http://www.bbc.co.uk/news/world-europe-21936308';
$request = 'http://example.org/full-text-rss/makefulltextfeed.php?format=json&url='.urlencode($article);
$result  = @file_get_contents($request);
```

Also, if you want to understand how things work internally, it's really hard to read and understand. And finally, there are **not tests** at all.

That's why I made this fork:

1. Easiest way to integrate it (composer & classes)
2. Fully tested
3. (hopefully) better to understand
4. A bit more decoupled

## How to use it

Add the lib using composer:

    composer require j0k3r/graby~1.0

Use the class to retrieve content:

```php
use Graby\Graby;

$article = 'http://www.bbc.co.uk/news/world-europe-21936308';

$graby = new Graby();
$result = $graby->fetchContent($article);
```
