# Bugban PHP SDK

Error & monitoring SDK for **any** PHP project — captures **exceptions, requests, auth user & session** and ships them to your Bugban platform.

- ✅ Framework-agnostic core (pure PHP, CodeIgniter, Symfony, WordPress, Slim…)
- ✅ First-class **Laravel** integration (auto exception + request + auth/session capture)
- ✅ **PHP 7.0 → 8.x** compatible (works on legacy hosts)
- ✅ **Manual, Composer-free install** for old projects
- ✅ Fire-and-forget transport — never breaks or slows the host app

## Install

### A) Composer (recommended)
```bash
composer require bugban/php-sdk
```
From a private/VCS repo (before Packagist), add to the host project's `composer.json`:
```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/Umid-ismayilov/bugban.git" }
  ]
}
```
then `composer require bugban/php-sdk`.

### B) Manual (legacy projects, no Composer)
Copy the `bugban-php-sdk` folder into your project and:
```php
require __DIR__ . '/libs/bugban-php-sdk/autoload.php';
```

## Usage

### Pure PHP / any framework
```php
\Bugban\Sdk\Bugban::init([
    'api_key'     => 'bb_xxxxxxxx',          // from Bugban panel → Projects
    'host'        => 'https://bugban.online',
    'environment' => 'production',
    'release'     => '1.4.2',
]);

// Automatic capture of errors, uncaught exceptions and fatals:
\Bugban\Sdk\Bugban::registerHandlers();

// Manual:
try {
    risky();
} catch (\Throwable $e) {
    \Bugban\Sdk\Bugban::capture($e);
}

// Procedural helpers (legacy code):
bugban_capture($e);
bugban_message('Cache miss', 'warning');
```

### Laravel
```bash
composer require bugban/php-sdk
php artisan vendor:publish --tag=bugban-config   # optional
```
`.env`:
```
BUGBAN_API_KEY=bb_xxxxxxxx
BUGBAN_HOST=https://bugban.online
BUGBAN_CAPTURE_REQUESTS=true
```
That's it — the service provider auto-registers and captures every reported exception together with the authenticated user, session and request. Zero code changes.

### CodeIgniter / old MVC
In `index.php` (front controller), after the autoloader:
```php
require APPPATH . '../libs/bugban-php-sdk/autoload.php';
\Bugban\Sdk\Bugban::init(['api_key' => 'bb_xxx', 'host' => 'https://bugban.online']);
\Bugban\Sdk\Bugban::registerHandlers();
```

## Configuration
| Key | Default | Meaning |
|---|---|---|
| `api_key` | `''` | Public project key (required) |
| `host` | `https://bugban.online` | Bugban platform URL |
| `environment` | `production` | Environment tag |
| `release` | `null` | Version / release string |
| `enabled` | `true` | Master switch |
| `timeout` | `3` | Transport timeout (s) |
| `sample_rate` | `1.0` | 0–1 fraction of events to send |
| `capture_requests` | `false` | Push per-request performance logs |
| `capture_logs` | `false` | Forward `Bugban::recordLog()` records (Log::error+, caught-and-logged) as events |
| `log_level` | `error` | Minimum PSR level forwarded when `capture_logs` is on |
| `capture_queries` | `true` | Slow-query (performance) monitoring master switch |
| `slow_query_ms` | `1000` | Only queries slower than this (ms) are reported |
| `redact` | common secrets | Keys scrubbed before sending |
| `before_send` | `null` | `fn(array $payload): ?array` filter/mutate |
| `code_context_lines` | `5` | Fallback source window (± lines) around each frame |
| `code_full_function` | `true` | Capture the ENTIRE enclosing function/method body per frame (falls back to the ± window when unresolvable) |

## Slow query monitoring
Works with **any** database (MySQL, PostgreSQL, SQLite, ...) — the SDK just reports SQL text + duration. Queries faster than `slow_query_ms` are dropped; slow ones are batched into a single non-blocking POST at shutdown (max 25 per request).

**Manual — any framework, any DB layer** (report the duration in milliseconds):

```php
$start = microtime(true);
$rows = $db->fetchAll($sql, $params);
\Bugban\Sdk\Bugban::recordQuery($sql, (microtime(true) - $start) * 1000, array(
    'connection' => 'mysql',        // optional
    'bindings'   => $params,        // optional
));
```

**Automatic — pure PHP with PDO**: use the drop-in `TracedPdo` (times `query()`, `exec()` and prepared `execute()` automatically):

```php
$pdo = new \Bugban\Sdk\Support\TracedPdo('mysql:host=localhost;dbname=app', $user, $pass);
// use exactly like \PDO
```

The caller file/line (first frame outside `vendor/`), request URL + method, and redacted/capped bindings are attached automatically. Framework adapters (`bugban/laravel`, `bugban/codeigniter`, `bugban/yii2`) wire this up automatically.

## Log capture
Errors that are logged but never thrown — `Log::error(...)`, `try { ... } catch ($e) { log_it($e); }` — only reach your log file by default. Enable `capture_logs` and forward them to Bugban with `recordLog()`:

```php
\Bugban\Sdk\Bugban::init(array(
    'api_key'      => 'bb_xxxxxxxx',
    'host'         => 'https://bugban.online',
    'capture_logs' => true,
    'log_level'    => 'error',   // debug|info|notice|warning|error|critical|alert|emergency
));

// Pure message:
\Bugban\Sdk\Bugban::recordLog('error', 'Payment reconciliation mismatch', array('order_id' => 123));

// Caught-and-logged throwable (attach it as context['exception'] for a full stacktrace):
try {
    charge();
} catch (\Throwable $e) {
    \Bugban\Sdk\Bugban::recordLog('error', $e->getMessage(), array('exception' => $e));
}
```

Records below `log_level` are dropped. Context is redacted (password/token/secret/authorization/...) and the raw `exception` object is reduced to its class+message. `recordLog()` never throws and is a silent no-op without an api_key. The **Laravel** adapter wires this automatically (`BUGBAN_CAPTURE_LOGS=true`); other frameworks call `recordLog()` from their log pipeline (e.g. a Monolog handler) or directly.

## Background runs (cron / queue / console)
In CLI the SDK records one *run* per process: duration, CPU, peak memory, query count, slow-query count, exit code and the fatal error if any. Slow queries recorded inside the process carry the command name, so the panel's **Processes** tab shows which cron or job loads the server, which ones overlap and which fail. The source (`cron`, `scheduler`, `queue`, `artisan`, `script`) is detected from argv, the parent processes and the TTY; override it when needed:

```php
\Bugban\Sdk\Bugban::setCommand('reports:nightly');   // name shown in the panel
\Bugban\Sdk\Bugban::setRunSource('cron');            // cron|scheduler|queue|artisan|script
\Bugban\Sdk\Bugban::setExitCode(1);                  // when you exit() yourself with a status
```

Disable with `'capture_runs' => false` in `init()`. Web requests are never recorded as runs.

## Updating the SDK
The SDK can update itself. Composer or manual install — same command:

```bash
vendor/bin/bugban check              # exit 10 when a newer version is published
vendor/bin/bugban update             # asks, then upgrades every installed bugban/* package
vendor/bin/bugban update --yes       # no prompt (cron / CI)
vendor/bin/bugban update --dry-run   # show what would happen
```

* **Composer install** — runs `composer require bugban/php-sdk:^<latest> bugban/<adapter>:^<latest> --update-with-dependencies` in your project root (core first, so an adapter can never run against an old core). `composer.lock` changes only on success.
* **Manual install** (no composer, `autoload.php`) — downloads the release zip from GitHub, verifies the version inside, swaps `src/` atomically and keeps the old copy as `src.bak-<version>` for rollback. Run it as `php path/to/bugban-php-sdk/bin/bugban update`.
* Key and host come from `BUGBAN_API_KEY` / `BUGBAN_HOST`, your `.env`, or `--key`/`--host`.

**Automatic:** `'auto_update' => true` in `init()` or `BUGBAN_AUTO_UPDATE=true`. Once a day the SDK asks the panel for the latest version and, if newer, updates itself in a detached process. The check runs at the end of a CLI run (cron, queue, command) **or**, for sites without any cron, at the end of a web request *after the response has been sent* (`fastcgi_finish_request`), so visitors never wait for it. Works for composer and manual installs alike. Log: `<tmp>/bugban-update.log`. Restart long-running processes (queue workers) afterwards so they load the new code.

From code: `Bugban::checkForUpdate()` → `['current','latest','update_available','error']`; `Bugban::update($dryRun = false)` → `['ok','mode','from','to','message']`. Neither throws.

## What gets sent
`POST {host}/api/ingest/events` with header `X-Bugban-Key: {api_key}` — exception class, message, file/line, stacktrace, request, auth user, session, breadcrumbs, context. Request logs go to `POST {host}/api/ingest/requests`. Slow queries go to `POST {host}/api/ingest/queries` (SQL text, duration ms, connection, caller file/line, url).

## API key & plans
Your API key is issued from the Bugban panel per project and is tied to your plan/subscription. Higher plans raise ingest rate limits and retention.
