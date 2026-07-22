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
    /** @var bool Forward error-level+ log records (Log::error, caught-and-logged) as events. */ public $captureLogs;
    /** @var string Minimum PSR log level forwarded when $captureLogs is on (debug..emergency). */ public $logLevel;
    /** @var bool Capture slow database queries (see $slowQueryMs). */ public $captureQueries;
    /** @var int Minimum query duration (milliseconds) to report as a slow query. */ public $slowQueryMs;
    /** @var bool Run EXPLAIN on slow SELECTs (Laravel adapter / TracedPdo) to detect index usage. */ public $explainQueries;
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
    /**
     * Allow Bugban to ask this app to re-run one of its own captured SELECTs and
     * report the timing.
     *
     * ON by default, because every layer that would make it dangerous is
     * removed: only a single SELECT/WITH runs (checked HERE, not just on the
     * server), a LIMIT is forced, it runs inside a transaction that is always
     * rolled back, and ONLY the duration and row COUNT travel back — never a
     * single row of data. The statement is one this application already runs.
     *
     * Set BUGBAN_ALLOW_QUERY_TEST=false to switch it off.
     * @var bool
     */
    public $allowQueryTest;

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
        // Auto-forward Log::error()/critical()/... records (and caught-and-logged errors) to
        // Bugban. Off by default so existing installs don't suddenly change what they report.
        $this->captureLogs = isset($c['capture_logs']) ? (bool) $c['capture_logs'] : false;
        $this->logLevel = (isset($c['log_level']) && $c['log_level']) ? strtolower((string) $c['log_level']) : 'error';
        $this->captureQueries = isset($c['capture_queries']) ? (bool) $c['capture_queries'] : true;
        $this->slowQueryMs = isset($c['slow_query_ms']) ? (int) $c['slow_query_ms'] : 1000;
        $this->explainQueries = isset($c['explain_queries']) ? (bool) $c['explain_queries'] : true;
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
        $this->allowQueryTest = isset($c['allow_query_test']) ? (bool) $c['allow_query_test'] : true;
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

    public function queriesUrl()
    {
        return $this->host . '/api/ingest/queries';
    }

    public function pingUrl()
    {
        return $this->host . '/api/ingest/ping';
    }

    /** Where the SDK asks whether a query test is waiting for it. */
    public function pendingTestsUrl()
    {
        return $this->host . '/api/ingest/tests/pending';
    }

    /** Where the SDK posts a finished test back. */
    public function testResultUrl($id)
    {
        return $this->host . '/api/ingest/tests/' . (int) $id . '/result';
    }
}
