<?php
declare(strict_types=1);
/** Record listing: the query behind "My Records" and the manager's "All Records". */

use App\Core\{Auth, Database};
use App\Services\RecordService;

echo "\nRecord Listing Tests\n";
// Both listing screens read from RecordService::listRecords(): the officer's "My Records /
// All Records" switch and public/records/all-records.php. A record can be reached from either,
// so the filters have to agree with what was actually stored.

$deskA = 'faduma';
$deskB = 'mahad';

/** One row of a listing, found by registration number. */
function listedRow(string $registrationNumber): ?array
{
    foreach (RecordService::listRecords(null, '', '', $registrationNumber, 10) as $row) {
        return $row;
    }
    return null;
}

Auth::logout(false);
Auth::attempt($deskA, 'ChangeMe#2026');
$deskAId = (int) Auth::id();
$mineA = RecordService::createBirth([
    'child_full_name'    => 'Listing Desk A Child',
    'gender'             => 'female',
    'date_of_birth'      => '2026-01-15',
    'district_id'        => 1,
    'place_of_birth'     => 'Listing Clinic',
    'hospital_of_birth'  => 'Listing Hospital',
    'father_full_name'   => 'Desk A Father',
    'father_nationality' => 'Somali',
    'mother_full_name'   => 'Desk A Mother',
    'mother_nationality' => 'Somali',
    'registration_date'  => '2026-09-26',
]);

Auth::logout(false);
Auth::attempt($deskB, 'ChangeMe#2026');
$deskBId = (int) Auth::id();
$mineB = RecordService::createBirth([
    'child_full_name'    => 'Listing Desk B Child',
    'gender'             => 'male',
    'date_of_birth'      => '2026-02-20',
    'district_id'        => 1,
    'place_of_birth'     => 'Listing Clinic',
    'hospital_of_birth'  => 'Listing Hospital',
    'father_full_name'   => 'Desk B Father',
    'father_nationality' => 'Somali',
    'mother_full_name'   => 'Desk B Mother',
    'mother_nationality' => 'Somali',
    'registration_date'  => '2026-09-26',
]);
// Registered by the second desk, so it is a record desk A must not see in its own listing.
$other = RecordService::createDeath([
    'deceased_full_name'     => 'Listing Deceased',
    'gender'                 => 'male',
    'date_of_birth'          => '1950-03-03',
    'date_of_death'          => '2026-03-03',
    'place_of_death'         => 'Listing Hospital',
    'district_id'            => 1,
    'cause_of_death'         => 'Natural',
    'guarantor_full_name'    => 'Listing Guarantor',
    'informant_name'         => 'Listing Informant',
    'informant_relationship' => 'Son',
    'registration_date'      => '2026-09-26',
]);

check('a desk sees only the records it registered', (function () use ($deskAId, $mineA, $mineB, $other): bool {
    $numbers = array_column(RecordService::listRecords($deskAId, '', '', '', 500), 'registration_number');
    return in_array($mineA['registration_number'], $numbers, true)
        && !in_array($mineB['registration_number'], $numbers, true)
        && !in_array($other['registration_number'], $numbers, true);
})());

check('the all-records list spans every desk and event type', (function () use ($mineA, $mineB, $other): bool {
    $all = RecordService::listRecords(null, '', '', '', 500);
    $numbers = array_column($all, 'registration_number');
    $types = array_unique(array_column($all, 'record_type'));
    return in_array($mineA['registration_number'], $numbers, true)
        && in_array($mineB['registration_number'], $numbers, true)
        && in_array($other['registration_number'], $numbers, true)
        && in_array('birth', $types, true)
        && in_array('death', $types, true);
})());

check('every listed row carries the columns both screens render', (function () use ($mineA): bool {
    $row = listedRow((string) $mineA['registration_number']);
    if ($row === null) {
        return false;
    }
    foreach (['id', 'record_type', 'registration_number', 'primary_name', 'status', 'registration_date', 'district_name', 'submitted_by', 'submitted_by_name'] as $column) {
        if (!array_key_exists($column, $row)) {
            return false;
        }
    }
    return $row['primary_name'] === 'Listing Desk A Child'
        && $row['record_type'] === 'birth'
        && (int) $row['submitted_by'] > 0
        && (string) $row['submitted_by_name'] !== '';
})());

check('a registration number search finds exactly that record', count(RecordService::listRecords(null, '', '', (string) $other['registration_number'], 10)) === 1);

check('a name search matches the record it belongs to', (function () use ($other): bool {
    $rows = RecordService::listRecords(null, '', 'death', 'Listing Deceased', 10);
    return count($rows) === 1 && $rows[0]['registration_number'] === $other['registration_number'];
})());

check('the event type filter narrows the list to one type', (function () use ($mineA, $other): bool {
    $rows = RecordService::listRecords(null, '', 'death', '', 500);
    $numbers = array_column($rows, 'registration_number');
    return in_array($other['registration_number'], $numbers, true)
        && !in_array($mineA['registration_number'], $numbers, true)
        && array_values(array_unique(array_column($rows, 'record_type'))) === ['death'];
})());

check('the status filter matches the stored status', (function () use ($deskAId, $mineA): bool {
    $drafts = RecordService::listRecords($deskAId, 'draft', '', '', 500);
    $numbers = array_column($drafts, 'registration_number');
    if (!in_array($mineA['registration_number'], $numbers, true)) {
        return false;
    }
    foreach ($drafts as $row) {
        if ($row['status'] !== 'draft') {
            return false;
        }
    }
    return !in_array($mineA['registration_number'], array_column(RecordService::listRecords($deskAId, 'approved', '', '', 500), 'registration_number'), true);
})());

check('rows come back newest first', (function () use ($deskAId): bool {
    $rows = RecordService::listRecords($deskAId, '', '', '', 500);
    if (count($rows) < 2) {
        return false;
    }
    for ($i = 1; $i < count($rows); $i++) {
        if (strcmp((string) $rows[$i - 1]['created_at'], (string) $rows[$i]['created_at']) < 0) {
            return false;
        }
    }
    return true;
})());

check('an archived record disappears from the listings', (function () use ($deskAId, $mineA): bool {
    Database::execute('UPDATE birth_records SET is_archived = 1 WHERE id = ?', [(int) $mineA['id']]);
    try {
        $numbers = array_column(RecordService::listRecords($deskAId, '', '', '', 500), 'registration_number');
        return !in_array($mineA['registration_number'], $numbers, true);
    } finally {
        Database::execute('UPDATE birth_records SET is_archived = 0 WHERE id = ?', [(int) $mineA['id']]);
    }
})());

check('an unknown event type falls back to the full list instead of failing', (function () use ($deskAId, $mineA): bool {
    $rows = RecordService::listRecords($deskAId, '', 'marriage; DROP TABLE birth_records', '', 500);
    return $rows !== [] && (int) Database::scalar('SELECT COUNT(*) FROM birth_records') > 0;
})());

check('a listed record stops being editable once approved', (function () use ($mineA): bool {
    $row = listedRow((string) $mineA['registration_number']);
    if ($row === null) {
        return false;
    }
    Database::execute('UPDATE birth_records SET status = ? WHERE id = ?', ['approved', (int) $mineA['id']]);
    try {
        return !RecordService::canEdit($row);
    } finally {
        Database::execute('UPDATE birth_records SET status = ? WHERE id = ?', ['draft', (int) $mineA['id']]);
    }
})());

echo "\nAll Records Screen Tests\n";
// The two listing screens are pages, and a page loads app/bootstrap.php itself, so each one is
// rendered in its own process with the signed-in user handed over through the session. A wrong
// role ends the request with exit(), which would take the whole run down if it happened here.
$userSession = static function (string $username): string {
    $restore = Auth::user();
    Auth::logout(false);
    Auth::attempt($username, 'ChangeMe#2026');
    $payload = json_encode(Auth::user(), JSON_THROW_ON_ERROR);
    if ($restore !== null) {
        $_SESSION['user'] = $restore;
    }
    return (string) $payload;
};

$renderPage = static function (string $userJson, string $page, string $query): array {
    $result = runSnippet(
        'session_id("crscreen" . getmypid());' . "\n"
        . 'session_start();' . "\n"
        . '$_SESSION["user"] = json_decode(' . var_export($userJson, true) . ', true);' . "\n"
        . '$_SERVER["REQUEST_URI"] = ' . var_export($query, true) . ';' . "\n"
        . 'parse_str((string) parse_url(' . var_export($query, true) . ', PHP_URL_QUERY), $_GET);' . "\n"
        . 'ob_start();' . "\n"
        . 'require ' . var_export(BASE_PATH . '/public/' . $page, true) . ';' . "\n"
        . 'file_put_contents(SNIPPET_OUT, (string) ob_get_clean());',
        false
    );
    return ['html' => $result['file'] !== '' ? $result['file'] : $result['stdout'], 'file' => $result['file'], 'stdout' => $result['stdout']];
};

$officerSession = $userSession('faduma');
$supervisorSession = $userSession('abdi');

$mineScreen = $renderPage($officerSession, 'officer/my-records.php', '/officer/my-records.php?scope=mine');
check('the officer "My Records" screen renders their own records only', str_contains($mineScreen['html'], 'My Registered Records')
    && !str_contains($mineScreen['html'], 'Registered By')
    && !str_contains($mineScreen['html'], 'Listing Desk B Child'));

$allScreen = $renderPage($officerSession, 'officer/my-records.php', '/officer/my-records.php?scope=all');
check('the officer "All Records" tab renders every desk with its owner', str_contains($allScreen['html'], 'Registered By')
    && str_contains($allScreen['html'], 'Listing Desk B Child')
    && str_contains($allScreen['html'], 'All Records ('));

$managerScreen = $renderPage($supervisorSession, 'records/all-records.php', '/records/all-records.php');
check('the manager All Records screen renders the registry list', str_contains($managerScreen['html'], 'All Records')
    && str_contains($managerScreen['html'], 'Registered By')
    && str_contains($managerScreen['html'], 'Registration desk')
    && str_contains($managerScreen['html'], 'Listing Desk A Child'));

$filteredScreen = $renderPage($supervisorSession, 'records/all-records.php', '/records/all-records.php?type=death&q=Listing');
check('the manager screen honours the event type and search filters', str_contains($filteredScreen['html'], 'Listing Deceased')
    && !str_contains($filteredScreen['html'], 'Listing Desk A Child'));

// The All Records screen is gated on the permission behind it, not on the administrator,
// so the caller that has to be turned away is an officer without record.view on that desk.
$denied = $renderPage($officerSession, 'records/all-records.php', '/records/all-records.php');
check('an officer is refused the manager All Records screen with 403', $denied['file'] === '' && str_contains($denied['stdout'], '403'), $denied['stdout']);

// Leave nothing behind: these rows are also in the runner's fixture list, which clears them
// at the start of the next run if this one is interrupted.
foreach ([['birth_records', (int) $mineA['id']], ['birth_records', (int) $mineB['id']], ['death_records', (int) $other['id']]] as [$table, $id]) {
    Database::execute('DELETE FROM record_status_history WHERE record_type = ? AND record_id = ?', [str_starts_with($table, 'birth') ? 'birth' : 'death', $id]);
    Database::execute("DELETE FROM {$table} WHERE id = ?", [$id]);
}
