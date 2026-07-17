<?php

namespace Bugban\Sdk\Support;

use Bugban\Sdk\Bugban;

/**
 * Drop-in PDO replacement for pure-PHP projects — times every query and
 * reports slow ones to Bugban (see Config: capture_queries / slow_query_ms).
 *
 * Usage (after Bugban::init):
 *
 *     $pdo = new \Bugban\Sdk\Support\TracedPdo('mysql:host=...;dbname=...', $user, $pass);
 *     // use exactly like \PDO — query(), exec(), prepare()->execute() are all timed.
 *
 * Works with any PDO driver (mysql, pgsql, sqlite, ...). The driver name is
 * reported as the query's "connection". Timing/reporting is fully guarded —
 * database behaviour is NEVER altered and the SDK NEVER throws.
 *
 * Note on the #[\ReturnTypeWillChange] lines: on PHP <= 7.4 `#` starts a
 * comment so they are ignored; on PHP >= 8.1 they suppress the tentative
 * return type deprecation. Do not merge them onto the method line.
 */
class TracedPdo extends \PDO
{
    /** @var string Driver name reported as the query "connection". */
    private $bugbanDriver = 'sql';

    /**
     * @var bool Reentrancy guard. True while we are running an EXPLAIN through
     * this same handle so the EXPLAIN query itself is neither timed/recorded
     * nor re-explained (it flows through query()/TracedPdoStatement::execute()).
     */
    private static $bugbanExplaining = false;

    public function __construct($dsn, $username = null, $password = null, $options = null)
    {
        parent::__construct($dsn, $username, $password, $options === null ? array() : $options);

        try {
            $driver = $this->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if (is_string($driver) && $driver !== '') {
                $this->bugbanDriver = $driver;
            }
        } catch (\Exception $e) {
            // keep default
        } catch (\Throwable $e) {
            // keep default
        }

        try {
            // prepare() then returns TracedPdoStatement, whose execute() is timed.
            // The PDO handle ($this) is passed so the statement can run EXPLAIN
            // on the same connection. (Not supported for persistent connections
            // — guarded; statements simply stay untimed in that case.)
            $this->setAttribute(
                \PDO::ATTR_STATEMENT_CLASS,
                array('Bugban\\Sdk\\Support\\TracedPdoStatement', array($this->bugbanDriver, $this))
            );
        } catch (\Exception $e) {
            // non-fatal
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    /**
     * True while an EXPLAIN is in flight — the statement class checks this to
     * avoid recording/explaining the EXPLAIN query itself.
     *
     * @return bool
     */
    public static function bugbanIsExplaining()
    {
        return self::$bugbanExplaining;
    }

    /**
     * Decide whether to EXPLAIN a query and, if so, run it. Returns the
     * normalized explain array, or null when explain is disabled/not needed or
     * anything goes wrong. NEVER throws.
     *
     * @param string    $sql
     * @param float|int $durationMs
     * @param array     $bindings
     * @return array|null
     */
    public function bugbanMaybeExplain($sql, $durationMs, $bindings = array())
    {
        try {
            $client = Bugban::client();
            if ($client === null) {
                return null;
            }
            $cfg = $client->config();
            if (!$cfg->captureQueries || !$cfg->explainQueries) {
                return null;
            }
            if ((float) $durationMs < $cfg->slowQueryMs) {
                return null;
            }
            if (!self::bugbanIsSelect($sql)) {
                return null;
            }
            return $this->bugbanRunExplain((string) $sql, is_array($bindings) ? $bindings : array());
        } catch (\Exception $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Run EXPLAIN on the same handle and normalize the result. Guarded by the
     * reentrancy flag so the EXPLAIN query is not itself captured. NEVER throws.
     *
     * @param string $sql
     * @param array  $bindings
     * @return array|null
     */
    private function bugbanRunExplain($sql, array $bindings)
    {
        if (self::$bugbanExplaining) {
            return null;
        }
        self::$bugbanExplaining = true;
        $explain = null;
        try {
            $driver = $this->bugbanDriver;
            $prefix = ($driver === 'sqlite') ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ';
            $values = array_values($bindings);

            if (empty($values)) {
                $stmt = parent::query($prefix . $sql);
                $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : array();
            } else {
                // parent::prepare() returns a TracedPdoStatement, but its
                // execute() bails on the reentrancy flag, so no recursion.
                $stmt = parent::prepare($prefix . $sql);
                if ($stmt === false) {
                    self::$bugbanExplaining = false;
                    return null;
                }
                $stmt->execute($values);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            }
            $explain = ExplainParser::parse($driver, is_array($rows) ? $rows : array());
        } catch (\Exception $e) {
            $explain = null;
        } catch (\Throwable $e) {
            $explain = null;
        }
        self::$bugbanExplaining = false;
        return $explain;
    }

    /**
     * @param string $sql
     * @return bool True if the statement is a plain SELECT.
     */
    private static function bugbanIsSelect($sql)
    {
        return stripos(ltrim((string) $sql), 'select') === 0;
    }

    #[\ReturnTypeWillChange]
    public function query($statement, ...$args)
    {
        $start = microtime(true);
        $result = parent::query($statement, ...$args);
        $this->bugbanRecord($statement, $start);
        return $result;
    }

    #[\ReturnTypeWillChange]
    public function exec($statement)
    {
        $start = microtime(true);
        $result = parent::exec($statement);
        $this->bugbanRecord($statement, $start);
        return $result;
    }

    /**
     * @param string $sql
     * @param float  $start microtime(true) before the query ran
     * @return void
     */
    private function bugbanRecord($sql, $start)
    {
        try {
            $durationMs = (microtime(true) - $start) * 1000;
            $meta = array('connection' => $this->bugbanDriver);
            // query()/exec() carry no bindings; explain runs only for slow SELECTs.
            $explain = $this->bugbanMaybeExplain((string) $sql, $durationMs, array());
            if (is_array($explain)) {
                $meta['explain'] = $explain;
            }
            Bugban::recordQuery((string) $sql, $durationMs, $meta);
        } catch (\Exception $e) {
            // telemetry must be non-fatal
        } catch (\Throwable $e) {
            // non-fatal
        }
    }
}
