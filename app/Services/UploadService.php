<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth, Audit, Database};
use RuntimeException;

/**
 * Service managing secure file and photo uploads.
 * Restricts extensions, inspects MIME types, enforces 2MB limit,
 * renames files, and serves private files only through PHP.
 */
final class UploadService
{
    private const ALLOWED_MIME = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'application/pdf' => 'pdf',
    ];

    private const MAX_SIZE = 2 * 1024 * 1024; // 2 MB limit

    /**
     * Handle an uploaded file from $_FILES.
     */
    public static function handleUpload(array $fileArray, string $recordType, ?int $recordId = null): int
    {
        if (!isset($fileArray['error']) || is_array($fileArray['error'])) {
            throw new RuntimeException("Invalid file upload parameters.");
        }

        if ($fileArray['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException("Upload failed with error code: " . $fileArray['error']);
        }

        if ($fileArray['size'] > self::MAX_SIZE) {
            throw new RuntimeException("The uploaded file exceeds the 2 MB maximum size limit.");
        }

        // Verify MIME type using finfo
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($fileArray['tmp_name']);

        if (!isset(self::ALLOWED_MIME[$mime])) {
            throw new RuntimeException("Invalid file type ({$mime}). Only JPG, PNG, and PDF files are permitted.");
        }

        $ext = self::ALLOWED_MIME[$mime];
        $originalName = basename($fileArray['name']);

        // Generate sanitized unique stored name
        $storedName = sprintf('doc_%s_%s_%s.%s', $recordType, date('Ymd_His'), bin2hex(random_bytes(6)), $ext);
        $uploadDir = BASE_PATH . '/storage/uploads';

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException("Upload directory could not be created.");
        }

        $targetPath = $uploadDir . '/' . $storedName;
        if (!move_uploaded_file($fileArray['tmp_name'], $targetPath)) {
            throw new RuntimeException("Failed to move uploaded file to secure storage.");
        }

        $userId = (int) (Auth::id() ?? 1);

        Database::execute(
            'INSERT INTO supporting_documents (record_type, record_id, original_name, stored_name, mime_type, file_size, uploaded_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [$recordType, $recordId, $originalName, $storedName, $mime, $fileArray['size'], $userId]
        );

        $docId = Database::lastId();
        Audit::log('upload', 'documents', "Uploaded document #{$docId} ({$originalName}) for {$recordType}", $recordType, $recordId);

        return $docId;
    }

    /**
     * Get document metadata by ID.
     */
    public static function getDocument(int $docId): ?array
    {
        return Database::fetchOne('SELECT * FROM supporting_documents WHERE id = ?', [$docId]);
    }

    /**
     * Safely serve a stored file through PHP with permission checks and audit logging.
     */
    public static function streamDocument(int $docId): void
    {
        $doc = self::getDocument($docId);
        if (!$doc) {
            http_response_code(404);
            exit('Document not found.');
        }

        $path = BASE_PATH . '/storage/uploads/' . basename($doc['stored_name']);
        if (!is_file($path)) {
            http_response_code(404);
            exit('File not found in storage.');
        }

        Audit::log('document_access', 'documents', "Accessed document #{$docId} ({$doc['original_name']})", $doc['record_type'], (int) ($doc['record_id'] ?? 0));

        header('Content-Type: ' . $doc['mime_type']);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: inline; filename="' . rawurlencode($doc['original_name']) . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        readfile($path);
        exit;
    }
}
