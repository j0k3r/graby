# FROM 2.x to 3.0

Graby 3 changes configuration and result types, even if you do not extend Graby.
Update your integration using the examples below. If you override methods, also update their parameter and return types to match the new signatures.

### Requirements and dependencies

- PHP **8.2 or later** is required.
- Guzzle 5 and 6 are no longer supported. Use Guzzle 7, `php-http/curl-client`, or another PSR-18 client such as [Symfony HttpClient](README.md#symfony-httpclient).
- Monolog **3** is required. Custom handlers and formatters must use `Monolog\LogRecord` rather than array records.
- The Tidy extension is now **optional**, but recommended: extraction from malformed HTML can differ without it.

### Configuration objects replace arrays

Constructors now take typed configuration objects instead of arrays:

| Class | Configuration class |
| --- | --- |
| `Graby\Graby` | `Graby\GrabyConfig` |
| `Graby\Extractor\HttpClient` | `Graby\Extractor\HttpClientConfig` |
| `Graby\Extractor\ContentExtractor` | `Graby\Extractor\ContentExtractorConfig` |
| `Graby\SiteConfig\ConfigBuilder` | `Graby\SiteConfig\ConfigBuilderConfig` |

Option names use camelCase instead of snake_case. Nested configuration uses objects too; `readability` takes a `ReadabilityConfig`.
Configuration objects are readonly: create a new object to change configuration, and read options through their public properties.

Before (2.x):

```php
use Graby\Graby;

$graby = new Graby([
    'content_links' => 'footnotes',
    'http_client' => ['max_redirect' => 5],
    'extractor' => [
        'config_builder' => ['site_config' => ['/path/to/site-config']],
    ],
]);
```

After (3.x):

```php
use Graby\Config\ContentLinks;
use Graby\Extractor\ContentExtractorConfig;
use Graby\Extractor\HttpClientConfig;
use Graby\Graby;
use Graby\GrabyConfig;
use Graby\SiteConfig\ConfigBuilderConfig;

$graby = new Graby(new GrabyConfig(
    contentLinks: ContentLinks::Footnotes,
    httpClient: new HttpClientConfig(maxRedirect: 5),
    extractor: new ContentExtractorConfig(
        configBuilder: new ConfigBuilderConfig(siteConfig: ['/path/to/site-config']),
    ),
));
```

String options are now enums:

| Old option | New option | Enum values |
| --- | --- | --- |
| `log_level` | `logLevel` | `Graby\Config\LogLevel::{Info, Debug}` |
| `content_links` | `contentLinks` | `Graby\Config\ContentLinks::{Preserve, Footnotes, Remove}` |
| `content_type_exc[*]['action']` | `contentTypeExc[*]['action']` | `Graby\Config\ContentTypeAction::{Exclude, Link}` |
| `extractor.default_parser` | `extractor.defaultParser` | `Graby\Extractor\Parser::{Libxml, Html5lib}` |

The `allowed_parsers` option has been removed; supported parsers are defined by the `Parser` enum.
See the [full configuration](README.md#full-configuration) for all options and defaults.

### Result objects replace arrays

`Graby::fetchContent()` now returns a `Graby\Content` object.

Before (2.x):

```php
$result = $graby->fetchContent($url);
$html = $result['html'];
$status = $result['status'];
$effectiveUrl = $result['url'];
```

After (3.x):

```php
$result = $graby->fetchContent($url);
$html = $result->getHtml();
$response = $result->getEffectiveResponse();
$status = $response->getResponse()->getStatusCode();
$effectiveUrl = (string) $response->getEffectiveUri();
```

Other result fields are available through `getTitle()`, `getLanguage()`, `getDate()`, `getAuthors()`, `getImage()`, `getSummary()` and `getIsNativeAd()` (formerly `native_ad`).
`Content` is immutable: use the available `with*()` methods and keep their returned copy, for example `$result = $result->withTitle('New title');`.

`Graby\Extractor\HttpClient::fetch()` now takes a PSR-7 `UriInterface` and returns a `Graby\HttpClient\EffectiveResponse`, not an array:

- `effective_url` becomes `getEffectiveUri()`, which returns a `UriInterface`; cast it to a string when needed.
- `status` becomes `getResponse()->getStatusCode()`.
- `body` becomes `(string) getResponse()->getBody()`.
- `headers` becomes `getResponse()->getHeaders()`. PSR-7 header values are arrays of strings; use `getResponse()->getHeaderLine('Content-Type')` for a single combined header value.

The same response accessors apply to the result of `Graby::fetchContent()` via `getEffectiveResponse()`.
`Graby::cleanupHtml()` also takes a `UriInterface` for the URL; it still returns an HTML string.

### Direct use of ContentExtractor

`ContentExtractor::process()` now takes a `UriInterface` for the URL and returns an `ExtractedContent` object rather than a success boolean.
The extractor no longer stores the last extraction result:

- Remove calls to `reset()`; the method has been removed.
- Replace result getters (`getTitle()`, `getContent()`, `getNextPageUrl()`, etc.) with properties on the returned object (`title`, `content`, `nextPageUrl`, etc.).
- Read `isSuccess`, `isNativeAd`, `siteConfig` and `readability` from that object, not from the extractor.
- `buildSiteConfig()` also takes a `UriInterface`.

For example:

```php
use Graby\Extractor\ContentExtractor;
use GuzzleHttp\Psr7\Uri;

$extractor = new ContentExtractor();
$result = $extractor->process($html, new Uri('https://example.com/article'));
if ($result->isSuccess) {
    $title = $result->title;
    $content = $result->content; // DOMElement, not an HTML string
}
```

### Site configuration builders

- `ConfigBuilder::build()` has been removed. Use `buildForHost()`/`buildFromUrl()` for the merged config or `loadSiteConfig()` to get the config for a site. `buildFromUrl()` takes a `UriInterface`.
- `ConfigBuilder::getCachedVersion()` and `ConfigBuilder::loadSiteConfig()` now return `null` instead of `false` when a `SiteConfig` cannot be loaded.
- The `ConfigLinesProvider` interface, `BuiltinConfigLinesProvider` implementation and third constructor argument of `ConfigBuilder` have been removed. Configure custom site-config directories with `ConfigBuilderConfig(siteConfig: [...])` instead.

# FROM 1.x to 2.0

### :warning: BC changes

- Support for PHP < 7.1 has been dropped
- PHP extension tidy is now a requirement

### :electric_plug: Now decoupled from the HTTP client

Graby 1 was hardly tied to Guzzle 5.

Graby 2 supports any [HTTP client implementation](https://packagist.org/providers/php-http/client-implementation). It is currently tested against:

- Guzzle 6
- Guzzle 5
- cURL

Here is how to install Graby with Guzzle 6:

```
composer require j0k3r/graby php-http/guzzle6-adapter
```

Then:


```php
use Graby\Graby;
use GuzzleHttp\Client as GuzzleClient;
use Http\Adapter\Guzzle6\Client as GuzzleAdapter;

$graby = new Graby([], new GuzzleAdapter(new GuzzleClient()));
```

### :wave: Configuration removed

- `http_client.timeout` option is gone, you should now implement it using the adapter of your choice, see [this part of the README](https://github.com/j0k3r/graby#timeout-configuration).

### :twisted_rightwards_arrows: Return information

- `all_headers` became `headers`
- `open_graph` no longer exist (title, image & locale are merged into global result)
- `content_type` no longer exist (check headers key instead)
