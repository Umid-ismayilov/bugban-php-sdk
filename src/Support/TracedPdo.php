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
            // (Not supported for persistent connections — guarded; statements
            // simply stay untimed in that case.)
            $this->setAttribute(
                \PDO::ATTR_STATEMENT_CLASS,
                array('Bugban\\Sdk\\Support\\TracedPdoStatement', array($this->bugbanDriver))
            );
        } catch (\Exception $e) {
            // non-fatal
        } catch (\Throwable $e) {
            // non-fatal
        }
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
            Bugban::recordQuery(
                (string) $sql,
                (microtime(true) - $start) * 1000,
                array('connection' => $this->bugbanDriver)
            );
        } catch (\Exception $e) {
            // telemetry must be non-fatal
        } catch (\Throwable $e) {
            // non-fatal
        }
    }
}
