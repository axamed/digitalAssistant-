<?php
declare(strict_types=1);

namespace App\Core;

/** Append-only activity log (the table also rejects UPDATE/DELETE via triggers). */
final class Audit
{
    public static function log(
        string $action,
        string $module,
        string $description = '',
        ?string $recordType = null,
        ?int $recordId = null,
        ?int $userId = null,
        ?string $username = null
    ): void {
        try {
            $user = Auth::user();
            Database::execute(
                'INSERT INTO audit_logs (user_id, username, action, module, record_type, record_id, description, ip_address, user_agent)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [
                    $userId ?? ($user['id'] ?? null),
                    $username ?? ($user['username'] ?? null),
                    $action, $module, $recordType, $recordId, $description,
                    substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                    substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ]
            );
        } catch (\Throwable $e) {
            Logger::error('Audit failure: ' . $e->getMessage());
        }
    }
}
