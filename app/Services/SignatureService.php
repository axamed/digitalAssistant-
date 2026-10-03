<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Handles capturing, validating, and storing digital supervisor signatures.
 */
final class SignatureService
{
    /**
     * Decode a base64 Data URL (data:image/png;base64,...) and save it to storage.
     * Returns relative path e.g. "signatures/sig_abdi_1727289192.png".
     */
    public static function saveFromDataUrl(string $dataUrl, int $userId): string
    {
        if (!preg_match('/^data:image\/(png|jpeg);base64,(.+)$/', $dataUrl, $matches)) {
            throw new RuntimeException("Invalid signature image format.");
        }

        $decoded = base64_decode($matches[2], true);
        if ($decoded === false) {
            throw new RuntimeException("Could not decode signature data.");
        }

        // Limit signature size to 500KB
        if (strlen($decoded) > 500 * 1024) {
            throw new RuntimeException("Signature image exceeds maximum allowed size.");
        }

        // The Data URL prefix is attacker-controlled, so confirm the decoded bytes really
        // are a PNG or JPEG and store it under the extension that was actually detected.
        $imageInfo = @getimagesizefromstring($decoded);
        $extensions = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg'];
        if ($imageInfo === false || !isset($extensions[$imageInfo[2]])) {
            throw new RuntimeException("Signature must be a valid PNG or JPEG image.");
        }
        $extension = $extensions[$imageInfo[2]];

        $dir = BASE_PATH . '/storage/uploads/signatures';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create signatures directory.");
        }

        $filename = sprintf('sig_%d_%s_%s.%s', $userId, date('Ymd_His'), bin2hex(random_bytes(4)), $extension);
        $target = $dir . '/' . $filename;

        if (file_put_contents($target, $decoded) === false) {
            throw new RuntimeException("Failed to save digital signature file.");
        }

        return 'signatures/' . $filename;
    }

    /**
     * Get absolute path on disk for a stored signature.
     */
    public static function getAbsolutePath(string $relativePath): ?string
    {
        // Prevent path traversal
        $clean = str_replace(['..', '\\'], ['', '/'], $relativePath);
        $fullPath = BASE_PATH . '/storage/uploads/' . ltrim($clean, '/');
        return is_file($fullPath) ? $fullPath : null;
    }

    /**
     * Build an inline data URI for a stored signature, with the MIME type read from the
     * file itself so a JPEG signature is not mislabelled as PNG.
     */
    public static function dataUri(string $relativePath): ?string
    {
        $fullPath = self::getAbsolutePath($relativePath);
        if ($fullPath === null) {
            return null;
        }
        $contents = file_get_contents($fullPath);
        if ($contents === false || $contents === '') {
            return null;
        }
        $info = @getimagesizefromstring($contents);
        $mime = isset($info['mime']) && is_string($info['mime']) ? $info['mime'] : 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }
}
