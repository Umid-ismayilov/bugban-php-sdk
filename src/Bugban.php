<?php

namespace Bugban\Sdk;

use Bugban\Sdk\Support\Pinger;

/**
 * Global entry point. Works in ANY environment (pure PHP, CodeIgniter, Symfony, WordPress...).
 * In Laravel the service provider wires this up automatically.
 */
class Bugban
{
    /** SDK version (sent with the one-time install ping). */
    const VERSION = '1.5.0';

    /** @var Client|null */
    private static $client = null;

    /** @var bool Recursion guard: true while a log record is being forwarded. */
    private static $recordingLog = false;

    /**
     * Initialize with a config array. Returns the client.
     *
     * @return Client
     */
    public static function init(array $config)
    {
        self::$client = new Client(new Config($config));
        Pinger::maybePing(self::$client->config());
        return self::$client;
    }

    public static function setClient(Client $client)
    {
        self::$client = $client;
        Pinger::maybePing($client->config());
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
     * Record a database query for slow-query (performance) monitoring.
     * Queries faster than the configured slow_query_ms threshold are ignored;
     * slow ones are batched and delivered non-blocking at shutdown. Never throws.
     *
     * @param string $sql        Raw SQL text.
     * @param float|int $durationMs Duration in MILLISECONDS.
     * @param array  $meta       Optional: connection, bindings, file, line.
     */
    /**
     * Register how a query test should be executed. Framework adapters call
     * this automatically; a composer-less install can pass its own PDO via
     * setTestPdo() instead. Never throws.
     *
     * @param callable $runner function(string $sql, array $bindings): int
     * @return void
     */
    public static function setQueryRunner($runner)
    {
        if (self::$client) {
            self::$client->setQueryRunner($runner);
        }
    }

    /**
     * Convenience for apps without a framework adapter: hand the SDK a PDO and
     * it builds the runner itself. The statement always runs inside a
     * transaction that is rolled back, so a test can never alter data.
     *
     * @param \PDO $pdo
     * @return void
     */
    public static function setTestPdo($pdo)
    {
        if (!self::$client || !($pdo instanceof \PDO)) {
            return;
        }
        self::$client->setQueryRunner(function ($sql, array $bindings) use ($pdo) {
            $inTransaction = false;
            try {
                $inTransaction = $pdo->beginTransaction();
            } catch (\Exception $e) {
                $inTransaction = false;   // e.g. DDL-implicit-commit engines
            }
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($bindings);
                $rows = 0;
                while ($stmt->fetch(\PDO::FETCH_NUM) !== false) {
                    $rows++;
                }
                $stmt->closeCursor();

                return $rows;
            } catch (\Exception $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw $e;
            } finally {
                if ($inTransaction) {
                    try {
                        $pdo->rollBack();
                    } catch (\Exception $e) {
                        // Nothing was written anyway; a failed rollback is not fatal.
                    }
                }
            }
        });
    }

    /**
     * Run one pending query test, if Bugban has queued one. Adapters call this
     * at the end of a request; a manual install may call it from a cron script.
     *
     * @return void
     */
    public static function checkQueryTests()
    {
        if (self::$client) {
            self::$client->checkQueryTests();
        }
    }

    public static function recordQuery($sql, $durationMs, array $meta = array())
    {
        if (self::$client) {
            self::$client->recordQuery($sql, $durationMs, $meta);
        }
    }

    /**
     * Forward a log record (Log::error / Log::critical / caught-and-logged error) to
     * Bugban as a handled event. No-op unless the SDK is usable, capture_logs is on and
     * $level is at/above the configured log_level. NEVER throws.
     *
     * A static in-progress flag guards against infinite recursion: if delivering a log
     * record itself triggers logging (e.g. a Monolog handler re-enters this method), the
     * nested call returns immediately instead of looping.
     *
     * @param string $level   PSR level: debug|info|notice|warning|error|critical|alert|emergency.
     * @param string $message The log message.
     * @param array  $context Monolog context array (redacted before sending).
     */
    public static function recordLog($level, $message, array $context = array())
    {
        if (self::$recordingLog || !self::$client) {
            return;
        }
        self::$recordingLog = true;
        try {
            self::$client->recordLogEvent($level, $message, $context);
        } catch (\Exception $e) {
            // Telemetry must be non-fatal.
        } catch (\Throwable $e) {
            // non-fatal
        }
        self::$recordingLog = false;
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
