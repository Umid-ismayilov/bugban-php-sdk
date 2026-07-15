<?php

/**
 * Manual (Composer-free) autoloader for legacy projects.
 *
 * Usage in any old PHP project that cannot run Composer:
 *
 *     require __DIR__ . '/path/to/bugban-php-sdk/autoload.php';
 *     \Bugban\Sdk\Bugban::init(array('api_key' => 'bb_xxx', 'host' => 'https://bugban.online'));
 *     \Bugban\Sdk\Bugban::registerHandlers(); // automatic error/exception capture
 */

spl_autoload_register(function ($class) {
    $prefix = 'Bugban\\Sdk\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require __DIR__ . '/src/helpers.php';
