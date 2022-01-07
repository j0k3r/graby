<?php

namespace Maintenance\Graby\Rector\Helpers;

use Http\Client\HttpClient;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class RecordingHttpClient implements HttpClient
{
    /**
     * @var ResponseInterface[]
     */
    private array $responses;

    private HttpClient $httpClient;

    public function __construct(HttpClient $httpClient)
    {
        $this->httpClient = $httpClient;
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
