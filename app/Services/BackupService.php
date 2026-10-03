<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth, Audit, Config, Database};
use RuntimeException;

/**
 * Service managing database backups: creation, logging, listing, and secure downloads.
 */
final class BackupService
{
    /** Values accepted for backup_logs.backup_type. Anything else would be stored as an empty enum. */
    public const TYPES = ['manual', 'automatic'];

    /**
     * Create a SQL dump backup of the database.
     */
    public static function createBackup(string $type = 'manual'): string
    {
        Auth::requirePermission('backup.manage');
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException('Invalid backup type.');
        }
        $userId = Auth::id();

        $c = Config::get('database');
        $dbName = $c['name'];
        $backupDir = BASE_PATH . '/storage/backups';

        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            throw new RuntimeException("Could not create backups directory.");
        }

        $filename = sprintf('backup_%s_%s.sql', $dbName, date('Ymd_His'));
        $target = $backupDir . '/' . $filename;

        // Export tables via PDO directly for portability without relying on external mysqldump path
        try {
            $pdo = Database::pdo();
            $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

            $handle = fopen($target, 'wb');
            if ($handle === false) {
                throw new RuntimeException("Cannot open backup file for writing.");
            }

            fwrite($handle, "-- Civil Registration Database Backup\n");
            fwrite($handle, "-- Database: {$dbName}\n");
            fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach ($tables as $table) {
                // Get create table statement
                $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
                $createSql = $createRow['Create Table'] ?? '';
                fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($handle, $createSql . ";\n\n");

                // Get table rows
                $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    foreach ($rows as $row) {
                        $cols = array_map(static fn($c) => "`{$c}`", array_keys($row));
                        $vals = array_map(static function ($v) use ($pdo) {
                            if ($v === null) {
                                return 'NULL';
                            }
                            return $pdo->quote((string) $v);
                        }, array_values($row));

                        $insertSql = sprintf(
                            "INSERT INTO `%s` (%s) VALUES (%s);\n",
                            $table,
                            implode(', ', $cols),
                            implode(', ', $vals)
                        );
                        fwrite($handle, $insertSql);
                    }
                    fwrite($handle, "\n");
                }
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($handle);

            $fileSize = filesize($target);

            // Record in backup logs
            Database::execute(
                'INSERT INTO backup_logs (created_by, file_name, backup_type, file_size, status, created_at)
                 VALUES (?, ?, ?, ?, "success", NOW())',
                [$userId, $filename, $type, $fileSize]
            );

            Audit::log('backup_create', 'backup', "Created database backup: {$filename} (" . round($fileSize / 1024, 1) . " KB)");

            return $filename;
        } catch (\Throwable $e) {
            Database::execute(
                'INSERT INTO backup_logs (created_by, file_name, backup_type, file_size, status, created_at)
                 VALUES (?, ?, ?, 0, "failed", NOW())',
                [$userId, $filename, $type]
            );
            Audit::log('backup_failure', 'backup', "Backup failed: " . $e->getMessage());
            throw new RuntimeException("Backup creation failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * List all recorded backups with file status.
     */
    public static function listBackups(): array
    {
        return Database::fetchAll(
            'SELECT b.*, u.full_name AS created_by_name
             FROM backup_logs b
             LEFT JOIN users u ON u.id = b.created_by
             ORDER BY b.created_at DESC'
        );
    }
}
