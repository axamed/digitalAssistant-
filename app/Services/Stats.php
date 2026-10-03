<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\RecordTypes;

/**
 * Read-only counters and metric queries for role-based dashboards.
 */
final class Stats
{
    /** @return array<string, array<string,int>> [type => [status => count]] */
    public static function recordCounts(?int $submittedBy = null): array
    {
        $out = [];
        foreach (RecordTypes::ALL as $type => $meta) {
            $sql = 'SELECT status, COUNT(*) AS c FROM ' . $meta['table'] . ' WHERE is_archived = 0';
            $params = [];
            if ($submittedBy !== null) {
                $sql .= ' AND submitted_by = ?';
                $params[] = $submittedBy;
            }
            $rows = Database::fetchAll($sql . ' GROUP BY status', $params);
            $out[$type] = array_column($rows, 'c', 'status');
        }
        return $out;
    }

    public static function total(array $counts, ?string $status = null): int
    {
        $sum = 0;
        foreach ($counts as $byStatus) {
            $sum += $status === null ? array_sum($byStatus) : (int) ($byStatus[$status] ?? 0);
        }
        return $sum;
    }

    public static function recordCountsByType(?int $submittedBy = null): array
    {
        $out = [];
        foreach (RecordTypes::ALL as $type => $meta) {
            $sql = "SELECT COUNT(*) FROM {$meta['table']} WHERE is_archived = 0";
            $params = [];
            if ($submittedBy !== null) {
                $sql .= " AND submitted_by = ?";
                $params[] = $submittedBy;
            }
            $out[$type] = (int) Database::scalar($sql, $params);
        }
        return $out;
    }

    public static function userCounts(): array
    {
        return [
            'total'    => (int) Database::scalar('SELECT COUNT(*) FROM users'),
            'active'   => (int) Database::scalar("SELECT COUNT(*) FROM users WHERE status = 'active'"),
            'inactive' => (int) Database::scalar("SELECT COUNT(*) FROM users WHERE status = 'inactive'"),
            'locked'   => (int) Database::scalar("SELECT COUNT(*) FROM users WHERE locked_until IS NOT NULL AND locked_until > NOW()"),
        ];
    }

    public static function certificateCounts(): array
    {
        $row = Database::fetchOne(
            "SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) AS paid,
                SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) AS unpaid,
                SUM(CASE WHEN status = 'valid' THEN 1 ELSE 0 END) AS valid,
                SUM(CASE WHEN status = 'revoked' THEN 1 ELSE 0 END) AS revoked,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN fee_amount ELSE 0 END), 0) AS revenue
             FROM certificates"
        );
        return [
            'total'   => (int) ($row['total'] ?? 0),
            'paid'    => (int) ($row['paid'] ?? 0),
            'unpaid'  => (int) ($row['unpaid'] ?? 0),
            'valid'   => (int) ($row['valid'] ?? 0),
            'revoked' => (int) ($row['revoked'] ?? 0),
            'revenue' => (float) ($row['revenue'] ?? 0.0),
        ];
    }

    public static function auditSummary(): array
    {
        return [
            'total'   => (int) Database::scalar('SELECT COUNT(*) FROM audit_logs'),
            'today'   => (int) Database::scalar('SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at) = CURDATE()'),
            'failed'  => (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action IN ('login_failed', 'login_blocked')"),
        ];
    }

    public static function recentAuditLogs(int $limit = 10): array
    {
        return Database::fetchAll(
            'SELECT a.*, u.full_name AS user_full_name
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.created_at DESC LIMIT ?',
            [$limit]
        );
    }

    public static function recentBackups(int $limit = 5): array
    {
        return Database::fetchAll(
            'SELECT b.*, u.full_name AS user_name
             FROM backup_logs b
             LEFT JOIN users u ON u.id = b.created_by
             ORDER BY b.created_at DESC LIMIT ?',
            [$limit]
        );
    }

    public static function recentRecords(?int $submittedBy = null, int $limit = 10): array
    {
        $all = [];
        foreach (RecordTypes::ALL as $type => $meta) {
            $nameCol = match ($type) {
                'birth'    => 'child_full_name',
                'death'    => 'deceased_full_name',
                'marriage' => 'husband_full_name',
                'divorce'  => 'husband_full_name',
            };
            $sql = "SELECT r.id, '{$type}' AS record_type, r.registration_number, r.{$nameCol} AS primary_name,
                           r.status, r.registration_date, r.created_at, d.name AS district_name,
                           u.full_name AS submitted_by_name
                    FROM {$meta['table']} r
                    LEFT JOIN districts d ON d.id = r.district_id
                    LEFT JOIN users u ON u.id = r.submitted_by
                    WHERE r.is_archived = 0";
            $params = [];
            if ($submittedBy !== null) {
                $sql .= " AND r.submitted_by = ?";
                $params[] = $submittedBy;
            }
            $sql .= " ORDER BY r.created_at DESC LIMIT {$limit}";
            $rows = Database::fetchAll($sql, $params);
            foreach ($rows as $r) {
                $all[] = $r;
            }
        }
        usort($all, static fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return array_slice($all, 0, $limit);
    }

    public static function topDistricts(int $limit = 5): array
    {
        $districts = Database::fetchAll('SELECT id, name FROM districts');
        $counts = [];
        foreach ($districts as $d) {
            $did = (int) $d['id'];
            $b = (int) Database::scalar('SELECT COUNT(*) FROM birth_records WHERE district_id = ? AND is_archived = 0', [$did]);
            $dt = (int) Database::scalar('SELECT COUNT(*) FROM death_records WHERE district_id = ? AND is_archived = 0', [$did]);
            $m = (int) Database::scalar('SELECT COUNT(*) FROM marriage_records WHERE district_id = ? AND is_archived = 0', [$did]);
            $dv = (int) Database::scalar('SELECT COUNT(*) FROM divorce_records WHERE district_id = ? AND is_archived = 0', [$did]);
            $tot = $b + $dt + $m + $dv;
            if ($tot > 0) {
                $counts[] = [
                    'name'  => $d['name'],
                    'total' => $tot,
                    'births'=> $b,
                    'deaths'=> $dt,
                    'marriages' => $m,
                    'divorces'  => $dv,
                ];
            }
        }
        usort($counts, static fn($a, $b) => $b['total'] <=> $a['total']);
        return array_slice($counts, 0, $limit);
    }

    public static function officerAttentionNeeded(int $userId): array
    {
        $issues = [];
        foreach (RecordTypes::ALL as $type => $meta) {
            $nameCol = match ($type) {
                'birth'    => 'child_full_name',
                'death'    => 'deceased_full_name',
                'marriage' => 'husband_full_name',
                'divorce'  => 'husband_full_name',
            };
            $rows = Database::fetchAll(
                "SELECT r.id, '{$type}' AS record_type, r.registration_number, r.{$nameCol} AS primary_name,
                        r.status, r.updated_at, a.comments, a.rejection_reason
                 FROM {$meta['table']} r
                 LEFT JOIN approvals a ON a.record_type = '{$type}' AND a.record_id = r.id
                 WHERE r.submitted_by = ? AND r.status IN ('correction_required', 'rejected') AND r.is_archived = 0
                 ORDER BY r.updated_at DESC",
                [$userId]
            );
            foreach ($rows as $r) {
                $issues[] = $r;
            }
        }
        return $issues;
    }
}
