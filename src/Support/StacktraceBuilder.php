<?php

namespace Bugban\Sdk\Support;

class StacktraceBuilder
{
    /**
     * Convert a Throwable into a normalized frame list (top frame = crash site).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fromThrowable(\Throwable $e): array
    {
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

        return $frames;
    }
}
