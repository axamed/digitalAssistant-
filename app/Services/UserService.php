<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Audit, Auth, Config, Database, Validator};
use RuntimeException;

/**
 * User administration: creation, profile edits, role assignment, activation
 * and password resets. Every mutation is written to the audit trail, and an
 * administrator can never lock themselves out of their own account.
 */
final class UserService
{
    /** Roles that may be assigned through the admin interface. */
    public const ASSIGNABLE_ROLES = ['administrator', 'supervisor', 'officer'];

    /** @return array users joined with their role label */
    public static function all(): array
    {
        return Database::fetchAll(
            'SELECT u.*, r.name AS role_name, r.label AS role_label
             FROM users u
             JOIN roles r ON r.id = u.role_id
             ORDER BY u.full_name ASC'
        );
    }

    public static function find(int $id): ?array
    {
        return Database::fetchOne(
            'SELECT u.*, r.name AS role_name, r.label AS role_label
             FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?',
            [$id]
        );
    }

    public static function usernameExists(string $username, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE LOWER(username) = LOWER(?)';
        $params = [$username];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return (int) Database::scalar($sql, $params) > 0;
    }

    /**
     * Create a new account. The user must set their own password on first login.
     *
     * @return array{id: int, password: string} the new id and the plaintext password to hand over once
     */
    public static function create(array $data): array
    {
        $fullName = trim((string) ($data['full_name'] ?? ''));
        $username = strtolower(trim((string) ($data['username'] ?? '')));
        $email    = trim((string) ($data['email'] ?? ''));
        $phone    = trim((string) ($data['phone'] ?? ''));
        $roleName = trim((string) ($data['role_name'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($fullName === '') {
            throw new RuntimeException('Full name is required.');
        }
        if (!preg_match('/^[a-z0-9._-]{3,30}$/', $username)) {
            throw new RuntimeException('Username must be 3-30 characters: lowercase letters, digits, dot, dash or underscore.');
        }
        if (self::usernameExists($username)) {
            throw new RuntimeException('That username is already taken.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid email address.');
        }
        if (!in_array($roleName, self::ASSIGNABLE_ROLES, true)) {
            throw new RuntimeException('Select a valid role.');
        }

        $temporary = $password !== '' ? $password : self::temporaryPassword();
        $strengthError = self::passwordError($temporary);
        if ($strengthError !== null) {
            throw new RuntimeException($strengthError);
        }

        $role = Database::fetchOne('SELECT id FROM roles WHERE name = ?', [$roleName]);
        if (!$role) {
            throw new RuntimeException('The selected role does not exist.');
        }

        Database::execute(
            'INSERT INTO users (full_name, username, email, phone, password_hash, role_id, status, must_change_password, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, "active", 1, NOW(), NOW())',
            [
                $fullName,
                $username,
                $email !== '' ? $email : null,
                $phone !== '' ? $phone : null,
                password_hash($temporary, PASSWORD_DEFAULT),
                (int) $role['id'],
            ]
        );

        $id = (int) Database::lastId();
        Audit::log('user_create', 'users', "Created user {$username} ({$fullName}) with role {$roleName}");
        return ['id' => $id, 'password' => $temporary];
    }

    /** Edit profile fields and role of an existing account. */
    public static function update(int $id, array $data): void
    {
        $user = self::find($id);
        if (!$user) {
            throw new RuntimeException('User not found.');
        }

        $fullName = trim((string) ($data['full_name'] ?? ''));
        $email    = trim((string) ($data['email'] ?? ''));
        $phone    = trim((string) ($data['phone'] ?? ''));
        $roleName = trim((string) ($data['role_name'] ?? ''));
        $status   = trim((string) ($data['status'] ?? $user['status']));

        if ($fullName === '') {
            throw new RuntimeException('Full name is required.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid email address.');
        }
        if (!in_array($roleName, self::ASSIGNABLE_ROLES, true)) {
            throw new RuntimeException('Select a valid role.');
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new RuntimeException('Invalid account status.');
        }
        if ($id === (int) Auth::id()) {
            if ($status !== 'active') {
                throw new RuntimeException('You cannot deactivate your own account.');
            }
            if ($roleName !== (string) $user['role_name']) {
                throw new RuntimeException('You cannot change your own role.');
            }
        }

        $role = Database::fetchOne('SELECT id FROM roles WHERE name = ?', [$roleName]);
        if (!$role) {
            throw new RuntimeException('The selected role does not exist.');
        }

        Database::execute(
            'UPDATE users SET full_name = ?, email = ?, phone = ?, role_id = ?, status = ?, updated_at = NOW() WHERE id = ?',
            [
                $fullName,
                $email !== '' ? $email : null,
                $phone !== '' ? $phone : null,
                (int) $role['id'],
                $status,
                $id,
            ]
        );

        $changes = [];
        if ($fullName !== $user['full_name'])        { $changes[] = 'name'; }
        if ($roleName !== $user['role_name'])        { $changes[] = 'role'; }
        if ($status !== $user['status'])             { $changes[] = 'status'; }
        if (($email ?: '') !== (string) ($user['email'] ?? ''))   { $changes[] = 'email'; }
        if (($phone ?: '') !== (string) ($user['phone'] ?? ''))   { $changes[] = 'phone'; }

        Audit::log(
            'user_update',
            'users',
            "Updated user {$user['username']}" . ($changes ? ' (' . implode(', ', $changes) . ')' : ' (no changes)')
        );
    }

    /** Toggle account status; blocks self-deactivation. */
    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new RuntimeException('Invalid account status.');
        }
        $user = self::find($id);
        if (!$user) {
            throw new RuntimeException('User not found.');
        }
        if ($id === (int) Auth::id()) {
            throw new RuntimeException('You cannot change the status of your own account.');
        }
        if ($status === 'inactive') {
            $openActions = (int) Database::scalar(
                "SELECT COUNT(*) FROM record_status_history h
                 JOIN users u ON u.id = h.user_id
                 WHERE h.user_id = ? AND h.status IN ('submitted', 'under_review', 'correction_required')",
                [$id]
            );
            if ($openActions > 0) {
                throw new RuntimeException("This user still has {$openActions} record(s) awaiting review. Reassign or action them first.");
            }
        }

        Database::execute('UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?', [$status, $id]);
        if ($status === 'inactive') {
            Database::execute('UPDATE users SET locked_until = NULL WHERE id = ?', [$id]);
        }
        Audit::log('user_status', 'users', "Set {$user['username']} to {$status}");
    }

    /** Clear a lockout caused by repeated failed logins and reset the attempt counter. */
    public static function unlock(int $id): void
    {
        $user = self::find($id);
        if (!$user) {
            throw new RuntimeException('User not found.');
        }
        if ($user['status'] !== 'active') {
            throw new RuntimeException('This account is inactive. Activate it before unlocking.');
        }
        Database::execute(
            'UPDATE users SET failed_attempts = 0, locked_until = NULL, updated_at = NOW() WHERE id = ?',
            [$id]
        );
        Audit::log('user_unlock', 'users', "Cleared the login lockout for {$user['username']}");
    }

    /** Administrative password reset; the account must set a new one at next login. */
    public static function resetPassword(int $id, ?string $newPassword = null): string
    {
        $user = self::find($id);
        if (!$user) {
            throw new RuntimeException('User not found.');
        }

        $password = $newPassword !== null && $newPassword !== '' ? $newPassword : self::temporaryPassword();
        $error = self::passwordError($password);
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        Database::execute(
            'UPDATE users SET password_hash = ?, must_change_password = 1, failed_attempts = 0, locked_until = NULL, updated_at = NOW() WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $id]
        );
        Audit::log('user_password_reset', 'users', "Reset the password for {$user['username']}");
        return $password;
    }

    /**
     * Minimum password policy shared by admin resets and profile changes.
     *
     * One rule for the whole system: at least app.security.min_password_length characters
     * (6 by default) with both letters and numbers, whichever page asks for a password.
     */
    public static function passwordError(string $password): ?string
    {
        $min = max(1, (int) Config::get('app.security.min_password_length', 6));
        if (strlen($password) < $min) {
            return "The password must be at least {$min} characters long.";
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'The password must contain both letters and numbers.';
        }
        return null;
    }

    /** Readable, single-use temporary password: Cr-<4 groups>. */
    public static function temporaryPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $groups = [];
        for ($g = 0; $g < 4; $g++) {
            $chunk = '';
            for ($i = 0; $i < 4; $i++) {
                $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $chunk;
        }
        return 'Cr-' . implode('-', $groups) . random_int(10, 99);
    }

    // ── Self-service security ───────────────────────────────────────────────
    // The three things a staff member may change on their own account, without the
    // administrator. Each one re-checks the current password first, so a borrowed or
    // still-open session cannot quietly take the account over.

    /**
     * Change your own password. Requires the current password, and clears the
     * "must change password" flag because the account now has a real one.
     *
     * @throws RuntimeException with a message meant for the user
     */
    public static function changeOwnPassword(int $userId, string $currentPassword, string $newPassword, string $confirmPassword): void
    {
        $user = self::find($userId);
        if (!$user) {
            throw new RuntimeException('Your account could not be loaded.');
        }
        if (!password_verify($currentPassword, (string) $user['password_hash'])) {
            throw new RuntimeException('Your current password is not correct.');
        }
        if ($newPassword !== $confirmPassword) {
            throw new RuntimeException('The new passwords do not match.');
        }
        if ($newPassword === $currentPassword) {
            throw new RuntimeException('The new password must be different from the current one.');
        }
        $error = self::passwordError($newPassword);
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        Database::execute(
            'UPDATE users SET password_hash = ?, must_change_password = 0, failed_attempts = 0,
                    locked_until = NULL, updated_at = NOW() WHERE id = ?',
            [password_hash($newPassword, PASSWORD_DEFAULT), $userId]
        );
        Audit::log('password_changed', 'auth', 'Staff member updated their own password', 'users', $userId, $userId, (string) $user['username']);
    }

    /**
     * Change your own username. The same format the administrator form enforces is
     * applied here, and the previous username is kept in the audit trail.
     */
    public static function changeOwnUsername(int $userId, string $newUsername, string $currentPassword): string
    {
        $user = self::find($userId);
        if (!$user) {
            throw new RuntimeException('Your account could not be loaded.');
        }
        if (!password_verify($currentPassword, (string) $user['password_hash'])) {
            throw new RuntimeException('Your current password is not correct.');
        }

        $username = strtolower(trim($newUsername));
        if (!preg_match('/^[a-z0-9._-]{3,30}$/', $username)) {
            throw new RuntimeException('Username must be 3-30 characters: lowercase letters, digits, dot, dash or underscore.');
        }
        if ($username === (string) $user['username']) {
            throw new RuntimeException('That is already your username.');
        }
        if (self::usernameExists($username, $userId)) {
            throw new RuntimeException('That username is already taken.');
        }

        Database::execute(
            'UPDATE users SET username = ?, updated_at = NOW() WHERE id = ?',
            [$username, $userId]
        );
        Audit::log(
            'username_changed',
            'users',
            "Renamed account from {$user['username']} to {$username}",
            'users',
            $userId,
            $userId,
            (string) $user['username']
        );
        return $username;
    }

    /**
     * Point the account at a new profile photo and remove the file it replaces.
     * The old file is deleted only after the row has been updated, so a failed
     * update never leaves the account without a photo.
     */
    public static function setPhoto(int $userId, ?string $relativePath): void
    {
        $user = self::find($userId);
        if (!$user) {
            throw new RuntimeException('Your account could not be loaded.');
        }
        $previous = $user['photo_path'] ?? null;

        Database::execute(
            'UPDATE users SET photo_path = ?, updated_at = NOW() WHERE id = ?',
            [$relativePath, $userId]
        );

        if (is_string($previous) && trim($previous) !== '' && $previous !== $relativePath) {
            PhotoService::delete($previous, $userId);
        }
        Audit::log(
            $relativePath === null ? 'photo_removed' : 'photo_updated',
            'users',
            $relativePath === null
                ? "Removed the profile photo of {$user['username']}"
                : "Updated the profile photo of {$user['username']}",
            'users',
            $userId,
            $userId,
            (string) $user['username']
        );
    }
}
