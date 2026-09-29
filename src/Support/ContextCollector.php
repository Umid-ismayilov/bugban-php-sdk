<?php

namespace Bugban\Sdk\Support;

/**
 * Framework-agnostic context collection from PHP superglobals.
 * Framework adapters (e.g. Laravel) can supply richer data via Config::$contextResolver.
 */
class ContextCollector
{
    /** @var array */
    private $redact;

    public function __construct(array $redact = array())
    {
        $this->redact = $redact;
    }

    /** Max bytes of raw request body to capture (JSON/text APIs). */
    const MAX_RAW_BODY = 65536;

    public function request()
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return self::cliRequest();
        }

        $headers = function_exists('getallheaders') ? getallheaders() : array();
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $path = strtok($uri, '?');

        $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : null;
        $body = $this->redactArray(isset($_POST) ? $_POST : array());

        // For JSON / non-form APIs, $_POST is empty — the payload lives in
        // php://input. Capture it (this is exactly what the AI needs to see
        // which request caused the error). Decode + redact JSON when possible.
        $rawBody = null;
        if (empty($body) && $this->hasRawBody($contentType)) {
            $raw = $this->readRawInput();
            if ($raw !== null && $raw !== '') {
                if ($contentType !== null && stripos($contentType, 'json') !== false) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $body = $this->redactArray($decoded);
                    } else {
                        $rawBody = $this->truncate($raw);
                    }
                } else {
                    $rawBody = $this->truncate($raw);
                }
            }
        }

        $data = array(
            'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null,
            'url' => $this->currentUrl(),
            'path' => $path !== false ? $path : null,
            'query' => $this->redactArray(isset($_GET) ? $_GET : array()),
            'body' => $body,
            'headers' => $this->redactArray(is_array($headers) ? $headers : array()),
            'cookies' => self::redactCookies($this->redactArray(isset($_COOKIE) ? $_COOKIE : array()), function_exists('session_name') ? session_name() : null),
            'ip' => $this->clientIp(),
            'content_type' => $contentType,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null,
            'referer' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null,
            'protocol' => isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : null,
            'host' => isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : null,
        );

        if ($rawBody !== null) {
            $data['raw_body'] = $rawBody;
        }

        return $data;
    }

    /** Whether this content type is likely to carry a raw (non-form) body. */
    private function hasRawBody($contentType)
    {
        if ($contentType === null) {
            return false;
        }
        if (stripos($contentType, 'multipart/form-data') !== false) {
            return false; // uploads — don't slurp
        }
        if (stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
            return false; // already in $_POST
        }
        return true;
    }

    private function readRawInput()
    {
        try {
            $raw = @file_get_contents('php://input', false, null, 0, self::MAX_RAW_BODY + 1);
            return $raw === false ? null : $raw;
        } catch (\Throwable $e) {
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function truncate($str)
    {
        if (strlen($str) <= self::MAX_RAW_BODY) {
            return $str;
        }
        return substr($str, 0, self::MAX_RAW_BODY) . '...[truncated]';
    }

    /** Best-effort real client IP, honouring common proxy headers. */
    private function clientIp()
    {
        $candidates = array('HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR');
        foreach ($candidates as $key) {
            if (!empty($_SERVER[$key])) {
                $val = $_SERVER[$key];
                if (strpos($val, ',') !== false) {
                    $parts = explode(',', $val);
                    $val = trim($parts[0]);
                }
                return $val;
            }
        }
        return null;
    }

    /**
     * CLI "request": the command line that was running when the error happened.
     *
     * A cron job or queue worker has no HTTP request, but the panel still needs
     * to answer "where did this come from?". Bugsnag shows e.g.
     * "artisan custom:declarations"; we do the same via the `request` block so
     * the same header/route line renders without a second code path.
     * Values that look like secrets (--password=..., --token=...) are masked.
     *
     * @return array|null
     */
    public static function cliRequest()
    {
        $argv = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : array();
        if (empty($argv)) {
            return array('method' => 'CLI', 'path' => 'php', 'url' => 'php', 'host' => php_uname('n'));
        }
        $script = basename((string) $argv[0]);
        $args = array();
        foreach (array_slice($argv, 1) as $a) {
            $a = (string) $a;
            if (preg_match('/^(--?[A-Za-z0-9_-]*(pass|password|secret|token|key|auth)[A-Za-z0-9_-]*)=(.*)$/i', $a, $m)) {
                $a = $m[1] . '=[REDACTED]';
            }
            if (strlen($a) > 200) {
                $a = substr($a, 0, 200) . '…';
            }
            $args[] = $a;
        }
        $command = $script . ($args ? ' ' . implode(' ', $args) : '');
        if (strlen($command) > 1000) {
            $command = substr($command, 0, 1000) . '…';
        }
        // First non-option argument is the command name (artisan/yii/console style).
        $name = null;
        foreach ($args as $a) {
            if ($a !== '' && $a[0] !== '-') {
                $name = $a;
                break;
            }
        }
        return array(
            'method' => 'CLI',
            'path' => $command,
            'url' => $command,
            'route' => $name,
            'script' => $script,
            'args' => $args,
            'host' => php_uname('n'),
            'pid' => function_exists('getmypid') ? getmypid() : null,
            'cwd' => getcwd(),
        );
    }

    public function session()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        return array(
            'id' => session_id() ? session_id() : null,
            'data' => $this->redactArray(isset($_SESSION) ? $_SESSION : array()),
        );
    }

    /**
     * Best-effort logged-in user from an ALREADY-started native PHP session
     * (plain PHP, CodeIgniter 3/4 incl. Ion Auth, Shield and Myth:Auth use
     * $_SESSION). Never starts a session, never throws; null when nothing
     * recognisable is there.
     *
     * @return array|null
     */
    public static function sessionUser()
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION) || !is_array($_SESSION)) {
            return null;
        }
        $s = $_SESSION;
        $user = array();

        // A user record kept whole: $_SESSION['user'] = array('id' => .., ...)
        foreach (array('user', 'auth_user', 'current_user', 'logged_user', 'admin', 'auth', 'member') as $k) {
            if (!isset($s[$k])) {
                continue;
            }
            $v = $s[$k];
            if (is_object($v) && !($v instanceof \__PHP_Incomplete_Class)) {
                $v = method_exists($v, 'toArray') ? $v->toArray() : get_object_vars($v);
            }
            if (is_array($v)) {
                $id = self::firstScalar($v, array('id', 'user_id', 'uid', 'ID'));
                if ($id !== null) {
                    $user = array(
                        'id' => $id,
                        'email' => self::firstScalar($v, array('email', 'mail', 'user_email')),
                        'name' => self::firstScalar($v, array('name', 'username', 'full_name', 'login', 'display_name')),
                        'guard' => 'session:' . $k,
                    );
                    break;
                }
            }
        }

        // Flat keys: $_SESSION['user_id'] = 5 (Ion Auth, hand-rolled logins).
        if (empty($user)) {
            foreach (array('user_id', 'userId', 'userid', 'uid', 'admin_id', 'id_user', 'member_id', 'customer_id', 'logged_in') as $k) {
                if (!isset($s[$k]) || is_bool($s[$k]) || !is_scalar($s[$k]) || (string) $s[$k] === '') {
                    continue;
                }
                // 'logged_in' is often just a true/1 flag — only a real id counts.
                if ($k === 'logged_in' && (!is_numeric($s[$k]) || (int) $s[$k] <= 1)) {
                    continue;
                }
                $user = array(
                    'id' => $s[$k],
                    'email' => self::firstScalar($s, array('email', 'user_email', 'identity')),
                    'name' => self::firstScalar($s, array('username', 'user_name', 'name', 'full_name')),
                    'guard' => 'session:' . $k,
                );
                break;
            }
        }

        if (empty($user)) {
            return null;
        }
        if (isset($user['email']) && strpos((string) $user['email'], '@') === false) {
            // Ion Auth's 'identity' may be a username, not an email.
            if (empty($user['name'])) {
                $user['name'] = $user['email'];
            }
            $user['email'] = null;
        }
        return array_filter($user, function ($v) {
            return $v !== null && $v !== '';
        });
    }

    /**
     * @return string|int|null
     */
    private static function firstScalar(array $a, array $keys)
    {
        foreach ($keys as $k) {
            if (isset($a[$k]) && is_scalar($a[$k]) && !is_bool($a[$k]) && (string) $a[$k] !== '') {
                return $a[$k];
            }
        }
        return null;
    }

    private function currentUrl()
    {
        if (empty($_SERVER['HTTP_HOST'])) {
            return null;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $scheme = $https ? 'https' : 'http';
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

        return $scheme . '://' . $_SERVER['HTTP_HOST'] . $uri;
    }

    /**
     * Cookies safe to ship: the session cookie, remember-me, XSRF and any
     * cookie named like session/token/auth are replaced, others kept.
     *
     * @return array
     */
    public static function redactCookies(array $cookies, $sessionCookie = null)
    {
        $out = array();
        foreach ($cookies as $k => $v) {
            $lk = strtolower((string) $k);
            $secret = ($sessionCookie !== null && $sessionCookie !== '' && $lk === strtolower($sessionCookie))
                || strpos($lk, 'remember_') === 0
                || preg_match('/sess|token|auth|xsrf|csrf|jwt|identity|sid$/', $lk);
            $out[$k] = $secret ? '[REDACTED]' : $v;
        }

        return $out;
    }

    public function redactArray(array $data)
    {
        $keys = array_map('strtolower', $this->redact);
        $out = array();
        foreach ($data as $k => $v) {
            if (in_array(strtolower((string) $k), $keys, true)) {
                $out[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $out[$k] = $this->redactArray($v);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
