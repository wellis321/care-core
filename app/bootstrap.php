<?php
declare(strict_types=1);

// Care Core bootstrap: autoloading, configuration, error handling. No framework, no Composer needed at runtime.

define('ROOT_PATH', dirname(__DIR__));
date_default_timezone_set('Europe/London');

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = ROOT_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

App\Core\Config::load(ROOT_PATH . '/.env');

if (App\Core\Config::bool('APP_DEBUG', false)) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}
ini_set('log_errors', '1');
