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

    /** If an enclosing function spans more than this many lines, capture a window instead. */
    const MAX_FUNCTION_SPAN = 120;

    /** Half-window used when a function is too large (frameLine -60 .. +60, clamped). */
    const FUNCTION_HALF_WINDOW = 60;

    /** Apply full-function capture only to this many top frames; deeper frames use the ±window. */
    const MAX_FULL_FUNCTION_FRAMES = 10;

    /**
     * Convert a Throwable into a normalized frame list (top frame = crash site).
     *
     * Each frame keeps the existing keys (file, line, function, class, type) and,
     * when the file is a readable local file, gains a `code` entry: an array of
     * { line: int, content: string, is_error: bool } ordered ascending by line.
     *
     * When $fullFunction is true, the `code` for a frame covers the ENTIRE enclosing
     * function/method of that frame's file:line (resolved via Reflection from the NEXT
     * trace entry's class/function — PHP trace semantics: entry i's file:line is a call
     * site inside the callable named by entry i+1, and the throwable's own location is
     * inside the callable named by trace[0]). Frames whose enclosing callable cannot be
     * resolved (top-level code, closures, internal functions, mismatching files) fall
     * back to the classic ±$contextLines window.
     *
     * @param int $contextLines Lines of source to include before/after each frame line (fallback window).
     * @param bool $fullFunction Capture the whole enclosing function body when resolvable.
     * @return array<int, array<string, mixed>>
     */
    public static function fromThrowable(\Throwable $e, $contextLines = self::DEFAULT_CONTEXT_LINES, $fullFunction = true): array
    {
        $contextLines = self::normalizeContextLines($contextLines);
        $fullFunction = (bool) $fullFunction;

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
            $code = null;

            // Frame i's file:line lives inside the callable named by frame i+1
            // (for i=0, the exception's own location is inside getTrace()[0]'s
            // callable, which is frames[1] here). The bottom frame has no next
            // entry — that is top-level code — so it keeps the ±window fallback.
            if ($fullFunction && $i < self::MAX_FULL_FUNCTION_FRAMES && isset($frames[$i + 1])) {
                $code = self::enclosingFunctionContext(
                    $frame['file'],
                    $frame['line'],
                    $frames[$i + 1]['class'],
                    $frames[$i + 1]['function']
                );
            }

            if ($code === null) {
                $code = self::sourceContext($frame['file'], $frame['line'], $contextLines);
            }
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
     * Capture the FULL body of the callable ($class::$function or $function) that
     * encloses $file:$line, as `code` rows ({line, content, is_error}) spanning
     * [getStartLine() .. getEndLine()], with is_error on $line.
     *
     * Returns null whenever anything cannot be resolved/validated, so callers can
     * fall back to the classic ±window. Never throws.
     *
     * @param mixed $file
     * @param mixed $line
     * @param mixed $class
     * @param mixed $function
     * @return array<int, array<string, mixed>>|null
     */
    private static function enclosingFunctionContext($file, $line, $class, $function)
    {
        if (!is_string($file) || $file === '' || !is_numeric($line)) {
            return null;
        }
        $line = (int) $line;
        if ($line < 1) {
            return null;
        }
        if (!is_string($function) || $function === '') {
            return null;
        }
        // Closures (and PHP 8.4 "{closure:...}" names) cannot be reflected by name.
        if (strpos($function, '{closure}') !== false || strpos($function, '{closure:') !== false) {
            return null;
        }
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        try {
            if (is_string($class) && $class !== '') {
                if (strpos($class, '{closure}') !== false || strpos($class, '{closure:') !== false) {
                    return null;
                }
                $ref = new \ReflectionMethod($class, $function);
            } elseif (function_exists($function)) {
                $ref = new \ReflectionFunction($function);
            } else {
                return null;
            }

            if ($ref->isInternal()) {
                return null;
            }

            // The reflected callable must be defined in THIS frame's file and its
            // span must contain the frame line (inherited methods, traits, eval'd
            // code etc. would fail this and fall back to the ±window).
            if ($ref->getFileName() !== $file) {
                return null;
            }
            $start = $ref->getStartLine();
            $end = $ref->getEndLine();
            if (!is_int($start) || !is_int($end) || $start < 1 || $end < $start) {
                return null;
            }
            if ($line < $start || $line > $end) {
                return null;
            }

            // Huge functions: keep a window around the frame line, clamped inside
            // the function bounds, instead of shipping hundreds of lines.
            if (($end - $start + 1) > self::MAX_FUNCTION_SPAN) {
                $wStart = $line - self::FUNCTION_HALF_WINDOW;
                $wEnd = $line + self::FUNCTION_HALF_WINDOW;
                if ($wStart < $start) {
                    $wStart = $start;
                }
                if ($wEnd > $end) {
                    $wEnd = $end;
                }
                $start = $wStart;
                $end = $wEnd;
            }

            return self::readLines($file, $start, $end, $line);
        } catch (\Exception $ex) {
            return null;
        } catch (\Throwable $ex) {
            return null;
        }
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

        $start = $line - $contextLines;
        if ($start < 1) {
            $start = 1;
        }
        $end = $line + $contextLines;

        return self::readLines($file, $start, $end, $line);
    }

    /**
     * Read lines [$start .. $end] of $file into `code` rows, marking $errorLine.
     *
     * Applies the file-size cap, tab expansion and line truncation. Never throws.
     *
     * @param string $file
     * @param int $start
     * @param int $end
     * @param int $errorLine
     * @return array<int, array<string, mixed>>|null
     */
    private static function readLines($file, $start, $end, $errorLine)
    {
        try {
            $size = @filesize($file);
            if ($size === false || $size > self::MAX_FILE_SIZE) {
                return null;
            }

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
                    'is_error' => ($n === $errorLine),
                );
                $spl->next();
                $n++;
            }

            return count($out) > 0 ? $out : null;
        } catch (\Exception $ex) {
            return null;
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
