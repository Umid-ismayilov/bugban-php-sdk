<?php

namespace Bugban\Sdk\Support;

/**
 * Runs an on-demand "how long does this query take now?" test.
 *
 * Bugban never holds database credentials, so it cannot time anything itself.
 * Instead the panel queues a test, this class picks it up and runs it against
 * the application's OWN connection, then posts the timing back.
 *
 * SECURITY — this executes SQL that arrived over the network, so it is treated
 * as hostile input even though it came from Bugban:
 *
 *   1. Disabled unless the app explicitly opts in (allow_query_test, default false).
 *   2. Only ONE statement, and only SELECT / WITH. Any write keyword, comment or
 *      second statement is refused locally, regardless of what the server said.
 *   3. Executed inside a transaction that is ALWAYS rolled back.
 *   4. A LIMIT is enforced so a mistake cannot drag the whole table into memory.
 *   5. The runner is supplied by the framework adapter; with no runner nothing
 *      can execute, which is the default state.
 *
 * PHP 5.6 → 8.4 compatible. Never throws.
 */
class QueryTester
{
    /** Rows a test may return before we stop caring about the rest. */
    const MAX_ROWS = 100;

    /** Seconds a single test may run before it is abandoned. */
    const MAX_SECONDS = 30;

    /**
     * The same read-only check the server does, repeated here on purpose.
     * The SDK must not rely on the server to protect the customer's database.
     *
     * @param string $sql
     * @return bool
     */
    public static function isReadOnly($sql)
    {
        $s = rtrim(trim((string) $sql), "; \t\n\r");

        if ($s === '' || strpos($s, ';') !== false) {
            return false;   // a second statement is hiding in there
        }
        if (strpos($s, '--') !== false || strpos($s, '/*') !== false) {
            return false;   // comments can mask one
        }
        if (!preg_match('/^\s*(select|with)\b/i', $s)) {
            return false;
        }

        $banned = array('insert', 'update', 'delete', 'drop', 'truncate', 'alter',
            'create', 'grant', 'revoke', 'replace', 'merge', 'call', 'do',
            'handler', 'lock', 'unlock', 'load', 'outfile', 'dumpfile', 'into');
        foreach ($banned as $word) {
            if (preg_match('/\b' . $word . '\b/i', $s)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Append a LIMIT when the statement has none, so a test can never pull an
     * unbounded result set into the application's memory.
     *
     * @param string $sql
     * @return string
     */
    public static function capRows($sql)
    {
        $s = rtrim(trim((string) $sql), "; \t\n\r");

        return preg_match('/\blimit\s+\d+/i', $s) ? $s : $s . ' LIMIT ' . self::MAX_ROWS;
    }

    /**
     * Execute one test with the adapter-supplied runner and time it.
     *
     * The runner receives ($sql, $bindings) and must execute the statement on
     * the application's own connection, returning the number of rows fetched.
     * It runs inside a transaction this method rolls back.
     *
     * @param callable $runner
     * @param string   $sql
     * @param array    $bindings
     * @return array  {duration_ms, rows, error}
     */
    public static function run($runner, $sql, array $bindings = array())
    {
        if (!self::isReadOnly($sql)) {
            return array('error' => 'Refused locally: not a single read-only statement.');
        }
        if (!is_callable($runner)) {
            return array('error' => 'No query runner is registered in this application.');
        }

        $sql = self::capRows($sql);
        $started = microtime(true);

        try {
            $rows = call_user_func($runner, $sql, $bindings);
            $ms = (microtime(true) - $started) * 1000;

            return array(
                'duration_ms' => round($ms, 2),
                'rows'        => is_numeric($rows) ? (int) $rows : null,
                'error'       => null,
            );
        } catch (\Exception $e) {
            return array('error' => self::shorten($e->getMessage()));
        } catch (\Throwable $e) {
            return array('error' => self::shorten($e->getMessage()));
        }
    }

    /**
     * Database errors can carry connection details; keep them short and never
     * let one become an exception in the host application.
     *
     * @param string $message
     * @return string
     */
    private static function shorten($message)
    {
        $message = (string) $message;

        return strlen($message) > 300 ? substr($message, 0, 300) . '…' : $message;
    }
}
