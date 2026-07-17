<?php

namespace Bugban\Sdk\Support;

use Bugban\Sdk\Bugban;

/**
 * PDOStatement subclass used by TracedPdo (via PDO::ATTR_STATEMENT_CLASS):
 * times execute() and reports slow queries to Bugban. Everything else is
 * inherited untouched. Reporting is fully guarded and NEVER throws.
 */
class TracedPdoStatement extends \PDOStatement
{
    /** @var string Driver name reported as the query "connection". */
    private $bugbanConnection;

    /** @var TracedPdo|null Owning PDO handle, used to run EXPLAIN on slow SELECTs. */
    private $bugbanPdo;

    /**
     * PDO instantiates statement classes itself; ctor args come from the
     * PDO::ATTR_STATEMENT_CLASS declaration in TracedPdo (driver name + the
     * owning PDO handle).
     */
    protected function __construct($connection = 'sql', $pdo = null)
    {
        $this->bugbanConnection = is_string($connection) && $connection !== '' ? $connection : 'sql';
        $this->bugbanPdo = ($pdo instanceof TracedPdo) ? $pdo : null;
    }

    #[\ReturnTypeWillChange]
    public function execute($params = null)
    {
        // Skip entirely while an EXPLAIN is running on the handle: this same
        // execute() fires for the EXPLAIN statement, and it must not be timed,
        // recorded or re-explained.
        if (TracedPdo::bugbanIsExplaining()) {
            return $params === null ? parent::execute() : parent::execute($params);
        }

        $start = microtime(true);
        $result = $params === null ? parent::execute() : parent::execute($params);

        try {
            $durationMs = (microtime(true) - $start) * 1000;
            $bindings = (is_array($params) && !empty($params)) ? array_values($params) : array();
            $meta = array('connection' => $this->bugbanConnection);
            if (!empty($bindings)) {
                $meta['bindings'] = $bindings;
            }
            if ($this->bugbanPdo !== null) {
                $explain = $this->bugbanPdo->bugbanMaybeExplain((string) $this->queryString, $durationMs, $bindings);
                if (is_array($explain)) {
                    $meta['explain'] = $explain;
                }
            }
            Bugban::recordQuery(
                (string) $this->queryString,
                $durationMs,
                $meta
            );
        } catch (\Exception $e) {
            // telemetry must be non-fatal
        } catch (\Throwable $e) {
            // non-fatal
        }

        return $result;
    }
}
