<?php

namespace Bugban\Sdk;

use Bugban\Sdk\Support\Breadcrumbs;
use Bugban\Sdk\Support\QueryTester;
use Bugban\Sdk\Support\CallerFinder;
use Bugban\Sdk\Support\Compat;
use Bugban\Sdk\Support\ContextCollector;
use Bugban\Sdk\Support\StacktraceBuilder;
use Bugban\Sdk\Transport\CurlTransport;
use Bugban\Sdk\Transport\StreamTransport;
use Bugban\Sdk\Transport\Transport;

class Client
{
    /** Max buffered slow-query rows per process/request (server accepts 50 per POST). */
    const MAX_QUERY_BUFFER = 25;
    /** Max SQL text length sent per query row. */
    const MAX_SQL_LENGTH = 10000;
    /** Max bindings kept per query row. */
    const MAX_BINDINGS = 30;
    /** Max characters kept per string binding. */
    const MAX_BINDING_LENGTH = 200;

    /** @var Config */
    private $config;
    /** @var Transport */
    private $transport;
    /** @var Breadcrumbs */
    private $breadcrumbs;
    /** @var ContextCollector */
    private $collector;
    /** @var array|null */
    private $user = null;
    /** @var array */
    private $extraContext = array();
    /** @var array Buffered telemetry payloads (each: array('url' => string, 'payload' => array)) */
    private $queue = array();
    /** @var array Buffered slow-query rows, batched into ONE POST at shutdown. */
    private $queries = array();
    /** @var bool Guard so only ONE shutdown function is ever registered per client. */
    private $shutdownRegistered = false;
    /** @var bool True once the shutdown flush has begun; later captures then send inline. */
    private $shutdownStarted = false;
    /**
     * Adapter-supplied closure that executes a read-only statement on the
     * application's OWN connection and returns the row count. Without one,
     * query tests silently do nothing — which is the state of any app that has
     * not wired an adapter.
     * @var callable|null
     */
    private $queryRunner = null;
    /** @var bool One test per process; a request must not become a test loop. */
    private $testChecked = false;

    /** Seconds between test polls, app-wide. Keeps the feature ~free. */
    const TEST_POLL_SECONDS = 20;

    public function __construct(Config $config, Transport $transport = null)
    {
        $this->config = $config;
        $this->transport = $transport ? $transport : self::defaultTransport($config->timeout);
        $this->breadcrumbs = new Breadcrumbs();
        $this->collector = new ContextCollector($config->redact);
    }

    /**
     * Pick the best available transport: cURL if present, otherwise PHP streams.
     */
    private static function defaultTransport($timeout)
    {
        if (extension_loaded('curl')) {
            return new CurlTransport($timeout);
        }
        return new StreamTransport($timeout);
    }

    public function config()
    {
        return $this->config;
    }

    public function breadcrumbs()
    {
        return $this->breadcrumbs;
    }

    public function setUser(array $user)
    {
        $this->user = $user;
        return $this;
    }

    public function setContext($key, $value)
    {
        $this->extraContext[$key] = $value;
        return $this;
    }

    public function addBreadcrumb($message, $category = 'default', array $data = array(), $level = 'info')
    {
        $this->breadcrumbs->add($message, $category, $data, $level);
        return $this;
    }

    /**
     * Report a caught throwable/exception.
     *
     * @param \Throwable|\Exception $e
     */
    public function capture($e, array $extra = array())
    {
        if (!$this->config->isUsable() || !$this->shouldSample()) {
            return;
        }

        $ctx = $this->collectContext();
        $payload = array(
            'type' => 'error',
            'exception_class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'level' => 'error',
            'handled' => true,
            'release' => $this->config->release,
            'environment' => $this->config->environment,
            'stacktrace' => StacktraceBuilder::fromThrowable($e, $this->config->codeContextLines, $this->config->codeFullFunction),
            'request' => $ctx['request'],
            'session' => $ctx['session'],
            'user' => $ctx['user'],
            'device' => Compat::runtime(),
            'breadcrumbs' => $this->breadcrumbs->all(),
            'context' => array_merge($this->extraContext, $ctx['context'], $extra),
        );

        $this->dispatch($payload);
    }

    /**
     * Report a message / non-exception event (also used by error & shutdown handlers).
     */
    public function captureMessage($message, $level = 'info', array $extra = array())
    {
        if (!$this->config->isUsable() || !$this->shouldSample()) {
            return;
        }

        $ctx = $this->collectContext();
        $payload = array(
            'type' => 'log',
            'exception_class' => null,
            'message' => $message,
            'file' => isset($extra['file']) ? $extra['file'] : null,
            'line' => isset($extra['line']) ? $extra['line'] : null,
            'level' => $level,
            'handled' => true,
            'release' => $this->config->release,
            'environment' => $this->config->environment,
            'stacktrace' => null,
            'request' => $ctx['request'],
            'session' => $ctx['session'],
            'user' => $ctx['user'],
            'device' => Compat::runtime(),
            'breadcrumbs' => $this->breadcrumbs->all(),
            'context' => array_merge($this->extraContext, $ctx['context'], $extra),
        );

        $this->dispatch($payload);
    }

    /**
     * PSR log-level severity ranking (higher = more severe). Unknown levels are
     * treated as 'error' so they are never silently dropped by the threshold.
     *
     * @param mixed $level
     * @return int
     */
    private static function levelSeverity($level)
    {
        $map = array(
            'debug' => 0,
            'info' => 1,
            'notice' => 2,
            'warning' => 3,
            'error' => 4,
            'critical' => 5,
            'alert' => 6,
            'emergency' => 7,
        );
        $level = is_string($level) ? strtolower($level) : 'error';
        return isset($map[$level]) ? $map[$level] : 4;
    }

    /**
     * Forward a log record (Log::error, caught-and-logged error, etc.) as a handled
     * event through the SAME buffered/shutdown delivery path events use.
     *
     * No-op unless the config is usable, capture_logs is on and $level is at/above the
     * configured log_level. When $context['exception'] is a Throwable, its class/file/line
     * and a full stacktrace are used; otherwise a synthetic 'Log' class with a caller
     * frame + lightweight backtrace is built. The context is redacted (password/token/
     * secret/authorization/...) and the raw Throwable is replaced by its class/message.
     * NEVER throws.
     *
     * @param string $level   PSR level.
     * @param string $message Log message.
     * @param array  $context Monolog context.
     * @return void
     */
    public function recordLogEvent($level, $message, array $context = array())
    {
        try {
            if (!$this->config->isUsable() || !$this->config->captureLogs) {
                return;
            }
            $level = is_string($level) ? strtolower($level) : 'error';
            if (self::levelSeverity($level) < self::levelSeverity($this->config->logLevel)) {
                return;
            }
            if (!$this->shouldSample()) {
                return;
            }

            $message = is_string($message) ? $message : $this->stringifyMessage($message);

            $throwable = isset($context['exception']) ? $context['exception'] : null;
            $isThrowable = ($throwable instanceof \Throwable || $throwable instanceof \Exception);

            $excClass = 'Log';
            $file = null;
            $line = null;
            $stacktrace = null;

            if ($isThrowable) {
                $excClass = get_class($throwable);
                $file = $throwable->getFile();
                $line = $throwable->getLine();
                $stacktrace = StacktraceBuilder::fromThrowable(
                    $throwable,
                    $this->config->codeContextLines,
                    $this->config->codeFullFunction
                );
                if ($message === '') {
                    $message = $throwable->getMessage();
                }
            } else {
                $caller = CallerFinder::find();
                if ($caller !== null) {
                    $file = $caller['file'];
                    $line = $caller['line'];
                }
                $stacktrace = $this->lightweightBacktrace();
            }

            $ctx = $this->collectContext();
            $payload = array(
                'type' => 'log',
                'exception_class' => $excClass,
                'message' => $message,
                'file' => $file,
                'line' => $line,
                'level' => $level,
                'handled' => true,
                'release' => $this->config->release,
                'environment' => $this->config->environment,
                'stacktrace' => $stacktrace,
                'request' => $ctx['request'],
                'session' => $ctx['session'],
                'user' => $ctx['user'],
                'device' => Compat::runtime(),
                'breadcrumbs' => $this->breadcrumbs->all(),
                'context' => array_merge($this->extraContext, $ctx['context'], $this->sanitizeLogContext($context)),
            );

            $this->dispatch($payload);
        } catch (\Exception $e) {
            // Telemetry must be non-fatal.
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    /**
     * Best-effort string form of a non-string log message (Throwable/stringable/other).
     *
     * @param mixed $message
     * @return string
     */
    private function stringifyMessage($message)
    {
        if (is_string($message)) {
            return $message;
        }
        if ($message instanceof \Throwable || $message instanceof \Exception) {
            return $message->getMessage();
        }
        if (is_scalar($message) || $message === null) {
            return (string) $message;
        }
        if (is_object($message) && method_exists($message, '__toString')) {
            return (string) $message;
        }
        return is_object($message) ? '[object ' . get_class($message) . ']' : '[' . gettype($message) . ']';
    }

    /**
     * Redact a log context array for transport: strip configured secret keys, replace a
     * raw 'exception' Throwable with its class/message, and reduce other objects to a
     * light placeholder so serialization stays safe. Never throws.
     *
     * @param array $context
     * @return array
     */
    private function sanitizeLogContext(array $context)
    {
        $out = array();
        foreach ($context as $k => $v) {
            if ($k === 'exception' && ($v instanceof \Throwable || $v instanceof \Exception)) {
                $out[$k] = array(
                    'class' => get_class($v),
                    'message' => $v->getMessage(),
                );
            } elseif (is_object($v)) {
                if ($v instanceof \DateTimeInterface) {
                    $out[$k] = $v->format('Y-m-d H:i:s');
                } elseif (method_exists($v, '__toString')) {
                    $out[$k] = (string) $v;
                } else {
                    $out[$k] = '[object ' . get_class($v) . ']';
                }
            } else {
                $out[$k] = $v;
            }
        }
        return $this->collector->redactArray($out);
    }

    /**
     * A lightweight call stack for synthetic ('Log') events: the frames leading to the
     * log call, excluding the SDK's own src/ frames. No source-code capture — just
     * file/line/function/class/type. Never throws.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function lightweightBacktrace()
    {
        try {
            $sdkDir = str_replace('\\', '/', __DIR__); // src/
            $frames = array();
            $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50);
            foreach ($bt as $t) {
                $file = isset($t['file']) && is_string($t['file']) ? str_replace('\\', '/', $t['file']) : null;
                if ($file !== null && strpos($file, $sdkDir . '/') === 0) {
                    continue; // inside the SDK itself
                }
                $frames[] = array(
                    'file' => isset($t['file']) ? $t['file'] : '[internal]',
                    'line' => isset($t['line']) ? (int) $t['line'] : null,
                    'function' => isset($t['function']) ? $t['function'] : null,
                    'class' => isset($t['class']) ? $t['class'] : null,
                    'type' => isset($t['type']) ? $t['type'] : null,
                );
                if (count($frames) >= 30) {
                    break;
                }
            }
            return count($frames) > 0 ? $frames : null;
        } catch (\Exception $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Send a request/performance log.
     */
    public function captureRequest(array $data)
    {
        if (!$this->config->isUsable()) {
            return;
        }
        $this->emit($this->config->requestsUrl(), $data);
    }

    /**
     * Record a database query for slow-query (performance) monitoring.
     *
     * Queries faster than Config::$slowQueryMs are ignored. Slow ones are
     * buffered (max MAX_QUERY_BUFFER per process/request) and batched into a
     * single non-blocking POST at shutdown — the same deferred mechanism
     * events use. NEVER throws; silent no-op when the config is not usable
     * or capture_queries is disabled.
     *
     * @param string $sql        Raw SQL text (any driver — MySQL, PostgreSQL, SQLite...).
     * @param float|int $durationMs Query duration in MILLISECONDS.
     * @param array  $meta       Optional: connection, bindings, file, line.
     * @return void
     */
    public function recordQuery($sql, $durationMs, array $meta = array())
    {
        try {
            if (!$this->config->isUsable() || !$this->config->captureQueries) {
                return;
            }
            if (!is_string($sql)) {
                if (is_object($sql) && method_exists($sql, '__toString')) {
                    $sql = (string) $sql;
                } else {
                    return;
                }
            }
            $sql = trim($sql);
            if ($sql === '' || !is_numeric($durationMs)) {
                return;
            }
            $durationMs = (float) $durationMs;
            if ($durationMs < $this->config->slowQueryMs) {
                return;
            }
            if (count($this->queries) >= self::MAX_QUERY_BUFFER) {
                return;
            }

            $row = array(
                'sql' => strlen($sql) > self::MAX_SQL_LENGTH
                    ? substr($sql, 0, self::MAX_SQL_LENGTH) . '...[truncated]'
                    : $sql,
                'duration_ms' => round($durationMs, 2),
                'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            );

            if (isset($meta['connection']) && is_string($meta['connection']) && $meta['connection'] !== '') {
                $row['connection'] = $meta['connection'];
            }
            if (isset($meta['bindings']) && is_array($meta['bindings']) && !empty($meta['bindings'])) {
                $row['bindings'] = $this->sanitizeBindings($meta['bindings']);
            }

            // Optional EXPLAIN / index-usage result, produced by the Laravel
            // adapter or TracedPdo (via ExplainParser). Forwarded as-is; the
            // server flags full-table-scans from it. Callers of the core may
            // also pass their own meta['explain'] for other frameworks.
            if (isset($meta['explain']) && is_array($meta['explain']) && !empty($meta['explain'])) {
                $row['explain'] = $meta['explain'];
            }

            // Caller: explicit meta wins; otherwise first stack frame outside
            // vendor/ and outside the SDK itself.
            if (isset($meta['file']) && is_string($meta['file']) && $meta['file'] !== '') {
                $row['file'] = $meta['file'];
                if (isset($meta['line']) && is_numeric($meta['line'])) {
                    $row['line'] = (int) $meta['line'];
                }
            } else {
                $caller = CallerFinder::find();
                if ($caller !== null) {
                    $row['file'] = $caller['file'];
                    if ($caller['line'] !== null) {
                        $row['line'] = $caller['line'];
                    }
                }
            }

            // Request context (web SAPIs only).
            if (PHP_SAPI !== 'cli') {
                if (!empty($_SERVER['REQUEST_URI'])) {
                    $row['url'] = (string) $_SERVER['REQUEST_URI'];
                }
                if (!empty($_SERVER['REQUEST_METHOD'])) {
                    $row['method'] = (string) $_SERVER['REQUEST_METHOD'];
                }
            }

            if ($this->shutdownStarted) {
                // Shutdown flush already ran — deliver inline so nothing is lost.
                $this->sendOne($this->config->queriesUrl(), array('queries' => array($row)));
                return;
            }

            $this->queries[] = $row;
            $this->registerShutdownFlush();
        } catch (\Exception $e) {
            // Telemetry must be non-fatal.
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    /**
     * Positional bindings, made safe to serialize: capped count, scalars only,
     * long strings truncated. Objects/arrays are replaced with placeholders.
     *
     * @param array $bindings
     * @return array
     */
    private function sanitizeBindings(array $bindings)
    {
        $out = array();
        $count = 0;
        foreach ($bindings as $value) {
            if ($count >= self::MAX_BINDINGS) {
                $out[] = '...[' . (count($bindings) - self::MAX_BINDINGS) . ' more]';
                break;
            }
            $count++;
            if (is_string($value)) {
                $out[] = strlen($value) > self::MAX_BINDING_LENGTH
                    ? substr($value, 0, self::MAX_BINDING_LENGTH) . '...[truncated]'
                    : $value;
            } elseif (is_scalar($value) || $value === null) {
                $out[] = $value;
            } elseif (is_object($value)) {
                if ($value instanceof \DateTimeInterface) {
                    $out[] = $value->format('Y-m-d H:i:s');
                } else {
                    $out[] = '[object ' . get_class($value) . ']';
                }
            } elseif (is_array($value)) {
                $out[] = '[array:' . count($value) . ']';
            } else {
                $out[] = '[resource]';
            }
        }
        return $out;
    }

    private function dispatch(array $payload)
    {
        if (is_callable($this->config->beforeSend)) {
            $payload = call_user_func($this->config->beforeSend, $payload);
            if (!is_array($payload)) {
                return;
            }
        }
        $this->emit($this->config->eventsUrl(), $payload);
    }

    /**
     * Either buffer the payload for a non-blocking shutdown flush (web SAPI, deferred),
     * or send it inline (CLI, flag off, or when we are already flushing at shutdown).
     */
    private function emit($url, array $payload)
    {
        $deferred = $this->config->sendOnShutdown && !$this->shutdownStarted;
        if ($deferred) {
            $this->queue[] = array('url' => $url, 'payload' => $payload);
            $this->registerShutdownFlush();
            return;
        }
        $this->sendOne($url, $payload);
    }

    /**
     * Register EXACTLY ONE shutdown function (guarded) that flushes the buffered queue
     * AFTER the HTTP response has been handed back to the end user.
     */
    private function registerShutdownFlush()
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        $self = $this;
        register_shutdown_function(function () use ($self) {
            $self->runShutdownFlush();
        });
    }

    /**
     * Shutdown handler: flush the response to the user first (PHP-FPM / LiteSpeed),
     * then deliver the buffered telemetry. Public because it is invoked from the
     * registered shutdown closure. Never throws.
     */
    public function runShutdownFlush()
    {
        // From now on, any freshly-captured events (e.g. a fatal caught by the SDK's own
        // shutdown handler that runs after this one) send inline instead of buffering.
        $this->shutdownStarted = true;

        // Hand the response back to the end user BEFORE we spend time on HTTP sends,
        // so telemetry adds zero perceived latency under PHP-FPM.
        if (function_exists('fastcgi_finish_request')) {
            try {
                fastcgi_finish_request();
            } catch (\Exception $e) {
                // non-fatal
            } catch (\Throwable $e) {
                // non-fatal
            }
        } elseif (function_exists('litespeed_finish_request')) {
            try {
                litespeed_finish_request();
            } catch (\Exception $e) {
                // non-fatal
            } catch (\Throwable $e) {
                // non-fatal
            }
        }

        $this->flushQueue();
    }

    /**
     * Force-send any buffered telemetry immediately. Useful for CLI, tests and
     * queue workers that want deterministic, synchronous delivery.
     */
    public function flush()
    {
        $this->flushQueue();
    }

    /**
     * Register the closure that runs a test statement. Called by the framework
     * adapter (or by the app itself for a composer-less install).
     *
     * @param callable $runner function(string $sql, array $bindings): int
     * @return void
     */
    public function setQueryRunner($runner)
    {
        if (!is_callable($runner)) {
            return;
        }
        $this->queryRunner = $runner;

        // Poll AFTER the response is delivered, so a waiting test costs the end
        // user nothing. Registered here — not on capture — because the point of
        // a test is to run when the query is no longer slow and nothing else
        // would trigger a flush.
        if ($this->config->isUsable() && $this->config->allowQueryTest) {
            $self = $this;
            register_shutdown_function(function () use ($self) {
                $self->checkQueryTests();
            });
        }
    }

    /**
     * At most one poll per POLL_SECONDS across the whole application, tracked by
     * a temp-file mtime. Without this every request would add an HTTP GET.
     *
     * @return bool
     */
    private function shouldPollForTests()
    {
        try {
            if (!function_exists('sys_get_temp_dir')) {
                return false;
            }
            $dir = @sys_get_temp_dir();
            if (!is_string($dir) || $dir === '' || !@is_dir($dir) || !@is_writable($dir)) {
                return false;
            }
            $marker = rtrim($dir, '/\\') . '/bugban-test-' . md5($this->config->apiKey . '|' . $this->config->host);

            if (@is_file($marker) && (time() - (int) @filemtime($marker)) < self::TEST_POLL_SECONDS) {
                return false;
            }
            @file_put_contents($marker, (string) time());

            return true;
        } catch (\Exception $e) {
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Ask Bugban whether a query test is waiting; if so run it and report back.
     *
     * Deliberately does nothing unless the app opted in AND an adapter supplied
     * a runner. Runs at most once per process and never throws, so wiring it
     * into a request lifecycle cannot hurt the host application.
     *
     * @return void
     */
    public function checkQueryTests()
    {
        if ($this->testChecked || !$this->config->isUsable()
            || !$this->config->allowQueryTest || !is_callable($this->queryRunner)) {
            return;
        }
        $this->testChecked = true;

        if (!$this->shouldPollForTests()) {
            return;
        }

        try {
            $response = $this->transport->fetch($this->config->pendingTestsUrl(), $this->config->apiKey);
            if (!is_array($response) || empty($response['test']) || !is_array($response['test'])) {
                return;
            }

            $test = $response['test'];
            $id = isset($test['id']) ? (int) $test['id'] : 0;
            $sql = isset($test['sql']) ? (string) $test['sql'] : '';
            $bindings = (isset($test['bindings']) && is_array($test['bindings'])) ? $test['bindings'] : array();
            if ($id < 1) {
                return;
            }

            $result = QueryTester::run($this->queryRunner, $sql, $bindings);

            // Only timing and a row COUNT go back. Result rows never leave the
            // customer's server — that is the whole point of running it here.
            $this->sendOne($this->config->testResultUrl($id), array(
                'duration_ms' => isset($result['duration_ms']) ? $result['duration_ms'] : null,
                'rows'        => isset($result['rows']) ? $result['rows'] : null,
                'error'       => isset($result['error']) ? $result['error'] : null,
            ));
        } catch (\Exception $e) {
            // A monitoring feature must never break the app.
        } catch (\Throwable $e) {
            // Same for PHP 7+ engine errors.
        }
    }

    private function flushQueue()
    {
        // Detach first so a re-entrant capture during send can't cause a double-send.
        $items = $this->queue;
        $this->queue = array();
        foreach ($items as $item) {
            $this->sendOne($item['url'], $item['payload']);
        }

        // Slow-query rows go out as ONE batched POST (server caps at 50 rows).
        $rows = $this->queries;
        $this->queries = array();
        if (!empty($rows)) {
            $this->sendOne($this->config->queriesUrl(), array('queries' => array_slice($rows, 0, 50)));
        }
    }

    /**
     * Single transport send, fully guarded — the client app must NEVER get an exception.
     */
    private function sendOne($url, array $payload)
    {
        try {
            $this->transport->send($url, $this->config->apiKey, $payload);
        } catch (\Exception $e) {
            // Telemetry must be non-fatal.
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    private function collectContext()
    {
        $ctx = array('request' => null, 'session' => null, 'user' => $this->user, 'context' => array());

        if (is_callable($this->config->contextResolver)) {
            $resolved = call_user_func($this->config->contextResolver);
            if (is_array($resolved)) {
                foreach (array('request', 'session', 'user', 'context') as $k) {
                    if (isset($resolved[$k]) && $resolved[$k] !== null) {
                        $ctx[$k] = $resolved[$k];
                    }
                }
                return $ctx;
            }
        }

        $ctx['request'] = $this->collector->request();
        $ctx['session'] = $this->collector->session();
        return $ctx;
    }

    private function shouldSample()
    {
        if ($this->config->sampleRate >= 1.0) {
            return true;
        }
        return (mt_rand() / mt_getrandmax()) <= $this->config->sampleRate;
    }
}
