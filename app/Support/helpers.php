<?php
declare(strict_types=1);

use App\Core\{Config, Session};

/** Escape for HTML output. */
function e(mixed $value): string
{
    // A stray array would otherwise print a warning to the log and the word "Array" on the page.
    if (is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
        return '';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim((string) Config::get('app.base_url', ''), '/') . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function flash(string $type, string $message): void
{
    Session::flash($type, $message);
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Previously submitted value, escaped, for re-filling forms. */
function old(string $key, string $default = ''): string
{
    return e($_POST[$key] ?? $default);
}

function render(string $view, array $data = []): void
{
    extract($data, EXTR_SKIP);
    require BASE_PATH . '/app/Views/' . $view . '.php';
}

function abort(int $code, string $message): never
{
    http_response_code($code);
    render('errors/error', ['code' => $code, 'message' => $message]);
    exit;
}

function money(float|int|string|null $amount): string
{
    return ($amount === null || (float) $amount <= 0) ? 'Not set' : '$' . number_format((float) $amount, 2);
}

/**
 * Cache-busting version for a stored photo, from its modification time. The photo is
 * streamed by public/avatar.php, so the URL has to change when the file is replaced or
 * a browser keeps showing the old face.
 */
function avatar_photo_stamp(string $relativePath): ?string
{
    $absolute = \App\Services\PhotoService::absolutePath($relativePath);
    if ($absolute === null) {
        return null;
    }
    $stamp = @filemtime($absolute);
    return $stamp === false ? null : (string) $stamp;
}

/** First letters of a name, for the placeholder shown when there is no photo. */
function initials(string $name, int $max = 2): string
{
    $letters = '';
    foreach (preg_split('/\s+/', trim($name)) ?: [] as $word) {
        if ($word !== '') {
            $letters .= mb_strtoupper(mb_substr($word, 0, 1));
        }
        if (mb_strlen($letters) >= $max) {
            break;
        }
    }
    return $letters !== '' ? $letters : '?';
}
