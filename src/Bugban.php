<?php

namespace Bugban\Sdk;

use Bugban\Sdk\Support\Pinger;
use Bugban\Sdk\Support\Updater;

/**
 * Global entry point. Works in ANY environment (pure PHP, CodeIgniter, Symfony, WordPress...).
 * In Laravel the service provider wires this up automatically.
 */
class Bugban
{
    /** SDK version (sent with the one-time install ping). */
    const VERSION = '1.7.2';

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
        // Idempotent: a second init() with the same api_key/host (typical after
        // install.sh added bugban.php while an older hand-pasted snippet is
        // still in public/index.php or a middleware) keeps the first client —
        // no second ping, no doubled handlers, no duplicate events.
        if (self::$client !== null) {
            try {
                $cur = self::$client->config();
                $key = isset($config['api_key']) ? (string) $config['api_key'] : '';
                $host = isset($config['host']) ? rtrim((string) $config['host'], '/') : null;
                if ($key !== '' && $key === (string) $cur->apiKey
                    && ($host === null || $host === rtrim((string) $cur->host, '/'))) {
                    return self::$client;
                }
            } catch (\Exception $e) {
                // fall through → re-init
            } catch (\Throwable $e) {
                // fall through → re-init
            }
        }
        // Remember WHICH file called init() (project-relative in the ping) so
        // the panel can say "your snippet is in public/index.php — artisan
        // never loads that" instead of guessing.
        Pinger::rememberInitFile(self::callerFile());
        self::$client = new Client(new Config($config));
        Pinger::maybePing(self::$client->config());
        // Web requests: daily self-update check after the response is flushed
        // (CLI runs get theirs from RunTracker::finish()). No-op unless
        // auto_update is on.
        Updater::registerWebHook(self::$client->config());
        return self::$client;
    }

    /**
     * The first stack frame outside the SDK's own source tree, or null.
     *
     * @return string|null
     */
    private static function callerFile()
    {
        try {
            if (!function_exists('debug_backtrace')) {
                return null;
            }
            $frames = @debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
            if (!is_array($frames)) {
                return null;
            }
            $sdk = dirname(__DIR__);
            foreach ($frames as $f) {
                if (!isset($f['file']) || !is_string($f['file']) || $f['file'] === '') {
                    continue;
                }
                if (strpos($f['file'], $sdk . DIRECTORY_SEPARATOR) === 0) {
                    continue;
                }
                return $f['file'];
            }
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }
        return null;
    }

    public static function setClient(Client $client)
    {
        self::$client = $client;
        Pinger::maybePing($client->config());
        Updater::registerWebHook($client->config());
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

    /**
     * Report an uncaught throwable (handled=false). See Client::captureUnhandled().
     *
     * @param \Throwable|\Exception $e
     */
    public static function captureUnhandled($e, array $extra = array())
    {
        if (self::$client) {
            self::$client->captureUnhandled($e, $extra);
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
        if (self::$client && method_exists(self::$client, 'setQueryRunner')) {
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
        if (!self::$client || !($pdo instanceof \PDO)
            || !method_exists(self::$client, 'setQueryRunner')) {
            return;
        }
        self::$client->setQueryRunner(function ($sql, array $bindings, $returnRows = false) use ($pdo) {
            $inTransaction = false;
            try {
                $inTransaction = $pdo->beginTransaction();
            } catch (\Exception $e) {
                $inTransaction = false;   // e.g. DDL-implicit-commit engines
            }
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($bindings);
                if ($returnRows) {
                    $out = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                    $stmt->closeCursor();

                    return is_array($out) ? $out : array();
                }
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
    // ---- Background-process attribution (needs core >= 1.7.0) ----------
    // All guarded with method_exists(): a newer adapter over an older core
    // must never fatal the host application (v1.5.2 lesson).

    // ---- Self-update (core >= 1.7.0) --------------------------------------

    /** @return array current, latest, update_available, error */
    public static function checkForUpdate()
    {
        if (!self::$client) {
            return array('current' => self::VERSION, 'latest' => null, 'update_available' => false, 'error' => 'Bugban::init() not called');
        }

        return \Bugban\Sdk\Support\Updater::check(self::$client->config());
    }

    /**
     * Upgrade the installed SDK to the newest release (composer or manual install).
     * @return array ok, mode, from, to, message, output
     */
    public static function update($dryRun = false, $log = null)
    {
        if (!self::$client) {
            return array('ok' => false, 'mode' => 'none', 'from' => self::VERSION, 'to' => null, 'message' => 'Bugban::init() not called', 'output' => '');
        }

        return \Bugban\Sdk\Support\Updater::update(self::$client->config(), null, $dryRun, $log);
    }

    public static function setCommand($name)
    {
        if (self::$client && method_exists(self::$client, 'setCommand')) {
            self::$client->setCommand($name);
        }
    }

    public static function setRunSource($source)
    {
        if (self::$client && method_exists(self::$client, 'setRunSource')) {
            self::$client->setRunSource($source);
        }
    }

    public static function setExitCode($code)
    {
        if (self::$client && method_exists(self::$client, 'setExitCode')) {
            self::$client->setExitCode($code);
        }
    }

    /**
     * @param string $class  job class / task name
     * @param array  $meta   small scalar map (queue, attempts, ...)
     * @param string $source 'queue' (default) or 'scheduler' for inline scheduled tasks
     */
    public static function beginJob($class, array $meta = array(), $source = 'queue')
    {
        if (self::$client && method_exists(self::$client, 'beginJob')) {
            self::$client->beginJob($class, $meta, $source);
        }
    }

    public static function endJob($exitCode = 0, $error = null)
    {
        if (self::$client && method_exists(self::$client, 'endJob')) {
            self::$client->endJob($exitCode, $error);
        }
    }

    public static function checkQueryTests()
    {
        if (self::$client && method_exists(self::$client, 'checkQueryTests')) {
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
            // Reached us through PHP's own error path, not an explicit call → unhandled.
            Bugban::captureMessage($message, 'error', array('severity' => $severity, 'file' => $file, 'line' => $line, 'handled' => false));
            return false; // let PHP's normal handler run too
        });

        set_exception_handler(function ($e) {
            Bugban::captureUnhandled($e);
        });

        register_shutdown_function(function () {
            $err = error_get_last();
            $fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
            if ($err && in_array($err['type'], $fatal, true)) {
                Bugban::captureMessage($err['message'], 'fatal', array('file' => $err['file'], 'line' => $err['line'], 'handled' => false));
            }
        });
    }
}
