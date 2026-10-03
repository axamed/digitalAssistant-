<?php
declare(strict_types=1);

namespace App\Core;

/** Authentication, session user and permission checks. Every protected page starts with one of the require* methods. */
final class Auth
{
    public const HOME = [
        'administrator' => 'admin/dashboard.php',
        'officer'       => 'officer/dashboard.php',
        'supervisor'    => 'supervisor/dashboard.php',
    ];

    /** Landing order for roles without a desk of their own, as path => required permission. */
    private const FALLBACK_ORDER = [
        'supervisor/dashboard.php' => 'record.approve',
        'officer/dashboard.php'    => 'birth.create',
        'records/search.php'       => 'record.search',
    ];

    private static ?string $dummyHash = null;

    /** @var string[]|null Permission codes resolved for this request, null until first read. */
    private static ?array $livePermissions = null;

    /** @return bool|string true on success, otherwise a safe error message */
    public static function attempt(string $username, string $password): bool|string
    {
        $generic = 'Invalid username or password.';
        $user = Database::fetchOne(
            'SELECT u.*, r.name AS role_name, r.label AS role_label
               FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = ?',
            [$username]
        );

        if ($user === null) {
            self::$dummyHash ??= password_hash('timing-guard', PASSWORD_DEFAULT);
            password_verify($password, self::$dummyHash);   // keep timing similar
            Audit::log('login_failed', 'auth', 'Unknown username', null, null, null, substr($username, 0, 60));
            return $generic;
        }
        if ($user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
            Audit::log('login_blocked', 'auth', 'Account temporarily locked', null, null, (int) $user['id'], $user['username']);
            return 'This account is temporarily locked. Please try again later.';
        }
        if ($user['status'] !== 'active') {
            Audit::log('login_blocked', 'auth', 'Inactive account', null, null, (int) $user['id'], $user['username']);
            return 'This account is disabled. Please contact the system administrator.';
        }
        if (!password_verify($password, $user['password_hash'])) {
            $attempts = (int) $user['failed_attempts'] + 1;
            $lock = $attempts >= (int) Config::get('app.security.max_failed_logins', 5)
                ? date('Y-m-d H:i:s', time() + 60 * (int) Config::get('app.security.lockout_minutes', 15)) : null;
            Database::execute('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?', [$attempts, $lock, $user['id']]);
            Audit::log('login_failed', 'auth', 'Wrong password', null, null, (int) $user['id'], $user['username']);
            return $generic;
        }

        Session::regenerate();
        Database::execute('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?', [$user['id']]);
        $perms = Database::fetchAll(
            'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?',
            [$user['role_id']]
        );
        Session::set('user', [
            'id'          => (int) $user['id'],
            'name'        => $user['full_name'],
            'full_name'   => $user['full_name'],
            'username'    => $user['username'],
            'role'        => $user['role_name'],
            'role_name'   => $user['role_name'],
            'role_label'  => $user['role_label'],
            'must_change' => (bool) $user['must_change_password'],
            'photo_path'  => $user['photo_path'] ?? null,
            'permissions' => array_column($perms, 'code'),
        ]);
        self::$livePermissions = null;   // resolve against the database on first use this request
        Audit::log('login', 'auth', 'Signed in');
        return true;
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function id(): ?int
    {
        return isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function requireUser(): array
    {
        return self::requireLogin();
    }

    /**
     * Landing page after sign-in.
     *
     * A user's role still decides their desk, but a role that has no desk of its own
     * (because its permissions were customised) falls back to the first page it may
     * actually open instead of a dead dashboard.
     */
    public static function homePath(): string
    {
        $role = self::user()['role'] ?? '';
        if (isset(self::HOME[$role])) {
            return self::HOME[$role];
        }
        foreach (self::FALLBACK_ORDER as $path => $permission) {
            if (self::can($permission)) {
                return $path;
            }
        }
        return 'profile.php';
    }

    /**
     * Permission codes held by the signed-in account, read fresh on every request.
     *
     * Re-reading instead of trusting the session copy means a grant or a revoke takes
     * effect on the account's very next click, with no "sign in again to see it" step.
     */
    public static function permissions(): array
    {
        if (self::$livePermissions !== null) {
            return self::$livePermissions;
        }
        $user = self::user();
        if ($user === null) {
            return [];
        }
        try {
            $rows = Database::fetchAll(
                'SELECT p.code FROM users u
                   JOIN role_permissions rp ON rp.role_id = u.role_id
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE u.id = ? ORDER BY p.id',
                [(int) $user['id']]
            );
            self::$livePermissions = array_column($rows, 'code');
        } catch (\Throwable $e) {
            // A database blip must not lock the user out mid-session; fall back to the copy
            // captured at sign-in.
            self::$livePermissions = (array) ($user['permissions'] ?? []);
        }
        return self::$livePermissions;
    }

    /** The administrator role is a superuser: it holds every permission in the catalogue. */
    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'administrator';
    }

    public static function can(string $permission): bool
    {
        if (self::isAdmin()) {
            return true;
        }
        return in_array($permission, self::permissions(), true);
    }

    public static function canAny(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::can($permission)) {
                return true;
            }
        }
        return false;
    }

    /** Logged in AND account still active. */
    public static function requireLogin(): array
    {
        $user = self::user();
        if ($user === null) {
            flash('warning', 'Please sign in to continue.');
            redirect('login.php');
        }
        $status = Database::scalar('SELECT status FROM users WHERE id = ?', [$user['id']]);
        if ($status !== 'active') {
            self::logout(false);
            Session::start();
            flash('danger', 'Your account is no longer active.');
            redirect('login.php');
        }
        if (!empty($user['must_change'])) {
            $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
            if ($currentScript !== 'change-password.php' && $currentScript !== 'logout.php') {
                flash('warning', 'You must change your default password before accessing the system.');
                redirect('change-password.php');
            }
        }
        return $user;
    }

    /**
     * Page guard for screens that belong to a named desk.
     *
     * The administrator is let through regardless of role so that no administrative
     * page becomes unreachable while still keeping its own data guards.
     */
    public static function requireRole(string ...$roles): array
    {
        $user = self::requireLogin();
        if (!self::isAdmin() && !in_array($user['role'], $roles, true)) {
            Audit::log('access_denied', 'security', 'Role not allowed: ' . ($_SERVER['REQUEST_URI'] ?? ''));
            abort(403, 'You do not have permission to open this page.');
        }
        return $user;
    }

    public static function requirePermission(string $permission): array
    {
        $user = self::requireLogin();
        if (!self::can($permission)) {
            Audit::log('access_denied', 'security', 'Missing permission ' . $permission);
            abort(403, 'You do not have permission to perform this action.');
        }
        return $user;
    }

    public static function logout(bool $audit = true): void
    {
        if ($audit && self::check()) {
            Audit::log('logout', 'auth', 'Signed out');
        }
        self::$livePermissions = null;
        Session::destroy();
    }
}
