<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Auth, Csrf};
use App\Services\BackupService;

Auth::requirePermission('backup.manage');

$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    try {
        $file = BackupService::createBackup('manual');
        $message = "Backup created successfully: {$file}";
    } catch (\Throwable $e) {
        $message = "Backup failed: " . $e->getMessage();
    }
}

$backups = BackupService::listBackups();

render('layouts/app_header', ['title' => 'Database Backup']);
?>
<div class="card">
  <h2>Database Backups</h2>
  <p class="muted">Generate and monitor full SQL backups stored securely in private storage.</p>

  <?php if ($message): ?>
    <div class="badge badge-info" style="display:block; padding:10px; margin-bottom:1rem; font-size:14px;">
      <?= e($message) ?>
    </div>
  <?php endif; ?>

  <form method="POST" style="margin-bottom:1.5rem;">
    <?= Csrf::field() ?>
    <button type="submit" class="btn btn-primary">Create Database Backup Now</button>
  </form>

  <table>
    <thead>
      <tr>
        <th>Backup File</th>
        <th>Type</th>
        <th>Size</th>
        <th>Status</th>
        <th>Created By</th>
        <th>Date</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($backups)): ?>
        <tr><td colspan="6" class="text-center muted">No backups recorded yet.</td></tr>
      <?php else: ?>
        <?php foreach ($backups as $b): ?>
        <tr>
          <td><code><?= e($b['file_name']) ?></code></td>
          <td><?= e(ucfirst($b['backup_type'])) ?></td>
          <td><?= e($b['file_size'] ? round($b['file_size'] / 1024, 1) . ' KB' : '—') ?></td>
          <td>
            <span class="badge <?= $b['status'] === 'success' ? 'badge-success' : 'badge-danger' ?>">
              <?= e(ucfirst($b['status'])) ?>
            </span>
          </td>
          <td><?= e($b['created_by_name'] ?? 'System') ?></td>
          <td><?= e($b['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php render('layouts/app_footer'); ?>
