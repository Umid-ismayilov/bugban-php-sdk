<?php

namespace Bugban\Sdk;

use Bugban\Sdk\Support\Breadcrumbs;
use Bugban\Sdk\Support\Compat;
use Bugban\Sdk\Support\ContextCollector;
use Bugban\Sdk\Support\StacktraceBuilder;
use Bugban\Sdk\Transport\CurlTransport;
use Bugban\Sdk\Transport\StreamTransport;
use Bugban\Sdk\Transport\Transport;

class Client
{
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
            'stacktrace' => StacktraceBuilder::fromThrowable($e, $this->config->codeContextLines),
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
        if (empty($this->queue)) {
            return;
        }
        // Detach first so a re-entrant capture during send can't cause a double-send.
        $items = $this->queue;
        $this->queue = array();
        foreach ($items as $item) {
            $this->sendOne($item['url'], $item['payload']);
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
