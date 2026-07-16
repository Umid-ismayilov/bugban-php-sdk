<?php

namespace Bugban\Sdk;

/**
 * Global entry point. Works in ANY environment (pure PHP, CodeIgniter, Symfony, WordPress...).
 * In Laravel the service provider wires this up automatically.
 */
class Bugban
{
    /** @var Client|null */
    private static $client = null;

    /**
     * Initialize with a config array. Returns the client.
     *
     * @return Client
     */
    public static function init(array $config)
    {
        self::$client = new Client(new Config($config));
        return self::$client;
    }

    public static function setClient(Client $client)
    {
        self::$client = $client;
    }

    /**
     * @return Client|null
     */
    public static function client()
    {
        return self::$client;
    }

    /**
     * @param \Throwable|\Exception $e
     */
    public static function capture($e, array $extra = array())
    {
        if (self::$client) {
            self::$client->capture($e, $extra);
        }
    }

    public static function captureMessage($message, $level = 'info', array $extra = array())
    {
        if (self::$client) {
            self::$client->captureMessage($message, $level, $extra);
        }
    }

    public static function addBreadcrumb($message, $category = 'default', array $data = array(), $level = 'info')
    {
        if (self::$client) {
            self::$client->addBreadcrumb($message, $category, $data, $level);
        }
    }

    public static function setUser(array $user)
    {
        if (self::$client) {
            self::$client->setUser($user);
        }
    }

    public static function setContext($key, $value)
    {
        if (self::$client) {
            self::$client->setContext($key, $value);
        }
    }

    /**
     * Force-send any buffered (deferred) telemetry immediately.
     * Handy for CLI scripts, tests and queue workers.
     */
    public static function flush()
    {
        if (self::$client) {
            self::$client->flush();
        }
    }

    /**
     * Register global error/exception/shutdown handlers — the one-liner for
     * pure-PHP and legacy projects to get automatic capture with no framework.
     */
    public static function registerHandlers()
    {
        set_error_handler(function ($severity, $message, $file = null, $line = null) {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            Bugban::captureMessage($message, 'error', array('severity' => $severity, 'file' => $file, 'line' => $line));
            return false; // let PHP's normal handler run too
        });

        set_exception_handler(function ($e) {
            Bugban::capture($e);
        });

        register_shutdown_function(function () {
            $err = error_get_last();
            $fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
            if ($err && in_array($err['type'], $fatal, true)) {
                Bugban::captureMessage($err['message'], 'fatal', array('file' => $err['file'], 'line' => $err['line']));
            }
        });
    }
}
