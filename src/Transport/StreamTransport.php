<?php

namespace Bugban\Sdk\Transport;

use Bugban\Sdk\Support\Compat;

/**
 * Fallback transport using PHP streams (file_get_contents) — for environments
 * WITHOUT the cURL extension (common on stripped-down / legacy PHP installs).
 * Requires allow_url_fopen=On.
 */
class StreamTransport implements Transport
{
    /** @var int */
    private $timeout;

    public function __construct($timeout = 3)
    {
        $this->timeout = (int) $timeout;
    }

    public function send($url, $apiKey, array $payload)
    {
        try {
            $body = json_encode($payload, Compat::jsonFlags());
            if ($body === false) {
                return;
            }

            $headers = "Content-Type: application/json\r\n"
                . "Accept: application/json\r\n"
                . "X-Bugban-Key: " . $apiKey . "\r\n"
                . "User-Agent: bugban-php-sdk/1.0\r\n";

            $context = stream_context_create(array(
                'http' => array(
                    'method' => 'POST',
                    'header' => $headers,
                    'content' => $body,
                    'timeout' => $this->timeout,
                    'ignore_errors' => true,
                ),
                'ssl' => array(
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ),
            ));

            @file_get_contents($url, false, $context);
        } catch (\Exception $e) {
            // non-fatal
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    public static function isAvailable()
    {
        return filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    }
}
