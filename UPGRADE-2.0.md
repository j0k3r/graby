# UPGRADE FROM 1.x to 2.0

### :warning: BC changes

- Support for PHP < 7.1 has been dropped
- PHP extension tidy is now a requirement

### :electric_plug: Now decoupled from the HTTP client

Graby 1 was hardly tied to Guzzle 5.

Graby 2 supports any [HTTP client implementation](https://packagist.org/providers/php-http/client-implementation). It is currently tested against:

- Guzzle 6,
- Guzzle 5 &
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
