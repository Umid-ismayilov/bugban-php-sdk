<?php

namespace Bugban\Sdk\Transport;

use Bugban\Sdk\Support\Compat;

class CurlTransport implements Transport
{
    /** @var int */
    private $timeout;

    public function __construct($timeout = 3)
    {
        $this->timeout = (int) $timeout;
    }

    public function send($url, $apiKey, array $payload)
    {
        // Fire-and-forget: the client app must never break or hang because of Bugban.
        try {
            $body = json_encode($payload, Compat::jsonFlags());
            if ($body === false) {
                return;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-Bugban-Key: ' . $apiKey,
                    'User-Agent: bugban-php-sdk/1.0',
                ),
            ));
            curl_exec($ch);
            Compat::closeCurl($ch);
        } catch (\Exception $e) {
            // Swallow — telemetry must be non-fatal.
        } catch (\Throwable $e) {
            // PHP 7+ engine errors — also non-fatal.
        }
    }

    public function fetch($url, $apiKey)
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_HTTPHEADER => array(
                    'Accept: application/json',
                    'X-Bugban-Key: ' . $apiKey,
                    'User-Agent: bugban-php-sdk/1.0',
                ),
            ));
            $body = curl_exec($ch);
            Compat::closeCurl($ch);
            if (!is_string($body) || $body === '') {
                return null;
            }
            $data = json_decode($body, true);

            return is_array($data) ? $data : null;
        } catch (\Exception $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
