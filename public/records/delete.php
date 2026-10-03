<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Auth, Csrf};
use App\Services\RecordService;
use App\Support\RecordTypes;

Auth::requirePermission('record.delete');

$type = trim($_REQUEST['type'] ?? '');
$id   = (int) ($_REQUEST['id'] ?? 0);

if (!isset(RecordTypes::ALL[$type]) || $id <= 0) {
    redirect('records/search.php');
}

$record = RecordService::find($type, $id);
if (!$record) {
    render('errors/error', ['code' => 404, 'message' => 'Record not found.']);
    exit;
}

if (!RecordService::canDelete($record)) {
    render('errors/error', ['code' => 403, 'message' => 'Approved records can never be deleted, and unapproved records can only be deleted by authorized staff.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $reason = trim($_POST['reason'] ?? '');
    RecordService::deleteRecord($type, $id, $reason);
    redirect('records/search.php?type=' . urlencode($type));
}

render('layouts/app_header', ['title' => 'Delete (Archive) Record']);
?>
<div class="card" style="max-width: 600px;">
  <h2>Confirm Record Archival / Deletion</h2>
  <p>Are you sure you want to archive <b><?= e(ucfirst($type)) ?> Record <?= e($record['registration_number']) ?></b>?</p>
  <p class="muted">This action will remove the record from active listings. The operation is permanently recorded in the audit trail.</p>

  <form method="POST">
    <?= Csrf::field() ?>
    <div style="margin-bottom:1rem;">
      <label><b>Reason for deletion:</b></label>
      <input type="text" name="reason" required class="form-control" style="width:100%; padding:8px; margin-top:4px;" placeholder="Explain why this record is being archived...">
    </div>
    <div style="display:flex; gap:10px;">
      <button type="submit" class="btn" style="background:var(--bad); color:#fff;">Confirm Archive</button>
      <a href="view.php?type=<?= e($type) ?>&id=<?= e($id) ?>" class="btn">Cancel</a>
    </div>
  </form>
</div>
<?php render('layouts/app_footer'); ?>
