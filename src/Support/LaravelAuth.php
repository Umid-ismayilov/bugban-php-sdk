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

        $later = array();
        foreach ($names as $name) {
            $u = null;
            try {
                $guard = $auth->guard($name);
                if ($name === $default || in_array($name, $forced, true)) {
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
