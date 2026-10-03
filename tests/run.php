<?php
/** Minimal test runner (no dependencies):  php tests/run.php */
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\{Auth, Database, Config};
use App\Support\{Age, RecordTypes, Validator};
use App\Services\{RecordService, CertificateService, CertificateVerifier, Settings, UserService, SignatureService, ApprovalService, PaymentService};

$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    echo ($ok ? '  PASS ' : '  FAIL ') . $name . PHP_EOL;
    if (!$ok && $detail !== '') {
        echo '        ' . $detail . PHP_EOL;
    }
    $failed += $ok ? 0 : 1;
}

/**
 * A guard that ends the request (redirect(), abort()) cannot be observed in-process:
 * it prints a header warning under the CLI SAPI and calls exit(). That used to stop the
 * run after a handful of checks and still exit 0, which is indistinguishable from a
 * short but successful run. $runComplete is set once the summary is printed, so any
 * process that stops before that is reported as a failure with the last error it hit.
 */
$runComplete = false;
register_shutdown_function(static function () use (&$runComplete): void {
    // Put the development accounts back first: an aborted run must not leave them
    // holding the suite's password.
    try {
        restoreSeedAccounts();
    } catch (\Throwable $e) {
        fwrite(STDERR, '        ' . $e->getMessage() . PHP_EOL);
    }
    if ($runComplete) {
        return;
    }
    $last = error_get_last();
    if (is_array($last)) {
        fwrite(STDERR, '        ' . $last['message'] . ' in ' . $last['file'] . ':' . $last['line'] . PHP_EOL);
    }
    fwrite(STDERR, '  FAIL the run ended before the summary was printed — a guard (redirect/abort) most likely called exit().' . PHP_EOL);
    exit(1);
});

/** @var array<string, string> the development accounts the suite signs in as */
$seedAccounts = ['nasra' => 'administrator', 'abdi' => 'supervisor', 'faduma' => 'officer', 'mahad' => 'officer'];

/** @var array<string, array> their rows as found before the run touched them */
$seedSnapshot = [];

/**
 * Put the development accounts into the state the README documents: active, unlocked and
 * on the default password, with must_change_password cleared.
 *
 * must_change_password is cleared because several services open with Auth::requireLogin(),
 * which redirects a default-password account to change-password.php and ends the process.
 * A database that has been used by hand — someone went through change-password.php, or a
 * password reset was issued — would otherwise fail the run on a password the suite never
 * chose, and on an account the suite is not allowed to approve. The rows are snapshotted
 * first and written back by restoreSeedAccounts(), which the shutdown hook calls too, so a
 * run leaves every account exactly as it found it.
 */
function applySeedAccountState(): void
{
    global $seedAccounts, $seedSnapshot;
    $hash = password_hash('ChangeMe#2026', PASSWORD_DEFAULT);
    foreach (array_keys($seedAccounts) as $username) {
        $before = Database::fetchOne(
            'SELECT id, status, password_hash, must_change_password, failed_attempts, locked_until
               FROM users WHERE username = ?',
            [$username]
        );
        if ($before === null) {
            continue;   // a missing account is reported by the Database & Schema checks
        }
        $seedSnapshot[$username] = $before;
        Database::execute(
            'UPDATE users SET status = "active", password_hash = ?, must_change_password = 0,
                    failed_attempts = 0, locked_until = NULL WHERE id = ?',
            [$hash, $before['id']]
        );
    }
}

/** Write the snapshotted account rows back. Safe to call twice. */
function restoreSeedAccounts(): void
{
    global $seedSnapshot;
    $rows = $seedSnapshot;
    $seedSnapshot = [];
    foreach ($rows as $before) {
        Database::execute(
            'UPDATE users SET status = ?, password_hash = ?, must_change_password = ?,
                    failed_attempts = ?, locked_until = ? WHERE id = ?',
            [
                $before['status'],
                $before['password_hash'],
                $before['must_change_password'],
                $before['failed_attempts'],
                $before['locked_until'],
                $before['id'],
            ]
        );
    }
}

/**
 * Sign in as an active account that holds $permission right now, and return its username.
 *
 * Permissions are administered at runtime from admin/roles.php, so a check that needs
 * "somebody who is allowed to take a fee" has to ask the database who that is, rather than
 * assume a particular desk still holds it. Officer accounts are preferred, then any other
 * role, so a permission moved from officer to supervisor does not fail the run.
 */
function signInWithPermission(string $permission): ?string
{
    $candidates = Database::fetchAll(
        'SELECT u.username FROM users u
           JOIN role_permissions rp ON rp.role_id = u.role_id
           JOIN permissions p ON p.id = rp.permission_id
          WHERE p.code = ? AND u.status = "active"
          ORDER BY FIELD(u.username, "faduma", "mahad", "abdi", "nasra"), u.id',
        [$permission]
    );

    Auth::logout(false);
    foreach ($candidates as $row) {
        if (Auth::attempt((string) $row['username'], 'ChangeMe#2026') === true) {
            return (string) $row['username'];
        }
    }
    return null;
}

/**
 * Run a PHP snippet in a separate process.
 *
 * A few guards (Csrf::check, Auth::requirePermission, ExportService::downloadCsv)
 * end the request with exit() instead of returning, so they can only be observed
 * from the outside. The snippet gets BASE_PATH bootstrapped and a writable path in
 * the SNIPPET_OUT constant; anything it writes there is returned as 'file'.
 *
 * Pass $bootstrap = false for a snippet that includes a page from public/: a page requires
 * app/bootstrap.php itself, and loading it twice redeclares the helpers and aborts the child.
 * Such a snippet gets no BASE_PATH up front, so it needs the full path to the page.
 *
 * @return array{stdout: string, file: string, code: int}
 */
function runSnippet(string $body, bool $bootstrap = true): array
{
    $outFile = (string) tempnam(sys_get_temp_dir(), 'crs_out_');
    $phpFile = $outFile . '.php';
    file_put_contents($phpFile, "<?php\n"
        . ($bootstrap ? 'require ' . var_export(BASE_PATH . '/app/bootstrap.php', true) . ";\n" : '')
        . 'define("SNIPPET_OUT", ' . var_export($outFile, true) . ");\n"
        . $body . "\n");

    $lines = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpFile) . ' 2>&1', $lines, $code);

    $written = is_file($outFile) ? (string) file_get_contents($outFile) : '';
    @unlink($outFile);
    @unlink($phpFile);

    return ['stdout' => implode("\n", $lines), 'file' => $written, 'code' => $code];
}

/**
 * Remove rows created by earlier test runs so the suite can be run repeatedly
 * with identical results (fixtures are rebuilt by the tests themselves).
 * audit_logs are append-only, so the audit trail of past runs is left untouched.
 */
function purgeFixtures(): void
{
    purgeRecordFixtures();
    purgeTestUsers();
}

/** Records, certificates and contact messages created by the suite. */
function purgeRecordFixtures(): void
{
    // [record type, name column, fixture name, table]
    $fixtures = [
        ['birth',    'child_full_name',    'Amina Abdirashid Jama', 'birth_records'],
        ['death',    'deceased_full_name', 'Hassan Omar Warsame',   'death_records'],
        ['marriage', 'husband_full_name',  'Liban Mohamed Farah',   'marriage_records'],
        ['divorce',  'wife_full_name',     'Asha Ahmed Nur',        'divorce_records'],
        ['birth',    'child_full_name',    'Workflow Test Child',   'birth_records'],
        ['birth',    'child_full_name',    'Auto Submit Child',     'birth_records'],
        ['birth',    'child_full_name',    'REPRO Test Child',      'birth_records'],
        ['birth',    'child_full_name',    'Temp Officer Test Child', 'birth_records'],
        ['birth',    'child_full_name',    'Cert Integrity Child',    'birth_records'],
        ['birth',    'child_full_name',    'Cert Integrity Second',   'birth_records'],
        ['birth',    'child_full_name',    'Listing Desk A Child',    'birth_records'],
        ['birth',    'child_full_name',    'Listing Desk B Child',    'birth_records'],
        ['death',    'deceased_full_name', 'Listing Deceased',        'death_records'],
        ['birth',    'child_full_name',    'Report Test Child',       'birth_records'],
        ['death',    'deceased_full_name', 'Report Test Deceased',    'death_records'],
        ['marriage', 'husband_full_name',  'Report Test Groom',       'marriage_records'],
        ['divorce',  'husband_full_name',  'Report Test Divorcer',    'divorce_records'],
    ];

    foreach ($fixtures as [$type, $nameCol, $name, $table]) {
        $sql = "SELECT id FROM {$table} WHERE {$nameCol} = ?";
        foreach (Database::fetchAll($sql, [$name]) as $row) {
            $id = (int) $row['id'];
            // Capture the signature path before the certificate row is removed, so the
            // stored image can be deleted too instead of being left behind on every run.
            foreach (Database::fetchAll('SELECT signature_image FROM certificates WHERE record_type = ? AND record_id = ?', [$type, $id]) as $cert) {
                $absolute = SignatureService::getAbsolutePath((string) ($cert['signature_image'] ?? ''));
                if ($absolute !== null) {
                    @unlink($absolute);
                }
            }
            Database::execute('DELETE FROM certificates WHERE record_type = ? AND record_id = ?', [$type, $id]);
            Database::execute('DELETE FROM approvals WHERE record_type = ? AND record_id = ?', [$type, $id]);
            Database::execute('DELETE FROM record_status_history WHERE record_type = ? AND record_id = ?', [$type, $id]);
            Database::execute("DELETE FROM {$table} WHERE id = ?", [$id]);
        }
    }

    Database::execute("DELETE FROM contact_messages WHERE full_name = 'Warsame Nur'");
}

/**
 * Accounts created by the suites in tests/suites/. Their notifications cascade;
 * records and history are removed by the fixture list above, which is why the two
 * purges are kept apart and this one runs last.
 */
function purgeTestUsers(): void
{
    Database::execute('DELETE FROM users WHERE username LIKE ?', ['test\\_%']);
}

applySeedAccountState();

echo "Age Calculation Tests\n";
check('newborn shows days', Age::between('2026-09-20', '2026-09-23') === '3 days');
check('months', Age::between('2026-01-10', '2026-06-20') === '5 mo');
check('years', Age::between('1990-05-01', '2026-05-01') === '36 yrs');
check('one year singular', Age::between('2025-05-01', '2026-05-02') === '1 yr');
check('dob after date is null', Age::between('2027-01-01', '2026-01-01') === null);
check('empty dob is null', Age::between(null) === null);

echo "\nValidator Tests\n";
$v = Validator::make(['name' => '', 'phone' => 'abc', 'dob' => '2030-01-01', 'd1' => '2026-05-01', 'd2' => '2026-01-01', 'bad' => '2026-13-40'])
    ->required('name', 'Name')->phone('phone', 'Phone')->notFuture('dob', 'Date of birth')
    ->before('d1', 'Birth', 'd2', 'marriage')->date('bad', 'Date');
check('required fails', isset($v->errors()['name']));
check('phone fails', isset($v->errors()['phone']));
check('future date fails', isset($v->errors()['dob']));
check('before() fails when later', isset($v->errors()['d1']));
check('invalid date fails', isset($v->errors()['bad']));
$ok = Validator::make(['phone' => '+252 61 000 0000', 'd' => '2026-02-03'])->phone('phone', 'Phone')->date('d', 'Date');
check('valid input passes', !$ok->fails());

echo "\nDatabase & Schema Tests\n";
check('20 Banadir districts are registered', (int) Database::scalar('SELECT COUNT(*) FROM districts') === 20,
    'found: ' . (string) Database::scalar('SELECT COUNT(*) FROM districts'));

$usersCount = (int) Database::scalar('SELECT COUNT(*) FROM users');
check('Development users seeded', $usersCount >= 4);

$adminExists = Database::scalar("SELECT id FROM users WHERE username = 'nasra'");
check('Admin user nasra exists', !empty($adminExists));

$supervisorExists = Database::scalar("SELECT id FROM users WHERE username = 'abdi'");
check('Supervisor user abdi exists', !empty($supervisorExists));

$officersCount = (int) Database::scalar("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'officer'");
check('Officers faduma & mahad exist', $officersCount >= 2);

echo "\nAuthentication & Security Tests\n";
// The deliberate wrong-password attempts below bump failed_attempts on this account,
// so snapshot it first and put the counters back afterwards to keep runs repeatable.
$nasraId  = (int) Database::scalar("SELECT id FROM users WHERE username = 'nasra'");
$preAuth = Database::fetchOne('SELECT failed_attempts, locked_until FROM users WHERE id = ?', [$nasraId]);

// Test successful login
$loginSuccess = Auth::attempt('nasra', 'ChangeMe#2026');
check('Auth::attempt with correct password succeeds', $loginSuccess === true);
check('Auth::user() populated in session', Auth::check() && Auth::user()['username'] === 'nasra');
check('Auth::user() contains permissions array', is_array(Auth::user()['permissions'] ?? null));
check('Auth::id() returns user ID', Auth::id() > 0);
check('Auth home path redirects by role', Auth::homePath() === 'admin/dashboard.php');

// Test failed login
$loginBadPass = Auth::attempt('nasra', 'WrongPassword!999');
check('Auth::attempt with wrong password returns safe error string', is_string($loginBadPass) && $loginBadPass === 'Invalid username or password.');

$loginUnknown = Auth::attempt('nonexistent_user', 'SomePassword123');
check('Auth::attempt with unknown username returns safe error string', is_string($loginUnknown) && $loginUnknown === 'Invalid username or password.');

// Logout test
Auth::logout(false);
check('Auth::logout clears session user', !Auth::check());

Database::execute(
    'UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?',
    [$preAuth['failed_attempts'], $preAuth['locked_until'], $nasraId]
);

echo "\nSettings & Fees Tests\n";
check('Office name setting retrieved', Settings::get('office_name') !== '');
// Fees are administrator-configurable, so assert that Settings::fee() returns the
// stored value for every certificate type instead of a hardcoded amount.
foreach (['birth', 'marriage', 'death', 'divorce'] as $type) {
    $stored = (float) Database::scalar('SELECT setting_value FROM system_settings WHERE setting_key = ?', ['fee_' . $type]);
    check("{$type} certificate fee matches stored setting", Settings::fee($type) === $stored && $stored > 0.0);
}

echo "\nContact Messages Tests\n";
Database::execute(
    'INSERT INTO contact_messages (full_name, phone, message, status, created_at) VALUES (?, ?, ?, "new", NOW())',
    ['Warsame Nur', '+252 61 500 9988', 'Inquiry regarding certificate replacement procedure.']
);
$lastContact = Database::fetchOne('SELECT * FROM contact_messages WHERE full_name = ? ORDER BY id DESC LIMIT 1', ['Warsame Nur']);
check('Contact message successfully persisted in database', $lastContact !== null && $lastContact['phone'] === '+252 61 500 9988');

echo "\nSample Records Tests\n";
$br412 = Database::fetchOne("SELECT * FROM birth_records WHERE registration_number = 'BR-2026-0412'");
check('Sample approved record BR-2026-0412 exists', $br412 !== null && $br412['status'] === 'approved');

$dr107 = Database::fetchOne("SELECT * FROM death_records WHERE registration_number = 'DR-2026-0107'");
check('Sample submitted record DR-2026-0107 exists', $dr107 !== null && $dr107['guarantor_full_name'] !== '');

$mr88 = Database::fetchOne("SELECT * FROM marriage_records WHERE registration_number = 'MR-2026-0088'");
check('Sample marriage MR-2026-0088 has husband and wife names', $mr88 !== null && isset($mr88['husband_full_name'], $mr88['wife_full_name']));

$dv12 = Database::fetchOne("SELECT * FROM divorce_records WHERE registration_number = 'DV-2026-0012'");
check('Sample divorce record DV-2026-0012 exists', $dv12 !== null && $dv12['marriage_registration_number'] === 'MR-2024-0045');

echo "\nPhase 4: Civil Records Registration Tests\n";
purgeFixtures();

// Create test birth record
$birthResult = RecordService::createBirth([
    'child_full_name'   => 'Amina Abdirashid Jama',
    'gender'            => 'female',
    'date_of_birth'     => '2026-08-15',
    'time_of_birth'     => '06:30:00',
    'place_of_birth'    => 'Mogadishu',
    'hospital_of_birth' => 'Banadir Maternity Hospital',
    'district_id'       => 9, // Hodan
    'father_full_name'  => 'Abdirashid Jama Warsame',
    'father_phone'      => '+252 61 511 2233',
    'mother_full_name'  => 'Halima Hassan Nur',
    'mother_phone'      => '+252 61 522 3344',
    'registration_date' => '2026-08-20',
], null, false);
check('RecordService::createBirth creates record with registration number', str_starts_with($birthResult['registration_number'], 'BR-2026-'));
check('Birth record saved with draft status', $birthResult['status'] === 'draft');

// Test duplicate check on birth
$duplicate = RecordService::checkDuplicate('birth', 'Amina Abdirashid Jama', '2026-08-15');
check('RecordService::checkDuplicate identifies existing birth record', $duplicate !== null && (int) $duplicate['id'] === (int) $birthResult['id']);

// Create test death record with guarantor
$deathResult = RecordService::createDeath([
    'deceased_full_name'  => 'Hassan Omar Warsame',
    'gender'              => 'male',
    'date_of_birth'       => '1955-03-10',
    'date_of_death'       => '2026-09-01',
    'place_of_death'      => 'Mogadishu',
    'district_id'         => 6, // Hamar Weyne
    'guarantor_full_name' => 'Abdullahi Hassan Omar',
    'informant_name'      => 'Maryan Hassan Omar',
    'informant_phone'     => '+252 61 577 8899',
    'registration_date'   => '2026-09-02',
], false);
check('RecordService::createDeath creates record with guarantor', str_starts_with($deathResult['registration_number'], 'DR-2026-'));
check('Age at death correctly calculated (1955 to 2026 = 71 yrs)', Age::between('1955-03-10', '2026-09-01') === '71 yrs');

// Create test marriage record with husband/wife and mahr
$marrResult = RecordService::createMarriage([
    'husband_full_name'     => 'Liban Mohamed Farah',
    'husband_date_of_birth' => '1995-04-12',
    'husband_phone'         => '+252 61 511 9900',
    'wife_full_name'        => 'Asha Ahmed Nur',
    'wife_date_of_birth'    => '1998-07-20',
    'wife_phone'            => '+252 61 522 9900',
    'marriage_date'         => '2026-07-15',
    'place_of_marriage'     => 'Hamar Weyne, Mogadishu',
    'district_id'           => 6,
    'mahr_amount'           => '800 USD',
    'witness1_full_name'    => 'Ali Nur Barre',
    'witness1_phone'        => '+252 61 533 1122',
    'witness2_full_name'    => 'Omar Jama Elmi',
    'witness2_phone'        => '+252 61 544 2233',
    'registration_date'     => '2026-07-16',
], false);
check('RecordService::createMarriage creates record with mahr and witnesses', str_starts_with($marrResult['registration_number'], 'MR-2026-'));
check('Husband age at marriage is 31 yrs', Age::between('1995-04-12', '2026-07-15') === '31 yrs');
check('Wife age at marriage is 27 yrs', Age::between('1998-07-20', '2026-07-15') === '27 yrs');

// Create test divorce record
$divResult = RecordService::createDivorce([
    'marriage_registration_number' => $marrResult['registration_number'],
    'husband_full_name'            => 'Liban Mohamed Farah',
    'husband_date_of_birth'        => '1995-04-12',
    'wife_full_name'               => 'Asha Ahmed Nur',
    'wife_date_of_birth'           => '1998-07-20',
    'divorce_date'                 => '2026-09-10',
    'district_id'                  => 6,
    'court_reference'              => 'BDR-CIV-2026-882',
    'registration_date'            => '2026-09-11',
], false);
check('RecordService::createDivorce references marriage registration number', str_starts_with($divResult['registration_number'], 'DV-2026-'));

purgeFixtures();

echo "\nCertificate Verification Tests\n";
$token = 'a91f3c7e52b04d6f8e2193b4a2c107e5';
$verification = CertificateVerifier::verify($token);
check('Sample certificate token verifies as valid', ($verification['result'] ?? '') === 'valid');

$bogusVerification = CertificateVerifier::verify('invalid_token_123456');
check('Invalid token returns not_found', ($bogusVerification['result'] ?? '') === 'not_found');

echo "\nAccount Lockout Tests\n";
// Lock a seeded account, clear it through the service an admin uses, then restore
// the original lock state so the suite stays repeatable.
$lockedId = (int) Database::scalar("SELECT id FROM users WHERE username = 'mahad'");
$before = Database::fetchOne('SELECT failed_attempts, locked_until FROM users WHERE id = ?', [$lockedId]);
Database::execute(
    'UPDATE users SET failed_attempts = 5, locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?',
    [$lockedId]
);
check('locked account is rejected by Auth::attempt', is_string(Auth::attempt('mahad', 'ChangeMe#2026')));

UserService::unlock($lockedId);
$after = Database::fetchOne('SELECT failed_attempts, locked_until FROM users WHERE id = ?', [$lockedId]);
check('UserService::unlock clears the lockout', (int) $after['failed_attempts'] === 0 && $after['locked_until'] === null);
check('unlocked account can sign in again', Auth::attempt('mahad', 'ChangeMe#2026') === true);
Auth::logout(false);

Database::execute(
    'UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?',
    [$before['failed_attempts'], $before['locked_until'], $lockedId]
);

echo "\nApproval Workflow Tests\n";
purgeFixtures();

$sigPng = 'data:image/png;base64,' . base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

// A helper that owns its own transaction must join an enclosing one rather than fail.
// PDO throws "There is already an active transaction" on a nested begin, which used to
// make every supervisor approval fail before a certificate could be issued.
$nestedOk = true;
$nestedNumber = '';
Database::beginTransaction();
try {
    $nestedNumber = CertificateService::nextCertificateNumber();
    Database::commit();
} catch (\Throwable $e) {
    $nestedOk = false;
    Database::rollBack();
}
check('certificate numbering works inside an enclosing transaction', $nestedOk && str_starts_with($nestedNumber, 'CERT-'));

// Officer creates and submits.
Auth::logout(false);
Auth::attempt('faduma', 'ChangeMe#2026');
$wf = RecordService::createBirth([
    'child_full_name'    => 'Workflow Test Child',
    'gender'             => 'female',
    'date_of_birth'      => '2026-03-04',
    'place_of_birth'     => 'Mogadishu',
    'hospital_of_birth'  => 'Benadir General',
    'district_id'        => 1,
    'father_full_name'   => 'Workflow Father',
    'father_nationality' => 'Somali',
    'mother_full_name'   => 'Workflow Mother',
    'mother_nationality' => 'Somali',
    'registration_date'  => '2026-09-25',
]);
$wfId = (int) $wf['id'];
check('workflow record starts as a draft', (string) Database::scalar('SELECT status FROM birth_records WHERE id = ?', [$wfId]) === 'draft');

// Every ApprovalService::approve/reject/requestCorrection call below starts with
// Auth::requireRole('supervisor') -> requireLogin(), and a supervisor still on the
// default password is redirected to change-password.php, which ends the process with
// exit(). Under the CLI SAPI that killed the whole run, silently and with code 0.
// Clear the flag for the duration of the workflow; it is written back at the end.
$supId = (int) Database::scalar("SELECT id FROM users WHERE username = 'abdi'");
$supMustChange = Database::scalar('SELECT must_change_password FROM users WHERE id = ?', [$supId]);
Database::execute('UPDATE users SET must_change_password = 0 WHERE id = ?', [$supId]);

check('draft record cannot be approved before submission', (function () use ($wfId, $sigPng): bool {
    Auth::logout(false);
    Auth::attempt('abdi', 'ChangeMe#2026');
    try {
        ApprovalService::approve('birth', $wfId, $sigPng);
        return false;
    } catch (\RuntimeException $e) {
        return true;
    }
})());

ApprovalService::submit('birth', $wfId, 'Ready for review');
check('submit moves the record to submitted', (string) Database::scalar('SELECT status FROM birth_records WHERE id = ?', [$wfId]) === 'submitted');
check('submitted record appears in the supervisor queue', in_array(
    $wfId,
    array_map(static fn(array $r): int => (int) $r['id'], array_filter(ApprovalService::getPendingQueue(), static fn(array $r): bool => $r['record_type'] === 'birth')),
    true
));

check('submitting an already submitted record is refused', (function () use ($wfId): bool {
    try {
        ApprovalService::submit('birth', $wfId, 'again');
        return false;
    } catch (\RuntimeException $e) {
        return true;
    }
})());

// The "Register & Submit" button creates the record and submits it in one call. Creating it
// as 'submitted' directly made ApprovalService::submit() reject the record it had just made
// ("cannot be submitted in its current status: submitted"), so the page reported a failure
// for a registration that was in fact saved. The workflow service must own the transition.
$auto = RecordService::createBirth([
    'child_full_name'    => 'Auto Submit Child',
    'gender'             => 'male',
    'date_of_birth'      => '2026-04-06',
    'place_of_birth'     => 'Mogadishu',
    'hospital_of_birth'  => 'Benadir General',
    'district_id'        => 1,
    'father_full_name'   => 'Auto Father',
    'father_nationality' => 'Somali',
    'mother_full_name'   => 'Auto Mother',
    'mother_nationality' => 'Somali',
    'registration_date'  => '2026-09-27',
], null, true);
$autoId = (int) $auto['id'];
check('register-and-submit returns a submitted record', $auto['status'] === 'submitted'
    && (string) Database::scalar('SELECT status FROM birth_records WHERE id = ?', [$autoId]) === 'submitted');
check('register-and-submit opens the approval row', (int) Database::scalar(
    'SELECT COUNT(*) FROM approvals WHERE record_type = "birth" AND record_id = ? AND decision = "pending"',
    [$autoId]
) === 1);
check('register-and-submit records both transitions in the history', (int) Database::scalar(
    'SELECT COUNT(*) FROM record_status_history WHERE record_type = "birth" AND record_id = ? AND status IN ("draft","submitted")',
    [$autoId]
) === 2);
$autoSupervisorId = (int) Database::scalar(
    "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'supervisor' AND u.status = 'active' ORDER BY u.id LIMIT 1"
);
check('register-and-submit notifies a supervisor', (int) Database::scalar(
    'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND link LIKE ?',
    [$autoSupervisorId, "%type=birth&id={$autoId}%"]
) >= 1);

// Supervisor side.
$approveOk = true;
try {
    ApprovalService::approve('birth', $wfId, $sigPng, 'Verified in person');
} catch (\Throwable $e) {
    $approveOk = false;
}
check('supervisor approval succeeds', $approveOk);
check('approved record is locked in approved status', (string) Database::scalar('SELECT status FROM birth_records WHERE id = ?', [$wfId]) === 'approved');

$wfCert = CertificateService::findByRecord('birth', $wfId);
check('approval issues a certificate', $wfCert !== null && str_starts_with((string) $wfCert['certificate_number'], 'CERT-'));
check('issued certificate starts unpaid', $wfCert !== null && $wfCert['payment_status'] === 'unpaid');
check('certificate fee copied from settings', $wfCert !== null && (float) $wfCert['fee_amount'] === Settings::fee('birth'));
check('unpaid certificate cannot be printed', !CertificateService::canPrint($wfCert));
check('approving an already approved record is refused', (function () use ($wfId, $sigPng): bool {
    try {
        ApprovalService::approve('birth', $wfId, $sigPng);
        return false;
    } catch (\RuntimeException $e) {
        return true;
    }
})());

// Payment unlocks printing. The account that takes the fee is whichever desk currently holds
// certificate.pay — an administrator may have moved it off the officer role — and markPaid()
// refuses anyone else, so the check has to ask who may pay instead of assuming.
$payer = signInWithPermission('certificate.pay');
check('an active account is allowed to take a certificate fee', $payer !== null, 'No active user holds the certificate.pay permission.');
if ($payer !== null) {
    check('fee payment is recorded', CertificateService::markPaid((int) $wfCert['id']));
    $wfPaid = CertificateService::findByRecord('birth', $wfId);
    check('paid certificate can now be printed', CertificateService::canPrint($wfPaid));
    check('payment records the paying account', (int) $wfPaid['paid_by'] === (int) Auth::id() && $wfPaid['paid_at'] !== null);
}

$summary = PaymentService::getFeeSummary();
check('fee summary counts the paid certificate', $summary['paid_count'] >= 1 && $summary['total_collected'] > 0.0);

// Verification of the newly issued certificate.
$verified = CertificateVerifier::verify((string) $wfCert['verification_token']);
check('new certificate verifies through the public token', ($verified['result'] ?? '') === 'valid');

// Revocation blocks printing again.
$revoker = signInWithPermission('certificate.revoke');
check('an active account is allowed to revoke a certificate', $revoker !== null, 'No active user holds the certificate.revoke permission.');
if ($revoker !== null) {
    check('a permitted account can revoke a certificate', CertificateService::revoke((int) $wfCert['id'], 'Issued in error'));
    $wfRevoked = CertificateService::findByRecord('birth', $wfId);
    check('revoked certificate cannot be printed', !CertificateService::canPrint($wfRevoked));
    check('revoked certificate verifies as revoked', (CertificateVerifier::verify((string) $wfCert['verification_token'])['result'] ?? '') === 'revoked');
    check('a permitted account can restore a certificate', CertificateService::restore((int) $wfCert['id']));
    check('restored certificate prints again', CertificateService::canPrint(CertificateService::findByRecord('birth', $wfId)));
}

Database::execute('UPDATE users SET must_change_password = ? WHERE id = ?', [$supMustChange, $supId]);

// Rejection and correction paths need their own record, and each guards its own input.
Auth::logout(false);
Auth::attempt('faduma', 'ChangeMe#2026');
$rej = RecordService::createBirth([
    'child_full_name'    => 'Workflow Test Child',
    'gender'             => 'male',
    'date_of_birth'      => '2026-04-05',
    'place_of_birth'     => 'Mogadishu',
    'hospital_of_birth'  => 'Benadir General',
    'district_id'        => 1,
    'father_full_name'   => 'Workflow Father',
    'father_nationality' => 'Somali',
    'mother_full_name'   => 'Workflow Mother',
    'mother_nationality' => 'Somali',
    'registration_date'  => '2026-09-25',
]);
$rejId = (int) $rej['id'];
ApprovalService::submit('birth', $rejId, null);

Auth::logout(false);
Auth::attempt('abdi', 'ChangeMe#2026');
check('rejection without a reason is refused', (function () use ($rejId): bool {
    try {
        ApprovalService::reject('birth', $rejId, '   ');
        return false;
    } catch (\RuntimeException $e) {
        return true;
    }
})());
check('correction without feedback is refused', (function () use ($rejId): bool {
    try {
        ApprovalService::requestCorrection('birth', $rejId, '');
        return false;
    } catch (\RuntimeException $e) {
        return true;
    }
})());

check('supervisor can request a correction', ApprovalService::requestCorrection('birth', $rejId, 'Father reference number is missing'));
check('correction returns the record to the officer', (string) Database::scalar('SELECT status FROM birth_records WHERE id = ?', [$rejId]) === 'correction_required');
check('a corrected record can be resubmitted', ApprovalService::submit('birth', $rejId, 'Reference added'));
check('resubmitted record is back in the queue', (string) Database::scalar('SELECT status FROM birth_records WHERE id = ?', [$rejId]) === 'submitted');

check('supervisor can reject with a reason', ApprovalService::reject('birth', $rejId, 'Hospital attestation could not be confirmed'));
check('rejected record shows the reason', str_contains(
    (string) Database::scalar('SELECT rejection_reason FROM approvals WHERE record_type = ? AND record_id = ? AND decision = "rejected"', ['birth', $rejId]),
    'Hospital attestation'
));

check('every transition is recorded in the status history', (int) Database::scalar(
    'SELECT COUNT(*) FROM record_status_history WHERE record_type = ? AND record_id = ?',
    ['birth', $wfId]
) >= 3);

purgeFixtures();
$leftoverCerts = (int) Database::scalar("SELECT COUNT(*) FROM certificates WHERE record_id IN (SELECT id FROM birth_records WHERE child_full_name = 'Workflow Test Child')");
check('workflow fixtures are fully removed', $leftoverCerts === 0);

echo "\nSignature Upload Tests\n";
$sigDir = BASE_PATH . '/storage/uploads/signatures';
$created = [];

// A real 1x1 PNG must be accepted and stored with a .png extension.
$pngDataUrl = 'data:image/png;base64,' . base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$pngPath = SignatureService::saveFromDataUrl($pngDataUrl, 999);
$created[] = $pngPath;
check('valid PNG signature is stored', str_starts_with($pngPath, 'signatures/sig_999_') && str_ends_with($pngPath, '.png'));
check('stored PNG is retrievable on disk', SignatureService::getAbsolutePath($pngPath) !== null);
check('dataUri reports the real image MIME type', str_starts_with((string) SignatureService::dataUri($pngPath), 'data:image/png;base64,'));

// A Data URL that claims to be a PNG but carries non-image bytes must be rejected.
$fake = 'data:image/png;base64,' . base64_encode('<?php echo "not an image"; ?>');
$rejected = false;
try {
    $created[] = SignatureService::saveFromDataUrl($fake, 999);
} catch (\RuntimeException $e) {
    $rejected = true;
}
check('non-image payload with a PNG data URL is rejected', $rejected);

$badFormat = false;
try {
    SignatureService::saveFromDataUrl('not-a-data-url', 999);
} catch (\RuntimeException $e) {
    $badFormat = true;
}
check('malformed data URL is rejected', $badFormat);

check('path traversal in signature path is refused', SignatureService::getAbsolutePath('../../../config/database.php') === null);

foreach ($created as $path) {
    $abs = SignatureService::getAbsolutePath($path);
    if ($abs !== null) {
        unlink($abs);
    }
}
check('signature test files cleaned up', !array_filter($created, static fn(string $p): bool => SignatureService::getAbsolutePath($p) !== null));

echo "\nAudit Log Append-Only Trigger Tests\n";
$triggerWorked = false;
try {
    Database::execute("UPDATE audit_logs SET action = 'tampered' LIMIT 1");
} catch (\PDOException $e) {
    $triggerWorked = str_contains($e->getMessage(), 'Audit logs are append-only') || str_contains($e->getMessage(), '45000');
}
check('Audit log UPDATE blocked by database trigger', $triggerWorked);

// Suites kept in tests/suites/, one file per area, run in filename order.
foreach (glob(__DIR__ . '/suites/*.php') ?: [] as $suite) {
    // Without this the app's error handler would render its 500 page and exit, so a suite that
    // threw would look like a short but successful run. Report it as the failure it is.
    try {
        require $suite;
    } catch (\Throwable $e) {
        check(basename($suite) . ' runs to the end', false, get_class($e) . ': ' . $e->getMessage());
    }
}

restoreSeedAccounts();
echo $failed === 0 ? "\nAll tests passed successfully.\n" : "\n$failed test(s) failed.\n";
$runComplete = true;
exit($failed === 0 ? 0 : 1);
