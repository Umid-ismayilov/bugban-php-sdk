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

    /**
     * PDO instantiates statement classes itself; ctor args come from the
     * PDO::ATTR_STATEMENT_CLASS declaration in TracedPdo.
     */
    protected function __construct($connection = 'sql')
    {
        $this->bugbanConnection = is_string($connection) && $connection !== '' ? $connection : 'sql';
    }

    #[\ReturnTypeWillChange]
    public function execute($params = null)
    {
        $start = microtime(true);
        $result = $params === null ? parent::execute() : parent::execute($params);

        try {
            $meta = array('connection' => $this->bugbanConnection);
            if (is_array($params) && !empty($params)) {
                $meta['bindings'] = array_values($params);
            }
            Bugban::recordQuery(
                (string) $this->queryString,
                (microtime(true) - $start) * 1000,
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
