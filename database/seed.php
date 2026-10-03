<?php
/**
 * Civil Registration System — Database Seeder
 * Mogadishu Municipal Government, Banadir Region, Somalia
 *
 * Populates reference data, development accounts, and sample records
 * specified in the system guidelines.
 *
 * Usage: php database/seed.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

const DEV_PASSWORD = 'ChangeMe#2026';

echo "=== Seeding Civil Registration Database ===\n";

// 1. Seed Development Accounts
$users = [
    [
        'name'     => 'Nasra Warsame',
        'username' => 'nasra',
        'email'    => 'nasra.warsame@mogadishu.gov.so',
        'phone'    => '+252 61 500 1101',
        'role'     => 'administrator',
    ],
    [
        'name'     => 'Abdi Farah',
        'username' => 'abdi',
        'email'    => 'abdi.farah@mogadishu.gov.so',
        'phone'    => '+252 61 500 1102',
        'role'     => 'supervisor',
    ],
    [
        'name'     => 'Faduma Ali',
        'username' => 'faduma',
        'email'    => 'faduma.ali@mogadishu.gov.so',
        'phone'    => '+252 61 500 1103',
        'role'     => 'officer',
    ],
    [
        'name'     => 'Mahad Yusuf',
        'username' => 'mahad',
        'email'    => 'mahad.yusuf@mogadishu.gov.so',
        'phone'    => '+252 61 500 1104',
        'role'     => 'officer',
    ],
];

foreach ($users as $u) {
    $exists = Database::scalar('SELECT id FROM users WHERE username = ?', [$u['username']]);
    if ($exists) {
        Database::execute(
            'UPDATE users SET full_name = ?, email = ?, phone = ?, password_hash = ?,
                    role_id = (SELECT id FROM roles WHERE name = ?),
                    status = "active", must_change_password = 1 WHERE username = ?',
            [
                $u['name'],
                $u['email'],
                $u['phone'],
                password_hash(DEV_PASSWORD, PASSWORD_DEFAULT),
                $u['role'],
                $u['username']
            ]
        );
        echo "Updated account: {$u['username']} ({$u['role']})\n";
    } else {
        Database::execute(
            'INSERT INTO users (full_name, username, email, phone, password_hash, role_id, status, must_change_password)
             VALUES (?, ?, ?, ?, ?, (SELECT id FROM roles WHERE name = ?), "active", 1)',
            [
                $u['name'],
                $u['username'],
                $u['email'],
                $u['phone'],
                password_hash(DEV_PASSWORD, PASSWORD_DEFAULT),
                $u['role']
            ]
        );
        echo "Created account: {$u['username']} ({$u['role']})\n";
    }
}

// User IDs lookup
$nasraId  = (int) Database::scalar("SELECT id FROM users WHERE username = 'nasra'");
$abdiId   = (int) Database::scalar("SELECT id FROM users WHERE username = 'abdi'");
$fadumaId = (int) Database::scalar("SELECT id FROM users WHERE username = 'faduma'");
$mahadId  = (int) Database::scalar("SELECT id FROM users WHERE username = 'mahad'");

// District IDs lookup
$districtMap = [];
$districts = Database::fetchAll('SELECT id, name FROM districts');
foreach ($districts as $d) {
    $districtMap[$d['name']] = (int) $d['id'];
}

// Helper to get district ID safely
$getDistId = static function (string $name) use ($districtMap): int {
    if (isset($districtMap[$name])) {
        return $districtMap[$name];
    }
    return (int) Database::scalar('SELECT id FROM districts LIMIT 1');
};

// 2. Number Sequences (Initialize sequences aligned with sample data)
$sequences = [
    ['BR', 2026, 412],
    ['DR', 2026, 107],
    ['MR', 2026, 88],
    ['DV', 2026, 12],
    ['CERT', 2026, 1],
];

foreach ($sequences as [$prefix, $year, $last]) {
    Database::execute(
        'INSERT INTO number_sequences (prefix, year, last_number) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, VALUES(last_number))',
        [$prefix, $year, $last]
    );
}
echo "Number sequences initialized for 2026.\n";

// Helper for status history
$addStatusHistory = static function (string $recordType, int $recordId, string $status, ?string $comment, int $userId): void {
    $exists = Database::scalar(
        'SELECT id FROM record_status_history WHERE record_type = ? AND record_id = ? AND status = ? LIMIT 1',
        [$recordType, $recordId, $status]
    );
    if (!$exists) {
        Database::execute(
            'INSERT INTO record_status_history (record_type, record_id, status, comment, user_id, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$recordType, $recordId, $status, $comment, $userId]
        );
    }
};

// 3. Sample Records

// (a) BR-2026-0412: Amina Abdi Hassan (Approved)
$br412 = Database::fetchOne("SELECT id FROM birth_records WHERE registration_number = 'BR-2026-0412'");
if (!$br412) {
    Database::execute(
        "INSERT INTO birth_records (
            registration_number, child_full_name, gender, date_of_birth, time_of_birth,
            place_of_birth, hospital_of_birth, district_id,
            father_full_name, father_nationality, father_reference, father_phone,
            mother_full_name, mother_nationality, mother_reference, mother_phone,
            registration_date, status, submitted_by, approved_by, approved_at
        ) VALUES (
            'BR-2026-0412', 'Amina Abdi Hassan', 'female', '2026-08-15', '06:45:00',
            'Mogadishu', 'Banadir Maternity & Children Hospital', ?,
            'Abdi Hassan Shire', 'Somali', 'SOM-ID-882194', '+252 61 511 8899',
            'Maryan Mohamed Ali', 'Somali', 'SOM-ID-992144', '+252 61 522 7788',
            '2026-08-20', 'approved', ?, ?, '2026-08-22 10:00:00'
        )",
        [$getDistId('Hodan'), $fadumaId, $abdiId]
    );
    $br412Id = Database::lastId();
    $addStatusHistory('birth', $br412Id, 'submitted', 'Submitted for supervisor review', $fadumaId);
    $addStatusHistory('birth', $br412Id, 'approved', 'Approved with digital signature', $abdiId);

    Database::execute(
        "INSERT INTO approvals (record_type, record_id, submitted_by, reviewed_by, decision, comments, submitted_at, reviewed_at)
         VALUES ('birth', ?, ?, ?, 'approved', 'All supporting identity documents verified.', '2026-08-20 09:30:00', '2026-08-22 10:00:00')",
        [$br412Id, $fadumaId, $abdiId]
    );

    // Certificate with known token for testing verify.php
    $certToken = 'a91f3c7e52b04d6f8e2193b4a2c107e5';
    Database::execute(
        "INSERT INTO certificates (
            certificate_number, record_type, record_id, verification_token,
            issue_date, issued_by, fee_amount, payment_status, paid_by, paid_at,
            status, signature_image, signed_by, signed_at
        ) VALUES (
            'CERT-2026-0001', 'birth', ?, ?,
            '2026-08-22', ?, 20.00, 'paid', ?, '2026-08-22 10:15:00',
            'valid', 'signatures/abdi_signature.png', ?, '2026-08-22 10:00:00'
        )",
        [$br412Id, $certToken, $abdiId, $fadumaId, $abdiId]
    );
    echo "Created sample approved birth record BR-2026-0412 with certificate CERT-2026-0001 (Token: $certToken)\n";
} else {
    echo "Record BR-2026-0412 already exists.\n";
}

// (b) DR-2026-0107: Yusuf Ali Warsame (Submitted)
$dr107 = Database::fetchOne("SELECT id FROM death_records WHERE registration_number = 'DR-2026-0107'");
if (!$dr107) {
    Database::execute(
        "INSERT INTO death_records (
            registration_number, deceased_full_name, gender, date_of_birth, date_of_death, time_of_death,
            place_of_death, district_id, guarantor_full_name, hospital_document_reference,
            nationality, reference_number, marital_status, spouse_name,
            informant_name, informant_relationship, informant_phone, cause_of_death,
            registration_date, status, submitted_by
        ) VALUES (
            'DR-2026-0107', 'Yusuf Ali Warsame', 'male', '1955-04-12', '2026-09-10', '14:20:00',
            'Mogadishu', ?, 'Mohamed Ali Warsame', 'DMH-D-8821',
            'Somali', 'SOM-ID-100234', 'married', 'Hawo Farah Duale',
            'Ali Yusuf Warsame', 'Son', '+252 61 555 1234', 'Cardiovascular arrest',
            '2026-09-12', 'submitted', ?
        )",
        [$getDistId('Hamar Jajab'), $mahadId]
    );
    $dr107Id = Database::lastId();
    $addStatusHistory('death', $dr107Id, 'submitted', 'Submitted with medical death notification', $mahadId);
    Database::execute(
        "INSERT INTO approvals (record_type, record_id, submitted_by, decision, comments, submitted_at)
         VALUES ('death', ?, ?, 'pending', 'Awaiting supervisor verification', '2026-09-12 11:00:00')",
        [$dr107Id, $mahadId]
    );
    echo "Created sample submitted death record DR-2026-0107\n";
}

// (c) MR-2026-0088: Hodan Nur and Abdirahman Jama (Submitted)
$mr88 = Database::fetchOne("SELECT id FROM marriage_records WHERE registration_number = 'MR-2026-0088'");
if (!$mr88) {
    Database::execute(
        "INSERT INTO marriage_records (
            registration_number,
            husband_full_name, husband_date_of_birth, husband_nationality, husband_reference, husband_phone, husband_address,
            wife_full_name, wife_date_of_birth, wife_nationality, wife_reference, wife_phone, wife_address,
            marriage_date, place_of_marriage, district_id, mahr_amount,
            witness1_full_name, witness1_phone, witness2_full_name, witness2_phone,
            registration_date, status, submitted_by
        ) VALUES (
            'MR-2026-0088',
            'Abdirahman Jama Farah', '1994-06-15', 'Somali', 'SOM-ID-774411', '+252 61 511 2233', 'Hamar Weyne, Mogadishu',
            'Hodan Nur Egal', '1997-09-20', 'Somali', 'SOM-ID-885522', '+252 61 522 3344', 'Hamar Weyne, Mogadishu',
            '2026-09-05', 'Mogadishu Central Mosque', ?, '500 USD',
            'Omar Jama Farah', '+252 61 533 4455', 'Hassan Nur Egal', '+252 61 544 5566',
            '2026-09-08', 'submitted', ?
        )",
        [$getDistId('Hamar Weyne'), $fadumaId]
    );
    $mr88Id = Database::lastId();
    $addStatusHistory('marriage', $mr88Id, 'submitted', 'Submitted with religious marriage contract', $fadumaId);
    Database::execute(
        "INSERT INTO approvals (record_type, record_id, submitted_by, decision, comments, submitted_at)
         VALUES ('marriage', ?, ?, 'pending', 'Submitted for supervisor signature and approval', '2026-09-08 14:30:00')",
        [$mr88Id, $fadumaId]
    );
    echo "Created sample submitted marriage record MR-2026-0088\n";
}

// (d) DV-2026-0012: Sahra Aden and Ibrahim Farah (Submitted)
$dv12 = Database::fetchOne("SELECT id FROM divorce_records WHERE registration_number = 'DV-2026-0012'");
if (!$dv12) {
    Database::execute(
        "INSERT INTO divorce_records (
            registration_number, marriage_registration_number,
            husband_full_name, husband_date_of_birth,
            wife_full_name, wife_date_of_birth,
            divorce_date, district_id, divorce_reason, court_reference,
            registration_date, status, submitted_by
        ) VALUES (
            'DV-2026-0012', 'MR-2024-0045',
            'Ibrahim Farah Guled', '1990-03-10',
            'Sahra Aden Warsame', '1993-07-22',
            '2026-08-30', ?, 'Irreconcilable differences with mutual agreement', 'BDR-CIV-2026-441',
            '2026-09-02', 'submitted', ?
        )",
        [$getDistId('Waberi'), $mahadId]
    );
    $dv12Id = Database::lastId();
    $addStatusHistory('divorce', $dv12Id, 'submitted', 'Submitted with Banadir Regional Court decree', $mahadId);
    Database::execute(
        "INSERT INTO approvals (record_type, record_id, submitted_by, decision, comments, submitted_at)
         VALUES ('divorce', ?, ?, 'pending', 'Court documentation attached, pending supervisor approval', '2026-09-02 10:15:00')",
        [$dv12Id, $mahadId]
    );
    echo "Created sample submitted divorce record DV-2026-0012\n";
}

// (e) BR-2026-0409: Abdullahi Yusuf Ali (Correction Required)
$br409 = Database::fetchOne("SELECT id FROM birth_records WHERE registration_number = 'BR-2026-0409'");
if (!$br409) {
    Database::execute(
        "INSERT INTO birth_records (
            registration_number, child_full_name, gender, date_of_birth, time_of_birth,
            place_of_birth, hospital_of_birth, district_id,
            father_full_name, father_nationality, father_reference, father_phone,
            mother_full_name, mother_nationality, mother_reference, mother_phone,
            registration_date, status, submitted_by
        ) VALUES (
            'BR-2026-0409', 'Abdullahi Yusuf Ali', 'male', '2026-07-10', '11:15:00',
            'Mogadishu', 'Kalkaal Hospital', ?,
            'Yusuf Ali Omar', 'Somali', 'SOM-ID-332112', '+252 61 588 9900',
            'Fatima Abdi Shire', 'Somali', 'SOM-ID-443221', '+252 61 577 8899',
            '2026-07-15', 'correction_required', ?
        )",
        [$getDistId('Wadajir'), $fadumaId]
    );
    $br409Id = Database::lastId();
    $addStatusHistory('birth', $br409Id, 'submitted', 'Submitted for review', $fadumaId);
    $addStatusHistory('birth', $br409Id, 'correction_required', 'Mother reference ID format needs correction', $abdiId);
    Database::execute(
        "INSERT INTO approvals (record_type, record_id, submitted_by, reviewed_by, decision, comments, submitted_at, reviewed_at)
         VALUES ('birth', ?, ?, ?, 'correction_required', 'Please verify mother\'s national reference number and confirm spelling of maternal surname with hospital delivery card.', '2026-07-15 08:30:00', '2026-07-16 09:45:00')",
        [$br409Id, $fadumaId, $abdiId]
    );
    echo "Created sample correction-required birth record BR-2026-0409\n";
}

// (f) BR-2026-0408: Fartun Ali Guled (Rejected)
$br408 = Database::fetchOne("SELECT id FROM birth_records WHERE registration_number = 'BR-2026-0408'");
if (!$br408) {
    Database::execute(
        "INSERT INTO birth_records (
            registration_number, child_full_name, gender, date_of_birth, time_of_birth,
            place_of_birth, hospital_of_birth, district_id,
            father_full_name, father_nationality, father_reference, father_phone,
            mother_full_name, mother_nationality, mother_reference, mother_phone,
            registration_date, status, submitted_by
        ) VALUES (
            'BR-2026-0408', 'Fartun Ali Guled', 'female', '2026-06-18', '03:10:00',
            'Mogadishu', 'SOS Children Hospital', ?,
            'Ali Guled Barre', 'Somali', 'SOM-ID-110099', '+252 61 566 4433',
            'Asha Mohamed Jimale', 'Somali', 'SOM-ID-220088', '+252 61 555 3322',
            '2026-06-20', 'rejected', ?
        )",
        [$getDistId('Heliwa'), $mahadId]
    );
    $br408Id = Database::lastId();
    $addStatusHistory('birth', $br408Id, 'submitted', 'Submitted for review', $mahadId);
    $addStatusHistory('birth', $br408Id, 'rejected', 'Rejected: duplicate hospital notification reference', $abdiId);
    Database::execute(
        "INSERT INTO approvals (record_type, record_id, submitted_by, reviewed_by, decision, rejection_reason, submitted_at, reviewed_at)
         VALUES ('birth', ?, ?, ?, 'rejected', 'Duplicate hospital notification reference submitted. Physical inspection and hospital confirmation required.', '2026-06-20 10:00:00', '2026-06-21 14:00:00')",
        [$br408Id, $mahadId, $abdiId]
    );
    echo "Created sample rejected birth record BR-2026-0408\n";
}

// (g) BR-2026-0407: Idil Hassan Nur (Draft)
$br407 = Database::fetchOne("SELECT id FROM birth_records WHERE registration_number = 'BR-2026-0407'");
if (!$br407) {
    Database::execute(
        "INSERT INTO birth_records (
            registration_number, child_full_name, gender, date_of_birth, time_of_birth,
            place_of_birth, hospital_of_birth, district_id,
            father_full_name, father_nationality, father_reference, father_phone,
            mother_full_name, mother_nationality, mother_reference, mother_phone,
            registration_date, status, submitted_by
        ) VALUES (
            'BR-2026-0407', 'Idil Hassan Nur', 'female', '2026-09-01', '08:25:00',
            'Mogadishu', 'De Martino Hospital', ?,
            'Hassan Nur Mohamed', 'Somali', 'SOM-ID-667788', '+252 61 544 1122',
            'Deqa Farah Ali', 'Somali', 'SOM-ID-554433', '+252 61 533 2211',
            '2026-09-05', 'draft', ?
        )",
        [$getDistId('Shangani'), $fadumaId]
    );
    $br407Id = Database::lastId();
    $addStatusHistory('birth', $br407Id, 'draft', 'Draft saved by officer', $fadumaId);
    echo "Created sample draft birth record BR-2026-0407\n";
}

// 4. Initial Notifications
$initialNotifs = [
    [$abdiId, 'Pending Approval: Death Record', 'New death record DR-2026-0107 submitted by Mahad Yusuf for review.', 'warning'],
    [$abdiId, 'Pending Approval: Marriage Record', 'New marriage record MR-2026-0088 submitted by Faduma Ali for review.', 'info'],
    [$abdiId, 'Pending Approval: Divorce Record', 'New divorce record DV-2026-0012 submitted by Mahad Yusuf for review.', 'info'],
    [$fadumaId, 'Correction Required: BR-2026-0409', 'Supervisor Abdi Farah requested correction for birth record BR-2026-0409.', 'warning'],
    [$mahadId, 'Record Rejected: BR-2026-0408', 'Birth record BR-2026-0408 was rejected by supervisor Abdi Farah.', 'danger'],
];
foreach ($initialNotifs as [$uid, $title, $msg, $type]) {
    $exists = Database::scalar('SELECT id FROM notifications WHERE user_id = ? AND title = ? LIMIT 1', [$uid, $title]);
    if (!$exists) {
        Database::execute(
            'INSERT INTO notifications (user_id, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())',
            [$uid, $title, $msg, $type]
        );
    }
}
echo "Sample notifications seeded.\n";

echo "=== Seeding Finished Successfully ===\n";
echo "Development Accounts (Default Password: " . DEV_PASSWORD . "):\n";
echo " - nasra  (Administrator)\n";
echo " - abdi   (Supervisor)\n";
echo " - faduma (Officer)\n";
echo " - mahad  (Officer)\n";
