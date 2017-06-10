<?php

namespace Graby\HttpClient\Plugin;

use Http\Client\Common\Plugin\Journal;
use Http\Client\Exception;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class History implements Journal
{
    /**
     * @var RequestInterface
     */
    private $lastRequest;
    /**
     * @var ResponseInterface
     */
    private $lastResponse;


    /**
     * @return RequestInterface|null
     */
    public function getLastRequest()
    {
        return $this->lastRequest;
    }

    /**
     * @return ResponseInterface|null
     */
    public function getLastResponse()
    {
        return $this->lastResponse;
    }

    public function addSuccess(RequestInterface $request, ResponseInterface $response)
    {
        $this->lastRequest = $request;
        $this->lastResponse = $response;
    }

    public function addFailure(RequestInterface $request, Exception $exception)
    {
    }
}

