<?php

namespace Bugban\Sdk\Transport;

interface Transport
{
    /**
     * Send a JSON payload to the given URL with the project key. Must never throw.
     *
     * @param string $url
     * @param string $apiKey
     * @param array  $payload
     * @return void
     */
    public function send($url, $apiKey, array $payload);

    /**
     * GET a JSON document and return it decoded, or null on any failure.
     * Used to ask Bugban whether a query test is waiting. Must never throw.
     *
     * @param string $url
     * @param string $apiKey
     * @return array|null
     */
    public function fetch($url, $apiKey);
}
