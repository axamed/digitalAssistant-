<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Services\PhotoService;

/**
 * Stream the signed-in staff member's own profile photo.
 *
 * storage/ is not web-facing, so the photo cannot be linked directly. No user id is
 * accepted: the image is always the one belonging to the current session, which keeps a
 * staff photo from being guessable by enumerating ids.
 */

$user = Auth::requireLogin();
$path = $user['photo_path'] ?? null;

$absolute = is_string($path) && trim($path) !== '' ? PhotoService::absolutePath($path) : null;
if ($absolute === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No profile photo.');
}

$info = @getimagesize($absolute);
$mime = is_string($info['mime'] ?? null) ? $info['mime'] : 'image/jpeg';
$stamp = (string) filemtime($absolute);
$etag = '"' . md5($absolute . '|' . $stamp) . '"';

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($absolute));
header('Cache-Control: private, max-age=600');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
readfile($absolute);
