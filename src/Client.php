<?php

namespace Bugban\Sdk;

use Bugban\Sdk\Support\Breadcrumbs;
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
