<?php

namespace Bugban\Sdk\Support;

/**
 * Centralizes behaviour that differs between PHP versions (7.0 → 8.4),
 * so the rest of the SDK stays version-agnostic.
 */
class Compat
{
    public static function phpVersionId()
    {
        return defined('PHP_VERSION_ID') ? PHP_VERSION_ID : 0;
    }

    public static function isPhp8()
    {
        return self::phpVersionId() >= 80000;
    }

    public static function isPhp7()
    {
        $v = self::phpVersionId();
        return $v >= 70000 && $v < 80000;
    }

    /** Runtime fingerprint attached to every payload. */
    public static function runtime()
    {
        return array(
            'php' => PHP_VERSION,
            'php_major' => PHP_MAJOR_VERSION,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS,
        );
    }

    /**
     * Close a cURL handle safely across versions. On PHP 7 it's a resource;
     * on PHP 8 it's a \CurlHandle object where curl_close is a harmless no-op.
     */
    public static function closeCurl($ch)
    {
        $isHandle = is_resource($ch) || (self::isPhp8() && $ch instanceof \CurlHandle);
        if ($isHandle && function_exists('curl_close')) {
            @curl_close($ch);
        }
    }

    /**
     * json flags that are safe on the running version.
     * JSON_UNESCAPED_SLASHES: 5.4+, JSON_PARTIAL_OUTPUT_ON_ERROR: 5.5+.
     */
    public static function jsonFlags()
    {
        $flags = 0;
        if (defined('JSON_UNESCAPED_SLASHES')) {
            $flags |= JSON_UNESCAPED_SLASHES;
        }
        if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
            $flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
        }
        return $flags;
    }
}
