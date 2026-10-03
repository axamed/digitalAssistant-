<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Database, Logger};

/** Key/value settings stored in system_settings (fees, office details). */
final class Settings
{
    private static ?array $cache = null;

    public static function get(string $key, string $default = ''): string
    {
        if (self::$cache === null) {
            try {
                self::$cache = array_column(Database::fetchAll('SELECT setting_key, setting_value FROM system_settings'), 'setting_value', 'setting_key');
            } catch (\Throwable $e) {
                Logger::error('Settings unavailable: ' . $e->getMessage());
                self::$cache = [];
            }
        }
        $value = self::$cache[$key] ?? $default;
        // A blank stored value must never surface as an empty setting (e.g. a fee of $0.00).
        return trim((string) $value) === '' ? $default : (string) $value;
    }

    /** Persist one setting, inserting it when it does not exist yet. */
    public static function set(string $key, string $value): void
    {
        Database::execute(
            'INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()',
            [$key, $value]
        );
        self::$cache[$key] = $value;
    }

    /** @return array<string,string> every stored setting */
    public static function all(): array
    {
        return array_column(
            Database::fetchAll('SELECT setting_key, setting_value FROM system_settings ORDER BY setting_key'),
            'setting_value',
            'setting_key'
        );
    }

    public static function fee(string $type): float
    {
        return (float) self::get('fee_' . $type, '0');
    }
}
