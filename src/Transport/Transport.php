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
}
