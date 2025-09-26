<?php

declare(strict_types=1);

namespace Maintenance\Graby\Rector\Helpers;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class RecordingHttpClient implements ClientInterface
{
    /**
     * @var ResponseInterface[]
     */
    private array $responses;

    public function __construct(private readonly ClientInterface $httpClient)
    {
    }

    /**
     * Sends a PSR-7 request and returns a PSR-7 response.
     *
     * @throws ClientExceptionInterface if an error happens while processing the request
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->httpClient->sendRequest($request);
        $this->responses[] = $response;

        return $response;
    }

    /**
     * @return ResponseInterface[]
     */
    public function getResponses(): array
    {
        return $this->responses;
    }
}
