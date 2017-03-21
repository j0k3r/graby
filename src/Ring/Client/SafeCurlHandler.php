<?php

namespace Graby\Ring\Client;

use fin1te\SafeCurl\Exception;
use fin1te\SafeCurl\SafeCurl;
use GuzzleHttp\Ring\Client\CurlFactory;
use GuzzleHttp\Ring\Core;
use GuzzleHttp\Ring\Future\CompletedFutureArray;
use GuzzleHttp\Stream\Stream;

/**
 * This is a hard copy/paste of the `GuzzleHttp\Ring\Client\CurlHandler`
 * to wrap SafeCurl inside it (in `_invokeAsArray`).
 */
class SafeCurlHandler
{
    /** @var callable */
    private $factory;

    public function __construct()
    {
        $this->factory = new CurlFactory();
    }

    /**
     * @param array $request
     *
     * @return CompletedFutureArray
     */
    public function __invoke(array $request)
    {
        return new CompletedFutureArray(
            $this->_invokeAsArray($request)
        );
    }

    /**
     * @internal
     *
     * @param array $request
     *
     * @return array
     */
    public function _invokeAsArray(array $request)
    {
        $factory = $this->factory;

        // Ensure headers are by reference. They're updated elsewhere.
        $result = $factory($request, curl_init());
        $h = $result[0];
        $hd = &$result[1];
        $body = $result[2];
        Core::doSleep($request);

        try {
            // override the default body stream with the request response
            $safecurl = new SafeCurl($h);
            $body = $safecurl->execute(Core::url($request));
        } catch (Exception $e) {
            // URL wasn't safe, return empty content
            $body = '';
            $safeCurlError = $e->getMessage();
        }

        $response = ['transfer_stats' => curl_getinfo($h)];
        $response['curl']['error'] = curl_error($h);
        $response['curl']['errno'] = curl_errno($h);
        $response['transfer_stats'] = array_merge($response['transfer_stats'], $response['curl']);
        curl_close($h);

        // override default error message in case of SafeCurl error
        if (isset($safeCurlError)) {
            $response['err_message'] = $safeCurlError;
        }

        return CurlFactory::createResponse([$this, '_invokeAsArray'], $request, $response, $hd, Stream::factory($body));
    }
}
