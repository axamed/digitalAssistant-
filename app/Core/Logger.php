<?php
declare(strict_types=1);

namespace App\Core;

/** Writes technical errors to storage/logs (never shown to users). */
final class Logger
{
    public static function error(string $message): void
    {
        $dir = BASE_PATH . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($dir . '/app-' . date('Y-m') . '.log', '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
