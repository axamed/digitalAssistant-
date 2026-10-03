<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth, Audit, Database};
use App\Support\RecordTypes;
use RuntimeException;

/**
 * Service managing review and approval workflows:
 * Submitting, supervisor review, approving with digital signature,
 * rejecting with reason, and requesting corrections.
 */
final class ApprovalService
{
    /**
     * Submit a record for review by an authorized officer.
     */
    public static function submit(string $type, int $id, ?string $notes = null): bool
    {
        $table = RecordTypes::table($type);
        $record = Database::fetchOne("SELECT * FROM {$table} WHERE id = ? AND is_archived = 0", [$id]);
        if (!$record) {
            throw new RuntimeException("Record not found.");
        }

        $allowed = in_array($record['status'], ['draft', 'correction_required'], true);
        if (!$allowed) {
            throw new RuntimeException("This record cannot be submitted in its current status: {$record['status']}");
        }

        $userId = (int) Auth::id();
        Database::execute("UPDATE {$table} SET status = 'submitted' WHERE id = ?", [$id]);

        // Insert or update approval tracking
        $approvalId = Database::scalar(
            'SELECT id FROM approvals WHERE record_type = ? AND record_id = ? AND decision = "pending" LIMIT 1',
            [$type, $id]
        );

        if ($approvalId) {
            Database::execute(
                'UPDATE approvals SET submitted_at = NOW(), comments = ? WHERE id = ?',
                [$notes, $approvalId]
            );
        } else {
            Database::execute(
                'INSERT INTO approvals (record_type, record_id, submitted_by, decision, comments, submitted_at)
                 VALUES (?, ?, ?, "pending", ?, NOW())',
                [$type, $id, $userId, $notes]
            );
        }

        RecordService::logStatusChange($type, $id, 'submitted', $notes ?: 'Submitted for supervisor review', $userId);
        Audit::log('submit', $type, "Submitted {$type} record {$record['registration_number']} for approval", $type, $id);

        // Notify supervisors
        $supervisors = Database::fetchAll("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'supervisor' AND u.status = 'active'");
        foreach ($supervisors as $s) {
            NotificationService::send(
                (int) $s['id'],
                "New {$type} record pending approval",
                "Record {$record['registration_number']} was submitted for your review.",
                'info',
                "records/view.php?type={$type}&id={$id}"
            );
        }

        return true;
    }

    /**
     * Approve a record with a digital signature captured from the supervisor.
     */
    public static function approve(string $type, int $id, string $signatureDataUrl, ?string $comments = null): bool
    {
        Auth::requireRole('supervisor');
        $supervisorId = (int) Auth::id();

        $table = RecordTypes::table($type);
        $record = Database::fetchOne("SELECT * FROM {$table} WHERE id = ? AND is_archived = 0", [$id]);
        if (!$record) {
            throw new RuntimeException("Record not found.");
        }

        // Supervisor cannot approve own record
        if ((int) $record['submitted_by'] === $supervisorId) {
            throw new RuntimeException("You cannot approve your own record.");
        }

        if (!in_array($record['status'], ['submitted', 'under_review'], true)) {
            throw new RuntimeException("Record must be in submitted or under review status to be approved.");
        }

        // Save supervisor's digital signature
        $sigFilename = SignatureService::saveFromDataUrl($signatureDataUrl, $supervisorId);

        Database::beginTransaction();
        try {
            // Update record status to approved
            Database::execute(
                "UPDATE {$table} SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?",
                [$supervisorId, $id]
            );

            // Update approval row
            Database::execute(
                'UPDATE approvals SET reviewed_by = ?, decision = "approved", comments = ?, reviewed_at = NOW()
                 WHERE record_type = ? AND record_id = ? AND decision = "pending"',
                [$supervisorId, $comments, $type, $id]
            );

            // Generate official certificate (unpaid initially)
            CertificateService::issueForRecord($type, $id, $supervisorId, $sigFilename);

            RecordService::logStatusChange($type, $id, 'approved', $comments ?: 'Approved with digital signature', $supervisorId);
            Audit::log('approve', $type, "Approved {$type} record {$record['registration_number']} with digital signature", $type, $id);

            // Notify submitting officer
            NotificationService::send(
                (int) $record['submitted_by'],
                "Record approved: {$record['registration_number']}",
                "Your {$type} record was approved by supervisor.",
                'success',
                "records/view.php?type={$type}&id={$id}"
            );

            Database::commit();
            return true;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw new RuntimeException("Failed to approve record: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Reject a record with mandatory reason.
     */
    public static function reject(string $type, int $id, string $reason): bool
    {
        Auth::requireRole('supervisor');
        $supervisorId = (int) Auth::id();

        if (trim($reason) === '') {
            throw new RuntimeException("A rejection reason is strictly required.");
        }

        $table = RecordTypes::table($type);
        $record = Database::fetchOne("SELECT * FROM {$table} WHERE id = ? AND is_archived = 0", [$id]);
        if (!$record) {
            throw new RuntimeException("Record not found.");
        }

        if ((int) $record['submitted_by'] === $supervisorId) {
            throw new RuntimeException("You cannot reject your own record.");
        }

        Database::execute("UPDATE {$table} SET status = 'rejected' WHERE id = ?", [$id]);

        Database::execute(
            'UPDATE approvals SET reviewed_by = ?, decision = "rejected", rejection_reason = ?, reviewed_at = NOW()
             WHERE record_type = ? AND record_id = ? AND decision = "pending"',
            [$supervisorId, $reason, $type, $id]
        );

        RecordService::logStatusChange($type, $id, 'rejected', "Rejected: {$reason}", $supervisorId);
        Audit::log('reject', $type, "Rejected {$type} record {$record['registration_number']}. Reason: {$reason}", $type, $id);

        NotificationService::send(
            (int) $record['submitted_by'],
            "Record rejected: {$record['registration_number']}",
            "Reason: {$reason}",
            'danger',
            "records/view.php?type={$type}&id={$id}"
        );

        return true;
    }

    /**
     * Request correction with specific feedback.
     */
    public static function requestCorrection(string $type, int $id, string $feedback): bool
    {
        Auth::requireRole('supervisor');
        $supervisorId = (int) Auth::id();

        if (trim($feedback) === '') {
            throw new RuntimeException("Correction instructions are required.");
        }

        $table = RecordTypes::table($type);
        $record = Database::fetchOne("SELECT * FROM {$table} WHERE id = ? AND is_archived = 0", [$id]);
        if (!$record) {
            throw new RuntimeException("Record not found.");
        }

        if ((int) $record['submitted_by'] === $supervisorId) {
            throw new RuntimeException("You cannot review your own record.");
        }

        Database::execute("UPDATE {$table} SET status = 'correction_required' WHERE id = ?", [$id]);

        Database::execute(
            'UPDATE approvals SET reviewed_by = ?, decision = "correction_required", comments = ?, reviewed_at = NOW()
             WHERE record_type = ? AND record_id = ? AND decision = "pending"',
            [$supervisorId, $feedback, $type, $id]
        );

        RecordService::logStatusChange($type, $id, 'correction_required', "Correction requested: {$feedback}", $supervisorId);
        Audit::log('correction_request', $type, "Requested correction for {$type} record {$record['registration_number']}", $type, $id);

        NotificationService::send(
            (int) $record['submitted_by'],
            "Correction required: {$record['registration_number']}",
            "Supervisor requested correction: {$feedback}",
            'warning',
            "records/edit.php?type={$type}&id={$id}"
        );

        return true;
    }

    /**
     * Get pending approvals queue for supervisors.
     */
    public static function getPendingQueue(): array
    {
        $results = [];
        foreach (RecordTypes::ALL as $type => $meta) {
            $table = $meta['table'];
            $nameCol = match ($type) {
                'birth'    => 'child_full_name',
                'death'    => 'deceased_full_name',
                'marriage' => 'husband_full_name',
                'divorce'  => 'husband_full_name',
            };
            $sql = "SELECT r.id, '{$type}' AS record_type, r.registration_number, r.{$nameCol} AS primary_name,
                           r.status, r.registration_date, r.created_at,
                           u.full_name AS submitted_by_name, d.name AS district_name,
                           a.comments AS submit_comments, a.submitted_at
                    FROM {$table} r
                    JOIN users u ON u.id = r.submitted_by
                    JOIN districts d ON d.id = r.district_id
                    LEFT JOIN approvals a ON a.record_type = '{$type}' AND a.record_id = r.id AND a.decision = 'pending'
                    WHERE r.status = 'submitted' AND r.is_archived = 0
                    ORDER BY r.created_at ASC";
            $rows = Database::fetchAll($sql);
            foreach ($rows as $row) {
                $results[] = $row;
            }
        }

        // Sort all by oldest submission first
        usort($results, static fn($a, $b) => strcmp($a['created_at'] ?? '', $b['created_at'] ?? ''));
        return $results;
    }
}
