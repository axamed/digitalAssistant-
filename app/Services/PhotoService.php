<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Service handling a staff member's own profile photo.
 *
 * The photo is a private staff record, not a public avatar, so it is kept in
 * storage/uploads/avatars/ and only ever served through PhotoService::dataUri() or
 * records/avatar.php - storage/ is outside the web root. As with a digital signature,
 * the extension is taken from the bytes that were actually decoded, never from the
 * name the browser sent.
 */
final class PhotoService
{
    /** 2 MB, matching UploadService, and a sane ceiling for a profile photo. */
    private const MAX_SIZE = 2 * 1024 * 1024;

    /** Reject anything wider or taller than this; a photo is displayed at 40-120px. */
    private const MAX_DIMENSION = 3000;

    private const ALLOWED = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    /**
     * Store an uploaded profile photo and return its relative path, e.g.
     * "avatars/user_3_20260927_181500_a1b2c3.jpg".
     *
     * @param array $fileArray one entry of $_FILES
     */
    public static function saveUpload(array $fileArray, int $userId): string
    {
        if (!isset($fileArray['error']) || is_array($fileArray['error'])) {
            throw new RuntimeException('Invalid file upload parameters.');
        }
        if ($fileArray['error'] === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('No photo was selected.');
        }
        if ($fileArray['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The photo could not be uploaded (error code ' . (int) $fileArray['error'] . ').');
        }
        if ((int) $fileArray['size'] > self::MAX_SIZE) {
            throw new RuntimeException('The photo must be smaller than 2 MB.');
        }

        $tmp = (string) $fileArray['tmp_name'];
        $info = @getimagesize($tmp);
        if ($info === false || !isset(self::ALLOWED[$info[2]])) {
            throw new RuntimeException('The photo must be a JPG, PNG, GIF or WEBP image.');
        }
        if ((int) $info[0] > self::MAX_DIMENSION || (int) $info[1] > self::MAX_DIMENSION) {
            throw new RuntimeException('The photo is too large; please use an image under ' . self::MAX_DIMENSION . ' pixels wide.');
        }

        $dir = BASE_PATH . '/storage/uploads/avatars';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('The photo directory could not be created.');
        }

        $filename = sprintf('user_%d_%s_%s.%s', $userId, date('Ymd_His'), bin2hex(random_bytes(4)), self::ALLOWED[$info[2]]);
        if (move_uploaded_file($tmp, $dir . '/' . $filename) === false) {
            throw new RuntimeException('The photo could not be saved.');
        }

        return 'avatars/' . $filename;
    }

    /**
     * Delete a stored photo. A photo belonging to another account is never touched:
     * the user id is part of the stored name, so it is checked before unlinking.
     */
    public static function delete(string $relativePath, int $userId): void
    {
        $absolute = self::absolutePath($relativePath);
        if ($absolute === null) {
            return;
        }
        if (!str_contains(basename($relativePath), 'user_' . $userId . '_')) {
            return;
        }
        @unlink($absolute);
    }

    /**
     * Absolute path of a stored photo, with path traversal stripped, or null when the
     * file is not there. Only files inside the avatars directory are reachable.
     */
    public static function absolutePath(string $relativePath): ?string
    {
        $clean = ltrim(str_replace(['..', '\\'], ['', '/'], $relativePath), '/');
        if (!str_starts_with($clean, 'avatars/')) {
            return null;
        }
        $full = BASE_PATH . '/storage/uploads/' . $clean;
        return is_file($full) ? $full : null;
    }

    /**
     * Inline the photo for display, with the MIME type read from the file itself so a
     * renamed image is not served under the wrong type.
     */
    public static function dataUri(?string $relativePath): ?string
    {
        if ($relativePath === null || trim($relativePath) === '') {
            return null;
        }
        $full = self::absolutePath($relativePath);
        if ($full === null) {
            return null;
        }
        $contents = file_get_contents($full);
        if ($contents === false || $contents === '') {
            return null;
        }
        $info = @getimagesizefromstring($contents);
        $mime = is_string($info['mime'] ?? null) ? $info['mime'] : 'image/jpeg';
        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }
}
