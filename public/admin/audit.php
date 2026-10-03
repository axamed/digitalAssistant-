<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Auth, Database};

Auth::requirePermission('audit.view');

$logs = Database::fetchAll(
    'SELECT a.*, u.full_name AS user_full_name
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.created_at DESC
     LIMIT 100'
);

render('layouts/app_header', ['title' => 'Audit Logs']);
?>
<div class="card">
  <h2>Append-Only Audit Logs</h2>
  <p class="muted">Tamper-evident system activity log enforced by database triggers.</p>
  <table>
    <thead>
      <tr>
        <th>Date & Time</th>
        <th>User</th>
        <th>Action</th>
        <th>Module</th>
        <th>Description</th>
        <th>IP Address</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($logs)): ?>
        <tr><td colspan="6" class="text-center muted">No audit logs recorded yet.</td></tr>
      <?php else: ?>
        <?php foreach ($logs as $l): ?>
        <tr>
          <td><small><?= e($l['created_at']) ?></small></td>
          <td><b><?= e($l['user_full_name'] ?? $l['username'] ?? 'System') ?></b></td>
          <td><code><?= e($l['action']) ?></code></td>
          <td><?= e($l['module']) ?></td>
          <td><?= e($l['description'] ?? '—') ?></td>
          <td><small><?= e($l['ip_address'] ?? '—') ?></small></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php render('layouts/app_footer'); ?>
