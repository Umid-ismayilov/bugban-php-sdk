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
| `redact` | common secrets | Keys scrubbed before sending |
| `before_send` | `null` | `fn(array $payload): ?array` filter/mutate |

## What gets sent
`POST {host}/api/ingest/events` with header `X-Bugban-Key: {api_key}` — exception class, message, file/line, stacktrace, request, auth user, session, breadcrumbs, context. Request logs go to `POST {host}/api/ingest/requests`.

## API key & plans
Your API key is issued from the Bugban panel per project and is tied to your plan/subscription. Higher plans raise ingest rate limits and retention.
