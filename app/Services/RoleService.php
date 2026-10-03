<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Audit, Auth, Database};
use RuntimeException;

/**
 * Role and permission administration. Permission sets are derived from the
 * permission catalogue, so granting a permission only ever references an
 * existing code.
 */
final class RoleService
{
    /**
     * Permission codes that pages and services guard themselves with.
     *
     * These may be edited but never deleted, because a page that checks for a code
     * nobody holds is a page nobody can open.
     */
    private const PROTECTED_PERMISSION_CODES = [
        'user.manage',
        'role.manage',
        'settings.manage',
        'backup.manage',
        'audit.view',
        'birth.create',
        'death.create',
        'marriage.create',
        'divorce.create',
        'record.search',
        'record.view',
        'record.update',
        'record.delete',
        'record.submit',
        'record.review',
        'record.approve',
        'record.reject',
        'report.generate',
        'certificate.print',
        'certificate.pay',
        'certificate.revoke',
    ];

    /** @return array roles with their permission codes */
    public static function all(): array
    {
        return Database::fetchAll(
            'SELECT r.*, (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count
             FROM roles r ORDER BY r.id ASC'
        );
    }

    public static function find(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM roles WHERE id = ?', [$id]);
    }

    public static function findByName(string $name): ?array
    {
        return Database::fetchOne('SELECT * FROM roles WHERE name = ?', [$name]);
    }

    /** @return array permission catalogue */
    public static function permissions(): array
    {
        return Database::fetchAll('SELECT * FROM permissions ORDER BY id ASC');
    }

    public static function permission(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM permissions WHERE id = ?', [$id]);
    }

    public static function findPermissionByCode(string $code): ?array
    {
        return Database::fetchOne('SELECT * FROM permissions WHERE code = ?', [$code]);
    }

    /**
     * Validate a permission code.
     *
     * Codes are referenced from the menu, the pages and the role editor, so they are
     * held to one shape: lowercase words joined by a single dot.
     */
    private static function permissionCodeError(string $code): ?string
    {
        if ($code === '') {
            return 'Enter a permission code.';
        }
        if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $code)) {
            return 'Use a code like "record.view": lowercase words joined by a dot.';
        }
        return null;
    }

    /**
     * Add a permission to the catalogue.
     *
     * New codes are granted to the administrator immediately, because the administrator
     * is a superuser and a permission nothing holds would never be usable.
     *
     * @return int the new permission id
     */
    public static function createPermission(string $code, string $description): int
    {
        $code = trim($code);
        $description = trim($description);

        if (($error = self::permissionCodeError($code)) !== null) {
            throw new RuntimeException($error);
        }
        if ($description === '') {
            throw new RuntimeException('Enter a short description for the permission.');
        }
        if (self::findPermissionByCode($code) !== null) {
            throw new RuntimeException('That permission code already exists.');
        }

        Database::execute('INSERT INTO permissions (code, description) VALUES (?, ?)', [$code, $description]);
        $id = (int) Database::pdo()->lastInsertId();
        self::grantToAdministrators($id);

        Audit::log('permission_create', 'permissions', "Added permission {$code}", null, null, null, $description);
        return $id;
    }

    /** Rename a permission or rewrite its description. */
    public static function updatePermission(int $id, string $code, string $description): void
    {
        $permission = self::permission($id);
        if (!$permission) {
            throw new RuntimeException('Permission not found.');
        }
        $code = trim($code);
        $description = trim($description);

        if (($error = self::permissionCodeError($code)) !== null) {
            throw new RuntimeException($error);
        }
        if ($description === '') {
            throw new RuntimeException('Enter a short description for the permission.');
        }
        if ($code !== $permission['code'] && self::findPermissionByCode($code) !== null) {
            throw new RuntimeException('That permission code already exists.');
        }

        Database::execute('UPDATE permissions SET code = ?, description = ? WHERE id = ?', [$code, $description, $id]);
        Audit::log(
            'permission_update',
            'permissions',
            "Renamed permission {$permission['code']} to {$code}",
            null,
            null,
            null,
            $description
        );
    }

    /**
     * Remove a permission from the catalogue.
     *
     * The codes the system guards its own pages with are refused: a page would then
     * lock itself to everyone, including the administrator trying to repair it.
     */
    public static function deletePermission(int $id): void
    {
        $permission = self::permission($id);
        if (!$permission) {
            throw new RuntimeException('Permission not found.');
        }
        if (in_array($permission['code'], self::PROTECTED_PERMISSION_CODES, true)) {
            throw new RuntimeException('That permission is required by the system and cannot be deleted.');
        }

        Database::execute('DELETE FROM role_permissions WHERE permission_id = ?', [$id]);
        Database::execute('DELETE FROM permissions WHERE id = ?', [$id]);
        Audit::log('permission_delete', 'permissions', 'Deleted permission ' . $permission['code']);
    }

    /**
     * Grant a permission to every role whose name is administrator.
     *
     * The administrator is the system's superuser, so a new permission is only useful
     * once they hold it too. Other roles are left for an administrator to assign.
     */
    public static function grantToAdministrators(int $permissionId): void
    {
        Database::execute(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, ? FROM roles r WHERE r.name = ?',
            [$permissionId, 'administrator']
        );
    }

    /**
     * Give the administrator role every permission in the catalogue.
     *
     * Run after the catalogue changes so an upgrade cannot leave the superuser behind
     * a permission that did not exist when their role was last saved.
     *
     * @return int how many grants were added
     */
    public static function syncAdministrator(): int
    {
        $roleId = (int) Database::scalar("SELECT id FROM roles WHERE name = 'administrator'");
        if ($roleId === 0) {
            return 0;
        }
        return Database::execute(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions',
            [$roleId]
        );
    }

    /** @return string[] permission codes granted to a role */
    public static function permissionCodes(int $roleId): array
    {
        return array_column(
            Database::fetchAll(
                'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                 WHERE rp.role_id = ? ORDER BY p.id',
                [$roleId]
            ),
            'code'
        );
    }

    /** @return array<string,string[]> permission codes grouped by permission module */
    public static function groupedPermissions(): array
    {
        $grouped = [];
        foreach (self::permissions() as $permission) {
            $module = explode('.', (string) $permission['code'])[0];
            $grouped[$module][$permission['code']] = (string) $permission['description'];
        }
        return $grouped;
    }

    /**
     * Replace the permission set of a role.
     *
     * @param string[] $codes Requested permission codes
     */
    public static function setPermissions(int $roleId, array $codes): void
    {
        $role = self::find($roleId);
        if (!$role) {
            throw new RuntimeException('Role not found.');
        }

        $valid = array_column(self::permissions(), 'code');
        $requested = array_values(array_unique(array_filter(
            $codes,
            static fn($code): bool => is_string($code) && in_array($code, $valid, true)
        )));

        // The administrator is the superuser: it always ends up holding the whole
        // catalogue, whatever the form posted, so nobody can lock the system out.
        if ($role['name'] === 'administrator') {
            $requested = $valid;
        }

        $current = self::permissionCodes($roleId);
        $added   = array_values(array_diff($requested, $current));
        $removed = array_values(array_diff($current, $requested));

        if ($added === [] && $removed === []) {
            return;
        }

        // A role must never be stripped of every permission: that would silently
        // lock every holder of the role out of the system.
        if ($requested === []) {
            throw new RuntimeException('A role must keep at least one permission.');
        }

        $pdo = Database::pdo();
        Database::beginTransaction();
        try {
            Database::execute('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
            foreach ($requested as $code) {
                $permissionId = (int) Database::scalar('SELECT id FROM permissions WHERE code = ?', [$code]);
                Database::execute(
                    'INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)',
                    [$roleId, $permissionId]
                );
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw new RuntimeException('Failed to update role permissions: ' . $e->getMessage(), 0, $e);
        }

        Audit::log(
            'role_permissions_update',
            'roles',
            "Updated permissions for role {$role['name']}"
            . ($added ? ' (+' . implode(', ', $added) . ')' : '')
            . ($removed ? ' (-' . implode(', ', $removed) . ')' : '')
        );
    }
}
