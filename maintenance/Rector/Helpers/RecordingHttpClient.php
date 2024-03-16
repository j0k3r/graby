<?php

declare(strict_types=1);

namespace Maintenance\Graby\Rector\Helpers;

use Http\Client\HttpAsyncClient;
use Http\Promise\Promise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class RecordingHttpClient implements HttpAsyncClient
{
    /**
     * @var ResponseInterface[]
     */
    private array $responses;

    private HttpAsyncClient $httpClient;

    public function __construct(HttpAsyncClient $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    /**
     * Sends a PSR-7 request in an asynchronous way.
     *
     * Exceptions related to processing the request are available from the returned Promise.
     *
     * @throws \Exception If processing the request is impossible (eg. bad configuration).
     *
     * @return Promise resolves a PSR-7 Response or fails with an Http\Client\Exception
     */
    public function sendAsyncRequest(RequestInterface $request): Promise
    {
        return $this->httpClient->sendAsyncRequest($request)->then(function (ResponseInterface $response): ResponseInterface {
            $this->responses[] = $response;

            return $response;
        });
    }

    /**
     * @return ResponseInterface[]
     */
    public function getResponses(): array
    {
        return $this->responses;
    }
}
