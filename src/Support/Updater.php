<?php

namespace Bugban\Sdk\Support;

use Bugban\Sdk\Bugban;
use Bugban\Sdk\Config;
use Bugban\Sdk\Transport\CurlTransport;
use Bugban\Sdk\Transport\StreamTransport;
use Bugban\Sdk\Transport\Transport;

/**
 * Keeps the SDK current.
 *
 *  - check()  asks the panel for the newest published version (GET /api/ingest/latest)
 *  - update() upgrades the install in place: `composer require bugban/*:^X` for a
 *             composer install, a GitHub release zip swapped atomically for a
 *             manual (autoload.php) install
 *  - maybeAutoUpdate() is the opt-in daily hook (`auto_update` / BUGBAN_AUTO_UPDATE):
 *             at most once per 24h, CLI only, runs `bin/bugban update` detached so
 *             the customer's cron/queue process is never slowed down by it.
 *
 * Everything here is best-effort and never throws: monitoring must not become
 * the outage, and neither must its updater. PHP 7.0 compatible.
 */
class Updater
{
    const RELEASE_ZIP = 'https://github.com/Umid-ismayilov/bugban-php-sdk/archive/refs/tags/v%s.zip';
    const CHECK_INTERVAL = 86400;
    const COMPOSER_TIMEOUT = 600;

    /**
     * @return array current, latest, update_available, error
     */
    public static function check(Config $config, Transport $transport = null)
    {
        $out = array('current' => Bugban::VERSION, 'latest' => null, 'update_available' => false, 'error' => null);
        try {
            if (!$config->isUsable()) {
                $out['error'] = 'no api_key configured (BUGBAN_API_KEY)';

                return $out;
            }
            $transport = $transport ? $transport : self::transport();
            $data = $transport->fetch($config->latestUrl(), $config->apiKey);
            $latest = (is_array($data) && isset($data['latest']['php'])) ? trim((string) $data['latest']['php']) : '';
            if ($latest === '' || !preg_match('/^\d+\.\d+\.\d+/', $latest)) {
                $out['error'] = 'could not read the latest version from ' . $config->host;

                return $out;
            }
            $out['latest'] = $latest;
            $out['update_available'] = version_compare($latest, Bugban::VERSION, '>');
        } catch (\Exception $e) {
            $out['error'] = $e->getMessage();
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }

        return $out;
    }

    /**
     * @param callable|null $log receives progress lines (composer output etc.)
     * @return array ok, mode (composer|manual|none), from, to, message, output
     */
    public static function update(Config $config, Transport $transport = null, $dryRun = false, $log = null)
    {
        $res = array('ok' => false, 'mode' => 'none', 'from' => Bugban::VERSION, 'to' => null, 'message' => '', 'output' => '');
        try {
            $check = self::check($config, $transport);
            if ($check['error'] !== null) {
                $res['message'] = $check['error'];

                return $res;
            }
            $res['to'] = $check['latest'];
            if (!$check['update_available']) {
                $res['ok'] = true;
                $res['message'] = 'already up to date (' . Bugban::VERSION . ')';

                return $res;
            }
            $install = self::detectInstall();
            $res['mode'] = $install['mode'];
            if ($install['mode'] === 'composer') {
                return self::composerUpdate($install, $check['latest'], $dryRun, $res, $log);
            }
            if ($install['mode'] === 'manual') {
                return self::manualUpdate($install, $check['latest'], $dryRun, $res, $log);
            }
            $res['message'] = 'cannot tell how the SDK was installed; update it by hand (composer require bugban/php-sdk:^' . $check['latest'] . ')';
        } catch (\Exception $e) {
            $res['message'] = $e->getMessage();
        } catch (\Throwable $e) {
            $res['message'] = $e->getMessage();
        }

        return $res;
    }

    /**
     * Opt-in daily hook. Called from the CLI run tracker on shutdown, and —
     * via registerWebHook() — after a web response has been flushed, so a
     * manual install on shared hosting with no cron still updates itself.
     * Never more than once per 24h per install; the actual work runs in a
     * detached process so the calling request/command finishes at normal speed.
     */
    public static function maybeAutoUpdate(Config $config, Transport $transport = null, $allowWeb = false)
    {
        try {
            if (!$config->autoUpdate || (PHP_SAPI !== 'cli' && !$allowWeb) || !$config->isUsable()) {
                return false;
            }
            if (!self::checkDue($config)) {
                return false;
            }
            @file_put_contents(self::markerPath($config), (string) time());

            $check = self::check($config, $transport);
            if ($check['error'] !== null || !$check['update_available']) {
                return false;
            }

            $bin = dirname(dirname(__DIR__)) . '/bin/bugban';
            $logFile = self::logPath();
            if (DIRECTORY_SEPARATOR === '/' && is_file($bin) && function_exists('proc_open')) {
                $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' update --yes';
                $cmd = '(echo ' . escapeshellarg('[' . date('c') . '] auto-update ' . Bugban::VERSION . ' -> ' . $check['latest']) . '; ' . $cmd . ') >> ' . escapeshellarg($logFile) . ' 2>&1 &';
                $env = self::childEnv($config);
                $p = @proc_open($cmd, array(), $pipes, null, $env);
                if (is_resource($p)) {
                    @proc_close($p);

                    return true;
                }
            }
            // No detached shell (Windows / proc_open disabled): do it inline.
            $res = self::update($config, $transport, false, null);
            @file_put_contents($logFile, date('c') . ' ' . ($res['ok'] ? 'ok' : 'failed') . ' ' . $res['message'] . "\n", FILE_APPEND);

            return $res['ok'];
        } catch (\Exception $e) {
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Web-request side of the daily check. Registered once from Bugban::init()
     * when auto_update is on and we are not in CLI. Runs at shutdown, AFTER
     * the response has gone out (fastcgi_finish_request when available), so
     * the visitor never waits for the network. Cheap when not due: one stat().
     */
    public static function registerWebHook(Config $config, Transport $transport = null)
    {
        if (!$config->autoUpdate || PHP_SAPI === 'cli' || !$config->isUsable()) {
            return;
        }
        register_shutdown_function(function () use ($config, $transport) {
            try {
                if (!self::checkDue($config)) {
                    return;
                }
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                self::maybeAutoUpdate($config, $transport, true);
            } catch (\Exception $e) {
                // never let the updater surface in the app
            } catch (\Throwable $e) {
                // ditto
            }
        });
    }

    /** True when the 24h marker is missing or older than CHECK_INTERVAL. */
    private static function checkDue(Config $config)
    {
        $marker = self::markerPath($config);
        if ($marker === null) {
            return false;
        }
        $stamp = @filemtime($marker);

        return $stamp === false || (time() - (int) $stamp) >= self::CHECK_INTERVAL;
    }

    /** Where maybeAutoUpdate() writes what it did. */
    public static function logPath()
    {
        $dir = @sys_get_temp_dir();

        return (is_string($dir) && $dir !== '' ? $dir : '/tmp') . '/bugban-update.log';
    }

    // ---- install detection ---------------------------------------------------

    /**
     * @return array mode (composer|manual|none), root, sdk_root, packages[]
     */
    public static function detectInstall()
    {
        $sdkRoot = dirname(dirname(__DIR__)); // .../src/Support -> package root
        $info = array('mode' => 'none', 'root' => null, 'sdk_root' => $sdkRoot, 'packages' => array());

        // vendor/bugban/php-sdk  -> vendor -> project root
        $vendor = dirname(dirname($sdkRoot));
        if (basename(dirname($sdkRoot)) === 'bugban' && is_file($vendor . '/composer/installed.json')) {
            $info['mode'] = 'composer';
            $info['root'] = dirname($vendor);
            $info['vendor'] = $vendor;
            $info['packages'] = self::installedBugbanPackages($vendor . '/composer/installed.json');
            if (!$info['packages']) {
                $info['packages'] = array('bugban/php-sdk');
            }

            return $info;
        }

        // Composer "path" repositories (and some deploy tools) SYMLINK
        // vendor/bugban/php-sdk to a source folder, so __DIR__ resolves outside
        // vendor/ and the check above misses. Composer 2 knows the truth
        // directly; its class file sits at vendor/composer/InstalledVersions.php.
        if (class_exists('\Composer\InstalledVersions') && method_exists('\Composer\InstalledVersions', 'isInstalled')) {
            try {
                if (\Composer\InstalledVersions::isInstalled('bugban/php-sdk')) {
                    $ref = new \ReflectionClass('\Composer\InstalledVersions');
                    $composerDir = dirname((string) $ref->getFileName());
                    if (is_file($composerDir . '/installed.json')) {
                        $info['mode'] = 'composer';
                        $info['vendor'] = dirname($composerDir);
                        $info['root'] = dirname($info['vendor']);
                        $info['packages'] = self::installedBugbanPackages($composerDir . '/installed.json');
                        if (!$info['packages']) {
                            $info['packages'] = array('bugban/php-sdk');
                        }

                        return $info;
                    }
                }
            } catch (\Exception $e) {
            } catch (\Throwable $e) {
            }
        }

        if (is_file($sdkRoot . '/autoload.php') && is_file($sdkRoot . '/src/Bugban.php')) {
            $info['mode'] = 'manual';
            $info['root'] = $sdkRoot;
            $info['packages'] = array('bugban/php-sdk');
        }

        return $info;
    }

    private static function installedBugbanPackages($installedJson)
    {
        $out = array();
        $data = json_decode((string) @file_get_contents($installedJson), true);
        if (!is_array($data)) {
            return $out;
        }
        $list = isset($data['packages']) && is_array($data['packages']) ? $data['packages'] : $data; // composer 2 vs 1
        foreach ($list as $pkg) {
            if (is_array($pkg) && isset($pkg['name']) && strpos((string) $pkg['name'], 'bugban/') === 0) {
                $out[] = (string) $pkg['name'];
            }
        }
        // Core first: a stale lock leaving an old core behind is what took a
        // customer's site down in v1.5.2.
        usort($out, function ($a, $b) {
            if ($a === 'bugban/php-sdk') {
                return -1;
            }
            if ($b === 'bugban/php-sdk') {
                return 1;
            }

            return strcmp($a, $b);
        });

        return array_values(array_unique($out));
    }

    // ---- composer mode --------------------------------------------------------

    private static function composerUpdate(array $install, $latest, $dryRun, array $res, $log)
    {
        $composer = self::findComposer($install['root']);
        if ($composer === null) {
            $res['message'] = 'composer executable not found (set COMPOSER_BIN=/path/to/composer)';

            return $res;
        }
        $parts = $composer;
        $parts[] = 'require';
        foreach ($install['packages'] as $name) {
            $parts[] = $name . ':^' . $latest;
        }
        $parts[] = '--update-with-dependencies';
        $parts[] = '--no-interaction';
        $parts[] = '--no-progress';
        if ($dryRun) {
            $parts[] = '--dry-run';
        }
        $cmd = implode(' ', array_map('escapeshellarg', $parts));
        self::say($log, '$ ' . $cmd);

        $env = self::childEnv(null);
        $env['COMPOSER_ALLOW_SUPERUSER'] = '1';
        $env['COMPOSER_NO_INTERACTION'] = '1';
        $env['COMPOSER_MEMORY_LIMIT'] = '-1';

        $exit = self::run($cmd, $install['root'], $env, self::COMPOSER_TIMEOUT, $output, $log);
        $res['output'] = $output;
        if ($exit === 0) {
            $res['ok'] = true;
            $res['message'] = ($dryRun ? '[dry-run] would update ' : 'updated ') . implode(', ', $install['packages']) . ' to ^' . $latest;
        } else {
            $res['message'] = 'composer exited with code ' . var_export($exit, true) . '; nothing was changed by us — see the output above';
        }

        return $res;
    }

    /** @return array|null argv prefix, e.g. ['composer'] or ['/usr/bin/php','/srv/app/composer.phar'] */
    private static function findComposer($root)
    {
        $envBin = getenv('COMPOSER_BIN');
        if (is_string($envBin) && $envBin !== '' && is_file($envBin)) {
            return substr($envBin, -5) === '.phar' ? array(PHP_BINARY, $envBin) : array($envBin);
        }
        if (is_file($root . '/composer.phar')) {
            return array(PHP_BINARY, $root . '/composer.phar');
        }
        $path = (string) getenv('PATH');
        $dirs = array_filter(explode(PATH_SEPARATOR, $path));
        $dirs[] = '/usr/local/bin';
        $dirs[] = '/usr/bin';
        $home = (string) getenv('HOME');
        if ($home !== '') {
            $dirs[] = $home . '/.local/bin';
            $dirs[] = $home . '/.composer/vendor/bin';
            $dirs[] = $home . '/.config/composer/vendor/bin';
        }
        foreach (array_unique($dirs) as $dir) {
            foreach (array('composer', 'composer.phar') as $name) {
                $bin = rtrim($dir, '/') . '/' . $name;
                if (is_file($bin) && (is_executable($bin) || $name === 'composer.phar')) {
                    return $name === 'composer.phar' ? array(PHP_BINARY, $bin) : array($bin);
                }
            }
        }

        return null;
    }

    // ---- manual (autoload.php) mode ---------------------------------------------

    private static function manualUpdate(array $install, $latest, $dryRun, array $res, $log)
    {
        $root = $install['root'];
        if (!class_exists('ZipArchive')) {
            $res['message'] = 'ext-zip is missing; download ' . sprintf(self::RELEASE_ZIP, $latest) . ' and replace ' . $root . '/src by hand';

            return $res;
        }
        if (!is_writable($root) || !is_writable($root . '/src')) {
            $res['message'] = $root . ' is not writable by ' . (function_exists('get_current_user') ? get_current_user() : 'this user');

            return $res;
        }
        $url = sprintf(self::RELEASE_ZIP, $latest);
        if ($dryRun) {
            $res['ok'] = true;
            $res['message'] = '[dry-run] would download ' . $url . ' and replace ' . $root . '/src';

            return $res;
        }
        self::say($log, 'downloading ' . $url);
        $zip = self::download($url);
        if ($zip === null) {
            $res['message'] = 'download failed: ' . $url;

            return $res;
        }
        $tmp = $zip . '.d';
        @mkdir($tmp, 0755, true);
        $za = new \ZipArchive();
        $opened = $za->open($zip);
        if ($opened !== true || !$za->extractTo($tmp)) {
            @unlink($zip);
            self::rmdir($tmp);
            $res['message'] = 'could not extract the release archive';

            return $res;
        }
        $za->close();
        @unlink($zip);

        $src = self::findExtracted($tmp);
        if ($src === null || !is_file($src . '/src/Bugban.php')) {
            self::rmdir($tmp);
            $res['message'] = 'release archive has an unexpected layout';

            return $res;
        }
        $shipped = (string) @file_get_contents($src . '/src/Bugban.php');
        if (strpos($shipped, "VERSION = '" . $latest . "'") === false) {
            self::rmdir($tmp);
            $res['message'] = 'archive does not contain version ' . $latest;

            return $res;
        }

        // Atomic swap: rename old src aside, move new src in, roll back on failure.
        $backup = $root . '/src.bak-' . Bugban::VERSION;
        self::rmdir($backup);
        if (!@rename($root . '/src', $backup)) {
            self::rmdir($tmp);
            $res['message'] = 'could not move the current src/ aside';

            return $res;
        }
        if (!@rename($src . '/src', $root . '/src')) {
            @rename($backup, $root . '/src');
            self::rmdir($tmp);
            $res['message'] = 'could not move the new src/ into place; old version restored';

            return $res;
        }
        foreach (array('autoload.php', 'README.md') as $f) {
            if (is_file($src . '/' . $f)) {
                @copy($src . '/' . $f, $root . '/' . $f);
            }
        }
        if (is_dir($src . '/bin')) {
            @mkdir($root . '/bin', 0755, true);
            if (is_file($src . '/bin/bugban')) {
                @copy($src . '/bin/bugban', $root . '/bin/bugban');
                @chmod($root . '/bin/bugban', 0755);
            }
        }
        self::rmdir($tmp);
        self::say($log, 'old files kept in ' . $backup);
        $res['ok'] = true;
        $res['message'] = 'updated ' . Bugban::VERSION . ' -> ' . $latest . ' in ' . $root . ' (backup: ' . basename($backup) . ')';
        // Adapters installed next to the core (libs/bugban-laravel, ...) ship
        // the same version — keep them in step. Best effort: a failed adapter
        // swap leaves the old adapter working and never undoes the core update.
        $done = self::updateSiblingAdapters(dirname($root), $latest, $log);
        if ($done) {
            $res['message'] .= '; adapters: ' . implode(', ', $done);
        }

        return $res;
    }

    /**
     * @return string[] adapter dirs that were updated
     */
    private static function updateSiblingAdapters($libs, $latest, $log)
    {
        $out = array();
        $repos = array(
            'bugban-laravel' => 'bugban-laravel',
            'bugban-symfony' => 'bugban-symfony',
            'bugban-codeigniter' => 'bugban-codeigniter',
            'bugban-yii2' => 'bugban-yii2',
        );
        foreach ($repos as $dir => $repo) {
            $root = $libs . '/' . $dir;
            if (!is_dir($root . '/src') || !is_writable($root)) {
                continue;
            }
            try {
                $url = 'https://github.com/Umid-ismayilov/' . $repo . '/archive/refs/tags/v' . $latest . '.zip';
                self::say($log, 'downloading ' . $url);
                $zip = self::download($url);
                if ($zip === null) {
                    continue;
                }
                $tmp = $zip . '.d';
                @mkdir($tmp, 0755, true);
                $za = new \ZipArchive();
                if ($za->open($zip) !== true || !$za->extractTo($tmp)) {
                    @unlink($zip);
                    self::rmdir($tmp);
                    continue;
                }
                $za->close();
                @unlink($zip);
                $src = self::findExtracted($tmp);
                if ($src === null || !is_dir($src . '/src')) {
                    self::rmdir($tmp);
                    continue;
                }
                $backup = $root . '/src.bak-' . Bugban::VERSION;
                self::rmdir($backup);
                if (@rename($root . '/src', $backup)) {
                    if (@rename($src . '/src', $root . '/src')) {
                        foreach (array('config', 'composer.json', 'README.md') as $f) {
                            if (is_dir($src . '/' . $f)) {
                                self::rmdir($root . '/' . $f);
                                @rename($src . '/' . $f, $root . '/' . $f);
                            } elseif (is_file($src . '/' . $f)) {
                                @copy($src . '/' . $f, $root . '/' . $f);
                            }
                        }
                        $out[] = $dir;
                    } else {
                        @rename($backup, $root . '/src');
                    }
                }
                self::rmdir($tmp);
            } catch (\Exception $e) {
            } catch (\Throwable $e) {
            }
        }

        return $out;
    }

    private static function download($url)
    {
        $dir = @sys_get_temp_dir();
        $file = (is_string($dir) && $dir !== '' ? $dir : '/tmp') . '/bugban-sdk-' . substr(md5($url . microtime(true)), 0, 10) . '.zip';
        $body = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT => 'bugban-php-sdk/' . Bugban::VERSION,
            ));
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            Compat::closeCurl($ch);
            if ($code !== 200) {
                $body = null;
            }
        } elseif (ini_get('allow_url_fopen')) {
            $ctx = stream_context_create(array('http' => array('timeout' => 120, 'follow_location' => 1, 'user_agent' => 'bugban-php-sdk/' . Bugban::VERSION)));
            $body = @file_get_contents($url, false, $ctx);
        }
        if (!is_string($body) || strlen($body) < 1000) {
            return null;
        }

        return @file_put_contents($file, $body) ? $file : null;
    }

    private static function findExtracted($tmp)
    {
        if (is_file($tmp . '/src/Bugban.php')) {
            return $tmp;
        }
        foreach ((array) @scandir($tmp) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($tmp . '/' . $entry) && is_file($tmp . '/' . $entry . '/src/Bugban.php')) {
                return $tmp . '/' . $entry;
            }
        }

        return null;
    }

    private static function rmdir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) @scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $p = $dir . '/' . $entry;
            if (is_dir($p) && !is_link($p)) {
                self::rmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    // ---- process helpers ----------------------------------------------------------

    private static function run($cmd, $cwd, array $env, $timeout, &$output, $log)
    {
        $output = '';
        if (!function_exists('proc_open')) {
            $output = 'proc_open() is disabled';

            return null;
        }
        $spec = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
        $p = @proc_open($cmd, $spec, $pipes, $cwd, $env);
        if (!is_resource($p)) {
            $output = 'could not start the process';

            return null;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = time() + $timeout;
        $open = true;
        while ($open) {
            $chunk = (string) @fread($pipes[1], 8192) . (string) @fread($pipes[2], 8192);
            if ($chunk !== '') {
                $output .= $chunk;
                self::say($log, rtrim($chunk, "\n"));
            }
            $status = proc_get_status($p);
            if (!$status['running']) {
                $open = false;
                break;
            }
            if (time() > $deadline) {
                @proc_terminate($p);
                $output .= "\n(timed out after {$timeout}s)";
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($p);

                return null;
            }
            usleep(100000);
        }
        $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($p);
        if (isset($status['exitcode']) && $status['exitcode'] !== -1) {
            $exit = $status['exitcode'];
        }

        return (int) $exit;
    }

    /** Environment for child processes: current env + Bugban key/host (never on argv). */
    private static function childEnv($config)
    {
        $env = array();
        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && is_string($v) && preg_match('/^[A-Z_][A-Z0-9_]*$/', $k)) {
                $env[$k] = $v;
            }
        }
        foreach (array('PATH', 'HOME', 'LANG', 'TMPDIR', 'COMPOSER_HOME', 'COMPOSER_BIN', 'APPDATA', 'SystemRoot') as $k) {
            $v = getenv($k);
            if (is_string($v) && $v !== '' && !isset($env[$k])) {
                $env[$k] = $v;
            }
        }
        if (!isset($env['HOME']) && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw) && !empty($pw['dir'])) {
                $env['HOME'] = $pw['dir'];
            }
        }
        if ($config instanceof Config) {
            $env['BUGBAN_API_KEY'] = $config->apiKey;
            $env['BUGBAN_HOST'] = $config->host;
        }

        return $env;
    }

    private static function say($log, $line)
    {
        if (is_callable($log) && $line !== '') {
            try {
                call_user_func($log, $line);
            } catch (\Exception $e) {
            } catch (\Throwable $e) {
            }
        }
    }

    private static function markerPath(Config $config)
    {
        $dir = @sys_get_temp_dir();
        if (!is_string($dir) || $dir === '' || !@is_dir($dir) || !@is_writable($dir)) {
            return null;
        }

        return $dir . '/.bugban-update-check-' . substr(md5($config->apiKey . '|' . dirname(dirname(__DIR__))), 0, 16);
    }

    private static function transport()
    {
        return function_exists('curl_init') ? new CurlTransport(8) : new StreamTransport(8);
    }
}
