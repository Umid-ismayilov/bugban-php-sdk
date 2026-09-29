<?php

namespace Bugban\Sdk\Support;

/**
 * Logged-in user of a Laravel app, seen from the core SDK alone.
 *
 * Manual installs (bugban.php + libs/bugban-php-sdk, no bugban/laravel
 * adapter) never keep the auth user in $_SESSION — Laravel has its own
 * session store — so the plain-session fallback finds nothing there. This
 * asks the running Laravel container instead, with the same rules as the
 * adapter: the default guard (or BUGBAN_AUTH_GUARDS) gets a full check(),
 * other guards only when they already hold a user or their login key is in
 * the session (separate admin/courier/worker panels), token guards last and
 * only when the request carries a bearer token. Laravel 5.5+ / PHP 7.0+.
 */
class LaravelAuth
{
    /**
     * @return array|null  id, email, name, guard
     */
    public static function user()
    {
        if (!class_exists('\Illuminate\Container\Container', false)) {
            return null;
        }
        try {
            $app = \Illuminate\Container\Container::getInstance();
            if (!is_object($app) || !$app->bound('auth') || !$app->bound('config')) {
                return null;
            }
            $auth = $app['auth'];
            $config = $app['config'];
            $default = null;
            try {
                $default = $auth->getDefaultDriver();
            } catch (\Exception $e) {
            } catch (\Throwable $e) {
            }
            $forcedRaw = (string) $config->get('bugban.auth_guards', '');
            if ($forcedRaw === '') {
                $env = getenv('BUGBAN_AUTH_GUARDS');
                $forcedRaw = is_string($env) ? $env : '';
            }
            $forced = array_values(array_filter(array_map('trim', explode(',', $forcedRaw))));
            $names = array_values(array_unique(array_merge(
                $forced,
                $default !== null ? array($default) : array(),
                array_keys((array) $config->get('auth.guards', array()))
            )));

            return self::fromGuards($app, $auth, $names, $default, $forced);
        } catch (\Exception $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function fromGuards($app, $auth, array $names, $default, array $forced)
    {
        $session = null;
        $bearer = null;
        try {
            if ($app->bound('request')) {
                $req = $app['request'];
                $session = method_exists($req, 'hasSession') && $req->hasSession() ? $req->session() : null;
                $bearer = method_exists($req, 'bearerToken') ? $req->bearerToken() : null;
            }
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }

        $cold = self::coldSession($session);
        $later = array();
        foreach ($names as $name) {
            $u = null;
            try {
                $guard = $auth->guard($name);
                if ($cold && method_exists($guard, 'getName')) {
                    // no started session: check() would see nobody, or log in
                    // through the remember cookie with events — fromCookies() below
                    $u = method_exists($guard, 'hasUser') && $guard->hasUser() ? $guard->user() : null;
                } elseif ($name === $default || in_array($name, $forced, true)) {
                    $u = $guard->check() ? $guard->user() : null;
                } elseif (method_exists($guard, 'hasUser') && $guard->hasUser()) {
                    $u = $guard->user();
                } elseif (method_exists($guard, 'getName') && $session !== null) {
                    $u = $session->has($guard->getName()) ? $guard->user() : null;
                } elseif ($bearer && !method_exists($guard, 'getName')) {
                    $later[] = $name;
                }
            } catch (\Exception $e) {
                $u = null;
            } catch (\Throwable $e) {
                $u = null;
            }
            if ($u) {
                return self::describe($u, $name);
            }
        }
        if ($cold && ($found = self::fromCookies($app, $auth, $names)) !== null) {
            return $found;
        }
        foreach ($later as $name) {
            try {
                $u = $auth->guard($name)->user();
            } catch (\Exception $e) {
                $u = null;
            } catch (\Throwable $e) {
                $u = null;
            }
            if ($u) {
                return self::describe($u, $name);
            }
        }

        return null;
    }

    /**
     * @return bool  true when the request has no session the app started
     */
    public static function coldSession($session)
    {
        return $session === null || (method_exists($session, 'isStarted') && !$session->isStarted());
    }

    /**
     * Errors thrown before the "web" middleware group runs — 404s for missing
     * files, 405s, a failing global middleware — have no started session, so
     * every guard looks anonymous although the browser sent a logged-in
     * session cookie (admin/courier/worker panels on their own guards too).
     * Reads that session read-only (never saved, regenerated or migrated),
     * then the guard's remember-me cookie, and looks the id up through the
     * guard's own provider. No login events fire. Laravel 5.5+ / PHP 7.0+.
     *
     * @return array|null  id, email, name, guard
     */
    public static function fromCookies($app, $auth, array $names)
    {
        try {
            if (!$app->bound('request') || !$app->bound('encrypter') || !$app->bound('session')) {
                return null;
            }
            $req = $app['request'];
            if (!is_object($req) || !isset($req->cookies) || !is_object($req->cookies)) {
                return null;
            }
            $enc = $app['encrypter'];
            $store = null;
            $cookie = (string) $app['config']->get('session.cookie', '');
            $raw = $cookie !== '' ? $req->cookies->get($cookie) : null;
            $id = is_string($raw) ? self::decryptCookie($enc, $raw) : null;
            if ($id !== null && preg_match('/^[a-zA-Z0-9]{40}$/', $id)) {
                try {
                    $store = clone $app['session']->driver();
                    $store->setId($id);
                    $store->start();
                } catch (\Exception $e) {
                    $store = null;
                } catch (\Throwable $e) {
                    $store = null;
                }
            }
            foreach ($names as $name) {
                try {
                    $guard = $auth->guard($name);
                    if (!method_exists($guard, 'getName') || !method_exists($guard, 'getProvider')) {
                        continue;
                    }
                    $provider = $guard->getProvider();
                    $u = null;
                    if ($store !== null) {
                        $uid = $store->get($guard->getName());
                        if ($uid !== null && $uid !== '') {
                            $u = $provider->retrieveById($uid);
                        }
                    }
                    if (!$u && method_exists($guard, 'getRecallerName')) {
                        $rc = $req->cookies->get($guard->getRecallerName());
                        $val = is_string($rc) ? self::decryptCookie($enc, $rc) : null;
                        $parts = $val !== null ? explode('|', $val, 3) : array();
                        if (count($parts) >= 2 && $parts[0] !== '' && $parts[1] !== '') {
                            $u = $provider->retrieveByToken($parts[0], $parts[1]);
                        }
                    }
                } catch (\Exception $e) {
                    $u = null;
                } catch (\Throwable $e) {
                    $u = null;
                }
                if ($u) {
                    return self::describe($u, $name);
                }
            }
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }

        return null;
    }

    /**
     * A Laravel-encrypted cookie's plain value: serialized (≤5.5.41) or not,
     * with or without the "<hmac>|" prefix Laravel 6.18+/7.22+ adds.
     *
     * @return string|null
     */
    private static function decryptCookie($enc, $raw)
    {
        try {
            $v = $enc->decrypt($raw, false);
        } catch (\Exception $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_string($v)) {
            return null;
        }
        if (preg_match('/^s:\d+:"(.*)";$/s', $v, $m)) {
            $v = $m[1];
        }
        if (strlen($v) > 41 && $v[40] === '|' && ctype_xdigit(substr($v, 0, 40))) {
            $v = substr($v, 41);
        }

        return $v;
    }

    /**
     * @return array
     */
    public static function describe($u, $guard)
    {
        $email = isset($u->email) ? $u->email : null;
        $display = isset($u->name) ? $u->name : null;
        if ($display === null) {
            foreach (array('username', 'login', 'full_name') as $f) {
                if (isset($u->$f)) {
                    $display = $u->$f;
                    break;
                }
            }
        }
        if ($display === null && (isset($u->first_name) || isset($u->last_name))) {
            $display = trim((isset($u->first_name) ? $u->first_name : '') . ' ' . (isset($u->last_name) ? $u->last_name : ''));
            $display = $display !== '' ? $display : null;
        }

        return array(
            'id' => method_exists($u, 'getAuthIdentifier') ? $u->getAuthIdentifier() : (isset($u->id) ? $u->id : null),
            'email' => $email,
            'name' => $display,
            'guard' => $guard,
        );
    }
}
