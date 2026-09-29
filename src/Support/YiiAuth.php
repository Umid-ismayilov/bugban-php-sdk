<?php

namespace Bugban\Sdk\Support;

/**
 * Logged-in user of a Yii2 app, seen from the core SDK alone — so manual
 * installs (bugban.php + libs/bugban-php-sdk, no bugban/yii2 extension) get
 * the user too. Every yii\web\User component counts ("user" first, then a
 * separate admin/backend identity); an error raised before the app touched
 * the user component is resolved read-only from the session / auto-login
 * cookie the browser sent. Yii 2.0.x / PHP 7.0+.
 */
class YiiAuth
{
    /**
     * @return array|null  id, email, name, guard
     */
    public static function user()
    {
        if (!class_exists('Yii', false) || !isset(\Yii::$app) || !is_object(\Yii::$app)) {
            return null;
        }

        return self::forApp(\Yii::$app);
    }

    /**
     * @param \yii\base\Application $app
     * @return array|null
     */
    public static function forApp($app)
    {
        try {
            if (!is_object($app) || !class_exists('yii\\web\\User') || !method_exists($app, 'getComponents')) {
                return null;
            }
            $ids = array();
            foreach ($app->getComponents(true) as $id => $def) {
                $class = is_object($def) ? get_class($def)
                    : (is_array($def) && isset($def['class']) ? $def['class'] : (is_string($def) ? $def : null));
                $class = is_string($class) ? ltrim($class, '\\') : null;
                if (is_string($class) && ($class === 'yii\\web\\User' || is_subclass_of($class, 'yii\\web\\User'))) {
                    $ids[] = $id;
                }
            }
            // "user" first — it is the app's own notion of "logged in".
            usort($ids, function ($a, $b) {
                return ($a === 'user' ? 0 : 1) - ($b === 'user' ? 0 : 1);
            });

            $session = null;
            if ($app->has('session', true)) {
                $session = $app->get('session');
            } elseif ($app instanceof \yii\web\Application && $app->has('session')) {
                // Not built yet (error before anything used it). Building it
                // only applies its config (name, handler) — it starts nothing.
                $session = $app->get('session');
            }
            foreach ($ids as $id) {
                $component = $app->get($id);
                // Already loaded this request: free. Otherwise only when its
                // session key is present — never start sessions or send cookies.
                $identity = $component->getIdentity(false);
                if ($identity === null && is_object($session) && $session->getIsActive()
                    && isset($component->idParam) && $session->has($component->idParam)) {
                    $identity = $component->getIdentity();
                }
                if ($identity === null) {
                    $identity = self::identityFromCookies($app, $component, $session);
                }
                if (!is_object($identity)) {
                    continue;
                }
                $email = isset($identity->email) ? $identity->email : null;
                $name = null;
                foreach (array('username', 'name', 'full_name', 'login') as $f) {
                    if (isset($identity->$f)) {
                        $name = $identity->$f;
                        break;
                    }
                }

                return array(
                    'id' => method_exists($identity, 'getId') ? $identity->getId() : null,
                    'email' => is_scalar($email) ? $email : null,
                    'name' => is_scalar($name) ? $name : null,
                    'guard' => $id,
                );
            }
        } catch (\Exception $e) {
            // ignore
        } catch (\Throwable $e) {
            // ignore
        }

        return null;
    }

    /**
     * Errors raised before anything touched the user component — a 404, a bad
     * route, a failing beforeAction — find no open session, so a logged-in
     * visitor (or admin on a separate backend identity) looked anonymous.
     * When the browser sent the session cookie, resume that session the way
     * the app itself would, then look the id up with findIdentity(); failing
     * that, the validated auto-login cookie (id + authKey). getIdentity() is
     * avoided on purpose: its auth-timeout / cookie-login path can log out,
     * regenerate the session and fire login events. Yii 2.0.x / PHP 7.0+.
     *
     * @return object|null
     */
    private static function identityFromCookies($app, $component, $session)
    {
        try {
            $class = isset($component->identityClass) ? $component->identityClass : null;
            if (!is_string($class) || !class_exists($class) || !method_exists($class, 'findIdentity')) {
                return null;
            }
            $request = $app->has('request', true) ? $app->get('request') : null;
            if (!$request instanceof \yii\web\Request) {
                return null;
            }
            if (is_object($session) && isset($component->idParam)) {
                if (!$session->getIsActive() && !headers_sent()
                    && isset($_COOKIE[$session->getName()]) && $_COOKIE[$session->getName()] !== '') {
                    $session->open();
                }
                if ($session->getIsActive() && $session->has($component->idParam)) {
                    $identity = call_user_func(array($class, 'findIdentity'), $session->get($component->idParam));
                    if (is_object($identity)) {
                        return $identity;
                    }
                }
            }
            if (!empty($component->enableAutoLogin) && isset($component->identityCookie['name'])) {
                $raw = $request->getCookies()->getValue($component->identityCookie['name']);
                $data = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($data) && count($data) >= 2 && isset($data[0], $data[1])) {
                    $identity = call_user_func(array($class, 'findIdentity'), $data[0]);
                    if (is_object($identity) && method_exists($identity, 'validateAuthKey')
                        && $identity->validateAuthKey($data[1])) {
                        return $identity;
                    }
                }
            }
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }

        return null;
    }
}
