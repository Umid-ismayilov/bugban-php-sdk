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

    /** Max bytes of raw request body to capture (JSON/text APIs). */
    const MAX_RAW_BODY = 65536;

    public function request()
    {
        if (PHP_SAPI === 'cli') {
            return null;
        }

        $headers = function_exists('getallheaders') ? getallheaders() : array();
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $path = strtok($uri, '?');

        $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : null;
        $body = $this->redactArray(isset($_POST) ? $_POST : array());

        // For JSON / non-form APIs, $_POST is empty — the payload lives in
        // php://input. Capture it (this is exactly what the AI needs to see
        // which request caused the error). Decode + redact JSON when possible.
        $rawBody = null;
        if (empty($body) && $this->hasRawBody($contentType)) {
            $raw = $this->readRawInput();
            if ($raw !== null && $raw !== '') {
                if ($contentType !== null && stripos($contentType, 'json') !== false) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $body = $this->redactArray($decoded);
                    } else {
                        $rawBody = $this->truncate($raw);
                    }
                } else {
                    $rawBody = $this->truncate($raw);
                }
            }
        }

        $data = array(
            'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null,
            'url' => $this->currentUrl(),
            'path' => $path !== false ? $path : null,
            'query' => $this->redactArray(isset($_GET) ? $_GET : array()),
            'body' => $body,
            'headers' => $this->redactArray(is_array($headers) ? $headers : array()),
            'cookies' => $this->redactArray(isset($_COOKIE) ? $_COOKIE : array()),
            'ip' => $this->clientIp(),
            'content_type' => $contentType,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null,
            'referer' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null,
            'protocol' => isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : null,
            'host' => isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : null,
        );

        if ($rawBody !== null) {
            $data['raw_body'] = $rawBody;
        }

        return $data;
    }

    /** Whether this content type is likely to carry a raw (non-form) body. */
    private function hasRawBody($contentType)
    {
        if ($contentType === null) {
            return false;
        }
        if (stripos($contentType, 'multipart/form-data') !== false) {
            return false; // uploads — don't slurp
        }
        if (stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
            return false; // already in $_POST
        }
        return true;
    }

    private function readRawInput()
    {
        try {
            $raw = @file_get_contents('php://input', false, null, 0, self::MAX_RAW_BODY + 1);
            return $raw === false ? null : $raw;
        } catch (\Throwable $e) {
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function truncate($str)
    {
        if (strlen($str) <= self::MAX_RAW_BODY) {
            return $str;
        }
        return substr($str, 0, self::MAX_RAW_BODY) . '...[truncated]';
    }

    /** Best-effort real client IP, honouring common proxy headers. */
    private function clientIp()
    {
        $candidates = array('HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR');
        foreach ($candidates as $key) {
            if (!empty($_SERVER[$key])) {
                $val = $_SERVER[$key];
                if (strpos($val, ',') !== false) {
                    $parts = explode(',', $val);
                    $val = trim($parts[0]);
                }
                return $val;
            }
        }
        return null;
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
