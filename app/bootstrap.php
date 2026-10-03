<?php
declare(strict_types=1);

use App\Core\{Config, Logger, Session};

define('BASE_PATH', dirname(__DIR__));

// PSR-4 style autoloader: App\Core\Auth -> app/Core/Auth.php
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require __DIR__ . '/Support/helpers.php';

date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));
error_reporting(E_ALL);
ini_set('display_errors', Config::get('app.debug') ? '1' : '0');

// Never show raw PHP/MySQL errors to users; log them instead.
set_exception_handler(static function (Throwable $e): void {
    Logger::error($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    $message = 'Something went wrong while processing your request. Please try again or contact the system administrator.';
    if (Config::get('app.debug')) {
        $message .= ' [' . $e->getMessage() . ']';
    }
    render('errors/error', ['code' => 500, 'message' => $message]);
});

Session::start();
