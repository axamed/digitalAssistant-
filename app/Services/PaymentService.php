<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth, Audit, Database};
use RuntimeException;

/**
 * Service managing fee collections, payment records, and fee totals.
 */
final class PaymentService
{
    /**
     * Record a fee payment for a given certificate.
     */
    public static function recordPayment(int $certificateId): bool
    {
        Auth::requirePermission('certificate.pay');
        return CertificateService::markPaid($certificateId);
    }

    /**
     * Get aggregate statistics on fees collected vs unpaid.
     */
    public static function getFeeSummary(): array
    {
        $sql = "SELECT 
                    COUNT(*) AS total_certificates,
                    SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
                    SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) AS unpaid_count,
                    COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN fee_amount ELSE 0 END), 0) AS total_collected,
                    COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN fee_amount ELSE 0 END), 0) AS total_unpaid
                FROM certificates WHERE status = 'valid'";
        $row = Database::fetchOne($sql);
        return [
            'total_certificates' => (int) ($row['total_certificates'] ?? 0),
            'paid_count'         => (int) ($row['paid_count'] ?? 0),
            'unpaid_count'       => (int) ($row['unpaid_count'] ?? 0),
            'total_collected'    => (float) ($row['total_collected'] ?? 0.0),
            'total_unpaid'       => (float) ($row['total_unpaid'] ?? 0.0),
        ];
    }
}
