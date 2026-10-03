<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Auth, Database};
use App\Services\{ApprovalService, Stats, Settings};
use App\Support\RecordTypes;

$user = Auth::requireRole('supervisor');
$userId = (int) $user['id'];

$counts       = Stats::recordCounts();
$pendingQueue = ApprovalService::getPendingQueue();
$topDistricts = Stats::topDistricts(5);
$certStats    = Stats::certificateCounts();

$totalAll    = Stats::total($counts);
$pendingCnt  = count($pendingQueue);
$approvedCnt = Stats::total($counts, 'approved');
$rejectCnt   = Stats::total($counts, 'rejected');
$correctCnt  = Stats::total($counts, 'correction_required');

render('layouts/app_header', ['title' => 'Supervisor / Manager Dashboard']);
?>

<div style="margin-bottom:24px;">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
    <div>
      <h1 style="font-size:1.85rem; margin-bottom:4px;">Supervisor Oversight: <?= e($user['name']) ?></h1>
      <p class="muted" style="margin:0;">
        Civil Registration Office &middot; Mogadishu Municipal Government, Banadir Region
      </p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
      <a href="approvals.php" class="btn btn-sm btn-primary">
        Review Queue (<?= e((string) $pendingCnt) ?>) &rarr;
      </a>
      <a href="reports.php" class="btn btn-sm">Analytics & Reports</a>
      <a href="activity.php" class="btn btn-sm">Officer Activity</a>
    </div>
  </div>
</div>

<!-- Supervisor KPIs -->
<div class="kpis">
  <div class="kpi <?= $pendingCnt > 0 ? 'kpi-warn' : 'kpi-accent' ?>">
    <div class="kpi-label">Pending Approval Queue</div>
    <div class="kpi-value"><?= e((string) $pendingCnt) ?></div>
    <div class="kpi-sub"><?= $pendingCnt > 0 ? 'Awaiting your signature' : 'Queue clear' ?></div>
  </div>

  <div class="kpi kpi-ok">
    <div class="kpi-label">Approved & Certified</div>
    <div class="kpi-value"><?= e((string) $approvedCnt) ?></div>
    <div class="kpi-sub">Total official approvals</div>
  </div>

  <div class="kpi kpi-accent">
    <div class="kpi-label">Corrections Active</div>
    <div class="kpi-value"><?= e((string) $correctCnt) ?></div>
    <div class="kpi-sub">Sent back to officers</div>
  </div>

  <div class="kpi kpi-accent">
    <div class="kpi-label">Rejected Records</div>
    <div class="kpi-value"><?= e((string) $rejectCnt) ?></div>
    <div class="kpi-sub">With logged reason</div>
  </div>

  <div class="kpi kpi-accent">
    <div class="kpi-label">Total Civil Archive</div>
    <div class="kpi-value"><?= e((string) $totalAll) ?></div>
    <div class="kpi-sub">All <?= (int) Database::scalar('SELECT COUNT(*) FROM districts') ?> Banadir districts</div>
  </div>
</div>

<!-- Pending Approvals Table (Top 5 preview) -->
<div class="card" style="margin-bottom:28px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
    <div>
      <h3 style="margin:0;">Pending Approvals Queue</h3>
      <span class="muted" style="font-size:12px;">Records submitted by officers awaiting digital signature approval</span>
    </div>
    <a href="approvals.php" class="btn btn-sm">Full Approval Queue (<?= e((string) $pendingCnt) ?>) &rarr;</a>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Reg Number</th>
          <th>Event Type</th>
          <th>Citizen / Primary Name</th>
          <th>District</th>
          <th>Submitted By</th>
          <th>Submission Date</th>
          <th style="text-align:right;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($pendingQueue)): ?>
          <tr><td colspan="7" class="text-center muted" style="padding:24px;">No records currently awaiting supervisor review.</td></tr>
        <?php else: ?>
          <?php foreach (array_slice($pendingQueue, 0, 5) as $q): ?>
            <tr>
              <td><code><?= e($q['registration_number']) ?></code></td>
              <td><?= e(ucfirst($q['record_type'])) ?></td>
              <td><strong><?= e($q['primary_name']) ?></strong></td>
              <td><?= e($q['district_name']) ?></td>
              <td><?= e($q['submitted_by_name']) ?></td>
              <td><small><?= e(date('M d, H:i', strtotime($q['created_at']))) ?></small></td>
              <td style="text-align:right;">
                <a href="../records/view.php?type=<?= e($q['record_type']) ?>&id=<?= e((string) $q['id']) ?>" class="btn btn-sm btn-primary">
                  Review & Sign &rarr;
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Two-Column Layout: Records by Event Type & Top Banadir Districts -->
<div class="grid-2" style="margin-bottom:28px;">
  <!-- Records by Event Category -->
  <div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h3 style="margin:0;">Archive Volume by Event</h3>
      <span class="muted" style="font-size:12px;">Total: <?= e((string) $totalAll) ?></span>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Event Category</th>
            <th>Total</th>
            <th>Approved</th>
            <th>Pending</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (RecordTypes::ALL as $type => $meta): 
            $typeCounts = $counts[$type] ?? [];
            $typeTotal  = array_sum($typeCounts);
            $typeAppr   = $typeCounts['approved'] ?? 0;
            $typePend   = ($typeCounts['submitted'] ?? 0) + ($typeCounts['under_review'] ?? 0);
          ?>
            <tr>
              <td><strong><?= e($meta['label']) ?> Records</strong></td>
              <td><?= e((string) $typeTotal) ?></td>
              <td><span class="badge badge-success"><?= e((string) $typeAppr) ?></span></td>
              <td><?= e((string) $typePend) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div style="margin-top:16px; padding-top:12px; border-top:1px solid var(--line); display:flex; justify-content:space-between; align-items:center;">
      <a href="reports.php" style="font-size:13.5px; font-weight:600;"> Full Statistical Reports &rarr;</a>
      <a href="../certificates/index.php" style="font-size:13.5px; font-weight:600;">Issued Certificates &rarr;</a>
    </div>
  </div>

  <!-- Top Districts Distribution -->
  <div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h3 style="margin:0;">Busiest Districts</h3>
      <span class="muted" style="font-size:12px;">Top <?= count($topDistricts) ?> of <?= (int) Database::scalar('SELECT COUNT(*) FROM districts') ?></span>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>District</th>
            <th>Births</th>
            <th>Deaths</th>
            <th>Marriages</th>
            <th>Total</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($topDistricts)): ?>
            <tr><td colspan="5" class="text-center muted">No registrations recorded yet.</td></tr>
          <?php else: ?>
            <?php foreach ($topDistricts as $td): ?>
              <tr>
                <td><strong><?= e($td['name']) ?></strong></td>
                <td><?= e((string) $td['births']) ?></td>
                <td><?= e((string) $td['deaths']) ?></td>
                <td><?= e((string) $td['marriages']) ?></td>
                <td><strong><?= e((string) $td['total']) ?></strong></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div style="margin-top:16px; padding-top:12px; border-top:1px solid var(--line); display:flex; justify-content:space-between; align-items:center;">
      <a href="activity.php" style="font-size:13.5px; font-weight:600;">👥 Officer Performance Monitoring &rarr;</a>
      <a href="../admin/audit.php" style="font-size:13.5px; font-weight:600;">Audit Trail &rarr;</a>
    </div>
  </div>
</div>

<!-- Supervisory Governance Note -->
<div class="card" style="background:var(--brass-faint); border-color:var(--brass-soft);">
  <div style="display:flex; gap:16px; align-items:center;">
    <div class="seal" style="width:42px; height:42px; font-size:1.3rem;">◈</div>
    <div style="font-size:13px; color:var(--ink); line-height:1.6;">
      <strong>Supervisory Standard:</strong> In accordance with Banadir Regional Civil Registration Decrees, approval requires direct inspection of identity references, verification of hospital birth and death records, and affixture of a certified on-screen digital signature. A supervisor is legally prohibited from approving their own registered entries.
    </div>
  </div>
</div>

<?php render('layouts/app_footer'); ?>
