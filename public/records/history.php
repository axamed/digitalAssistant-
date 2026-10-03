<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Services\RecordService;
use App\Support\RecordTypes;

Auth::requirePermission('record.view');

$type = trim($_GET['type'] ?? '');
$id   = (int) ($_GET['id'] ?? 0);

if (!isset(RecordTypes::ALL[$type]) || $id <= 0) {
    redirect('records/search.php');
}

$record = RecordService::find($type, $id);
if (!$record) {
    render('errors/error', ['code' => 404, 'message' => 'Record not found.']);
    exit;
}

$history = RecordService::getHistory($type, $id);

render('layouts/app_header', ['title' => 'Record History: ' . $record['registration_number']]);
?>
<div class="card">
  <h2>Complete Record History: <?= e($record['registration_number']) ?></h2>
  <p class="muted">Append-only log of all status transitions, officer actions, and supervisor reviews.</p>
  <table>
    <thead>
      <tr>
        <th>Date & Time</th>
        <th>Staff Member</th>
        <th>Status</th>
        <th>Comments & Audit Notes</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($history as $h): ?>
      <tr>
        <td><small><?= e($h['created_at']) ?></small></td>
        <td><b><?= e($h['user_full_name'] ?? $h['username'] ?? 'System') ?></b></td>
        <td><span class="badge"><?= e(ucfirst(str_replace('_', ' ', $h['status']))) ?></span></td>
        <td><?= e($h['comment'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div style="margin-top:1rem;">
    <a href="view.php?type=<?= e($type) ?>&id=<?= e($id) ?>" class="btn">Back to Record</a>
  </div>
</div>
<?php render('layouts/app_footer'); ?>
