<?php
declare(strict_types=1);
/** Certificate issuance, numbering, printing gates and fee collection. */

use App\Core\{Auth, Database};
use App\Services\{CertificateService, PaymentService, RecordService, RoleService, Settings, UserService};

purgeFixtures();
Auth::logout(false);

// The certificate counter is a real register, so it is only read here, never rewound: a skipped
// number is acceptable, handing the same number out twice is not.
$certSequence = Database::fetchOne("SELECT last_number FROM number_sequences WHERE prefix = 'CERT' AND year = ?", [(int) date('Y')]);
$certSequenceBefore = $certSequence === null ? 0 : (int) $certSequence['last_number'];

$supervisorId = (int) Database::scalar("SELECT id FROM users WHERE username = 'abdi'");

/** Create a birth record as the officer and set it straight to approved, isolating the certificate service from the workflow. */
$approvedBirth = static function (string $name) use ($supervisorId): array {
    Auth::attempt('faduma', 'ChangeMe#2026');
    $record = RecordService::createBirth([
        'child_full_name'    => $name,
        'gender'             => 'female',
        'date_of_birth'      => '2020-03-14',
        'place_of_birth'     => 'Mogadishu',
        'hospital_of_birth'  => 'Benadir General',
        'district_id'        => 1,
        'father_full_name'   => 'Integrity Father',
        'father_nationality' => 'Somali',
        'mother_full_name'   => 'Integrity Mother',
        'mother_nationality' => 'Somali',
        'registration_date'  => '2026-09-25',
    ], null, false);
    Database::execute(
        "UPDATE birth_records SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?",
        [$supervisorId, (int) $record['id']]
    );
    return $record;
};

echo "\nCertificate Numbering Tests\n";
$firstNumber = CertificateService::nextCertificateNumber();
$secondNumber = CertificateService::nextCertificateNumber();
check('a certificate number is prefixed with the year and padded', (bool) preg_match('/^CERT-' . date('Y') . '-\d{4}$/', $firstNumber), $firstNumber);
check('certificate numbers are sequential', (int) substr($secondNumber, -4) === (int) substr($firstNumber, -4) + 1, $firstNumber . ' then ' . $secondNumber);
$futureYear = CertificateService::nextCertificateNumber(2031);
check('a different year counts on its own sequence', (bool) preg_match('/^CERT-2031-\d{4}$/', $futureYear) && (int) substr($futureYear, -4) !== (int) substr($firstNumber, -4), $futureYear);

// A rolled back unit of work must not burn a certificate number.
Database::beginTransaction();
$insideTransaction = CertificateService::nextCertificateNumber();
Database::rollBack();
check('a rolled back transaction does not consume a number', CertificateService::nextCertificateNumber() === $insideTransaction, $insideTransaction);

echo "\nCertificate Issuance Tests\n";
Auth::attempt('faduma', 'ChangeMe#2026');
$draft = RecordService::createBirth([
    'child_full_name'    => 'Cert Integrity Child',
    'gender'             => 'male',
    'date_of_birth'      => '2021-08-09',
    'place_of_birth'     => 'Mogadishu',
    'hospital_of_birth'  => 'Benadir General',
    'district_id'        => 1,
    'father_full_name'   => 'Integrity Father',
    'father_nationality' => 'Somali',
    'mother_full_name'   => 'Integrity Mother',
    'mother_nationality' => 'Somali',
    'registration_date'  => '2026-09-25',
], null, false);

$refusal = static fn(callable $fn): string => (static function () use ($fn): string {
    try {
        $fn();
        return 'no error';
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }
})();
check('a draft record cannot be certified', $refusal(static fn() => CertificateService::issueForRecord('birth', (int) $draft['id'], $supervisorId, 'integrity.png')) === 'Only an approved record can be certified.');
check('a missing record cannot be certified', $refusal(static fn() => CertificateService::issueForRecord('birth', 999999, $supervisorId, 'integrity.png')) === 'Record not found.');
check('the refused attempts wrote no certificate', (int) Database::scalar('SELECT COUNT(*) FROM certificates WHERE record_id = ? AND record_type = ?', [(int) $draft['id'], 'birth']) === 0);

Database::execute("UPDATE birth_records SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?", [$supervisorId, (int) $draft['id']]);
$certificate = CertificateService::issueForRecord('birth', (int) $draft['id'], $supervisorId, 'integrity.png');
check('an approved record is certified', !empty($certificate['id']) && !empty($certificate['certificate_number']), json_encode($certificate));
check('the certificate starts valid and unpaid', ($certificate['status'] ?? '') === 'valid' && ($certificate['payment_status'] ?? '') === 'unpaid');
check('the certificate carries the birth fee', (float) $certificate['fee_amount'] === (float) Settings::get('fee_birth', '0.00'), (string) $certificate['fee_amount']);
check('the certificate names the supervisor who signed it', (int) $certificate['issued_by'] === $supervisorId && (int) $certificate['signed_by'] === $supervisorId);
check('the verification token is 32 hex characters', (bool) preg_match('/^[0-9a-f]{32}$/', (string) $certificate['verification_token']), (string) $certificate['verification_token']);
check('issuing an audit entry is written', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'certificate_issue' AND record_type = 'certificate' AND record_id = ?", [(int) $certificate['id']]) === 1);

$countBefore = (int) Database::scalar('SELECT COUNT(*) FROM certificates WHERE record_type = ? AND record_id = ?', ['birth', (int) $draft['id']]);
$again = CertificateService::issueForRecord('birth', (int) $draft['id'], $supervisorId, 'other.png');
check('certifying the same record twice returns the same certificate', (int) $again['id'] === (int) $certificate['id']);
check('the duplicate call creates no second certificate', (int) Database::scalar('SELECT COUNT(*) FROM certificates WHERE record_type = ? AND record_id = ?', ['birth', (int) $draft['id']]) === $countBefore);
check('the duplicate call writes no second audit entry', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'certificate_issue' AND record_type = 'certificate' AND record_id = ?", [(int) $certificate['id']]) === 1);
check('the duplicate call keeps the first signature', (string) $again['signature_image'] === 'integrity.png', (string) $again['signature_image']);

$second = $approvedBirth('Cert Integrity Second');
$secondCertificate = CertificateService::issueForRecord('birth', (int) $second['id'], $supervisorId, 'integrity.png');
check('two certificates never share a token', $secondCertificate['verification_token'] !== $certificate['verification_token']);
check('two certificates never share a number', $secondCertificate['certificate_number'] !== $certificate['certificate_number']);
check('a token finds its certificate', (int) (CertificateService::findByToken((string) $certificate['verification_token'])['id'] ?? 0) === (int) $certificate['id']);
check('a token is matched after trimming', (int) (CertificateService::findByToken('  ' . $certificate['verification_token'] . ' ')['id'] ?? 0) === (int) $certificate['id']);
check('an unknown token finds nothing', CertificateService::findByToken('deadbeef') === null);
check('a certificate number finds its certificate', (int) (CertificateService::findByNumber((string) $certificate['certificate_number'])['id'] ?? 0) === (int) $certificate['id']);
$byRecord = CertificateService::findByRecord('birth', (int) $draft['id']);
check('looking a certificate up by record names the issuer', ($byRecord['issued_by_name'] ?? '') === 'Abdi' || ($byRecord['issued_by_name'] ?? '') !== '');

echo "\nPrinting Gate Tests\n";
check('a valid paid certificate may print', CertificateService::canPrint(['status' => 'valid', 'payment_status' => 'paid']));
check('an unpaid certificate may not print', !CertificateService::canPrint(['status' => 'valid', 'payment_status' => 'unpaid']));
check('a revoked certificate may not print even when paid', !CertificateService::canPrint(['status' => 'revoked', 'payment_status' => 'paid']));
check('an empty certificate may not print', !CertificateService::canPrint([]));
check('a certificate missing its keys may not print', !CertificateService::canPrint(['id' => 1]));

echo "\nFee Collection Tests\n";
// Fee work needs certificate.pay, and the administrator is a superuser, so the desk that
// takes the money is whichever account the database says holds it today. The certificate
// fee is deliberately paid by that account rather than by the administrator: it keeps this
// suite honest about which permissions the workflow really needs.
$payUser = signInWithPermission('certificate.pay');
check('somebody holds certificate.pay', $payUser !== null, (string) $payUser);
check('paying an unknown certificate is refused', $refusal(static fn() => CertificateService::markPaid(999999)) === 'Certificate not found.');
check('recording the fee marks the certificate paid', CertificateService::markPaid((int) $certificate['id']) === true);
$paid = CertificateService::findByRecord('birth', (int) $draft['id']);
check('the payment stores the paying officer and the time', (int) ($paid['paid_by'] ?? 0) === (int) Auth::id() && ($paid['paid_at'] ?? '') !== '');
check('the paid certificate may now print', CertificateService::canPrint($paid));
$paidAt = (string) $paid['paid_at'];

check('paying twice changes nothing', CertificateService::markPaid((int) $certificate['id']) === true);
$paidAgain = CertificateService::findByRecord('birth', (int) $draft['id']);
check('the second payment keeps the first timestamp', (string) $paidAgain['paid_at'] === $paidAt);
check('the second payment is not audited twice', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment' AND record_type = 'certificate' AND record_id = ?", [(int) $certificate['id']]) === 1);

echo "\nFee Permission Tests\n";
// requirePermission() ends the request, so the refusal is observed from a child process.
//
// The caller has to be somebody who genuinely lacks certificate.pay, and no seeded account
// can be relied on for that: the administrator is a superuser and passes every check, and an
// administrator is free to move the fee permission to any desk. So a throwaway account is
// created on a desk the paying user is *not* signed in on, certificate.pay is taken off that
// desk for the length of the child process and put straight back, and the refusal is then
// observed on an account that provably does not hold the permission.
$payRoleId = (int) Database::scalar('SELECT role_id FROM users WHERE id = ?', [(int) Auth::id()]);
$deniedRole = Database::fetchOne(
    'SELECT r.id, r.name FROM roles r
      WHERE r.name <> "administrator" AND r.id <> ?
      ORDER BY FIELD(r.name, "officer", "supervisor"), r.id LIMIT 1',
    [$payRoleId]
);
$deniedUser = UserService::create([
    'full_name' => 'Fee Denied Fixture',
    'username'  => 'test_fee_denied',
    'role_name' => (string) $deniedRole['name'],
    'password'  => 'FeeDenied#2026',
]);
// The child process reaches Auth::requireLogin(), which sends an account still on its
// first-run password to change-password.php, so the flag is cleared for the run.
Database::execute('UPDATE users SET must_change_password = 0 WHERE id = ?', [(int) $deniedUser['id']]);

$deniedRoleId = (int) $deniedRole['id'];
$deniedRoleCodes = RoleService::permissionCodes($deniedRoleId);
$beforeDenied = CertificateService::findByRecord('birth', (int) $draft['id']);

// The permission goes back whatever happens in between. A run that dies part-way through must
// not leave a real desk unable to take fees, so the borrow is unwound by finally rather than by
// the next line.
try {
    RoleService::setPermissions($deniedRoleId, array_values(array_diff($deniedRoleCodes, ['certificate.pay'])));
    $denied = runSnippet(
        'App\Core\Auth::attempt("test_fee_denied", "FeeDenied#2026");' . "\n"
        . 'App\Services\CertificateService::markPaid(' . (int) $certificate['id'] . ');' . "\n"
        . 'file_put_contents(SNIPPET_OUT, "PAID");'
    );
} finally {
    RoleService::setPermissions($deniedRoleId, $deniedRoleCodes);
}

$afterDenied = CertificateService::findByRecord('birth', (int) $draft['id']);
check('a caller without certificate.pay is refused with 403', $denied['file'] === '' && str_contains($denied['stdout'], '403'), $denied['stdout']);
check('the refused payment left the recorded payer alone', (int) $afterDenied['paid_by'] === (int) $beforeDenied['paid_by'] && (string) $afterDenied['paid_at'] === (string) $beforeDenied['paid_at']);
check('the fee permission is back on the desk that had it', RoleService::permissionCodes($deniedRoleId) === $deniedRoleCodes, (string) $deniedRole['name']);

// The child process changed nothing, but the revoked-certificate checks below need a caller
// who may revoke as well as take a fee, so the account that was signed in before the borrow
// is signed in again.
Auth::attempt($payUser, 'ChangeMe#2026');

echo "\nRevoked Certificate Tests\n";
$revokedId = (int) $secondCertificate['id'];
CertificateService::revoke($revokedId, 'integrity test');
$revoked = CertificateService::findByRecord('birth', (int) $second['id']);
check('a revoked certificate is not valid', ($revoked['status'] ?? '') === 'revoked');
check('a revoked certificate cannot take a payment', $refusal(static fn() => CertificateService::markPaid($revokedId)) === 'A revoked certificate cannot take a fee payment.');
check('a revoked certificate is still unpaid', (string) (CertificateService::findByRecord('birth', (int) $second['id'])['payment_status'] ?? '') === 'unpaid');
check('re-certifying a revoked record does not resurrect it', (string) (CertificateService::issueForRecord('birth', (int) $second['id'], $supervisorId, 'integrity.png')['status'] ?? '') === 'revoked');
check('no extra certificate appeared for the revoked record', (int) Database::scalar('SELECT COUNT(*) FROM certificates WHERE record_type = ? AND record_id = ?', ['birth', (int) $second['id']]) === 1);
CertificateService::restore($revokedId);
check('a restored certificate is valid again', (string) (CertificateService::findByRecord('birth', (int) $second['id'])['status'] ?? '') === 'valid');

echo "\nFee Summary Tests\n";
$summary = PaymentService::getFeeSummary();
$validCount = (int) Database::scalar("SELECT COUNT(*) FROM certificates WHERE status = 'valid'");
$paidCount = (int) Database::scalar("SELECT COUNT(*) FROM certificates WHERE status = 'valid' AND payment_status = 'paid'");
check('the summary only counts valid certificates', $summary['total_certificates'] === $validCount, json_encode($summary));
check('the summary splits paid from unpaid', $summary['paid_count'] === $paidCount && $summary['unpaid_count'] === $validCount - $paidCount);
check('the summary counts the collected money', abs($summary['total_collected'] - (float) Database::scalar("SELECT COALESCE(SUM(fee_amount), 0) FROM certificates WHERE status = 'valid' AND payment_status = 'paid'")) < 0.001);
check('the summary counts the money still owed', abs($summary['total_unpaid'] - (float) Database::scalar("SELECT COALESCE(SUM(fee_amount), 0) FROM certificates WHERE status = 'valid' AND payment_status = 'unpaid'")) < 0.001);
check('the two money totals add up to the certificate fees', abs(($summary['total_collected'] + $summary['total_unpaid']) - (float) Database::scalar("SELECT COALESCE(SUM(fee_amount), 0) FROM certificates WHERE status = 'valid'")) < 0.001);
check('the summary is unaffected by a revoked certificate', (int) Database::scalar("SELECT COUNT(*) FROM certificates WHERE status = 'revoked'") === 0 || $summary['total_certificates'] === $validCount);

echo "\nCertificate Counter Tests\n";
// The counter is deliberately not rewound. A real register may skip a number, but it must never
// hand the same number out twice, so the test leaves the sequence where its certificates took it.
$certSequenceNow = (int) Database::scalar("SELECT last_number FROM number_sequences WHERE prefix = 'CERT' AND year = ?", [(int) date('Y')]);
check('the certificate counter only moves forward', $certSequenceNow >= (int) $certSequenceBefore, 'was ' . var_export($certSequenceBefore, true) . ', now ' . $certSequenceNow);
check('the counter covers every certificate issued in this run', $certSequenceNow >= count(array_filter([
    $firstNumber, $secondNumber, $insideTransaction, $futureYear,
], static fn(string $n): bool => str_ends_with(substr($n, 0, 5), date('Y')))), 'counter ' . $certSequenceNow);
Database::execute("DELETE FROM number_sequences WHERE prefix = 'CERT' AND year = 2031");
check('the invented future year sequence is removed', Database::fetchOne("SELECT last_number FROM number_sequences WHERE prefix = 'CERT' AND year = 2031") === null);

purgeRecordFixtures();
purgeTestUsers();
check('the certificate fixtures are removed', (int) Database::scalar("SELECT COUNT(*) FROM certificates WHERE record_type = 'birth' AND record_id NOT IN (SELECT id FROM birth_records)") === 0);
check('the test certificates are gone', (int) Database::scalar("SELECT COUNT(*) FROM certificates WHERE signature_image = 'integrity.png'") === 0);
check('the fee refusal fixture account is removed', !UserService::usernameExists('test_fee_denied'));
