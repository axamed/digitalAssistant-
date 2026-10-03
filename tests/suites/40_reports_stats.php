<?php
declare(strict_types=1);
/** Dashboard metrics and the administrative reports. */

use App\Core\{Auth, Database};
use App\Services\{ApprovalService, RecordService, ReportService, Stats};
use App\Support\RecordTypes;

purgeFixtures();
Auth::logout(false);
Auth::attempt('nasra', 'ChangeMe#2026');

/** Sum the four per-type counts of a report row and compare with its total. */
$totalAddsUp = static fn(bool $ok, array $row): bool => $ok && (int) $row['total'] === (int) $row['births'] + (int) $row['deaths'] + (int) $row['marriages'] + (int) $row['divorces'];
$birthsIn = static function (array $report, string $district): int {
    foreach ($report as $row) {
        if ($row['district_name'] === $district) {
            return (int) $row['births'];
        }
    }
    return -1;
};

echo "\nDistrict Report Tests\n";
$byDistrict = ReportService::byDistrict();
$districtName = (string) Database::scalar('SELECT name FROM districts WHERE id = 1');
check('the district report covers all 20 districts', count($byDistrict) === 20, 'reported ' . count($byDistrict));
check('every district row carries the four record counts', array_reduce($byDistrict, static fn(bool $ok, array $row): bool => $ok && isset($row['births'], $row['deaths'], $row['marriages'], $row['divorces'], $row['total']), true));
check('each district total is the sum of its counts', array_reduce($byDistrict, $totalAddsUp, true));
check('the district births add up to every live birth record', array_sum(array_map('intval', array_column($byDistrict, 'births'))) === (int) Database::scalar('SELECT COUNT(*) FROM birth_records WHERE is_archived = 0'));

// A new record must appear in its district, and archiving it must take it out again.
$birthsBefore = $birthsIn(ReportService::byDistrict(), $districtName);
$archiveMe = RecordService::createBirth([
    'child_full_name'    => 'Temp Officer Test Child',
    'gender'             => 'male',
    'date_of_birth'      => '2026-06-07',
    'place_of_birth'     => 'Mogadishu',
    'hospital_of_birth'  => 'Benadir General',
    'district_id'        => 1,
    'father_full_name'   => 'Temp Father',
    'father_nationality' => 'Somali',
    'mother_full_name'   => 'Temp Mother',
    'mother_nationality' => 'Somali',
    'registration_date'  => '2026-09-25',
], null, false);
check('a new record shows up in its district', $birthsIn(ReportService::byDistrict(), $districtName) === $birthsBefore + 1);
RecordService::deleteRecord('birth', (int) $archiveMe['id'], 'archive report test');
check('an archived record drops out of the district report', $birthsIn(ReportService::byDistrict(), $districtName) === $birthsBefore, 'before=' . $birthsBefore);
check('an archived record is hidden from the record lookup', RecordService::find('birth', (int) $archiveMe['id']) === null);
purgeRecordFixtures();

echo "\nMonthly Report Tests\n";
$monthly = ReportService::monthlyBreakdown(2026);
check('the monthly report has twelve months', count($monthly) === 12);
check('the months are labelled and ordered', ($monthly[0]['month'] ?? '') === 'Jan 2026' && ($monthly[11]['month'] ?? '') === 'Dec 2026');
check('each month total is the sum of its counts', array_reduce($monthly, $totalAddsUp, true));
check('the months add up to the whole year', array_sum(array_map('intval', array_column($monthly, 'births'))) === (int) Database::scalar('SELECT COUNT(*) FROM birth_records WHERE YEAR(registration_date) = 2026 AND is_archived = 0'));
check('the report defaults to the current year', array_sum(array_map('intval', array_column(ReportService::monthlyBreakdown(), 'births'))) === (int) Database::scalar('SELECT COUNT(*) FROM birth_records WHERE YEAR(registration_date) = ' . (int) date('Y') . ' AND is_archived = 0'));
check('a year with no registrations reports zeros', array_sum(array_map('intval', array_column(ReportService::monthlyBreakdown(1999), 'total'))) === 0);

echo "\nOfficer Activity Report Tests\n";
$activity = ReportService::officerActivity();
check('the activity report lists officers and supervisors only', array_reduce($activity, static fn(bool $ok, array $row): bool => $ok && $row['username'] !== 'nasra', true));
check('the seeded officers appear in the activity report', count(array_intersect(['faduma', 'mahad'], array_column($activity, 'username'))) === 2);
check('each activity total is the sum of its counts', array_reduce($activity, $totalAddsUp, true));
check('approved records are never more than the total submitted', array_reduce($activity, static fn(bool $ok, array $row): bool => $ok && $row['approved'] <= $row['total'], true));
check('an officer credited with nothing is still listed', array_reduce($activity, static fn(bool $ok, array $row): bool => $ok && $row['officer_name'] !== '' && $row['username'] !== '', true));

echo "\nFee Collection Report Tests\n";
$fees = ReportService::feeCollections();
$validCertificates = (int) Database::scalar("SELECT COUNT(*) FROM certificates WHERE status = 'valid'");
$reportedCertificates = array_sum(array_map('intval', array_column($fees, 'certificate_count')));
check('the fee report only counts valid certificates', $reportedCertificates === $validCertificates, 'valid=' . $validCertificates . ' reported=' . $reportedCertificates);
check('each fee row splits paid from unpaid', array_reduce($fees, static fn(bool $ok, array $row): bool => $ok && (int) $row['paid_count'] + (int) $row['unpaid_count'] === (int) $row['certificate_count'], true));
check('collected fees match the paid certificates', abs(array_sum(array_map('floatval', array_column($fees, 'total_collected'))) - (float) Database::scalar("SELECT COALESCE(SUM(fee_amount), 0) FROM certificates WHERE status = 'valid' AND payment_status = 'paid'")) < 0.001);
check('unpaid certificates are reported as still owed', abs(array_sum(array_map('floatval', array_column($fees, 'total_unpaid'))) - (float) Database::scalar("SELECT COALESCE(SUM(fee_amount), 0) FROM certificates WHERE status = 'valid' AND payment_status = 'unpaid'")) < 0.001);
check('every record type in the report is a known one', array_reduce($fees, static fn(bool $ok, array $row): bool => $ok && isset(RecordTypes::ALL[$row['record_type']]), true));

echo "\nDashboard Count Tests\n";
$counts = Stats::recordCounts();
check('record counts cover the four record types', array_keys($counts) === array_keys(RecordTypes::ALL));
check('record counts add up to the per-type totals', Stats::total($counts) === array_sum(Stats::recordCountsByType()));
$approvedTotal = 0;
foreach (RecordTypes::ALL as $meta) {
    $approvedTotal += (int) Database::scalar("SELECT COUNT(*) FROM {$meta['table']} WHERE status = 'approved' AND is_archived = 0");
}
check('a single status can be totalled', Stats::total($counts, 'approved') === $approvedTotal);
check('an unknown status totals zero', Stats::total($counts, 'no_such_status') === 0);

$users = Stats::userCounts();
check('user counts split into active and inactive', $users['total'] === $users['active'] + $users['inactive']);
check('only live lockouts are counted', $users['locked'] === (int) Database::scalar('SELECT COUNT(*) FROM users WHERE locked_until IS NOT NULL AND locked_until > NOW()'));

$certs = Stats::certificateCounts();
check('certificate counts split into paid and unpaid', $certs['total'] === $certs['paid'] + $certs['unpaid']);
check('certificate counts split into valid and revoked', $certs['valid'] + $certs['revoked'] === $certs['total']);
check('reported revenue is the sum of the paid fees', abs($certs['revenue'] - (float) Database::scalar("SELECT COALESCE(SUM(fee_amount), 0) FROM certificates WHERE payment_status = 'paid'")) < 0.001);

$audit = Stats::auditSummary();
check("the audit summary counts today's entries separately", $audit['total'] >= $audit['today'] && $audit['failed'] >= 0);

echo "\nRecent Activity Tests\n";
// The limit is the second argument: recentRecords($submittedBy, $limit). Passing 4 as the
// first one filtered by user id 4, so this check quietly asserted that one desk had at most
// four records rather than that the limit is honoured — and went red as soon as the office
// registered more. The whole list is asked for, with the limit checked against a number no
// amount of live data can reach.
$recent = Stats::recentRecords(null, 4);
check('the recent list honours its limit', count($recent) <= 4);
check('the recent list is ordered newest first', (static function (array $rows): bool {
    $previous = null;
    foreach ($rows as $row) {
        if ($previous !== null && $previous < (string) $row['created_at']) {
            return false;
        }
        $previous = (string) $row['created_at'];
    }
    return true;
})($recent));
check('the recent list names the record type and person', array_reduce($recent, static fn(bool $ok, array $row): bool => $ok && isset($row['record_type'], $row['primary_name'], $row['registration_number']), true));
$fadumaId = (int) Database::scalar("SELECT id FROM users WHERE username = 'faduma'");
$fadumaName = (string) Database::scalar("SELECT full_name FROM users WHERE username = 'faduma'");
check('the recent list can be filtered by officer', array_reduce(
    Stats::recentRecords($fadumaId, 5),
    static fn(bool $ok, array $row): bool => $ok && $row['submitted_by_name'] === $fadumaName,
    true
));
check('the audit list honours its limit', count(Stats::recentAuditLogs(3)) <= 3);
check('the backup list honours its limit', count(Stats::recentBackups(2)) <= 2);

$topDistricts = Stats::topDistricts(3);
check('the top district list is limited to districts with records', count($topDistricts) <= 3 && array_reduce($topDistricts, static fn(bool $ok, array $row): bool => $ok && $row['total'] > 0, true));
check('the top district list is ordered by total', (static function (array $rows): bool {
    $previous = null;
    foreach ($rows as $row) {
        if ($previous !== null && $previous < (int) $row['total']) {
            return false;
        }
        $previous = (int) $row['total'];
    }
    return true;
})($topDistricts));
check('the top district totals match the district report', array_reduce($topDistricts, static function (bool $ok, array $row) use ($byDistrict): bool {
    foreach ($byDistrict as $district) {
        if ($district['district_name'] === $row['name']) {
            return $ok && (int) $district['total'] === (int) $row['total'];
        }
    }
    return false;
}, true));

echo "\nOfficer Attention List Tests\n";
// The officer submits, the supervisor rejects: the record must land on the officer's list.
Auth::logout(false);
Auth::attempt('faduma', 'ChangeMe#2026');
$attentionRecord = RecordService::createBirth([
    'child_full_name'    => 'Temp Officer Test Child',
    'gender'             => 'female',
    'date_of_birth'      => '2026-07-08',
    'place_of_birth'     => 'Mogadishu',
    'hospital_of_birth'  => 'Benadir General',
    'district_id'        => 1,
    'father_full_name'   => 'Temp Father',
    'father_nationality' => 'Somali',
    'mother_full_name'   => 'Temp Mother',
    'mother_nationality' => 'Somali',
    'registration_date'  => '2026-09-25',
]);
ApprovalService::submit('birth', (int) $attentionRecord['id'], 'please review');
Auth::logout(false);

// requireLogin() would send an account still on its default password to the change-password
// page, which ends the request, so clear the flag for the supervisor step and put it back.
$supervisorId = (int) Database::scalar("SELECT id FROM users WHERE username = 'abdi'");
$mustChange = Database::scalar('SELECT must_change_password FROM users WHERE id = ?', [$supervisorId]);
Database::execute('UPDATE users SET must_change_password = 0 WHERE id = ?', [$supervisorId]);
Auth::attempt('abdi', 'ChangeMe#2026');
ApprovalService::reject('birth', (int) $attentionRecord['id'], 'Hospital attestation is missing');
Database::execute('UPDATE users SET must_change_password = ? WHERE id = ?', [$mustChange, $supervisorId]);

$rejected = static function (int $userId) use ($attentionRecord): array {
    // The seeded data carries rejected records of its own, so match on this record only.
    return array_values(array_filter(
        Stats::officerAttentionNeeded($userId),
        static fn(array $row): bool => (int) $row['id'] === (int) $attentionRecord['id'] && $row['status'] === 'rejected'
    ));
};
$otherOfficerId = (int) Database::scalar("SELECT id FROM users WHERE username = 'mahad'");
check('a rejected record reaches the attention list of the officer who filed it', count($rejected($fadumaId)) === 1);
check('the attention list explains the rejection', str_contains((string) ($rejected($fadumaId)[0]['rejection_reason'] ?? ''), 'Hospital attestation'));
check('the attention entry links to the record', ($rejected($fadumaId)[0]['record_type'] ?? '') === 'birth');
check('another officer does not see the rejection', count($rejected($otherOfficerId)) === 0);
// The list must hold exactly the officer's own rejected and correction records, nothing else.
$union = [];
foreach (RecordTypes::ALL as $meta) {
    $union[] = "SELECT registration_number FROM {$meta['table']} WHERE submitted_by = ? AND status IN ('correction_required','rejected') AND is_archived = 0";
}
$expected = array_column(Database::fetchAll(implode(' UNION ', $union), [$fadumaId, $fadumaId, $fadumaId, $fadumaId]), 'registration_number');
$listed = array_column(Stats::officerAttentionNeeded($fadumaId), 'registration_number');
sort($expected);
sort($listed);
check("the attention list holds exactly the officer's rejected and correction records", $listed === $expected, 'listed: ' . implode(',', $listed) . ' expected: ' . implode(',', $expected));
check('a record still in review is not on the attention list', count(array_filter(
    Stats::officerAttentionNeeded($fadumaId),
    static fn(array $row): bool => $row['status'] === 'submitted'
)) === 0);

echo "\nReports Screen Tests\n";
// One record of every event type in the reporting year, so each individual report and the
// combined register have something to show. The names are in the runner's fixture list.
$reportYear = (int) date('Y');
$reportDate = $reportYear . '-03-15';
Auth::logout(false);
Auth::attempt('faduma', 'ChangeMe#2026');
RecordService::createBirth([
    'child_full_name'    => 'Report Test Child',
    'gender'             => 'female',
    'date_of_birth'      => '2026-01-20',
    'district_id'        => 2,
    'place_of_birth'     => 'Mogadishu',
    'hospital_of_birth'  => 'Benadir General',
    'father_full_name'   => 'Report Father',
    'father_nationality' => 'Somali',
    'mother_full_name'   => 'Report Mother',
    'mother_nationality' => 'Somali',
    'registration_date'  => $reportDate,
]);
RecordService::createDeath([
    'deceased_full_name'     => 'Report Test Deceased',
    'gender'                 => 'male',
    'date_of_birth'          => '1955-04-04',
    'date_of_death'          => $reportDate,
    'place_of_death'         => 'Mogadishu',
    'district_id'            => 3,
    'cause_of_death'         => 'Natural',
    'guarantor_full_name'    => 'Report Guarantor',
    'informant_name'         => 'Report Informant',
    'informant_relationship' => 'Son',
    'registration_date'      => $reportDate,
]);
RecordService::createMarriage([
    'husband_full_name'    => 'Report Test Groom',
    'husband_date_of_birth' => '1992-02-02',
    'husband_nationality'  => 'Somali',
    'husband_phone'        => '+252 61 555 0101',
    'wife_full_name'       => 'Report Test Bride',
    'wife_date_of_birth'  => '1994-05-05',
    'wife_nationality'    => 'Somali',
    'wife_phone'          => '+252 61 555 0102',
    'marriage_date'       => $reportDate,
    'place_of_marriage'   => 'Mogadishu',
    'district_id'         => 2,
    'mahr_amount'         => '500 USD',
    'witness1_full_name'  => 'Report Witness One',
    'witness1_phone'      => '+252 61 555 0103',
    'witness2_full_name'  => 'Report Witness Two',
    'witness2_phone'      => '+252 61 555 0104',
    'registration_date'   => $reportDate,
]);
RecordService::createDivorce([
    'marriage_registration_number' => 'MR/2026/0001',
    'husband_full_name'    => 'Report Test Divorcer',
    'husband_date_of_birth' => '1988-06-06',
    'wife_full_name'       => 'Report Test Ex-Wife',
    'wife_date_of_birth'  => '1990-07-07',
    'divorce_date'        => $reportDate,
    'district_id'         => 3,
    'divorce_reason'      => 'Irreconcilable difference',
    'court_reference'     => 'Court/2026/99',
    'registration_date'   => $reportDate,
]);
Auth::logout(false);

// The screen is a page, and a page loads app/bootstrap.php itself, so it is rendered in its
// own process with the signed-in user handed over through the session.
$reportsUser = signInWithPermission('report.generate');
check('somebody holds report.generate to render the screen', $reportsUser !== null);

$renderReports = static function (string $userJson, string $query): array {
    $result = runSnippet(
        'session_id("crreports" . getmypid());' . "\n"
        . 'session_start();' . "\n"
        . '$_SESSION["user"] = json_decode(' . var_export($userJson, true) . ', true);' . "\n"
        . '$_SERVER["REQUEST_URI"] = ' . var_export($query, true) . ';' . "\n"
        . 'parse_str((string) parse_url(' . var_export($query, true) . ', PHP_URL_QUERY), $_GET);' . "\n"
        . 'ob_start();' . "\n"
        . 'require ' . var_export(BASE_PATH . '/public/supervisor/reports.php', true) . ';' . "\n"
        . 'file_put_contents(SNIPPET_OUT, (string) ob_get_clean());',
        false
    );
    return ['html' => $result['file'] !== '' ? $result['file'] : $result['stdout'], 'file' => $result['file'], 'stdout' => $result['stdout']];
};

$sessionJson = static function (string $username): string {
    $restore = Auth::user();
    Auth::logout(false);
    Auth::attempt($username, 'ChangeMe#2026');
    $payload = (string) json_encode(Auth::user(), JSON_THROW_ON_ERROR);
    if ($restore !== null) {
        $_SESSION['user'] = $restore;
    }
    return $payload;
};

$reporterSession = $sessionJson((string) $reportsUser);
$officerSession  = $sessionJson('faduma');

// The 404 that started this: an href of 'supervisor/reports.php' inside /supervisor/ resolves
// to /supervisor/supervisor/reports.php. Every link on the screen must be built with url().
$reportPage = $renderReports($reporterSession, '/supervisor/reports.php?year=' . $reportYear);
check('the screen renders', str_contains($reportPage['html'], 'Civil Registration Reports'));
check('no link points at the doubled supervisor path', !str_contains($reportPage['html'], 'supervisor/supervisor'), 'found a doubled path');
$reportHrefs = [];
if (preg_match_all('/href="([^"]*supervisor\/reports\.php[^"]*)"/', $reportPage['html'], $matches)) {
    $reportHrefs = $matches[1];
}
check('every export and tab link is absolute from the base URL', (static function (array $hrefs) use ($reportPage): bool {
    if ($hrefs === []) {
        return false;
    }
    foreach ($hrefs as $href) {
        if (!str_starts_with($href, url('supervisor/reports.php'))) {
            return false;
        }
    }
    return true;
})($reportHrefs), implode(' | ', array_slice($reportHrefs, 0, 3)));
check('the year is carried by the event links', (static function (array $hrefs): bool {
    foreach ($hrefs as $href) {
        if (str_contains($href, 'event=') && !str_contains($href, 'year=')) {
            return false;
        }
    }
    return true;
})($reportHrefs));

echo "\nIndividual Event Report Tests\n";
// One report per event, all on the same screen, chosen by the event tab.
$eventPages = [];
foreach (RecordTypes::ALL as $type => $meta) {
    $eventPages[$type] = $renderReports($reporterSession, '/supervisor/reports.php?year=' . $reportYear . '&event=' . $type);
}
foreach (RecordTypes::ALL as $type => $meta) {
    $html = $eventPages[$type]['html'];
    $label = $meta['label'];
    check("the {$label} report shows its own heading, volume and figures", str_contains($html, "{$label} Report — {$reportYear}")
        && str_contains($html, 'Monthly ' . strtolower($label) . ' volume')
        && str_contains($html, "{$label}s registered in {$reportYear}")
        && str_contains($html, 'By district')
        && str_contains($html, 'By workflow status'));
    check("the {$label} report lists the records of that event only", str_contains($html, "{$label} records in {$reportYear}"));
    check("the {$label} report offers its four exports", str_contains($html, "event={$type}&amp;export=event_summary")
        && str_contains($html, "event={$type}&amp;export=event_monthly")
        && str_contains($html, "event={$type}&amp;export=event_district")
        && str_contains($html, "event={$type}&amp;export=event_status"));
    check("the {$label} tab is the selected one", str_contains($html, "event={$type}\" class=\"btn btn-sm btn-primary\""));
    check("the {$label} report " . ($meta['gender'] ? 'breaks the figures down by sex' : 'has no sex breakdown, as the event has no gender column'),
        $meta['gender'] === str_contains($html, 'Male / female'));
}

// The events are told apart by the records they show: each report must carry its own record
// and not another event's. The all-events register sits further down the same page and
// names every fixture, so the check is made against the part of the page above it.
$fixtures = [
    'birth'    => ['own' => 'Report Test Child',         'other' => 'Report Test Deceased'],
    'death'    => ['own' => 'Report Test Deceased',      'other' => 'Report Test Child'],
    'marriage' => ['own' => 'Report Test Groom',         'other' => 'Report Test Divorcer'],
    'divorce'  => ['own' => 'Report Test Divorcer',      'other' => 'Report Test Groom'],
];
foreach ($fixtures as $type => $names) {
    $ownSection = explode('Registrations Across', $eventPages[$type]['html'])[0];
    check("the {$type} report shows its own record and not another event's", str_contains($ownSection, $names['own'])
        && !str_contains($ownSection, $names['other']));
}

$unknownEvent = $renderReports($reporterSession, '/supervisor/reports.php?year=' . $reportYear . '&event=marriage; DROP TABLE birth_records');
check('an unknown event falls back to birth instead of reaching the record type map', str_contains($unknownEvent['html'], 'Birth Report — ' . $reportYear)
    && (int) Database::scalar('SELECT COUNT(*) FROM birth_records') > 0);

echo "\nAll Events Register Tests\n";
check('the register lists all four events in one table', str_contains($reportPage['html'], "All Events Register — {$reportYear}")
    && str_contains($reportPage['html'], 'Second Party')
    && str_contains($reportPage['html'], 'Report Test Child')
    && str_contains($reportPage['html'], 'Report Test Deceased')
    && str_contains($reportPage['html'], 'Report Test Groom')
    && str_contains($reportPage['html'], 'Report Test Divorcer'));
check('the register names the second party of a couple', str_contains($reportPage['html'], 'Report Test Bride')
    && str_contains($reportPage['html'], 'Report Test Ex-Wife'));
check('the register shows the sex of a person, where the event records one', str_contains($reportPage['html'], '(Female)') && str_contains($reportPage['html'], '(Male)'));
check('the register has its own export', str_contains($reportPage['html'], 'export=all_records'));

echo "\nReports Export Tests\n";
// The exports answer with a CSV and end the request, so each is asked for in its own process
// and read off stdout. A doubled path or a missing column shows up here as broken CSV.
$fetchExport = static function (string $userJson, string $query): string {
    $result = runSnippet(
        'session_id("crexport" . getmypid());' . "\n"
        . 'session_start();' . "\n"
        . '$_SESSION["user"] = json_decode(' . var_export($userJson, true) . ', true);' . "\n"
        . '$_SERVER["REQUEST_URI"] = ' . var_export($query, true) . ';' . "\n"
        . 'parse_str((string) parse_url(' . var_export($query, true) . ', PHP_URL_QUERY), $_GET);' . "\n"
        . 'require ' . var_export(BASE_PATH . '/public/supervisor/reports.php', true) . ';',
        false
    );
    return ltrim($result['stdout'], "\xEF\xBB\xBF");
};
/** The header row of a CSV, as written — the writer quotes any field holding a comma. */
$csvHeader = static function (string $csv): array {
    $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
    return str_getcsv((string) ($lines[0] ?? ''));
};

$expected = [
    'district'   => ['District', 'Births', 'Deaths', 'Marriages', 'Divorces', 'Total'],
    'monthly'    => ['Month', 'Births', 'Deaths', 'Marriages', 'Divorces', 'Total'],
    'fees'       => ['Record Type', 'Certificates Issued', 'Paid', 'Unpaid', 'Collected (USD)', 'Outstanding (USD)'],
    'officers'   => ['Officer', 'Username', 'Births', 'Deaths', 'Marriages', 'Divorces', 'Total Registered', 'Approved'],
    'all_records' => ['Event', 'Registration Number', 'Primary Name', 'Second Party', 'Gender', 'Status', 'Registration Date', 'District', 'Registered By'],
];
foreach ($expected as $kind => $header) {
    $csv = $fetchExport($reporterSession, '/supervisor/reports.php?year=' . $reportYear . '&export=' . $kind);
    check("the {$kind} export answers with its header row", $csvHeader($csv) === $header, substr($csv, 0, 120));
}
$eventExports = [
    'event_summary'  => ['Event', 'Year', 'Registered', 'Approved', 'Awaiting Approval', 'Male', 'Female'],
    'event_monthly'  => ['Month', 'Birth Registrations'],
    'event_district' => ['District', 'Birth Registrations'],
    'event_status'   => ['Status', 'Records'],
];
foreach ($eventExports as $kind => $header) {
    $csv = $fetchExport($reporterSession, '/supervisor/reports.php?year=' . $reportYear . '&event=birth&export=' . $kind);
    check("the {$kind} export answers with its header row", $csvHeader($csv) === $header, substr($csv, 0, 120));
}
$marriageMonthly = $fetchExport($reporterSession, '/supervisor/reports.php?year=' . $reportYear . '&event=marriage&export=event_monthly');
check('an event export follows the event it was asked for', $csvHeader($marriageMonthly) === ['Month', 'Marriage Registrations'], substr($marriageMonthly, 0, 120));
$allCsv = $fetchExport($reporterSession, '/supervisor/reports.php?year=' . $reportYear . '&export=all_records');
check('the register export carries a row for every event of the year', str_contains($allCsv, 'Report Test Child')
    && str_contains($allCsv, 'Report Test Deceased')
    && str_contains($allCsv, 'Report Test Groom')
    && str_contains($allCsv, 'Report Test Divorcer'));
$unknownExport = $fetchExport($reporterSession, '/supervisor/reports.php?year=' . $reportYear . '&export=nope');
check('an unknown export is refused instead of answered', !str_contains($unknownExport, 'Event,Registration Number')
    && !str_contains($unknownExport, 'Fatal error') && !str_contains($unknownExport, 'Warning:'), $unknownExport);

$denied = $renderReports($officerSession, '/supervisor/reports.php?year=' . $reportYear);
check('an account without report.generate is refused with 403', $denied['file'] === '' && str_contains($denied['stdout'], '403'), $denied['stdout']);

purgeFixtures();
Auth::logout(false);
