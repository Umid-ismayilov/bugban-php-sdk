<?php

namespace Bugban\Sdk\Support;

use Bugban\Sdk\Bugban;
use Bugban\Sdk\Config;
use Bugban\Sdk\Transport\CurlTransport;
use Bugban\Sdk\Transport\StreamTransport;
use Bugban\Sdk\Transport\Transport;

/**
 * One-time "install ping" (SDK handshake).
 *
 * Right after installation the SDK pings the Bugban server ONCE so the
 * customer immediately sees "SDK connected" in the panel. Once-only
 * semantics are enforced by a marker file in the system temp dir; when the
 * temp dir is not usable we still ping at most once per process. The ping
 * is fire-and-forget: the response is ignored and this class NEVER throws.
 */
class Pinger
{
    /** @var bool Once-per-process guard (fallback when the temp marker cannot be used). */
    private static $attempted = false;

    /**
     * Send the install ping once, if the config is usable and it was not sent before.
     *
     * @param Config $config
     * @param Transport|null $transport Reuses the SDK transports when omitted.
     * @return void
     */
    public static function maybePing(Config $config, Transport $transport = null)
    {
        try {
            if (self::$attempted || !$config->isUsable()) {
                return;
            }

            $marker = self::markerPath($config);
            if ($marker !== null && @file_exists($marker)) {
                return;
            }

            self::$attempted = true;

            if ($transport === null) {
                $transport = self::defaultTransport();
            }

            // send() is fire-and-forget and never throws; response is ignored.
            $transport->send($config->pingUrl(), $config->apiKey, self::payload($config));

            if ($marker !== null) {
                @file_put_contents($marker, (string) time());
            }
        } catch (\Exception $e) {
            // The handshake must be non-fatal — stay silent no matter what.
        } catch (\Throwable $e) {
            // non-fatal (PHP 7+ engine errors)
        }
    }

    /**
     * Marker file path keyed by api_key + host, or null when unavailable.
     *
     * @param Config $config
     * @return string|null
     */
    private static function markerPath(Config $config)
    {
        try {
            if (!function_exists('sys_get_temp_dir')) {
                return null;
            }
            $dir = @sys_get_temp_dir();
            if (!is_string($dir) || $dir === '' || !@is_dir($dir) || !@is_writable($dir)) {
                return null;
            }
            // The SDK VERSION is part of the key on purpose: after an upgrade the
            // marker no longer matches, so the SDK pings again and the panel
            // learns the new version. Without this an upgraded install would
            // report its original version forever.
            return rtrim($dir, '/\\') . '/bugban-ping-'
                . md5($config->apiKey . '|' . $config->host . '|' . \Bugban\Sdk\Bugban::VERSION);
        } catch (\Exception $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Build the handshake payload (all fields optional strings, server-side).
     *
     * @param Config $config
     * @return array
     */
    private static function payload(Config $config)
    {
        $payload = array(
            'php_version' => PHP_VERSION,
            'sdk' => $config->sdkName !== null ? $config->sdkName : 'bugban/php-sdk',
            'sdk_version' => Bugban::VERSION,
            'environment' => $config->environment,
        );

        if (function_exists('gethostname')) {
            $hostname = @gethostname();
            if (is_string($hostname) && $hostname !== '') {
                $payload['hostname'] = $hostname;
            }
        }
        if ($config->appName !== null) {
            $payload['app_name'] = $config->appName;
        }
        if ($config->framework !== null) {
            $payload['framework'] = $config->framework;
        }
        if ($config->frameworkVersion !== null) {
            $payload['framework_version'] = $config->frameworkVersion;
        }

        return $payload;
    }

    /**
     * Same transport choice as the Client, but capped at a 3s timeout.
     *
     * @return Transport
     */
    private static function defaultTransport()
    {
        if (extension_loaded('curl')) {
            return new CurlTransport(3);
        }
        return new StreamTransport(3);
    }
}
