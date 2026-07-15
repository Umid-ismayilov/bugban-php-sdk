<?php

namespace Bugban\Sdk\Support;

/**
 * Framework-agnostic context collection from PHP superglobals.
 * Framework adapters (e.g. Laravel) can supply richer data via Config::$contextResolver.
 */
class ContextCollector
{
    /** @var array */
    private $redact;

    public function __construct(array $redact = array())
    {
        $this->redact = $redact;
    }

    public function request()
    {
        if (PHP_SAPI === 'cli') {
            return null;
        }

        $headers = function_exists('getallheaders') ? getallheaders() : array();
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $path = strtok($uri, '?');

        return array(
            'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null,
            'url' => $this->currentUrl(),
            'path' => $path !== false ? $path : null,
            'query' => $this->redactArray(isset($_GET) ? $_GET : array()),
            'body' => $this->redactArray(isset($_POST) ? $_POST : array()),
            'headers' => $this->redactArray(is_array($headers) ? $headers : array()),
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
        );
    }

    public function session()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        return array(
            'id' => session_id() ? session_id() : null,
            'data' => $this->redactArray(isset($_SESSION) ? $_SESSION : array()),
        );
    }

    private function currentUrl()
    {
        if (empty($_SERVER['HTTP_HOST'])) {
            return null;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $scheme = $https ? 'https' : 'http';
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

        return $scheme . '://' . $_SERVER['HTTP_HOST'] . $uri;
    }

    public function redactArray(array $data)
    {
        $keys = array_map('strtolower', $this->redact);
        $out = array();
        foreach ($data as $k => $v) {
            if (in_array(strtolower((string) $k), $keys, true)) {
                $out[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $out[$k] = $this->redactArray($v);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
