<?php
declare(strict_types=1);
/** Self-service profile security: own password, own username, own photo. */

use App\Core\{Audit, Auth, Database};
use App\Services\{PhotoService, UserService};

purgeFixtures();   // a crashed earlier run must not block these tests
Auth::logout(false);
Auth::attempt('nasra', 'ChangeMe#2026');

/** Run a self-service call and return its error message, or null on success. */
$serviceError = static function (callable $fn): ?string {
    try {
        $fn();
        return null;
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }
};

// A 1x1 PNG, the smallest real image, for the storage and rendering checks below.
$pngBytes = (string) base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    true
);
/** A PNG whose header claims a given size, to exercise the dimension limit without GD. */
$pngOfSize = static function (int $width, int $height): string {
    $ihdr = pack('N2C5', $width, $height, 8, 2, 0, 0, 0);
    return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
};

$avatarDir = BASE_PATH . '/storage/uploads/avatars';
if (!is_dir($avatarDir)) {
    mkdir($avatarDir, 0755, true);
}
$scratch = [];
/** Put a file into the avatars directory under a name of the given user id. */
$storePhoto = static function (string $name, string $bytes) use ($avatarDir, &$scratch): string {
    $path = $avatarDir . '/' . $name;
    file_put_contents($path, $bytes);
    $scratch[] = $path;
    return 'avatars/' . $name;
};
/** Remove anything a failed check left in the avatars directory. */
$cleanPhotos = static function () use (&$scratch): void {
    foreach ($scratch as $path) {
        @unlink($path);
    }
    $scratch = [];
};

$created = UserService::create([
    'full_name' => 'Test Profile Officer',
    'username'  => 'test_profile',
    'role_name' => 'officer',
    'password'  => 'TestProfile#2026',
]);
$id = (int) $created['id'];
$startPassword = (string) $created['password'];
$row = static fn(): ?array => UserService::find($id);
$auditHas = static function (string $action) use ($id): bool {
    return (int) Database::scalar('SELECT COUNT(*) FROM audit_logs WHERE user_id = ? AND action = ?', [$id, $action]) > 0;
};

echo "\nOwn Password Tests\n";
check('a wrong current password is refused', $serviceError(static fn() => UserService::changeOwnPassword($id, 'not-the-password', 'BrandNew#2026x', 'BrandNew#2026x')) === 'Your current password is not correct.');
check('a mismatched confirmation is refused', $serviceError(static fn() => UserService::changeOwnPassword($id, $startPassword, 'BrandNew#2026x', 'BrandNew#2026y')) === 'The new passwords do not match.');
check('a password under the 6 character minimum is refused', $serviceError(static fn() => UserService::changeOwnPassword($id, $startPassword, 'Ab1cd', 'Ab1cd')) === 'The password must be at least 6 characters long.');
check('a password without digits is refused', $serviceError(static fn() => UserService::changeOwnPassword($id, $startPassword, 'abcdefghijkl', 'abcdefghijkl')) === 'The password must contain both letters and numbers.');
check('reusing the current password is refused', $serviceError(static fn() => UserService::changeOwnPassword($id, $startPassword, $startPassword, $startPassword)) === 'The new password must be different from the current one.');
check('an unknown account is refused', $serviceError(static fn() => UserService::changeOwnPassword(999999, 'x', 'BrandNew#2026x', 'BrandNew#2026x')) === 'Your account could not be loaded.');
check('every refusal left the stored hash alone', (static function () use ($row, $startPassword): bool {
    $current = $row();
    return password_verify($startPassword, (string) $current['password_hash']) && (int) $current['must_change_password'] === 1;
})());

UserService::changeOwnPassword($id, $startPassword, 'BrandNew#2026x', 'BrandNew#2026x');
check('the new password verifies', password_verify('BrandNew#2026x', (string) $row()['password_hash']));
check('the old password no longer verifies', !password_verify($startPassword, (string) $row()['password_hash']));
check('the account is released from the first-sign-in step', (int) $row()['must_change_password'] === 0);
check('a lockout is cleared by a successful change', (static function () use ($id): bool {
    Database::execute('UPDATE users SET failed_attempts = 3, locked_until = ? WHERE id = ?', [date('Y-m-d H:i:s', time() + 900), $id]);
    UserService::changeOwnPassword($id, 'BrandNew#2026x', 'ThirdPass#2026y', 'ThirdPass#2026y');
    $current = UserService::find($id);
    return (int) $current['failed_attempts'] === 0 && $current['locked_until'] === null;
})());
check('the change is written to the audit trail', $auditHas('password_changed'));

echo "\nOwn Username Tests\n";
check('a wrong current password is refused', $serviceError(static fn() => UserService::changeOwnUsername($id, 'test_profile_renamed', 'not-the-password')) === 'Your current password is not correct.');
check('a short username is refused', $serviceError(static fn() => UserService::changeOwnUsername($id, 'ab', 'ThirdPass#2026y')) === 'Username must be 3-30 characters: lowercase letters, digits, dot, dash or underscore.');
check('a username with a space is refused', $serviceError(static fn() => UserService::changeOwnUsername($id, 'has space', 'ThirdPass#2026y')) === 'Username must be 3-30 characters: lowercase letters, digits, dot, dash or underscore.');
check('the current username is refused', $serviceError(static fn() => UserService::changeOwnUsername($id, 'TEST_PROFILE', 'ThirdPass#2026y')) === 'That is already your username.');
check('a username belonging to somebody else is refused', $serviceError(static fn() => UserService::changeOwnUsername($id, 'nasra', 'ThirdPass#2026y')) === 'That username is already taken.');
check('an unknown account is refused', $serviceError(static fn() => UserService::changeOwnUsername(999999, 'test_ghost', 'x')) === 'Your account could not be loaded.');

$renamed = UserService::changeOwnUsername($id, 'Test_Profile_Renamed', 'ThirdPass#2026y');
check('the username is stored lowercased', $renamed === 'test_profile_renamed' && (string) $row()['username'] === 'test_profile_renamed');
check('the old username is gone from the account', !UserService::usernameExists('test_profile'));
check('the rename is written to the audit trail', $auditHas('username_changed'));
check('the account still signs in under the new username', (static function (): bool {
    Auth::logout(false);
    $result = Auth::attempt('Test_Profile_Renamed', 'ThirdPass#2026y');
    $ok = $result === true && (string) Auth::user()['username'] === 'test_profile_renamed';
    Auth::logout(false);
    return $ok;
})());
check('the old username can no longer sign in', (static function (): bool {
    Auth::logout(false);
    $result = Auth::attempt('test_profile', 'ThirdPass#2026y');
    Auth::logout(false);
    return $result !== true;
})());

echo "\nProfile Photo Storage Tests\n";
check('a photo outside the avatars directory is not reachable', PhotoService::absolutePath('uploads/signature_x.png') === null);
check('a traversal out of the avatars directory is refused', (static function (): bool {
    $escape = PhotoService::absolutePath('avatars/../../database/schema.sql');
    return $escape === null && PhotoService::absolutePath('../../database/schema.sql') === null;
})());
check('a photo that is not there resolves to nothing', PhotoService::absolutePath('avatars/no_such_photo.png') === null);
check('a missing or empty path yields no inline image', PhotoService::dataUri(null) === null && PhotoService::dataUri('  ') === null && PhotoService::dataUri('avatars/gone.png') === null);

$ownPhoto = $storePhoto("user_{$id}_suite.png", $pngBytes);
$otherId = (int) Database::scalar("SELECT id FROM users WHERE username = 'nasra'");
$othersPhoto = $storePhoto("user_{$otherId}_suite.png", $pngBytes);

UserService::setPhoto($id, $ownPhoto);
check('the account points at the stored photo', (string) $row()['photo_path'] === $ownPhoto);
check('the stored file is left in place', is_file((string) PhotoService::absolutePath($ownPhoto)));
check('the photo is inlined for display under the type the bytes declare', (static function () use ($ownPhoto, $pngBytes): bool {
    $dataUri = PhotoService::dataUri($ownPhoto);
    return is_string($dataUri)
        && str_starts_with($dataUri, 'data:image/png;base64,')
        && base64_decode(substr($dataUri, strlen('data:image/png;base64,')), true) === $pngBytes;
})());
check('the photo change is written to the audit trail', $auditHas('photo_updated'));

$replacement = $storePhoto("user_{$id}_suite2.png", $pngBytes);
UserService::setPhoto($id, $replacement);
check('replacing a photo deletes the file it superseded', !is_file((string) PhotoService::absolutePath($ownPhoto)) && is_file((string) PhotoService::absolutePath($replacement)));

UserService::setPhoto($id, null);
check('removing a photo clears the account and deletes the file', $row()['photo_path'] === null && !is_file((string) PhotoService::absolutePath($replacement)));
check('the removal is written to the audit trail', $auditHas('photo_removed'));

check('a photo belonging to another account is never deleted', (static function () use ($id, $othersPhoto): bool {
    PhotoService::delete($othersPhoto, $id);
    return is_file((string) PhotoService::absolutePath($othersPhoto));
})());
check('its owner can still delete it', (static function () use ($otherId, $othersPhoto): bool {
    PhotoService::delete($othersPhoto, $otherId);
    return PhotoService::absolutePath($othersPhoto) === null;
})());
check('a path outside the avatars directory is never deleted', (static function (): bool {
    $outside = BASE_PATH . '/database/schema.sql';
    PhotoService::delete('../database/schema.sql', 1);
    return is_file($outside);
})());

echo "\nProfile Photo Upload Tests\n";
$tmpDir = BASE_PATH . '/storage/uploads';
$textFile = $tmpDir . '/suite_not_an_image.txt';
file_put_contents($textFile, 'this is not an image');
$realPng = $tmpDir . '/suite_real.png';
file_put_contents($realPng, $pngBytes);
$hugePng = $tmpDir . '/suite_huge.png';
file_put_contents($hugePng, $pngOfSize(4000, 4000));
$scratch = [$textFile, $realPng, $hugePng];
$upload = [
    'error'            => UPLOAD_ERR_OK,
    'name'             => 'me.png',
    'size'             => strlen($pngBytes),
    'tmp_name'         => $realPng,
    'type'             => 'image/png',
];

check('a request with no file selected is refused', $serviceError(static fn() => PhotoService::saveUpload(['error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'tmp_name' => '', 'name' => ''], $id)) === 'No photo was selected.');
check('a file over 2 MB is refused', $serviceError(static fn() => PhotoService::saveUpload(['error' => UPLOAD_ERR_OK, 'name' => 'big.png', 'size' => 3 * 1024 * 1024, 'tmp_name' => $realPng], $id)) === 'The photo must be smaller than 2 MB.');
check('a file that is not an image is refused', $serviceError(static fn() => PhotoService::saveUpload(['error' => UPLOAD_ERR_OK, 'name' => 'note.txt', 'size' => 22, 'tmp_name' => $textFile], $id)) === 'The photo must be a JPG, PNG, GIF or WEBP image.');
check('an image over 3000 pixels is refused', $serviceError(static fn() => PhotoService::saveUpload(['error' => UPLOAD_ERR_OK, 'name' => 'huge.png', 'size' => 68, 'tmp_name' => $hugePng], $id)) === 'The photo is too large; please use an image under 3000 pixels wide.');
check('an upload error code is reported, not ignored', $serviceError(static fn() => PhotoService::saveUpload(['error' => UPLOAD_ERR_PARTIAL, 'name' => 'me.png', 'size' => 10, 'tmp_name' => $realPng], $id)) === 'The photo could not be uploaded (error code 3).');
check('no refused upload was written to the avatars directory', (static function () use ($id, $avatarDir): bool {
    return count(glob($avatarDir . '/user_' . $id . '_*') ?: []) === 0;
})(), implode(', ', glob($avatarDir . '/user_' . $id . '_*') ?: []));
$cleanPhotos();

echo "\nProfile Screen Tests\n";
// public/profile.php now carries the details form, the photo and the two security forms, so it
// is rendered in its own process with the signed-in user handed over through the session.
$restore = Auth::user();
Auth::logout(false);
Auth::attempt('nasra', 'ChangeMe#2026');
$session = (string) json_encode(Auth::user(), JSON_THROW_ON_ERROR);
if ($restore !== null) {
    $_SESSION['user'] = $restore;
}
$screen = runSnippet(
    'session_id("crprofile" . getmypid());' . "\n"
    . 'session_start();' . "\n"
    . '$_SESSION["user"] = json_decode(' . var_export($session, true) . ', true);' . "\n"
    . '$_SERVER["REQUEST_URI"] = "/profile.php";' . "\n"
    . 'ob_start();' . "\n"
    . 'require ' . var_export(BASE_PATH . '/public/profile.php', true) . ';' . "\n"
    . 'file_put_contents(SNIPPET_OUT, (string) ob_get_clean());',
    false
);
$html = $screen['file'] !== '' ? $screen['file'] : $screen['stdout'];
check('the profile screen renders with all three security forms', str_contains($html, 'Update my details')
    && str_contains($html, 'Profile photo')
    && str_contains($html, 'Change username')
    && str_contains($html, 'Change password'));
check('the security forms carry their own action', substr_count($html, 'name="action" value="details"') === 1
    && substr_count($html, 'name="action" value="photo"') === 1
    && substr_count($html, 'name="action" value="username"') === 1
    && substr_count($html, 'name="action" value="password"') === 1);
check('the upload form is multipart and accepts images only', str_contains($html, 'enctype="multipart/form-data"') && str_contains($html, 'accept="image/png,image/jpeg,image/gif,image/webp"'));
check('the header shows the initials of an account with no photo', str_contains($html, 'avatar-initials') && substr_count($html, 'avatar-initials') >= 2);
check('the header does not ask for a photo that is not there', !str_contains($html, 'avatar.php'));

$withPhoto = $storePhoto("user_{$otherId}_screen.png", $pngBytes);
UserService::setPhoto($otherId, $withPhoto);
$restore = Auth::user();
Auth::logout(false);
Auth::attempt('nasra', 'ChangeMe#2026');
$_SESSION['user']['photo_path'] = $withPhoto;
$sessionWithPhoto = (string) json_encode(Auth::user(), JSON_THROW_ON_ERROR);
if ($restore !== null) {
    $_SESSION['user'] = $restore;
}
$photoScreen = runSnippet(
    'session_id("crprofile2" . getmypid());' . "\n"
    . 'session_start();' . "\n"
    . '$_SESSION["user"] = json_decode(' . var_export($sessionWithPhoto, true) . ', true);' . "\n"
    . '$_SERVER["REQUEST_URI"] = "/profile.php";' . "\n"
    . 'ob_start();' . "\n"
    . 'require ' . var_export(BASE_PATH . '/public/profile.php', true) . ';' . "\n"
    . 'file_put_contents(SNIPPET_OUT, (string) ob_get_clean());',
    false
);
$photoHtml = $photoScreen['file'] !== '' ? $photoScreen['file'] : $photoScreen['stdout'];
check('the header streams the photo through avatar.php', str_contains($photoHtml, 'avatar.php?v=') && !str_contains($photoHtml, 'avatar-initials'));
check('the screen offers to remove a photo that exists', str_contains($photoHtml, 'name="action" value="remove_photo"'));
UserService::setPhoto($otherId, null);
$cleanPhotos();

$cleanPhotos();
purgeFixtures();
Auth::logout(false);
