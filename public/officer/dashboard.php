<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Auth, Database};
use App\Services\{RecordService, Stats, Settings};
use App\Support\RecordTypes;

$user = Auth::requireRole('officer');
$userId = (int) $user['id'];

$counts    = Stats::recordCounts($userId);
$byType    = Stats::recordCountsByType($userId);
$recent    = Stats::recentRecords($userId, 8);

$totalRecords = Stats::total($counts);
$drafts       = Stats::total($counts, 'draft');
$awaiting     = Stats::total($counts, 'submitted') + Stats::total($counts, 'under_review');
$approved     = Stats::total($counts, 'approved');
$corrections  = Stats::total($counts, 'correction_required');
$rejected     = Stats::total($counts, 'rejected');

render('layouts/app_header', ['title' => 'Civil Registration Officer Dashboard']);
?>

<div style="margin-bottom:24px;">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
    <div>
      <h1 style="font-size:1.85rem; margin-bottom:4px;">Registration Desk: <?= e($user['name']) ?></h1>
      <p class="muted" style="margin:0;">
        Civil Registration Office &middot; Mogadishu Municipal Government, Banadir Region
      </p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
      <a href="birth.php" class="btn btn-sm btn-primary">+ Register Birth</a>
      <a href="marriage.php" class="btn btn-sm btn-primary">+ Register Marriage</a>
      <a href="death.php" class="btn btn-sm">+ Register Death</a>
      <a href="divorce.php" class="btn btn-sm">+ Register Divorce</a>
    </div>
  </div>
</div>

<!-- Officer KPIs -->
<div class="kpis">
  <div class="kpi kpi-accent">
    <div class="kpi-label">My Total Records</div>
    <div class="kpi-value"><?= e((string) $totalRecords) ?></div>
    <div class="kpi-sub">Lifetime registrations</div>
  </div>

  <div class="kpi <?= $drafts > 0 ? 'kpi-warn' : 'kpi-accent' ?>">
    <div class="kpi-label">Drafts (Unsubmitted)</div>
    <div class="kpi-value"><?= e((string) $drafts) ?></div>
    <div class="kpi-sub"><?= $drafts > 0 ? 'Ready for completion' : 'No drafts pending' ?></div>
  </div>

  <div class="kpi kpi-accent">
    <div class="kpi-label">Awaiting Approval</div>
    <div class="kpi-value"><?= e((string) $awaiting) ?></div>
    <div class="kpi-sub">In supervisor queue</div>
  </div>

  <div class="kpi kpi-ok">
    <div class="kpi-label">Approved & Certified</div>
    <div class="kpi-value"><?= e((string) $approved) ?></div>
    <div class="kpi-sub">Signed by supervisor</div>
  </div>

  <div class="kpi <?= $corrections > 0 ? 'kpi-bad' : 'kpi-accent' ?>">
    <div class="kpi-label">Corrections Needed</div>
    <div class="kpi-value"><?= e((string) $corrections) ?></div>
    <div class="kpi-sub"><?= $corrections > 0 ? 'Requires your edit' : 'None pending' ?></div>
  </div>
</div>

<!-- Two Column Layout: Quick Actions & Records Distribution -->
<div class="grid-2" style="grid-template-columns: 1fr 1.3fr; margin-bottom:28px;">
  <!-- Fast Event Intake Tiles -->
  <div class="card">
    <h3 style="margin-bottom:14px;">Vital Event Intake</h3>
    <p class="muted" style="font-size:13px; margin-bottom:16px;">
      Register citizen vital life events occurring across the Banadir Region.
    </p>

    <div style="display:flex; flex-direction:column; gap:10px;">
      <a href="birth.php" class="btn" style="justify-content:space-between; padding:11px 16px;">
        <span><strong>Birth Registration</strong> (with photo upload)</span>
        <span class="badge" style="background:var(--brass-soft);">$20 Fee</span>
      </a>

      <a href="marriage.php" class="btn" style="justify-content:space-between; padding:11px 16px;">
        <span><strong>Marriage Registration</strong> (Husband & Wife)</span>
        <span class="badge" style="background:var(--brass-soft);">$25 Fee</span>
      </a>

      <a href="death.php" class="btn" style="justify-content:space-between; padding:11px 16px;">
        <span><strong>Death Registration</strong> (Guarantor required)</span>
        <span class="badge">Official Register</span>
      </a>

      <a href="divorce.php" class="btn" style="justify-content:space-between; padding:11px 16px;">
        <span><strong>Divorce Registration</strong> (Court reference)</span>
        <span class="badge">Court Decree</span>
      </a>
    </div>

    <div style="margin-top:16px; padding-top:14px; border-top:1px solid var(--line); display:flex; justify-content:space-between; align-items:center;">
      <a href="../records/search.php" style="font-size:13.5px; font-weight:600;">🔍 Search All Banadir Records &rarr;</a>
      <a href="my-records.php" style="font-size:13.5px; font-weight:600;">My Records &rarr;</a>
    </div>
  </div>

  <!-- Records Breakdown by Category -->
  <div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h3 style="margin:0;">My Registration Volume by Event</h3>
      <span class="muted" style="font-size:12px;"><?= e((string) $totalRecords) ?> total records</span>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Event Category</th>
            <th>Registered</th>
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

    <div style="margin-top:18px; padding-top:14px; border-top:1px solid var(--line); font-size:12.5px; color:var(--muted); line-height:1.6;">
      <strong>Officer Permissions:</strong> You can edit and archive only records registered under your staff account that are currently in <code>Draft</code> or <code>Correction Required</code> status. Approved records are permanently locked.
    </div>
  </div>
</div>

<!-- Recent Records Created by this Officer -->
<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
    <div>
      <h3 style="margin:0;">My Recent Registrations</h3>
      <span class="muted" style="font-size:12px;">Last 8 civil records entered under your account</span>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
      <a href="my-records.php?scope=all" class="btn btn-sm">All Registry Records &rarr;</a>
      <a href="my-records.php" class="btn btn-sm">View All My Records &rarr;</a>
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Reg Number</th>
          <th>Event Type</th>
          <th>Citizen / Primary Name</th>
          <th>District</th>
          <th>Registration Date</th>
          <th>Status</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($recent)): ?>
          <tr><td colspan="7" class="text-center muted">No registrations recorded yet.</td></tr>
        <?php else: ?>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td><code><?= e($r['registration_number']) ?></code></td>
              <td><?= e(ucfirst($r['record_type'])) ?></td>
              <td><strong><?= e($r['primary_name']) ?></strong></td>
              <td><?= e($r['district_name'] ?? '—') ?></td>
              <td><?= e($r['registration_date']) ?></td>
              <td>
                <span class="badge status-<?= e($r['status']) ?>">
                  <?= e(ucfirst(str_replace('_', ' ', $r['status']))) ?>
                </span>
              </td>
              <td style="text-align:right;">
                <a href="../records/view.php?type=<?= e($r['record_type']) ?>&id=<?= e((string) $r['id']) ?>" class="btn btn-sm">View</a>
                <?php if (RecordService::canEdit($r)): ?>
                  <a href="../records/edit.php?type=<?= e($r['record_type']) ?>&id=<?= e((string) $r['id']) ?>" class="btn btn-sm btn-primary">Edit</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render('layouts/app_footer'); ?>
