<?php
declare(strict_types=1);
/** Database backups and CSV exports. Both end the request, so the export runs in its own process. */

use App\Core\{Auth, Database};
use App\Services\{BackupService, ExportService, ReportService, Settings};

$backupDir = BASE_PATH . '/storage/backups';
$backupsBefore = count(glob($backupDir . '/backup_*.sql') ?: []);
$logsBefore = (int) Database::scalar('SELECT COUNT(*) FROM backup_logs');

Auth::logout(false);
Auth::attempt('nasra', 'ChangeMe#2026');

echo "\nBackup Tests\n";
// A value with an apostrophe proves the hand-written INSERT quoting survives a restore.
Settings::set('test.quote', "O'Brien");

$backupFile = BackupService::createBackup();
$backupPath = $backupDir . '/' . $backupFile;
$createdBackups = [$backupFile];
$dump = is_file($backupPath) ? (string) file_get_contents($backupPath) : '';

check('a backup file is created', is_file($backupPath) && filesize($backupPath) > 0, $backupFile);
check('the backup file is named after the database', (bool) preg_match('/^backup_[a-z0-9_]+_\d{8}_\d{6}\.sql$/', $backupFile), $backupFile);
check('the backup carries a header and the table definitions', str_contains($dump, '-- Civil Registration Database Backup') && str_contains($dump, 'CREATE TABLE `districts`'));
check('the backup contains every district row', substr_count($dump, 'INSERT INTO `districts`') === (int) Database::scalar('SELECT COUNT(*) FROM districts'));
check('the backup contains the record tables', str_contains($dump, 'CREATE TABLE `birth_records`') && str_contains($dump, 'CREATE TABLE `certificates`'));
check('a value with an apostrophe is quoted for a safe restore', str_contains($dump, "O\\'Brien"), 'not found in dump');
check('foreign key checks are disabled and restored', strpos($dump, 'SET FOREIGN_KEY_CHECKS=0;') !== false && strpos($dump, 'SET FOREIGN_KEY_CHECKS=1;') !== false);
check('the backup is recorded in the log as a manual backup', (int) Database::scalar('SELECT COUNT(*) FROM backup_logs WHERE file_name = ? AND status = ? AND backup_type = ?', [$backupFile, 'success', 'manual']) === 1, 'stored type: ' . (string) Database::scalar('SELECT backup_type FROM backup_logs WHERE file_name = ? ORDER BY id DESC LIMIT 1', [$backupFile]));
check('the backup log records who made it and how big it is', (static function () use ($backupFile): bool {
    $row = Database::fetchOne('SELECT * FROM backup_logs WHERE file_name = ? ORDER BY id DESC LIMIT 1', [$backupFile]);
    return $row !== null && (int) $row['created_by'] === (int) Auth::id() && (int) $row['file_size'] > 0;
})());
check('the backup appears in the list', in_array($backupFile, array_column(BackupService::listBackups(), 'file_name'), true));
check('the backup list names the author', (string) (BackupService::listBackups()[0]['created_by_name'] ?? '') !== '');
check('the allowed backup types match the log column', BackupService::TYPES === ['manual', 'automatic']);

// The backup type reaches an ENUM column and must never travel into the file path.
$evilRefused = (static function (): bool {
    try {
        BackupService::createBackup('../../escape');
        return false;
    } catch (\RuntimeException $e) {
        return $e->getMessage() === 'Invalid backup type.';
    }
})();
check('an unexpected backup type is refused', $evilRefused);
check('the refused type wrote no file', count(glob($backupDir . '/backup_*.sql') ?: []) === $backupsBefore + 1);
check('a file outside the backup folder cannot be produced', !file_exists(BASE_PATH . '/storage/escape') && !file_exists(BASE_PATH . '/storage/backups/escape'));
check('the generated file name is a plain name inside the backup folder', basename($backupFile) === $backupFile && str_contains($backupFile, '_') && realpath($backupDir . '/' . $backupFile) === realpath($backupPath));

Database::execute('DELETE FROM system_settings WHERE setting_key LIKE ?', ['test.%']);

echo "\nBackup Permission Tests\n";
$filesBeforeDenied = count(glob($backupDir . '/backup_*.sql') ?: []);
$logsBeforeDenied = (int) Database::scalar('SELECT COUNT(*) FROM backup_logs');
// requireLogin() sends an account still on its default password to the change-password page,
// which ends the request, so clear the flag for the officer and put it back afterwards.
$officerId = (int) Database::scalar("SELECT id FROM users WHERE username = 'faduma'");
$officerMustChange = Database::scalar('SELECT must_change_password FROM users WHERE id = ?', [$officerId]);
Database::execute('UPDATE users SET must_change_password = 0 WHERE id = ?', [$officerId]);
$denied = runSnippet(
    'App\Core\Auth::attempt("faduma", "ChangeMe#2026");' . "\n"
    . 'App\Services\BackupService::createBackup();' . "\n"
    . 'file_put_contents(SNIPPET_OUT, "BACKUP CREATED");'
);
Database::execute('UPDATE users SET must_change_password = ? WHERE id = ?', [$officerMustChange, $officerId]);

check('an officer without backup.manage is refused with 403', $denied['file'] === '' && str_contains($denied['stdout'], '403'));
check('the refused attempt wrote no file', count(glob($backupDir . '/backup_*.sql') ?: []) === $filesBeforeDenied);
check('the refused attempt left no trace in the backup log', (int) Database::scalar('SELECT COUNT(*) FROM backup_logs') === $logsBeforeDenied);

foreach ($createdBackups as $file) {
    @unlink($backupDir . '/' . $file);
    Database::execute('DELETE FROM backup_logs WHERE file_name = ?', [$file]);
}
check('the test backups are removed again', count(glob($backupDir . '/backup_*.sql') ?: []) === $backupsBefore && (int) Database::scalar('SELECT COUNT(*) FROM backup_logs') === $logsBefore);

echo "\nCSV Export Tests\n";
$csv = runSnippet(
    'App\Services\ExportService::downloadCsv(' . var_export('birth_records_2026.csv', true) . ', '
    . '["reg" => "Registration Number", "name" => "Child full name", "notes" => "Notes"], '
    . '[["reg" => "BR-2026-0001", "name" => "Warsame, Marian", "notes" => "Line one"], '
    . ' ["reg" => "BR-2026-0002", "name" => "Ali Nur", "notes" => "He said \"hello\""]]);'
);
check('the export starts with the Excel UTF-8 marker', str_starts_with($csv['stdout'], "\xEF\xBB\xBF"), bin2hex(substr($csv['stdout'], 0, 6)));
$csvLines = array_values(array_filter(explode("\n", str_replace("\xEF\xBB\xBF", '', $csv['stdout'])), static fn(string $l): bool => $l !== ''));
// fputcsv encloses any field holding a space, a comma or a quote, which Excel expects.
check('the export writes the display headers, not the keys', ($csvLines[0] ?? '') === '"Registration Number","Child full name",Notes', $csvLines[0] ?? '');
check('a value containing a comma is quoted', str_contains($csv['stdout'], '"Warsame, Marian"'));
check('a value containing a quote is escaped', str_contains($csv['stdout'], '"He said ""hello"""'));
check('the export holds one line per row', count($csvLines) === 3, (string) count($csvLines));

$missing = runSnippet(
    'App\Services\ExportService::downloadCsv("t.csv", ["a" => "A", "b" => "B"], [["a" => "only a"]]);'
);
$missingLines = array_values(array_filter(explode("\n", str_replace("\xEF\xBB\xBF", '', $missing['stdout'])), static fn(string $l): bool => $l !== ''));
check('a row missing a column exports an empty cell', ($missingLines[1] ?? '') === '"only a",', $missingLines[1] ?? '');

$districts = ReportService::byDistrict();
$reportCsv = runSnippet(
    '$rows = App\Services\ReportService::byDistrict();' . "\n"
    . 'App\Services\ExportService::downloadCsv("district_report.csv", '
    . '["district_name" => "District", "births" => "Births", "deaths" => "Deaths", "marriages" => "Marriages", "divorces" => "Divorces", "total" => "Total"], $rows);'
);
$reportLines = array_values(array_filter(explode("\n", str_replace("\xEF\xBB\xBF", '', $reportCsv['stdout'])), static fn(string $l): bool => $l !== ''));
check('a report export has one line per district plus a header', count($reportLines) === count($districts) + 1, (string) count($reportLines) . ' lines for ' . count($districts) . ' districts');
$firstDistrict = $districts[0];
check('an exported row matches the report it came from', ($reportLines[1] ?? '') === implode(',', [
    $firstDistrict['district_name'], $firstDistrict['births'], $firstDistrict['deaths'],
    $firstDistrict['marriages'], $firstDistrict['divorces'], $firstDistrict['total'],
]), $reportLines[1] ?? '');

Auth::logout(false);
