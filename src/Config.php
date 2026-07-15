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
}
