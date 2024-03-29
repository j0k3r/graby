<?php

declare(strict_types=1);

namespace Graby\HttpClient;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * Wrapper around PSR-7 Response that also holds the effective URI.
 */
final class EffectiveResponse
{
    private UriInterface $effectiveUrl;
    private ResponseInterface $response;

    public function __construct(
        UriInterface $effectiveUrl,
        ResponseInterface $response
    ) {
        $this->effectiveUrl = $effectiveUrl;
        $this->response = $response;
    }

    public function getEffectiveUri(): UriInterface
    {
        return $this->effectiveUrl;
    }

    public function withEffectiveUri(UriInterface $effectiveUrl): self
    {
        $new = clone $this;
        $new->effectiveUrl = $effectiveUrl;

        return $new;
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}
