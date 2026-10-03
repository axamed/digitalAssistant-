<?php
declare(strict_types=1);
/** User administration and role/permission management. */

use App\Core\{Auth, Database};
use App\Services\{ApprovalService, RecordService, RoleService, UserService};

purgeFixtures();   // a crashed earlier run must not block these tests
Auth::logout(false);
Auth::attempt('nasra', 'ChangeMe#2026');

echo "\nPassword Policy Tests\n";
check('a password under the 6 character minimum is refused', UserService::passwordError('Ab1cd') === 'The password must be at least 6 characters long.');
check('a password at the 6 character minimum is accepted', UserService::passwordError('Ab1cde') === null);
check('a password without digits is refused', UserService::passwordError('abcdefghijkl') === 'The password must contain both letters and numbers.');
check('a password without letters is refused', UserService::passwordError('1234567890123') === 'The password must contain both letters and numbers.');
check('a long password with letters and digits passes', UserService::passwordError('ChangeMe#2026') === null);

echo "\nTemporary Password Tests\n";
$temp1 = UserService::temporaryPassword();
$temp2 = UserService::temporaryPassword();
check('a generated password is Cr-XXXX-XXXX-XXXX-XXXXnn', (bool) preg_match('/^Cr-[A-Z2-9]{4}(-[A-Z2-9]{4}){3}\d{2}$/', $temp1));
check('a generated password satisfies the policy', UserService::passwordError($temp1) === null);
check('two generated passwords differ', $temp1 !== $temp2);

echo "\nUsername Lookup Tests\n";
$nasraId = (int) Database::scalar("SELECT id FROM users WHERE username = 'nasra'");
check('username lookup ignores case', UserService::usernameExists('NASRA'));
check('username lookup can exclude the account itself', !UserService::usernameExists('nasra', $nasraId));
check('username lookup still finds another account', UserService::usernameExists('nasra', $nasraId + 1));

echo "\nUser Creation Tests\n";
/** Run UserService::create() and return the error message, or null on success. */
$createError = static function (array $data): ?string {
    try {
        UserService::create($data);
        return null;
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }
};
$validUser = [
    'full_name' => 'Test Officer One',
    'username'  => 'Test_Officer_One',
    'email'     => 'officer.one@example.so',
    'phone'     => '+252 61 555 0001',
    'role_name' => 'officer',
];
check('a full name is required', $createError(['username' => 'x1', 'role_name' => 'officer']) === 'Full name is required.');
check('a short username is refused', $createError(['full_name' => 'X', 'username' => 'ab', 'role_name' => 'officer']) !== null);
check('a username with spaces is refused', $createError(['full_name' => 'X', 'username' => 'has space', 'role_name' => 'officer']) !== null);
check('a malformed email is refused', $createError(['full_name' => 'X', 'username' => 'okname', 'email' => 'nope@', 'role_name' => 'officer']) === 'Enter a valid email address.');
check('an unknown role is refused', $createError(['full_name' => 'X', 'username' => 'okname2', 'role_name' => 'mayor']) === 'Select a valid role.');
check('a weak password is refused', $createError(['full_name' => 'X', 'username' => 'okname3', 'role_name' => 'officer', 'password' => 'weak']) === 'The password must be at least 6 characters long.');

$created = UserService::create(['full_name' => 'Test Officer One', 'username' => 'Test_Officer_One', 'role_name' => 'officer', 'password' => 'TestOfficer#2026']);
$officerId = (int) $created['id'];
$officerRow = UserService::find($officerId);
check('a new account is stored lowercased', (string) $officerRow['username'] === 'test_officer_one');
check('a new account is active and carries its role', $officerRow['status'] === 'active' && $officerRow['role_name'] === 'officer');
check('a new account must set its own password', (int) $officerRow['must_change_password'] === 1);
check('the password is stored hashed, never in clear', password_verify('TestOfficer#2026', (string) $officerRow['password_hash']) && !str_contains((string) $officerRow['password_hash'], 'TestOfficer#2026'));
check('a duplicate username is refused', $createError($validUser) === 'That username is already taken.');
$second = UserService::create(['full_name' => 'Test Officer Two', 'username' => 'test_officer_two', 'role_name' => 'officer']);
check('an account created without a password gets a generated one', (bool) preg_match('/^Cr-[A-Z2-9]{4}(-[A-Z2-9]{4}){3}\d{2}$/', $second['password']));

echo "\nUser Sign-In Tests\n";
Auth::logout(false);
check('the new account can sign in', Auth::attempt('test_officer_one', 'TestOfficer#2026') === true);
check('signing in loads the role permissions', in_array('birth.create', Auth::user()['permissions'], true) && !in_array('user.manage', Auth::user()['permissions'], true));
check('a new officer is routed to the officer dashboard', Auth::homePath() === 'officer/dashboard.php');
Auth::logout(false);

echo "\nAccount Status Tests\n";
Auth::attempt('nasra', 'ChangeMe#2026');
check('an invalid status is refused', (static function () use ($officerId): bool {
    try {
        UserService::setStatus($officerId, 'suspended');
        return false;
    } catch (\RuntimeException $e) {
        return true;
    }
})());
check('an unknown account is refused', (static function (): bool {
    try {
        UserService::setStatus(999999, 'inactive');
        return false;
    } catch (\RuntimeException $e) {
        return $e->getMessage() === 'User not found.';
    }
})());
check('an administrator cannot deactivate their own account', (static function () use ($nasraId): bool {
    try {
        UserService::setStatus($nasraId, 'inactive');
        return false;
    } catch (\RuntimeException $e) {
        return $e->getMessage() === 'You cannot change the status of your own account.';
    }
})());
check('an administrator cannot change their own role', (static function (): bool {
    try {
        UserService::update((int) Auth::id(), ['full_name' => 'Nasra', 'role_name' => 'officer', 'status' => 'active']);
        return false;
    } catch (\RuntimeException $e) {
        return $e->getMessage() === 'You cannot change your own role.';
    }
})());

// An officer holding a record in the review queue may not be pulled off the system.
Auth::logout(false);
Auth::attempt('test_officer_one', 'TestOfficer#2026');
$openRecord = RecordService::createBirth([
    'child_full_name'    => 'Temp Officer Test Child',
    'gender'             => 'female',
    'date_of_birth'      => '2026-05-06',
    'place_of_birth'     => 'Mogadishu',
    'hospital_of_birth'  => 'Benadir General',
    'district_id'        => 1,
    'father_full_name'   => 'Temp Father',
    'father_nationality' => 'Somali',
    'mother_full_name'   => 'Temp Mother',
    'mother_nationality' => 'Somali',
    'registration_date'  => '2026-09-25',
]);
ApprovalService::submit('birth', (int) $openRecord['id'], 'awaiting review');
Auth::logout(false);
Auth::attempt('nasra', 'ChangeMe#2026');
check('an officer with a record awaiting review cannot be deactivated', (static function () use ($officerId): bool {
    try {
        UserService::setStatus($officerId, 'inactive');
        return false;
    } catch (\RuntimeException $e) {
        return str_contains($e->getMessage(), 'awaiting review');
    }
})());

purgeRecordFixtures();   // clears the record and its history, so nothing is left in the queue
check('an officer with an empty queue can be deactivated', (static function () use ($officerId): bool {
    UserService::setStatus($officerId, 'inactive');
    return UserService::find($officerId)['status'] === 'inactive';
})());
check('a disabled account cannot sign in', (static function (): bool {
    Auth::logout(false);
    $result = Auth::attempt('test_officer_one', 'TestOfficer#2026');
    Auth::logout(false);
    return is_string($result) && str_contains($result, 'disabled');
})());
check('unlocking a disabled account is refused', (static function () use ($officerId): bool {
    try {
        UserService::unlock($officerId);
        return false;
    } catch (\RuntimeException $e) {
        return $e->getMessage() === 'This account is inactive. Activate it before unlocking.';
    }
})());
UserService::setStatus($officerId, 'active');
check('a reactivated account can sign in again', Auth::attempt('test_officer_one', 'TestOfficer#2026') === true);
Auth::logout(false);

echo "\nAdministrative Password Reset Tests\n";
Auth::attempt('nasra', 'ChangeMe#2026');
Database::execute('UPDATE users SET failed_attempts = 3, locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?', [$officerId]);
$resetPassword = UserService::resetPassword($officerId, 'ResetPass#2026');
$resetRow = UserService::find($officerId);
check('a reset returns the new password', $resetPassword === 'ResetPass#2026');
check('a reset forces a password change at next sign-in', (int) $resetRow['must_change_password'] === 1);
check('a reset clears the lockout', (int) $resetRow['failed_attempts'] === 0 && $resetRow['locked_until'] === null);
check('the account signs in with the reset password', (static function (): bool {
    Auth::logout(false);
    $ok = Auth::attempt('test_officer_one', 'ResetPass#2026') === true;
    Auth::logout(false);
    return $ok;
})());
check('a weak reset password is refused', (static function () use ($officerId): bool {
    try {
        UserService::resetPassword($officerId, 'short');
        return false;
    } catch (\RuntimeException $e) {
        return $e->getMessage() === 'The password must be at least 6 characters long.';
    }
})());
check('resetting an unknown account is refused', (static function (): bool {
    try {
        UserService::resetPassword(999999);
        return false;
    } catch (\RuntimeException $e) {
        return $e->getMessage() === 'User not found.';
    }
})());

echo "\nRole and Permission Tests\n";
$roles = RoleService::all();
check('the three roles are listed with a permission count', count($roles) === 3 && array_reduce($roles, static fn(bool $ok, array $r): bool => $ok && isset($r['permission_count']), true));
check('a role can be looked up by name', (RoleService::findByName('supervisor')['label'] ?? '') !== '' && RoleService::findByName('mayor') === null);

$officerRoleId   = (int) RoleService::findByName('officer')['id'];
$adminRoleId     = (int) RoleService::findByName('administrator')['id'];
$supervisorRoleId = (int) RoleService::findByName('supervisor')['id'];
$officerCodes   = RoleService::permissionCodes($officerRoleId);
$adminCodes     = RoleService::permissionCodes($adminRoleId);
$supervisorCodes = RoleService::permissionCodes($supervisorRoleId);

// A role's permission set is administered at runtime from admin/roles.php, so these checks
// assert the invariants that must hold whatever an administrator grants — not the seeded
// policy. Pinning the seeded grants here made the suite go red the moment an administrator
// moved a permission between roles, which is a legitimate change, not a defect.
$catalogue = array_column(RoleService::permissions(), 'code');
check('every role keeps at least one permission', $officerCodes !== [] && $adminCodes !== [] && $supervisorCodes !== []);
check('the administrator role holds the whole catalogue', $adminCodes === $catalogue, count($adminCodes) . ' of ' . count($catalogue));
check('every grant refers to a real catalogue permission', array_diff(
    array_merge($officerCodes, $adminCodes, $supervisorCodes),
    $catalogue
) === []);
check('only the administrator manages users', in_array('user.manage', $adminCodes, true)
    && !in_array('user.manage', $officerCodes, true)
    && !in_array('user.manage', $supervisorCodes, true));
check('no role is granted the same permission twice', count($officerCodes) === count(array_unique($officerCodes))
    && count($supervisorCodes) === count(array_unique($supervisorCodes)));

$grouped = RoleService::groupedPermissions();
$groupedTotal = array_sum(array_map('count', $grouped));
check('the permission catalogue is grouped by module', isset($grouped['record'], $grouped['certificate'], $grouped['user']) && count($grouped['record']) === 8 && count($grouped['certificate']) === 3);
check('every catalogue entry lands in exactly one module', $groupedTotal === count(RoleService::permissions()));
check('an unknown role is refused', (static function (): bool {
    try {
        RoleService::setPermissions(999999, ['birth.create']);
        return false;
    } catch (\RuntimeException $e) {
        return $e->getMessage() === 'Role not found.';
    }
})());
check('a role cannot be stripped of every permission', (static function () use ($officerRoleId, $officerCodes): bool {
    try {
        RoleService::setPermissions($officerRoleId, []);
        return false;
    } catch (\RuntimeException $e) {
        return $e->getMessage() === 'A role must keep at least one permission.' && RoleService::permissionCodes($officerRoleId) === $officerCodes;
    }
})());
// The administrator is the superuser, so a form that posts a reduced set for that role is
// topped back up to the whole catalogue instead of narrowing it. Nothing is thrown: the
// administrator must never be the reason the system locks itself out.
check('the administrator cannot be narrowed below the whole catalogue', (static function () use ($adminRoleId, $catalogue): bool {
    RoleService::setPermissions($adminRoleId, ['audit.view', 'backup.manage']);
    return RoleService::permissionCodes($adminRoleId) === $catalogue;
})());
check('an administrator passes any permission check', (static function () use ($catalogue): bool {
    Auth::logout(false);
    $ok = Auth::attempt('nasra', 'ChangeMe#2026') === true;
    // A code that is not in the catalogue at all still passes, which is what makes the
    // administrator a superuser rather than merely a well-permissioned desk.
    $ok = $ok && Auth::isAdmin() && Auth::can('report.generate') && Auth::can('anything.at.all');
    Auth::logout(false);
    $ok = $ok && Auth::attempt('faduma', 'ChangeMe#2026') === true;
    $ok = $ok && !Auth::isAdmin() && !Auth::can('anything.at.all') && in_array('birth.create', $catalogue, true);
    Auth::logout(false);
    return $ok;
})());
check('a new permission is granted to the administrator straight away', (static function () use ($catalogue): bool {
    // The code has to match the catalogue's shape, and every segment must start with a
    // letter, so the random part is prefixed rather than left to chance.
    $code = 'check.superuser.f' . substr(bin2hex(random_bytes(4)), 0, 7);
    RoleService::createPermission($code, 'Suite fixture');
    $granted = in_array($code, RoleService::permissionCodes((int) RoleService::findByName('administrator')['id']), true);
    RoleService::deletePermission((int) RoleService::findPermissionByCode($code)['id']);
    return $granted && !in_array($code, $catalogue, true) && RoleService::findPermissionByCode($code) === null;
})());

// Unknown codes are dropped rather than stored, and the change is applied as a set.
RoleService::setPermissions($officerRoleId, ['birth.create', 'not.a.real.permission', 'birth.create']);
check('unknown permission codes are ignored', RoleService::permissionCodes($officerRoleId) === ['birth.create']);
RoleService::setPermissions($officerRoleId, $officerCodes);
check('the original permission set is restored', RoleService::permissionCodes($officerRoleId) === $officerCodes);
RoleService::setPermissions($officerRoleId, $officerCodes);
check('re-applying the same set changes nothing', RoleService::permissionCodes($officerRoleId) === $officerCodes);
check('editing another role leaves the administrator on the whole catalogue', RoleService::permissionCodes($adminRoleId) === $catalogue);

// The roles screen must not offer a way to narrow the administrator, or the service would
// silently ignore what was just posted. It is a page, so it is rendered in its own process.
$roleScreen = static function (int $roleId): array {
    $restore = Auth::user();
    Auth::logout(false);
    Auth::attempt('nasra', 'ChangeMe#2026');
    $payload = (string) json_encode(Auth::user(), JSON_THROW_ON_ERROR);
    if ($restore !== null) {
        Auth::logout(false);
        $_SESSION['user'] = $restore;
    }
    $query = '/admin/roles.php?role=' . $roleId;
    $result = runSnippet(
        'session_id("crroles" . getmypid());' . "\n"
        . 'session_start();' . "\n"
        . '$_SESSION["user"] = json_decode(' . var_export($payload, true) . ', true);' . "\n"
        . '$_SERVER["REQUEST_URI"] = ' . var_export($query, true) . ';' . "\n"
        . 'parse_str((string) parse_url(' . var_export($query, true) . ', PHP_URL_QUERY), $_GET);' . "\n"
        . 'ob_start();' . "\n"
        . 'require ' . var_export(BASE_PATH . '/public/admin/roles.php', true) . ';' . "\n"
        . 'file_put_contents(SNIPPET_OUT, (string) ob_get_clean());',
        false
    );
    // The template wraps its attributes, so the markup is compared with its runs of
    // whitespace collapsed rather than matched literally.
    return [
        'html'      => $result['file'],
        'rendered'  => $result['file'] !== '',
        'locked'    => preg_match_all('/<input type="checkbox"[^>]*\bchecked\b[^>]*\bdisabled\b/', preg_replace('/\s+/', ' ', $result['file'])),
        'editable'  => substr_count((string) preg_replace('/\s+/', ' ', $result['file']), 'Save Permissions</button>'),
    ];
};

$adminScreen = $roleScreen($adminRoleId);
$officerScreen = $roleScreen($officerRoleId);
check('the roles screen renders the administrator with every box ticked and locked', $adminScreen['rendered']
    && $adminScreen['locked'] === count($catalogue)
    && str_contains((string) preg_replace('/\s+/', ' ', $adminScreen['html']), 'already holds all ' . count($catalogue) . ' permissions'));
check('the roles screen still lets an ordinary role be edited', $officerScreen['rendered']
    && $officerScreen['editable'] === 1
    && $officerScreen['locked'] < count($catalogue));

purgeFixtures();
Auth::logout(false);
