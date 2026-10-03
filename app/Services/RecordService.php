<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth, Audit, Database};
use App\Support\RecordTypes;
use RuntimeException;

/**
 * Core service for civil registration records (Birth, Death, Marriage, Divorce).
 * Manages sequence generation, CRUD operations, permission checks, duplicate detection, and history.
 */
final class RecordService
{
    /**
     * Generate the next sequential registration number for a record type.
     * Format: BR-2026-0001, DR-2026-0001, MR-2026-0001, DV-2026-0001
     */
    public static function nextRegistrationNumber(string $type, ?int $year = null): string
    {
        $year ??= (int) date('Y');
        $prefix = RecordTypes::ALL[$type]['prefix'] ?? throw new \InvalidArgumentException("Invalid record type: $type");

        Database::beginTransaction();
        try {
            // Lock the sequence row or insert if not exists
            $stmt = Database::pdo()->prepare(
                'SELECT last_number FROM number_sequences WHERE prefix = ? AND year = ? FOR UPDATE'
            );
            $stmt->execute([$prefix, $year]);
            $current = $stmt->fetchColumn();

            if ($current === false) {
                $next = 1;
                $insert = Database::pdo()->prepare(
                    'INSERT INTO number_sequences (prefix, year, last_number) VALUES (?, ?, ?)'
                );
                $insert->execute([$prefix, $year, $next]);
            } else {
                $next = ((int) $current) + 1;
                $update = Database::pdo()->prepare(
                    'UPDATE number_sequences SET last_number = ? WHERE prefix = ? AND year = ?'
                );
                $update->execute([$next, $prefix, $year]);
            }

            Database::commit();
            return sprintf('%s-%d-%04d', $prefix, $year, $next);
        } catch (\Throwable $e) {
            Database::rollBack();
            throw new RuntimeException("Failed to generate registration number: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Find a single record by type and ID.
     */
    public static function find(string $type, int $id): ?array
    {
        $table = RecordTypes::table($type);
        $sql = "SELECT r.*, d.name AS district_name, u_sub.full_name AS submitted_by_name, u_app.full_name AS approved_by_name
                FROM {$table} r
                LEFT JOIN districts d ON d.id = r.district_id
                LEFT JOIN users u_sub ON u_sub.id = r.submitted_by
                LEFT JOIN users u_app ON u_app.id = r.approved_by
                WHERE r.id = ? AND r.is_archived = 0";
        return Database::fetchOne($sql, [$id]);
    }

    /**
     * List records across the event types for a listing screen.
     *
     * Serves both "My Records" and the manager's "All Records": pass $submittedBy to limit the
     * result to one desk, or null for every record in the registry. Each event type is queried
     * separately (they live in separate tables) and the merged rows are ordered newest first.
     *
     * @param int|null $submittedBy Registry desk to filter on, or null for all records
     * @param string   $status      Optional status filter ('' for any status)
     * @param string   $type        Optional event type key ('birth', 'death', 'marriage', 'divorce')
     * @param string   $query       Optional text matched against the registration number and name
     * @return array<int, array<string, mixed>>
     */
    public static function listRecords(
        ?int $submittedBy = null,
        string $status = '',
        string $type = '',
        string $query = '',
        int $limit = 200
    ): array {
        $types = ($type !== '' && isset(RecordTypes::ALL[$type])) ? [$type => RecordTypes::ALL[$type]] : RecordTypes::ALL;
        $perType = (int) max(1, min(500, $limit));

        $rows = [];
        foreach ($types as $key => $meta) {
            $table = $meta['table'];
            $nameCol = $meta['name'];

            $sql = "SELECT r.id, '{$key}' AS record_type, r.registration_number,
                           r.{$nameCol} AS primary_name, r.status, r.registration_date,
                           r.created_at, r.submitted_by, d.name AS district_name,
                           u.full_name AS submitted_by_name
                    FROM {$table} r
                    LEFT JOIN districts d ON d.id = r.district_id
                    LEFT JOIN users u ON u.id = r.submitted_by
                    WHERE r.is_archived = 0";
            $params = [];

            if ($submittedBy !== null) {
                $sql .= ' AND r.submitted_by = ?';
                $params[] = $submittedBy;
            }
            if ($status !== '') {
                $sql .= ' AND r.status = ?';
                $params[] = $status;
            }
            if ($query !== '') {
                $sql .= " AND (r.registration_number LIKE ? OR r.{$nameCol} LIKE ?)";
                $params[] = "%{$query}%";
                $params[] = "%{$query}%";
            }
            $sql .= " ORDER BY r.created_at DESC LIMIT {$perType}";

            foreach (Database::fetchAll($sql, $params) as $row) {
                $rows[] = $row;
            }
        }

        // A static closure rather than a static arrow function carrying both parameter and
        // return types: that exact form fails to parse on this PHP build (8.2.12 ZTS) with a
        // misleading "Unclosed '{'" error, even though the equivalent untyped arrow function
        // and the equivalent closure both parse.
        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) $b['created_at'], (string) $a['created_at']);
        });
        return $rows;
    }

    /**
     * Find a record by registration number across all record types.
     */
    public static function findByRegistrationNumber(string $registrationNumber): ?array
    {
        $reg = trim($registrationNumber);
        foreach (RecordTypes::ALL as $type => $meta) {
            $table = $meta['table'];
            $row = Database::fetchOne("SELECT *, '{$type}' AS record_type FROM {$table} WHERE registration_number = ? AND is_archived = 0", [$reg]);
            if ($row) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Check duplicate candidate for duplicate prevention.
     */
    public static function checkDuplicate(string $type, string $primaryName, string $date, ?int $excludeId = null): ?array
    {
        $table = RecordTypes::table($type);
        $nameCol = match ($type) {
            'birth'    => 'child_full_name',
            'death'    => 'deceased_full_name',
            'marriage' => 'husband_full_name',
            'divorce'  => 'husband_full_name',
        };
        $dateCol = match ($type) {
            'birth'    => 'date_of_birth',
            'death'    => 'date_of_death',
            'marriage' => 'marriage_date',
            'divorce'  => 'divorce_date',
        };

        $sql = "SELECT id, registration_number, {$nameCol} AS full_name, {$dateCol} AS record_date, status
                FROM {$table}
                WHERE LOWER(TRIM({$nameCol})) = LOWER(TRIM(?))
                  AND {$dateCol} = ?
                  AND is_archived = 0";
        $params = [$primaryName, $date];

        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }

        $sql .= ' ORDER BY id DESC';

        return Database::fetchOne($sql, $params);
    }

    /**
     * A divorce is a duplicate when another divorce already references the same marriage.
     */
    public static function checkDuplicateByMarriageReference(string $marriageRegNumber, ?int $excludeId = null): ?array
    {
        $sql = "SELECT id, registration_number, marriage_registration_number AS full_name, divorce_date AS record_date, status
                FROM divorce_records
                WHERE LOWER(TRIM(marriage_registration_number)) = LOWER(TRIM(?))
                  AND is_archived = 0";
        $params = [$marriageRegNumber];

        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }

        $sql .= ' ORDER BY id DESC';

        return Database::fetchOne($sql, $params);
    }

    /**
     * Normalise submitted values for storage: trim text, cast the district,
     * turn empty optional values into NULL and keep the registration number.
     */
    public static function normalize(string $type, array $data): array
    {
        $clean = [];
        foreach (RecordTypes::fields($type) as $name => $field) {
            $value = $data[$name] ?? null;
            $typeName = $field['type'] ?? 'text';

            if ($typeName === 'district') {
                $clean[$name] = (int) ($value ?: 0);
                continue;
            }

            $value = trim((string) ($value ?? ''));

            if ($value === '') {
                // Required fields are rejected by the validator before reaching here.
                $clean[$name] = null;
                continue;
            }

            if (in_array($typeName, ['gender', 'marital'], true)) {
                $clean[$name] = $value;
                continue;
            }

            // TEXT columns have no practical limit; only bound what the schema bounds.
            $limit = $field['max'] ?? null;
            $clean[$name] = $limit ? mb_substr($value, 0, (int) $limit) : $value;
        }

        return $clean;
    }

    /**
     * Update an existing record. Only fields declared in RecordTypes are written,
     * so a crafted form can never touch status, approval or audit columns.
     */
    public static function update(string $type, int $id, array $data): bool
    {
        $record = self::find($type, $id);
        if (!$record) {
            throw new RuntimeException("Record not found.");
        }
        if (!self::canEdit($record)) {
            throw new RuntimeException("You do not have permission to edit this record.");
        }

        $table = RecordTypes::table($type);
        $clean = self::normalize($type, $data);

        $changed = [];
        $set = [];
        $params = [];
        foreach ($clean as $column => $value) {
            $before = (string) ($record[$column] ?? '');
            $after  = (string) ($value ?? '');
            if ($before === $after) {
                continue;
            }
            $changed[] = $column;
            $set[] = "{$column} = ?";
            $params[] = $value;
        }

        if ($set === []) {
            return false;
        }

        $params[] = $id;
        Database::execute("UPDATE {$table} SET " . implode(', ', $set) . " WHERE id = ?", $params);

        self::logStatusChange($type, $id, 'updated', 'Record details updated: ' . implode(', ', $changed));
        Audit::log(
            'update',
            $type,
            "Updated {$type} record {$record['registration_number']} (" . implode(', ', $changed) . ')',
            $type,
            $id
        );

        return true;
    }

    /**
     * Record status history entry (append-only).
     */
    public static function logStatusChange(string $type, int $recordId, string $status, ?string $comment, ?int $userId = null): void
    {
        $userId ??= (int) (Auth::id() ?? 1);
        Database::execute(
            'INSERT INTO record_status_history (record_type, record_id, status, comment, user_id, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$type, $recordId, $status, $comment, $userId]
        );
    }

    /**
     * Fetch status history entries for a record.
     */
    public static function getHistory(string $type, int $recordId): array
    {
        return Database::fetchAll(
            'SELECT h.*, u.full_name AS user_full_name, u.username
             FROM record_status_history h
             LEFT JOIN users u ON u.id = h.user_id
             WHERE h.record_type = ? AND h.record_id = ?
             ORDER BY h.created_at ASC, h.id ASC',
            [$type, $recordId]
        );
    }

    /**
     * Check if the current user can edit a record.
     * - Officer can edit ONLY their own record while in 'draft' or 'correction_required' status.
     * - Supervisor / Admin can edit ANY record that is not yet 'approved'.
     * - Approved records are locked for all roles.
     */
    public static function canEdit(array $record): bool
    {
        if (($record['status'] ?? '') === 'approved' || !empty($record['is_archived'])) {
            return false;
        }

        $user = Auth::user();
        if (!$user) {
            return false;
        }

        $role = $user['role_name'] ?? '';
        if ($role === 'supervisor' || $role === 'administrator') {
            return true;
        }

        if ($role === 'officer') {
            $isOwner = (int) ($record['submitted_by'] ?? 0) === (int) $user['id'];
            $allowedStatus = in_array($record['status'] ?? '', ['draft', 'correction_required'], true);
            return $isOwner && $allowedStatus;
        }

        return false;
    }

    /**
     * Check if the current user can submit a record for supervisor review.
     * - Officer can submit only their own record in 'draft' or 'correction_required' status.
     * - Supervisor / Admin can submit ANY record that is not yet approved.
     */
    public static function canSubmit(array $record): bool
    {
        if (!empty($record['is_archived']) || !in_array($record['status'] ?? '', ['draft', 'correction_required'], true)) {
            return false;
        }

        $user = Auth::user();
        if (!$user) {
            return false;
        }

        $role = $user['role_name'] ?? '';
        if ($role === 'supervisor' || $role === 'administrator') {
            return true;
        }

        if ($role === 'officer') {
            return (int) ($record['submitted_by'] ?? 0) === (int) $user['id'];
        }

        return false;
    }

    /**
     * Check if the current user can delete (archive) a record.
     * - Officer can delete ONLY their own 'draft' records.
     * - Supervisor / Admin can delete (archive) ANY record that is not yet 'approved'.
     * - Approved records can NEVER be deleted.
     */
    public static function canDelete(array $record): bool
    {
        if (($record['status'] ?? '') === 'approved' || !empty($record['is_archived'])) {
            return false;
        }

        $user = Auth::user();
        if (!$user) {
            return false;
        }

        $role = $user['role_name'] ?? '';
        if ($role === 'supervisor' || $role === 'administrator') {
            return true;
        }

        if ($role === 'officer') {
            $isOwner = (int) ($record['submitted_by'] ?? 0) === (int) $user['id'];
            return $isOwner && ($record['status'] ?? '') === 'draft';
        }

        return false;
    }

    /**
     * Soft delete / archive a record with confirmation and audit logging.
     */
    public static function deleteRecord(string $type, int $id, string $reason = ''): bool
    {
        $record = self::find($type, $id);
        if (!$record) {
            throw new RuntimeException("Record not found.");
        }

        if (!self::canDelete($record)) {
            throw new RuntimeException("You do not have permission to delete this record.");
        }

        $table = RecordTypes::table($type);
        Database::execute("UPDATE {$table} SET is_archived = 1 WHERE id = ?", [$id]);

        self::logStatusChange($type, $id, 'archived', 'Record archived: ' . ($reason ?: 'No reason provided'));

        Audit::log(
            'delete',
            $type,
            "Archived {$type} record {$record['registration_number']}. Reason: " . ($reason ?: 'N/A'),
            $type,
            $id
        );

        return true;
    }

    /**
     * Create Birth Record.
     */
    public static function createBirth(array $data, ?array $file = null, bool $submit = false): array
    {
        $userId = (int) (Auth::id() ?? 1);
        $regNum = self::nextRegistrationNumber('birth');
        // A new record is always inserted as a draft. ApprovalService::submit() owns the
        // transition to 'submitted' (status history, approvals row, supervisor notification),
        // so writing 'submitted' here made submit() reject the record it had just created
        // ("cannot be submitted in its current status: submitted") and the page reported a
        // failure for a registration that had in fact been saved.
        $storedStatus = 'draft';
        $status = $submit ? 'submitted' : 'draft';

        $photoDocId = null;
        if ($file && isset($file['error']) && $file['error'] === UPLOAD_ERR_OK) {
            $photoDocId = UploadService::handleUpload($file, 'birth');
        }

        Database::execute(
            'INSERT INTO birth_records (
                registration_number, child_full_name, gender, date_of_birth, time_of_birth,
                place_of_birth, hospital_of_birth, district_id, father_full_name, father_nationality,
                father_reference, father_phone, mother_full_name, mother_nationality, mother_reference,
                mother_phone, registration_date, photo_document_id, status, notes, submitted_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $regNum,
                trim((string) $data['child_full_name']),
                $data['gender'],
                $data['date_of_birth'],
                !empty($data['time_of_birth']) ? $data['time_of_birth'] : null,
                trim((string) $data['place_of_birth']),
                trim((string) $data['hospital_of_birth']),
                (int) $data['district_id'],
                trim((string) $data['father_full_name']),
                trim((string) ($data['father_nationality'] ?? 'Somali')) ?: 'Somali',
                trim((string) ($data['father_reference'] ?? '')) ?: null,
                trim((string) ($data['father_phone'] ?? '')) ?: null,
                trim((string) $data['mother_full_name']),
                trim((string) ($data['mother_nationality'] ?? 'Somali')) ?: 'Somali',
                trim((string) ($data['mother_reference'] ?? '')) ?: null,
                trim((string) ($data['mother_phone'] ?? '')) ?: null,
                $data['registration_date'] ?? date('Y-m-d'),
                $photoDocId,
                $storedStatus,
                trim((string) ($data['notes'] ?? '')) ?: null,
                $userId,
            ]
        );

        $id = (int) Database::lastId();

        if ($photoDocId !== null) {
            Database::execute('UPDATE supporting_documents SET record_id = ? WHERE id = ?', [$id, $photoDocId]);
        }

        self::logStatusChange('birth', $id, $storedStatus, 'Saved as draft', $userId);
        Audit::log('create', 'birth', "Registered birth record {$regNum} ({$status})", 'birth', $id);

        if ($submit) {
            ApprovalService::submit('birth', $id, $data['notes'] ?? null);
        }

        return ['id' => $id, 'registration_number' => $regNum, 'status' => $status];
    }

    /**
     * Create Death Record.
     */
    public static function createDeath(array $data, bool $submit = false): array
    {
        $userId = (int) (Auth::id() ?? 1);
        $regNum = self::nextRegistrationNumber('death');
        $storedStatus = 'draft';   // see createBirth(): submit() owns the transition to 'submitted'
        $status = $submit ? 'submitted' : 'draft';

        Database::execute(
            'INSERT INTO death_records (
                registration_number, deceased_full_name, gender, date_of_birth, date_of_death,
                time_of_death, place_of_death, district_id, guarantor_full_name, hospital_document_reference,
                nationality, reference_number, marital_status, spouse_name, informant_name,
                informant_relationship, informant_phone, cause_of_death, registration_date,
                status, notes, submitted_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $regNum,
                trim((string) $data['deceased_full_name']),
                $data['gender'],
                $data['date_of_birth'],
                $data['date_of_death'],
                !empty($data['time_of_death']) ? $data['time_of_death'] : null,
                trim((string) $data['place_of_death']),
                (int) $data['district_id'],
                trim((string) $data['guarantor_full_name']),
                trim((string) ($data['hospital_document_reference'] ?? '')) ?: null,
                trim((string) ($data['nationality'] ?? 'Somali')) ?: 'Somali',
                trim((string) ($data['reference_number'] ?? '')) ?: null,
                !empty($data['marital_status']) ? $data['marital_status'] : null,
                trim((string) ($data['spouse_name'] ?? '')) ?: null,
                trim((string) $data['informant_name']),
                trim((string) ($data['informant_relationship'] ?? '')) ?: null,
                trim((string) ($data['informant_phone'] ?? '')) ?: null,
                trim((string) ($data['cause_of_death'] ?? '')) ?: null,
                $data['registration_date'] ?? date('Y-m-d'),
                $storedStatus,
                trim((string) ($data['notes'] ?? '')) ?: null,
                $userId,
            ]
        );

        $id = (int) Database::lastId();
        self::logStatusChange('death', $id, $storedStatus, 'Saved as draft', $userId);
        Audit::log('create', 'death', "Registered death record {$regNum} ({$status})", 'death', $id);

        if ($submit) {
            ApprovalService::submit('death', $id, $data['notes'] ?? null);
        }

        return ['id' => $id, 'registration_number' => $regNum, 'status' => $status];
    }

    /**
     * Create Marriage Record.
     */
    public static function createMarriage(array $data, bool $submit = false): array
    {
        $userId = (int) (Auth::id() ?? 1);
        $regNum = self::nextRegistrationNumber('marriage');
        $storedStatus = 'draft';   // see createBirth(): submit() owns the transition to 'submitted'
        $status = $submit ? 'submitted' : 'draft';

        Database::execute(
            'INSERT INTO marriage_records (
                registration_number, husband_full_name, husband_date_of_birth, husband_nationality,
                husband_reference, husband_phone, husband_address, wife_full_name, wife_date_of_birth,
                wife_nationality, wife_reference, wife_phone, wife_address, marriage_date,
                place_of_marriage, district_id, mahr_amount, witness1_full_name, witness1_phone,
                witness2_full_name, witness2_phone, registration_date, status, notes, submitted_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $regNum,
                trim((string) $data['husband_full_name']),
                $data['husband_date_of_birth'],
                trim((string) ($data['husband_nationality'] ?? 'Somali')) ?: 'Somali',
                trim((string) ($data['husband_reference'] ?? '')) ?: null,
                trim((string) $data['husband_phone']),
                trim((string) ($data['husband_address'] ?? '')) ?: null,
                trim((string) $data['wife_full_name']),
                $data['wife_date_of_birth'],
                trim((string) ($data['wife_nationality'] ?? 'Somali')) ?: 'Somali',
                trim((string) ($data['wife_reference'] ?? '')) ?: null,
                trim((string) $data['wife_phone']),
                trim((string) ($data['wife_address'] ?? '')) ?: null,
                $data['marriage_date'],
                trim((string) $data['place_of_marriage']),
                (int) $data['district_id'],
                trim((string) $data['mahr_amount']),
                trim((string) $data['witness1_full_name']),
                trim((string) $data['witness1_phone']),
                trim((string) $data['witness2_full_name']),
                trim((string) $data['witness2_phone']),
                $data['registration_date'] ?? date('Y-m-d'),
                $storedStatus,
                trim((string) ($data['notes'] ?? '')) ?: null,
                $userId,
            ]
        );

        $id = (int) Database::lastId();
        self::logStatusChange('marriage', $id, $storedStatus, 'Saved as draft', $userId);
        Audit::log('create', 'marriage', "Registered marriage record {$regNum} ({$status})", 'marriage', $id);

        if ($submit) {
            ApprovalService::submit('marriage', $id, $data['notes'] ?? null);
        }

        return ['id' => $id, 'registration_number' => $regNum, 'status' => $status];
    }

    /**
     * Create Divorce Record.
     */
    public static function createDivorce(array $data, bool $submit = false): array
    {
        $userId = (int) (Auth::id() ?? 1);
        $regNum = self::nextRegistrationNumber('divorce');
        $storedStatus = 'draft';   // see createBirth(): submit() owns the transition to 'submitted'
        $status = $submit ? 'submitted' : 'draft';

        Database::execute(
            'INSERT INTO divorce_records (
                registration_number, marriage_registration_number, husband_full_name,
                husband_date_of_birth, wife_full_name, wife_date_of_birth, divorce_date,
                district_id, divorce_reason, court_reference, registration_date,
                status, notes, submitted_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $regNum,
                trim((string) $data['marriage_registration_number']),
                trim((string) $data['husband_full_name']),
                $data['husband_date_of_birth'],
                trim((string) $data['wife_full_name']),
                $data['wife_date_of_birth'],
                $data['divorce_date'],
                (int) $data['district_id'],
                trim((string) ($data['divorce_reason'] ?? '')) ?: null,
                trim((string) ($data['court_reference'] ?? '')) ?: null,
                $data['registration_date'] ?? date('Y-m-d'),
                $storedStatus,
                trim((string) ($data['notes'] ?? '')) ?: null,
                $userId,
            ]
        );

        $id = (int) Database::lastId();
        self::logStatusChange('divorce', $id, $storedStatus, 'Saved as draft', $userId);
        Audit::log('create', 'divorce', "Registered divorce record {$regNum} ({$status})", 'divorce', $id);

        if ($submit) {
            ApprovalService::submit('divorce', $id, $data['notes'] ?? null);
        }

        return ['id' => $id, 'registration_number' => $regNum, 'status' => $status];
    }
}
