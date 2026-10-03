<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\RecordTypes;

/**
 * Service generating civil registration statistics and administrative reports:
 * Record types, every Banadir district, monthly/annual timelines, status summaries,
 * officer activity, and fee collections.
 */
final class ReportService
{
    /**
     * Report: Registrations by district across all Banadir districts.
     */
    public static function byDistrict(): array
    {
        $districts = Database::fetchAll('SELECT id, name FROM districts ORDER BY name ASC');
        $data = [];

        foreach ($districts as $d) {
            $did = (int) $d['id'];
            $births = (int) Database::scalar('SELECT COUNT(*) FROM birth_records WHERE district_id = ? AND is_archived = 0', [$did]);
            $deaths = (int) Database::scalar('SELECT COUNT(*) FROM death_records WHERE district_id = ? AND is_archived = 0', [$did]);
            $marriages = (int) Database::scalar('SELECT COUNT(*) FROM marriage_records WHERE district_id = ? AND is_archived = 0', [$did]);
            $divorces = (int) Database::scalar('SELECT COUNT(*) FROM divorce_records WHERE district_id = ? AND is_archived = 0', [$did]);

            $data[] = [
                'district_name' => $d['name'],
                'births'        => $births,
                'deaths'        => $deaths,
                'marriages'     => $marriages,
                'divorces'      => $divorces,
                'total'         => $births + $deaths + $marriages + $divorces,
            ];
        }

        return $data;
    }

    /**
     * Report: Monthly registration volume for a given year.
     */
    public static function monthlyBreakdown(?int $year = null): array
    {
        $year ??= (int) date('Y');
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthStr = sprintf('%04d-%02d', $year, $m);
            $monthLabel = date('M Y', strtotime("{$monthStr}-01"));

            $births = (int) Database::scalar("SELECT COUNT(*) FROM birth_records WHERE DATE_FORMAT(registration_date, '%Y-%m') = ? AND is_archived = 0", [$monthStr]);
            $deaths = (int) Database::scalar("SELECT COUNT(*) FROM death_records WHERE DATE_FORMAT(registration_date, '%Y-%m') = ? AND is_archived = 0", [$monthStr]);
            $marriages = (int) Database::scalar("SELECT COUNT(*) FROM marriage_records WHERE DATE_FORMAT(registration_date, '%Y-%m') = ? AND is_archived = 0", [$monthStr]);
            $divorces = (int) Database::scalar("SELECT COUNT(*) FROM divorce_records WHERE DATE_FORMAT(registration_date, '%Y-%m') = ? AND is_archived = 0", [$monthStr]);

            $months[] = [
                'month'     => $monthLabel,
                'births'    => $births,
                'deaths'    => $deaths,
                'marriages' => $marriages,
                'divorces'  => $divorces,
                'total'     => $births + $deaths + $marriages + $divorces,
            ];
        }
        return $months;
    }

    /**
     * Report: Officer activity summary.
     */
    public static function officerActivity(): array
    {
        $officers = Database::fetchAll(
            "SELECT u.id, u.full_name, u.username, u.status
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE r.name IN ('officer', 'supervisor')
             ORDER BY u.full_name ASC"
        );

        $report = [];
        foreach ($officers as $off) {
            $uid = (int) $off['id'];
            $b = (int) Database::scalar('SELECT COUNT(*) FROM birth_records WHERE submitted_by = ? AND is_archived = 0', [$uid]);
            $d = (int) Database::scalar('SELECT COUNT(*) FROM death_records WHERE submitted_by = ? AND is_archived = 0', [$uid]);
            $m = (int) Database::scalar('SELECT COUNT(*) FROM marriage_records WHERE submitted_by = ? AND is_archived = 0', [$uid]);
            $dv = (int) Database::scalar('SELECT COUNT(*) FROM divorce_records WHERE submitted_by = ? AND is_archived = 0', [$uid]);

            $approved = 0;
            foreach (RecordTypes::ALL as $type => $meta) {
                $approved += (int) Database::scalar("SELECT COUNT(*) FROM {$meta['table']} WHERE submitted_by = ? AND status = 'approved' AND is_archived = 0", [$uid]);
            }

            $report[] = [
                'officer_name' => $off['full_name'],
                'username'     => $off['username'],
                'births'       => $b,
                'deaths'       => $d,
                'marriages'    => $m,
                'divorces'     => $dv,
                'total'        => $b + $d + $m + $dv,
                'approved'     => $approved,
            ];
        }

        return $report;
    }

    /**
     * Report: Fees collected by record type and payment status.
     */
    public static function feeCollections(): array
    {
        $sql = "SELECT record_type,
                       COUNT(*) AS certificate_count,
                       SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
                       SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) AS unpaid_count,
                       COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN fee_amount ELSE 0 END), 0) AS total_collected,
                       COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN fee_amount ELSE 0 END), 0) AS total_unpaid
                FROM certificates
                WHERE status = 'valid'
                GROUP BY record_type";
        return Database::fetchAll($sql);
    }

    // ── Individual event reports ────────────────────────────────────────────
    // The reports above mix the four events. These back the per-event report on
    // supervisor/reports.php: pick birth, death, marriage or divorce and read that one
    // event on its own. Every event lives in its own table, so the type is resolved
    // through RecordTypes (the table name whitelist) and never taken from the query.

    /**
     * Headline figures for one event type, for a year and for the whole register.
     */
    public static function typeTotals(string $type, ?int $year = null): array
    {
        $table = RecordTypes::table($type);
        $where = 'is_archived = 0';
        $params = [];

        if ($year !== null) {
            $where .= ' AND YEAR(registration_date) = ?';
            $params[] = $year;
        }

        $total = (int) Database::scalar("SELECT COUNT(*) FROM {$table} WHERE {$where}", $params);
        $approved = (int) Database::scalar("SELECT COUNT(*) FROM {$table} WHERE {$where} AND status = 'approved'", $params);

        // Marriage and divorce are recorded for a couple and have no gender column,
        // so the split is only offered for the events that have one.
        $hasGender = RecordTypes::hasGender($type);
        $male = $hasGender ? (int) Database::scalar("SELECT COUNT(*) FROM {$table} WHERE {$where} AND gender = 'male'", $params) : 0;
        $female = $hasGender ? (int) Database::scalar("SELECT COUNT(*) FROM {$table} WHERE {$where} AND gender = 'female'", $params) : 0;

        return [
            'record_type'      => $type,
            'label'            => RecordTypes::ALL[$type]['label'],
            'year'             => $year,
            'total'            => $total,
            'approved'         => $approved,
            'pending'          => $total - $approved,
            'has_gender'       => $hasGender,
            'male'             => $male,
            'female'           => $female,
        ];
    }

    /**
     * One row per event type for the year selector, so the report page can show the
     * four event tabs with their counts.
     *
     * @return array<string, array> keyed by record type
     */
    public static function allTypeTotals(?int $year = null): array
    {
        $totals = [];
        foreach (RecordTypes::ALL as $type => $meta) {
            $totals[$type] = self::typeTotals($type, $year);
        }
        return $totals;
    }

    /**
     * Monthly volume for one event type, one row per month (zero months included,
     * so a bar chart never has a gap where nothing was registered).
     */
    public static function typeMonthly(string $type, ?int $year = null): array
    {
        $table = RecordTypes::table($type);
        $year ??= (int) date('Y');
        $counts = [];
        foreach (Database::fetchAll(
            "SELECT DATE_FORMAT(registration_date, '%Y-%m') AS ym, COUNT(*) AS c
             FROM {$table} WHERE is_archived = 0 AND YEAR(registration_date) = ? GROUP BY ym",
            [$year]
        ) as $row) {
            $counts[(string) $row['ym']] = (int) $row['c'];
        }

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $ym = sprintf('%04d-%02d', $year, $m);
            $months[] = [
                'month' => date('M Y', strtotime("{$ym}-01")),
                'ym'    => $ym,
                'count' => $counts[$ym] ?? 0,
            ];
        }
        return $months;
    }

    /**
     * One event type across all Banadir districts, districts with no record included.
     */
    public static function typeByDistrict(string $type): array
    {
        $table = RecordTypes::table($type);
        $counts = [];
        foreach (Database::fetchAll(
            "SELECT district_id, COUNT(*) AS c FROM {$table} WHERE is_archived = 0 GROUP BY district_id"
        ) as $row) {
            $counts[(int) $row['district_id']] = (int) $row['c'];
        }

        $data = [];
        foreach (Database::fetchAll('SELECT id, name FROM districts ORDER BY name ASC') as $district) {
            $data[] = [
                'district_id'   => (int) $district['id'],
                'district_name' => $district['name'],
                'count'         => $counts[(int) $district['id']] ?? 0,
            ];
        }
        return $data;
    }

    /**
     * Where one event type currently stands in the approval workflow, for a year.
     */
    public static function typeStatusSummary(string $type, ?int $year = null): array
    {
        $table = RecordTypes::table($type);
        $where = 'is_archived = 0';
        $params = [];
        if ($year !== null) {
            $where .= ' AND YEAR(registration_date) = ?';
            $params[] = $year;
        }

        $counts = [];
        foreach (Database::fetchAll("SELECT status, COUNT(*) AS c FROM {$table} WHERE {$where} GROUP BY status", $params) as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }

        $summary = [];
        foreach (RecordTypes::STATUSES as $status) {
            $summary[$status] = $counts[$status] ?? 0;
        }
        $summary['total'] = array_sum($summary);
        return $summary;
    }

    /**
     * Every record of every type for a year, in one flat list: the combined register
     * behind the "All events" report and its CSV export.
     */
    public static function everyRecord(?int $year = null, int $limit = 2000): array
    {
        $where = 'is_archived = 0';
        $params = [];
        if ($year !== null) {
            $where .= ' AND YEAR(registration_date) = ?';
            $params[] = $year;
        }

        $rows = [];
        foreach (RecordTypes::ALL as $type => $meta) {
            $table = $meta['table'];
            $nameCol = $meta['name'];
            $genderCol = RecordTypes::hasGender($type) ? 'r.gender' : "'' AS gender";
            $partnerCol = RecordTypes::PARTNER[$type] ?? null;
            $partnerSel = $partnerCol !== null
                ? "r.{$partnerCol} AS partner_name"
                : "'' AS partner_name";

            $sql = "SELECT '{$type}' AS record_type, r.registration_number, r.{$nameCol} AS primary_name,
                           {$partnerSel}, {$genderCol}, r.status, r.registration_date,
                           d.name AS district_name, u.full_name AS submitted_by_name
                    FROM {$table} r
                    LEFT JOIN districts d ON d.id = r.district_id
                    LEFT JOIN users u ON u.id = r.submitted_by
                    WHERE {$where}
                    ORDER BY r.registration_date DESC
                    LIMIT " . (int) max(1, min(5000, $limit));
            foreach (Database::fetchAll($sql, $params) as $row) {
                $rows[] = $row;
            }
        }

        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) $b['registration_date'], (string) $a['registration_date']);
        });
        return $rows;
    }
}
