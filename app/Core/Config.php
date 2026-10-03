<?php
declare(strict_types=1);

namespace App\Core;

/** Reads config/<file>.php (merged with config/<file>.local.php). Usage: Config::get('app.session.lifetime'). */
final class Config
{
    private static array $files = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $file = array_shift($parts);
        $value = self::load($file);
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    private static function load(string $file): array
    {
        if (!isset(self::$files[$file])) {
            $base = BASE_PATH . '/config/' . $file;
            $data = is_file($base . '.php') ? require $base . '.php' : [];
            if (is_file($base . '.local.php')) {
                $data = array_replace_recursive($data, require $base . '.local.php');
            }
            self::$files[$file] = $data;
        }
        return self::$files[$file];
    }
}
