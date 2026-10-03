<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth, Audit, Database};
use App\Support\RecordTypes;
use RuntimeException;

/**
 * Service managing official certificates: issuance, numbers, tokens, fees,
 * payment verification, printing gates, and revocation/restoration.
 */
final class CertificateService
{
    /**
     * Generate the next sequential certificate number (e.g. CERT-2026-0001).
     */
    public static function nextCertificateNumber(?int $year = null): string
    {
        $year ??= (int) date('Y');
        Database::beginTransaction();
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT last_number FROM number_sequences WHERE prefix = "CERT" AND year = ? FOR UPDATE'
            );
            $stmt->execute([$year]);
            $current = $stmt->fetchColumn();

            if ($current === false) {
                $next = 1;
                $insert = Database::pdo()->prepare(
                    'INSERT INTO number_sequences (prefix, year, last_number) VALUES ("CERT", ?, ?)'
                );
                $insert->execute([$year, $next]);
            } else {
                $next = ((int) $current) + 1;
                $update = Database::pdo()->prepare(
                    'UPDATE number_sequences SET last_number = ? WHERE prefix = "CERT" AND year = ?'
                );
                $update->execute([$next, $year]);
            }

            Database::commit();
            return sprintf('CERT-%d-%04d', $year, $next);
        } catch (\Throwable $e) {
            Database::rollBack();
            throw new RuntimeException("Failed to generate certificate number: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Issue an official certificate for an approved record.
     */
    public static function issueForRecord(string $recordType, int $recordId, int $supervisorId, string $signatureImage): array
    {
        // Only an approved record may be certified. The approval workflow sets the status and
        // issues the certificate inside one transaction, so this only ever refuses a caller
        // that skipped it.
        $table = RecordTypes::table($recordType);
        $record = Database::fetchOne("SELECT status FROM {$table} WHERE id = ?", [$recordId]);
        if (!$record) {
            throw new RuntimeException("Record not found.");
        }
        if ($record['status'] !== 'approved') {
            throw new RuntimeException("Only an approved record can be certified.");
        }

        // Check if certificate already exists
        $existing = Database::fetchOne(
            'SELECT * FROM certificates WHERE record_type = ? AND record_id = ?',
            [$recordType, $recordId]
        );
        if ($existing) {
            return $existing;
        }

        $certNumber = self::nextCertificateNumber();
        $token = bin2hex(random_bytes(16)); // 32-character secure random verification token

        // Read fee from settings
        $feeKey = 'fee_' . $recordType;
        $fee = (float) Settings::get($feeKey, '0.00');

        Database::execute(
            'INSERT INTO certificates (
                certificate_number, record_type, record_id, verification_token,
                issue_date, issued_by, fee_amount, payment_status, status,
                signature_image, signed_by, signed_at, created_at
             ) VALUES (?, ?, ?, ?, CURDATE(), ?, ?, "unpaid", "valid", ?, ?, NOW(), NOW())',
            [$certNumber, $recordType, $recordId, $token, $supervisorId, $fee, $signatureImage, $supervisorId]
        );

        $certId = Database::lastId();
        Audit::log('certificate_issue', 'certificates', "Issued certificate {$certNumber} for {$recordType} record #{$recordId}", 'certificate', $certId);

        return Database::fetchOne('SELECT * FROM certificates WHERE id = ?', [$certId]) ?? [];
    }

    /**
     * Find a certificate by record type and record ID.
     */
    public static function findByRecord(string $type, int $recordId): ?array
    {
        return Database::fetchOne(
            'SELECT c.*, u_iss.full_name AS issued_by_name, u_sig.full_name AS signed_by_name, u_pay.full_name AS paid_by_name
             FROM certificates c
             LEFT JOIN users u_iss ON u_iss.id = c.issued_by
             LEFT JOIN users u_sig ON u_sig.id = c.signed_by
             LEFT JOIN users u_pay ON u_pay.id = c.paid_by
             WHERE c.record_type = ? AND c.record_id = ?',
            [$type, $recordId]
        );
    }

    /**
     * Find a certificate by its unique verification token.
     */
    public static function findByToken(string $token): ?array
    {
        return Database::fetchOne('SELECT * FROM certificates WHERE verification_token = ?', [trim($token)]);
    }

    /**
     * Find certificate by certificate number.
     */
    public static function findByNumber(string $certNumber): ?array
    {
        return Database::fetchOne('SELECT * FROM certificates WHERE certificate_number = ?', [trim($certNumber)]);
    }

    /**
     * Mark certificate fee as paid.
     */
    public static function markPaid(int $certificateId): bool
    {
        Auth::requirePermission('certificate.pay');
        $cert = Database::fetchOne('SELECT * FROM certificates WHERE id = ?', [$certificateId]);
        if (!$cert) {
            throw new RuntimeException("Certificate not found.");
        }

        if ($cert['payment_status'] === 'paid') {
            return true;
        }

        if ($cert['status'] !== 'valid') {
            throw new RuntimeException("A revoked certificate cannot take a fee payment.");
        }

        $userId = (int) Auth::id();
        Database::execute(
            'UPDATE certificates SET payment_status = "paid", paid_by = ?, paid_at = NOW() WHERE id = ?',
            [$userId, $certificateId]
        );

        Audit::log(
            'payment',
            'certificates',
            "Recorded fee payment of \${$cert['fee_amount']} for certificate {$cert['certificate_number']}",
            'certificate',
            $certificateId
        );

        return true;
    }

    /**
     * Check if a certificate is printable (must be paid and valid).
     */
    public static function canPrint(array $cert): bool
    {
        return ($cert['payment_status'] ?? '') === 'paid' && ($cert['status'] ?? '') === 'valid';
    }

    /**
     * Revoke a certificate (Supervisor only).
     */
    public static function revoke(int $certificateId, string $reason): bool
    {
        Auth::requirePermission('certificate.revoke');
        $cert = Database::fetchOne('SELECT * FROM certificates WHERE id = ?', [$certificateId]);
        if (!$cert) {
            throw new RuntimeException("Certificate not found.");
        }

        Database::execute('UPDATE certificates SET status = "revoked" WHERE id = ?', [$certificateId]);
        Audit::log('certificate_revoke', 'certificates', "Revoked certificate {$cert['certificate_number']}. Reason: {$reason}", 'certificate', $certificateId);
        return true;
    }

    /**
     * Restore a revoked certificate (Supervisor only).
     */
    public static function restore(int $certificateId): bool
    {
        Auth::requirePermission('certificate.revoke');
        $cert = Database::fetchOne('SELECT * FROM certificates WHERE id = ?', [$certificateId]);
        if (!$cert) {
            throw new RuntimeException("Certificate not found.");
        }

        Database::execute('UPDATE certificates SET status = "valid" WHERE id = ?', [$certificateId]);
        Audit::log('certificate_restore', 'certificates', "Restored certificate {$cert['certificate_number']}", 'certificate', $certificateId);
        return true;
    }
}
