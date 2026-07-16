<?php

namespace Bugban\Sdk\Support;

class StacktraceBuilder
{
    /** Default number of source lines to include before AND after each frame line. */
    const DEFAULT_CONTEXT_LINES = 5;

    /** Never read source files larger than this many bytes (2 MB). */
    const MAX_FILE_SIZE = 2097152;

    /** Truncate individual source lines longer than this. */
    const MAX_LINE_LENGTH = 400;

    /**
     * Convert a Throwable into a normalized frame list (top frame = crash site).
     *
     * Each frame keeps the existing keys (file, line, function, class, type) and,
     * when the file is a readable local file, gains a `code` entry: an array of
     * { line: int, content: string, is_error: bool } ordered ascending by line.
     *
     * @param int $contextLines Lines of source to include before/after each frame line.
     * @return array<int, array<string, mixed>>
     */
    public static function fromThrowable(\Throwable $e, $contextLines = self::DEFAULT_CONTEXT_LINES): array
    {
        $contextLines = self::normalizeContextLines($contextLines);

        $frames = [[
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'function' => null,
            'class' => null,
            'type' => null,
        ]];

        foreach ($e->getTrace() as $t) {
            $frames[] = [
                'file' => $t['file'] ?? '[internal]',
                'line' => $t['line'] ?? null,
                'function' => $t['function'] ?? null,
                'class' => $t['class'] ?? null,
                'type' => $t['type'] ?? null,
            ];
        }

        foreach ($frames as $i => $frame) {
            $code = self::sourceContext($frame['file'], $frame['line'], $contextLines);
            if ($code !== null) {
                $frames[$i]['code'] = $code;
            }
        }

        return $frames;
    }

    /**
     * Clamp the requested context window into a sane range.
     *
     * @param mixed $contextLines
     * @return int
     */
    private static function normalizeContextLines($contextLines)
    {
        if (!is_numeric($contextLines)) {
            return self::DEFAULT_CONTEXT_LINES;
        }
        $n = (int) $contextLines;
        if ($n < 0) {
            $n = 0;
        }
        if ($n > 50) {
            $n = 50;
        }
        return $n;
    }

    /**
     * Read a window of source lines around $line from a local, readable file.
     *
     * Returns null (context omitted) if the file cannot be safely read. Never throws.
     *
     * @param mixed $file
     * @param mixed $line
     * @param int $contextLines
     * @return array<int, array<string, mixed>>|null
     */
    private static function sourceContext($file, $line, $contextLines)
    {
        if (!is_string($file) || $file === '' || !is_numeric($line)) {
            return null;
        }
        $line = (int) $line;
        if ($line < 1) {
            return null;
        }
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        try {
            $size = @filesize($file);
            if ($size === false || $size > self::MAX_FILE_SIZE) {
                return null;
            }

            $start = $line - $contextLines;
            if ($start < 1) {
                $start = 1;
            }
            $end = $line + $contextLines;

            $spl = new \SplFileObject($file, 'rb');
            $spl->seek($start - 1); // seek() is 0-indexed

            $out = array();
            $n = $start;
            while ($n <= $end && !$spl->eof()) {
                $raw = $spl->current();
                if ($raw === false) {
                    break;
                }
                $out[] = array(
                    'line' => $n,
                    'content' => self::normalizeLine($raw),
                    'is_error' => ($n === $line),
                );
                $spl->next();
                $n++;
            }

            return count($out) > 0 ? $out : null;
        } catch (\Throwable $ex) {
            return null;
        }
    }

    /**
     * Expand tabs, strip the trailing newline, and truncate overly long lines.
     *
     * @param string $raw
     * @return string
     */
    private static function normalizeLine($raw)
    {
        $line = rtrim($raw, "\r\n");
        $line = str_replace("\t", '    ', $line);

        $hasMb = function_exists('mb_strlen') && function_exists('mb_substr');
        $length = $hasMb ? mb_strlen($line, 'UTF-8') : strlen($line);
        if ($length > self::MAX_LINE_LENGTH) {
            $line = $hasMb
                ? mb_substr($line, 0, self::MAX_LINE_LENGTH, 'UTF-8')
                : substr($line, 0, self::MAX_LINE_LENGTH);
            $line .= '...';
        }

        return $line;
    }
}
