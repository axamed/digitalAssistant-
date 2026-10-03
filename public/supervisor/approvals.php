<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Services\ApprovalService;

Auth::requireRole('supervisor');

$queue = ApprovalService::getPendingQueue();

render('layouts/app_header', ['title' => 'Pending Approvals Queue']);
?>
<div class="card">
  <h2>Pending Review & Approval Queue</h2>
  <p class="muted">Records submitted by civil registration officers requiring supervisor verification and digital signature.</p>

  <table>
    <thead>
      <tr>
        <th>Reg Number</th>
        <th>Type</th>
        <th>Primary Name</th>
        <th>District</th>
        <th>Submitted By</th>
        <th>Submission Date</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($queue)): ?>
        <tr><td colspan="7" class="text-center muted">No records currently awaiting supervisor approval.</td></tr>
      <?php else: ?>
        <?php foreach ($queue as $q): ?>
        <tr>
          <td><b><?= e($q['registration_number']) ?></b></td>
          <td><?= e(ucfirst($q['record_type'])) ?></td>
          <td><?= e($q['primary_name']) ?></td>
          <td><?= e($q['district_name']) ?></td>
          <td><?= e($q['submitted_by_name']) ?></td>
          <td><?= e($q['created_at']) ?></td>
          <td>
            <a href="../records/view.php?type=<?= e($q['record_type']) ?>&id=<?= e($q['id']) ?>" class="btn btn-primary" style="padding:4px 8px; font-size:12px;">Review</a>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php render('layouts/app_footer'); ?>
