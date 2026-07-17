<?php

namespace Bugban\Sdk\Support;

/**
 * Normalizes raw EXPLAIN output (MySQL/MariaDB, PostgreSQL, SQLite) into the
 * compact `explain` shape the Bugban queries endpoint understands:
 *
 *     array(
 *         'driver'     => 'mysql'|'mariadb'|'pgsql'|'sqlite',
 *         'type'       => access type / plan node (e.g. 'ALL', 'ref', 'Seq Scan', 'SCAN'),
 *         'key'        => index used (MySQL) or null,
 *         'rows'       => estimated rows examined (MySQL) or null,
 *         'raw'        => trimmed raw rows (capped),
 *         'uses_index' => bool|null (true = an index is used, false = full/seq scan),
 *     )
 *
 * The server flags full-table-scans from this. Pure static, stateless and it
 * NEVER throws — on any oddity it returns whatever was parsed so far.
 *
 * Used by both the Laravel adapter and TracedPdo so the driver logic lives in
 * exactly one place.
 */
class ExplainParser
{
    /** Max raw rows kept per explain payload. */
    const MAX_RAW_ROWS = 10;

    /**
     * @param string $driver   Driver name: mysql|mariadb|pgsql|sqlite.
     * @param array  $rawRows  EXPLAIN rows (assoc arrays; strings tolerated).
     * @return array Normalized explain array (see class docblock).
     */
    public static function parse($driver, $rawRows)
    {
        $driver = strtolower((string) $driver);
        $result = array(
            'driver' => $driver,
            'type' => null,
            'key' => null,
            'rows' => null,
            'raw' => array(),
            'uses_index' => null,
        );

        try {
            if (!is_array($rawRows)) {
                $rawRows = array();
            }
            if ($driver === 'pgsql' || $driver === 'postgres' || $driver === 'postgresql') {
                $result['driver'] = 'pgsql';
                return self::parsePgsql($result, $rawRows);
            }
            if ($driver === 'sqlite') {
                return self::parseSqlite($result, $rawRows);
            }
            // mysql / mariadb (and any other row-based EXPLAIN with type/key columns)
            return self::parseMysql($result, $rawRows);
        } catch (\Exception $e) {
            return $result;
        } catch (\Throwable $e) {
            return $result;
        }
    }

    /**
     * MySQL/MariaDB: classic tabular EXPLAIN. Pick the row with the WORST access
     * type (ALL beats index beats range beats ref beats const/system) so a
     * full-table-scan anywhere in a joined plan is surfaced.
     */
    private static function parseMysql(array $result, array $rows)
    {
        // Higher score = worse (more likely a full scan).
        $rank = array(
            'all' => 9,
            'index' => 8,
            'range' => 6,
            'index_merge' => 5,
            'ref_or_null' => 4,
            'fulltext' => 4,
            'ref' => 3,
            'unique_subquery' => 2,
            'index_subquery' => 2,
            'eq_ref' => 1,
            'const' => 0,
            'system' => 0,
        );

        $worst = null;
        $worstScore = -1;
        $raw = array();
        $count = 0;

        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $type = self::pick($r, 'type');
            $typeKey = is_string($type) ? strtolower($type) : '';
            // Unknown/absent type is treated as bad (7) but not worse than a
            // confirmed full scan (9).
            $score = isset($rank[$typeKey]) ? $rank[$typeKey] : 7;
            if ($score > $worstScore) {
                $worstScore = $score;
                $worst = $r;
            }
            if ($count < self::MAX_RAW_ROWS) {
                $raw[] = self::trimMysqlRow($r);
                $count++;
            }
        }

        if ($worst !== null) {
            $type = self::pick($worst, 'type');
            $key = self::pick($worst, 'key');
            $rowsCount = self::pick($worst, 'rows');

            $result['type'] = ($type === '' ? null : $type);
            $result['key'] = ($key === '' || $key === null) ? null : $key;
            $result['rows'] = is_numeric($rowsCount) ? (int) $rowsCount : null;

            $isFullScan = (is_string($type) && strtolower($type) === 'all')
                || $result['key'] === null;
            $result['uses_index'] = !$isFullScan;
        }

        $result['raw'] = $raw;
        return $result;
    }

    /**
     * Keep only the useful EXPLAIN columns (case-insensitive) so the payload stays small.
     */
    private static function trimMysqlRow(array $r)
    {
        $keep = array('select_type', 'table', 'partitions', 'type', 'possible_keys', 'key', 'key_len', 'ref', 'rows', 'filtered', 'Extra');
        $out = array();
        foreach ($keep as $col) {
            $val = self::pick($r, $col);
            if ($val !== null) {
                $out[$col] = is_scalar($val) ? $val : (string) json_encode($val);
            }
        }
        // If nothing matched (unexpected column names), fall back to the raw row (scalars only).
        if (empty($out)) {
            foreach ($r as $k => $v) {
                if (is_scalar($v) || $v === null) {
                    $out[$k] = $v;
                }
            }
        }
        return $out;
    }

    /**
     * PostgreSQL: EXPLAIN returns rows of text under the 'QUERY PLAN' column.
     * Join the lines; a plan is a full scan when it contains "Seq Scan" and no
     * "Index Scan"/"Index Only Scan".
     */
    private static function parsePgsql(array $result, array $rows)
    {
        $lines = array();
        foreach ($rows as $r) {
            if (is_array($r)) {
                $val = self::pick($r, 'QUERY PLAN');
                if ($val === null) {
                    $val = reset($r);
                }
                if ($val !== null) {
                    $lines[] = (string) $val;
                }
            } elseif (is_string($r)) {
                $lines[] = $r;
            }
        }

        $lines = array_slice($lines, 0, 40);
        $text = implode("\n", $lines);
        $hasSeq = stripos($text, 'Seq Scan') !== false;
        $hasIndex = stripos($text, 'Index Scan') !== false
            || stripos($text, 'Index Only Scan') !== false;

        $result['raw'] = $lines;
        $result['type'] = $hasSeq ? 'Seq Scan' : ($hasIndex ? 'Index Scan' : null);
        $result['uses_index'] = ($hasIndex && !$hasSeq);
        return $result;
    }

    /**
     * SQLite: EXPLAIN QUERY PLAN yields a 'detail' column per node.
     *   "SCAN <table>"                      => full table scan (no index)
     *   "SEARCH <table> USING INDEX ..."    => index used
     *   "SEARCH <table> USING INTEGER PRIMARY KEY" => primary key used
     */
    private static function parseSqlite(array $result, array $rows)
    {
        $details = array();
        $hasIndexUse = false;
        $hasFullScan = false;

        foreach ($rows as $r) {
            $detail = null;
            if (is_array($r)) {
                $detail = self::pick($r, 'detail');
                if ($detail === null) {
                    $detail = end($r);
                }
            } elseif (is_string($r)) {
                $detail = $r;
            }
            if ($detail === null || $detail === false) {
                continue;
            }
            $detail = (string) $detail;
            $details[] = $detail;
            $upper = strtoupper($detail);
            if (strpos($upper, 'USING INDEX') !== false
                || strpos($upper, 'USING COVERING INDEX') !== false
                || strpos($upper, 'USING INTEGER PRIMARY KEY') !== false
                || strpos($upper, 'USING PRIMARY KEY') !== false) {
                $hasIndexUse = true;
            } elseif (strpos($upper, 'SCAN') !== false) {
                // A SCAN line with no index => full table scan.
                $hasFullScan = true;
            }
        }

        $result['raw'] = array_slice($details, 0, self::MAX_RAW_ROWS);
        $result['type'] = $hasFullScan ? 'SCAN' : ($hasIndexUse ? 'SEARCH' : null);
        $result['uses_index'] = ($hasIndexUse && !$hasFullScan);
        return $result;
    }

    /**
     * Case-insensitive column lookup ('key' vs 'KEY', 'QUERY PLAN' variants).
     *
     * @param array  $row
     * @param string $key
     * @return mixed|null
     */
    private static function pick($row, $key)
    {
        if (!is_array($row)) {
            return null;
        }
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }
        $lk = strtolower($key);
        foreach ($row as $k => $v) {
            if (strtolower((string) $k) === $lk) {
                return $v;
            }
        }
        return null;
    }
}
