<?php

use Bugban\Sdk\Bugban;

/**
 * Procedural helpers for legacy / non-OO codebases.
 */

if (!function_exists('bugban_init')) {
    function bugban_init(array $config)
    {
        return Bugban::init($config);
    }
}

if (!function_exists('bugban_capture')) {
    /**
     * @param \Throwable|\Exception $e
     */
    function bugban_capture($e, array $extra = array())
    {
        Bugban::capture($e, $extra);
    }
}

if (!function_exists('bugban_message')) {
    function bugban_message($message, $level = 'info', array $extra = array())
    {
        Bugban::captureMessage($message, $level, $extra);
    }
}

if (!function_exists('bugban')) {
    /**
     * @return \Bugban\Sdk\Client|null
     */
    function bugban()
    {
        return Bugban::client();
    }
}
