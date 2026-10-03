<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        // Starting again after a destroy() within the same request is legitimate, but the cookie
        // settings can no longer be sent then, and asking for them only produces a warning.
        if (!headers_sent()) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_name((string) Config::get('app.session.name', 'crs_session'));
            session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        }
        session_start();

        // Idle timeout
        $lifetime = (int) Config::get('app.session.lifetime', 1800);
        if (isset($_SESSION['_last']) && time() - $_SESSION['_last'] > $lifetime) {
            self::destroy();
            // A fresh session can only be opened while headers are still available. If they are
            // not, the user is signed out either way, so ask them to sign in again regardless.
            if (!headers_sent()) {
                session_start();
            }
            self::flash('warning', 'Your session expired. Please sign in again.');
        }
        $_SESSION['_last'] = time();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function regenerate(): void
    {
        if (!headers_sent() && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return array<int, array{type:string,message:string}> */
    public static function pullFlash(): array
    {
        $items = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $items;
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (!headers_sent() && ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
