<?php

namespace Bugban\Sdk;

class Config
{
    /** @var string */ public $apiKey;
    /** @var string */ public $host;
    /** @var string */ public $environment;
    /** @var string|null */ public $release;
    /** @var bool */ public $enabled;
    /** @var int */ public $timeout;
    /** @var float */ public $sampleRate;
    /** @var bool */ public $captureRequests;
    /** @var array */ public $redact;
    /** @var callable|null */ public $beforeSend;
    /** @var callable|null */ public $contextResolver;
    /** @var int */ public $codeContextLines;
    /** @var bool */ public $codeFullFunction;
    /** @var bool */ public $sendOnShutdown;
    /** @var string|null Human-readable application name (shown in the panel after the install ping). */ public $appName;
    /** @var string|null Framework name provided by an adapter (e.g. 'laravel'). */ public $framework;
    /** @var string|null Framework version provided by an adapter. */ public $frameworkVersion;
    /** @var string|null Notifier/SDK package name override (adapters may set e.g. 'bugban/laravel'). */ public $sdkName;

    public function __construct(array $c = array())
    {
        $this->apiKey = (string) (isset($c['api_key']) ? $c['api_key'] : '');
        $this->host = rtrim((string) (isset($c['host']) && $c['host'] ? $c['host'] : 'https://bugban.online'), '/');
        $this->environment = (string) (isset($c['environment']) && $c['environment'] ? $c['environment'] : 'production');
        $this->release = isset($c['release']) ? $c['release'] : null;
        $this->enabled = isset($c['enabled']) ? (bool) $c['enabled'] : true;
        $this->timeout = isset($c['timeout']) ? (int) $c['timeout'] : 3;
        $this->sampleRate = isset($c['sample_rate']) ? (float) $c['sample_rate'] : 1.0;
        $this->captureRequests = isset($c['capture_requests']) ? (bool) $c['capture_requests'] : false;
        $this->redact = (isset($c['redact']) && is_array($c['redact']))
            ? $c['redact']
            : array('password', 'password_confirmation', 'token', 'secret', 'authorization', 'cookie', 'api_key');
        $this->beforeSend = isset($c['before_send']) ? $c['before_send'] : null;
        $this->contextResolver = isset($c['context_resolver']) ? $c['context_resolver'] : null;
        $this->codeContextLines = isset($c['code_context_lines']) ? (int) $c['code_context_lines'] : 5;
        // Capture the WHOLE enclosing function/method body for stack frames (falls back
        // to the ±code_context_lines window when the callable cannot be resolved).
        $this->codeFullFunction = isset($c['code_full_function']) ? (bool) $c['code_full_function'] : true;
        // Defer telemetry to shutdown (after the response is flushed to the user) on web SAPIs.
        // On CLI there is no fastcgi_finish_request and scripts are short-lived, so send inline
        // by default to guarantee delivery before the process exits.
        $this->sendOnShutdown = isset($c['send_on_shutdown'])
            ? (bool) $c['send_on_shutdown']
            : (PHP_SAPI !== 'cli');
        // Optional metadata used by the one-time install ping (SDK handshake).
        $this->appName = (isset($c['app_name']) && $c['app_name'] !== '' && $c['app_name'] !== null) ? (string) $c['app_name'] : null;
        $this->framework = (isset($c['framework']) && $c['framework'] !== '' && $c['framework'] !== null) ? (string) $c['framework'] : null;
        $this->frameworkVersion = (isset($c['framework_version']) && $c['framework_version'] !== '' && $c['framework_version'] !== null) ? (string) $c['framework_version'] : null;
        $this->sdkName = (isset($c['sdk']) && $c['sdk'] !== '' && $c['sdk'] !== null) ? (string) $c['sdk'] : null;
    }

    public function isUsable()
    {
        return $this->enabled && $this->apiKey !== '';
    }

    public function eventsUrl()
    {
        return $this->host . '/api/ingest/events';
    }

    public function requestsUrl()
    {
        return $this->host . '/api/ingest/requests';
    }

    public function pingUrl()
    {
        return $this->host . '/api/ingest/ping';
    }
}
