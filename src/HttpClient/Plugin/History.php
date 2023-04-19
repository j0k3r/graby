<?php

declare(strict_types=1);

namespace Graby\HttpClient\Plugin;

use Http\Client\Common\Plugin\Journal;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class History implements Journal
{
    private RequestInterface $lastRequest;
    private ResponseInterface $lastResponse;

    public function getLastRequest(): ?RequestInterface
    {
        return $this->lastRequest;
    }

    public function getLastResponse(): ?ResponseInterface
    {
        return $this->lastResponse;
    }

    public function addSuccess(RequestInterface $request, ResponseInterface $response): void
    {
        $this->lastRequest = $request;
        $this->lastResponse = $response;
    }

    public function addFailure(RequestInterface $request, ClientExceptionInterface $exception): void
    {
    }
}
