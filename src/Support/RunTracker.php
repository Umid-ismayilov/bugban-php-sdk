<?php

namespace Bugban\Sdk\Support;

use Bugban\Sdk\Bugban;
use Bugban\Sdk\Config;
use Bugban\Sdk\Transport\Transport;

/**
 * One record per background unit of work: a cron job, an artisan/console
 * command, a queue job, a plain `php script.php`.
 *
 * Answers "which command loads the server, how much, and what else was
 * running at that moment": wall time, CPU time, peak memory, how many queries
 * ran and how long they took in total, exit code, host, PID. Slow queries the
 * client records while this tracker is alive are tagged with the same
 * source/command/run_id so the panel can join them.
 *
 * Only exists in CLI processes. Web requests are covered by request logs.
 *
 * Rules: PHP 7.0 syntax, never throws, never blocks the host for more than one
 * fire-and-forget POST at exit (queue jobs are batched: ≥20 or 15 s).
 */
class RunTracker
{
    const FLUSH_EVERY = 20;
    const FLUSH_SECONDS = 15;
    const COMMAND_MAX = 300;
    const RUNNERS = 'artisan|yii|console|spark|bin/console|occ|craft|magento|drush|wp';

    /** @var Config */
    private $config;
    /** @var Transport */
    private $transport;

    private $runId;
    private $source;
    private $command;
    private $argvLine = '';
    private $startedAt;      // float, microtime
    private $startCpuMs;     // float|null
    private $queryCount = 0;
    private $queryMs = 0.0;
    private $slowCount = 0;
    private $exitCode = null;
    private $meta = array();

    /** Current queue job (array) or null. */
    private $job = null;
    private $jobsSeen = 0;

    private $buffer = array();
    private $lastFlushAt;
    private $finished = false;

    /** One tracker per process, even if two clients get constructed. */
    private static $started = false;

    /**
     * @return RunTracker|null
     */
    public static function start(Config $config, Transport $transport)
    {
        try {
            if (self::$started || PHP_SAPI !== 'cli' || !$config->captureRuns || !$config->isUsable()) {
                return null;
            }
            self::$started = true;
            $tracker = new self($config, $transport);
            register_shutdown_function(array($tracker, 'finish'));

            return $tracker;
        } catch (\Exception $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function __construct(Config $config, Transport $transport)
    {
        $this->config = $config;
        $this->transport = $transport;
        $this->startedAt = microtime(true);
        $this->lastFlushAt = $this->startedAt;
        $this->startCpuMs = self::cpuMs();
        $this->runId = self::newId();

        $argv = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : array();
        $this->argvLine = self::redactArgs($argv);
        $this->command = self::guessCommand($argv);
        $this->source = self::detectSource($this->argvLine, $argv);
    }

    // ---- what the client asks ------------------------------------------

    /** Tags for a slow-query row recorded right now. */
    public function context()
    {
        if ($this->job) {
            return array(
                'source' => 'queue',
                'command' => $this->job['class'],
                'run_id' => $this->job['run_id'],
            );
        }

        return array(
            'source' => $this->source,
            'command' => $this->command,
            'run_id' => $this->runId,
        );
    }

    /**
     * Called for EVERY query the adapter sees, not only slow ones — the whole
     * point is the total: 40 000 fast queries in a cron are a load too.
     */
    public function countQuery($durationMs, $isSlow)
    {
        $ms = is_numeric($durationMs) ? (float) $durationMs : 0.0;
        if ($this->job) {
            $this->job['query_count']++;
            $this->job['query_ms'] += $ms;
            if ($isSlow) {
                $this->job['slow_count']++;
            }
            return;
        }
        $this->queryCount++;
        $this->queryMs += $ms;
        if ($isSlow) {
            $this->slowCount++;
        }
    }

    /** Adapters refine the name (Laravel gives the real signature, e.g. `emails:send`). */
    public function setCommand($name)
    {
        if (is_string($name) && trim($name) !== '') {
            $this->command = self::clip(trim($name), self::COMMAND_MAX);
        }
    }

    public function setSource($source)
    {
        if (in_array($source, array('cron', 'scheduler', 'queue', 'artisan', 'script'), true)) {
            $this->source = $source;
        }
    }

    public function setExitCode($code)
    {
        if (is_numeric($code)) {
            $this->exitCode = (int) $code;
        }
    }

    public function setMeta($key, $value)
    {
        if (is_string($key) && $key !== '' && count($this->meta) < 20 && (is_scalar($value) || $value === null)) {
            $this->meta[$key] = is_string($value) ? self::clip($value, 1000) : $value;
        }
    }

    public function runId()
    {
        return $this->runId;
    }

    public function source()
    {
        return $this->source;
    }

    public function command()
    {
        return $this->command;
    }

    // ---- queue jobs -----------------------------------------------------

    /**
     * A worker process lives for hours; the unit that matters is the JOB.
     * Each job gets its own run record; the worker itself is not reported
     * once it has processed at least one job.
     */
    public function beginJob($class, array $meta = array(), $source = 'queue')
    {
        try {
            if (!is_string($class) || trim($class) === '') {
                return;
            }
            // Which kind of unit this is: a queue job (default) or a scheduled
            // task run inline by the scheduler. Anything else falls back to queue.
            $source = is_string($source) && in_array($source, array('queue', 'scheduler', 'cron', 'artisan', 'script'), true) ? $source : 'queue';
            if ($this->job) {
                $this->endJob(null);
            }
            // PHP 8.2+: measure this job's own peak, not the worker's lifetime peak.
            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }
            $clean = array();
            foreach ($meta as $k => $v) {
                if (is_string($k) && (is_scalar($v) || $v === null) && count($clean) < 10) {
                    $clean[$k] = is_string($v) ? self::clip($v, 300) : $v;
                }
            }
            $this->job = array(
                'run_id' => self::newId(),
                'class' => self::clip(trim($class), self::COMMAND_MAX),
                'started_at' => microtime(true),
                'cpu_ms' => self::cpuMs(),
                'query_count' => 0,
                'query_ms' => 0.0,
                'slow_count' => 0,
                'meta' => $clean,
                'source' => $source,
            );
            if ($source === 'queue') {
                // The surrounding process is a worker; if it ever reports itself
                // (no jobs seen), it is a queue worker, not a plain script.
                $this->source = 'queue';
            }
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }
    }

    /**
     * @param int|null $exitCode 0 = ok, 1 = failed/exception, null = unknown
     * @param string|null $error short failure reason
     */
    public function endJob($exitCode = 0, $error = null)
    {
        try {
            if (!$this->job) {
                return;
            }
            $job = $this->job;
            $this->job = null;
            $this->jobsSeen++;

            $now = microtime(true);
            $meta = $job['meta'];
            $meta['worker'] = self::clip($this->argvLine, 300);
            if (is_string($error) && $error !== '') {
                $meta['error'] = self::clip($error, 500);
            }

            $this->buffer[] = $this->record(
                $job['run_id'],
                $job['class'],
                isset($job['source']) ? $job['source'] : 'queue',
                $job['class'],
                $job['started_at'],
                $now,
                self::cpuDelta($job['cpu_ms']),
                $job['query_count'],
                $job['query_ms'],
                $job['slow_count'],
                is_numeric($exitCode) ? (int) $exitCode : null,
                $meta
            );

            if (count($this->buffer) >= self::FLUSH_EVERY || ($now - $this->lastFlushAt) >= self::FLUSH_SECONDS) {
                $this->flush();
            }
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }
    }

    // ---- shutdown -------------------------------------------------------

    /** Registered as a shutdown function; safe to call twice. */
    public function finish()
    {
        try {
            if ($this->finished) {
                return;
            }
            $this->finished = true;

            if ($this->job) {
                // Process died mid-job (fatal / kill): the job record is the useful one.
                $this->endJob(255, 'process ended before the job finished');
            }

            if ($this->jobsSeen === 0) {
                $exit = $this->exitCode;
                if ($exit === null) {
                    $last = error_get_last();
                    $fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);
                    $exit = ($last && in_array($last['type'], $fatal, true)) ? 255 : 0;
                    if ($exit === 255) {
                        $this->setMeta('fatal', isset($last['message']) ? $last['message'] : 'fatal error');
                    }
                }
                // Dispatcher processes (schedule:run, an idle queue:work) only
                // wait for children / for jobs, and every child reports its own
                // run. A row per minute saying "schedule:run took 59 s" would
                // bury the real work in the Processes view and make every
                // overlap check say "schedule:run was running" — dropped unless
                // it FAILED (a crashing scheduler must stay visible).
                if (!($exit === 0 && self::isDispatcher($this->command, $this->argvLine))) {
                    $meta = $this->meta;
                    $meta['argv'] = self::clip($this->argvLine, 1000);
                    $this->buffer[] = $this->record(
                        $this->runId,
                        $this->command,
                        $this->source,
                        null,
                        $this->startedAt,
                        microtime(true),
                        self::cpuDelta($this->startCpuMs),
                        $this->queryCount,
                        $this->queryMs,
                        $this->slowCount,
                        $exit,
                        $meta
                    );
                }
            }

            $this->flush();

            // Opt-in daily self-update (auto_update / BUGBAN_AUTO_UPDATE); detached, never throws.
            Updater::maybeAutoUpdate($this->config, $this->transport);
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }
    }

    /**
     * schedule:run / schedule:work / queue:work / horizon / messenger:consume:
     * orchestration only — the useful rows are the children's / the jobs'.
     */
    private static function isDispatcher($command, $argvLine)
    {
        $hay = (string) $command . ' ' . (string) $argvLine;
        return (bool) preg_match('~\bschedule:(run|work)\b|\bschedule-run\b|\bcron:run\b|\bqueue:(work|listen)\b|\bhorizon(:work)?\b|\bqueue/listen\b|\bmessenger:consume\b~', $hay);
    }

    private function flush()
    {
        if (!$this->buffer) {
            return;
        }
        $runs = $this->buffer;
        $this->buffer = array();
        $this->lastFlushAt = microtime(true);
        try {
            // Server accepts ≤100 per request; the buffer never exceeds FLUSH_EVERY.
            $this->transport->send($this->config->runsUrl(), $this->config->apiKey, array('runs' => $runs));
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }
    }

    private function record($runId, $command, $source, $jobClass, $startedAt, $finishedAt, $cpuMs, $queryCount, $queryMs, $slowCount, $exitCode, array $meta)
    {
        $row = array(
            'run_id' => $runId,
            'command' => $command,
            'source' => $source,
            'runtime' => 'php',
            'job_class' => $jobClass,
            'started_at' => gmdate('c', (int) floor($startedAt)),
            'finished_at' => gmdate('c', (int) floor($finishedAt)),
            'duration_ms' => (int) round(($finishedAt - $startedAt) * 1000),
            'cpu_ms' => $cpuMs,
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'query_count' => (int) $queryCount,
            'query_total_ms' => (int) round($queryMs),
            'slow_query_count' => (int) $slowCount,
            'exit_code' => $exitCode,
            'hostname' => function_exists('gethostname') ? self::clip((string) gethostname(), 120) : null,
            'pid' => function_exists('getmypid') ? getmypid() : null,
            'sdk_version' => Bugban::VERSION,
            'meta' => $meta,
        );
        if ($this->config->environment) {
            $row['meta']['environment'] = $this->config->environment;
        }
        if ($this->config->framework) {
            $row['meta']['framework'] = $this->config->framework;
        }

        return $row;
    }

    // ---- detection ------------------------------------------------------

    /**
     * cron | scheduler | queue | artisan | script
     *
     * Order matters: a `queue:work` started by cron is still a queue worker;
     * an artisan command started by `schedule:run` is "scheduler" even though
     * `schedule:run` itself was started by cron.
     */
    private static function detectSource($argvLine, array $argv)
    {
        if (preg_match('/\bqueue:(work|listen)\b|\bhorizon(:work)?\b|\bqueue\/listen\b|\bmessenger:consume\b/', $argvLine)) {
            return 'queue';
        }

        $ancestors = self::ancestorCommands(4);
        foreach ($ancestors as $cmd) {
            if (preg_match('/\bschedule:(run|work)\b|\bschedule-run\b|\bcron:run\b/', $cmd)) {
                return 'scheduler';
            }
        }
        foreach ($ancestors as $cmd) {
            if (preg_match('/(^|[\/\s])(crond?|CRON|anacron|systemd-run)(\s|$|:)/', $cmd) || preg_match('/\bcron\b/i', $cmd)) {
                return 'cron';
            }
        }
        // systemd timer / cron variants we cannot name: parent is init and there is no terminal.
        $tty = function_exists('posix_isatty') && defined('STDIN') ? @posix_isatty(STDIN) : null;
        $noTerminal = ($tty === false) && !getenv('TERM') && !getenv('SSH_TTY');
        if ($noTerminal && (!$ancestors || preg_match('/(^|\/)(systemd|init|supervisord|sh -c)(\s|$)/', $ancestors[0]))) {
            return 'cron';
        }

        $script = isset($argv[0]) ? basename((string) $argv[0]) : '';
        if ($script !== '' && preg_match('~^(' . self::RUNNERS . ')(\.php)?$~', $script)) {
            return 'artisan';
        }

        return 'script';
    }

    /**
     * `php artisan emails:send --force` → `emails:send`
     * `php yii cache/flush`             → `cache/flush`
     * `php cron/import.php --full`      → `import.php`
     */
    private static function guessCommand(array $argv)
    {
        $script = isset($argv[0]) ? basename((string) $argv[0]) : 'php';
        $isRunner = preg_match('~^(' . self::RUNNERS . ')(\.php)?$~', $script) === 1;
        $first = null;
        for ($i = 1; $i < count($argv); $i++) {
            $a = (string) $argv[$i];
            if ($a !== '' && $a[0] !== '-') {
                $first = $a;
                break;
            }
        }
        $name = ($isRunner && $first !== null) ? $first : $script;

        return self::clip($name !== '' ? $name : 'php', self::COMMAND_MAX);
    }

    /** Command lines of the parent, grandparent, … (Linux /proc only). */
    private static function ancestorCommands($depth)
    {
        $out = array();
        try {
            if (!is_readable('/proc/self/stat')) {
                return $out;
            }
            $pid = function_exists('getmypid') ? getmypid() : null;
            for ($i = 0; $i < $depth; $i++) {
                $stat = @file_get_contents($pid ? '/proc/' . $pid . '/stat' : '/proc/self/stat');
                if (!$stat) {
                    break;
                }
                // comm may contain spaces/parens: parse after the LAST ')'.
                $tail = substr($stat, strrpos($stat, ')') + 2);
                $parts = explode(' ', $tail);
                $ppid = isset($parts[1]) ? (int) $parts[1] : 0;
                if ($ppid <= 1) {
                    if ($ppid === 1) {
                        $out[] = 'systemd';
                    }
                    break;
                }
                $cmd = @file_get_contents('/proc/' . $ppid . '/cmdline');
                if ($cmd === false || $cmd === '') {
                    $comm = @file_get_contents('/proc/' . $ppid . '/comm');
                    $cmd = $comm !== false ? trim($comm) : '';
                }
                $out[] = trim(str_replace("\0", ' ', $cmd));
                $pid = $ppid;
            }
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }

        return $out;
    }

    /** `--password=x`, `--token x`, `KEY=value`-looking args are masked. */
    private static function redactArgs(array $argv)
    {
        $parts = array();
        $maskNext = false;
        foreach ($argv as $a) {
            $a = (string) $a;
            if ($maskNext) {
                $parts[] = '[redacted]';
                $maskNext = false;
                continue;
            }
            if (preg_match('/^(--?[a-z0-9_-]*(pass(word)?|secret|token|key|auth|credential|dsn)[a-z0-9_-]*)(=|$)/i', $a, $m)) {
                if (isset($m[4]) && $m[4] === '=') {
                    $parts[] = $m[1] . '=[redacted]';
                } else {
                    $parts[] = $a;
                    $maskNext = true;
                }
                continue;
            }
            $parts[] = self::clip($a, 200);
        }

        return implode(' ', $parts);
    }

    private static function cpuMs()
    {
        if (!function_exists('getrusage')) {
            return null;
        }
        $r = @getrusage();
        if (!is_array($r) || !isset($r['ru_utime.tv_sec'])) {
            return null;
        }

        return $r['ru_utime.tv_sec'] * 1000 + $r['ru_utime.tv_usec'] / 1000
            + $r['ru_stime.tv_sec'] * 1000 + $r['ru_stime.tv_usec'] / 1000;
    }

    private static function cpuDelta($startMs)
    {
        $now = self::cpuMs();
        if ($now === null || $startMs === null) {
            return null;
        }

        return max(0, (int) round($now - $startMs));
    }

    private static function newId()
    {
        if (function_exists('random_bytes')) {
            try {
                return bin2hex(random_bytes(8));
            } catch (\Exception $e) {
            }
        }

        return substr(str_replace('.', '', uniqid('', true)) . dechex(mt_rand()), 0, 16);
    }

    private static function clip($s, $max)
    {
        $s = (string) $s;
        if (function_exists('mb_substr')) {
            return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
        }

        return strlen($s) > $max ? substr($s, 0, $max) : $s;
    }
}
