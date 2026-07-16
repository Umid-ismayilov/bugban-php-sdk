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
        $this->transport->send($this->config->requestsUrl(), $this->config->apiKey, $data);
    }

    private function dispatch(array $payload)
    {
        if (is_callable($this->config->beforeSend)) {
            $payload = call_user_func($this->config->beforeSend, $payload);
            if (!is_array($payload)) {
                return;
            }
        }
        $this->transport->send($this->config->eventsUrl(), $this->config->apiKey, $payload);
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
