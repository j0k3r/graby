<?php

namespace Graby\HttpClient\Plugin\ServerSideRequestForgeryProtection;

use Http\Client\Common\Plugin;
use Http\Discovery\UriFactoryDiscovery;
use Http\Message\UriFactory;
use Psr\Http\Message\RequestInterface;

/**
 * Validates each part of the URL against a white or black list, to help protect against Server-Side Request Forgery
 * attacks.
 *
 * @see https://github.com/j0k3r/safecurl
 * @see https://whitton.io/articles/safecurl-ssrf-protection-and-a-capture-the-bitcoins/
 */
class ServerSideRequestForgeryProtectionPlugin implements Plugin
{
    private $options;
    private $uriFactory;

    public function __construct(Options $options = null, UriFactory $uriFactory = null)
    {
        $this->options = $options ?: new Options();
        $this->uriFactory = $uriFactory ?: UriFactoryDiscovery::find();
        //TODO force using IPV4 ? @see SafeCurl::init()
    }

    /**
     * @inheritdoc
     * @throws \Graby\HttpClient\Plugin\ServerSideRequestForgeryProtection\Exception
     */
    public function handleRequest(RequestInterface $request, callable $next, callable $first)
    {
        $urlData = Url::validateUrl((string) $request->getUri(), $this->options);
        $uri = $this->uriFactory->createUri($urlData['url']);

        if ($uri !== $request->getUri()) {
            $request = $request->withUri($uri->withHost($urlData['host']));
        }

        return $next($request);
    }
}
