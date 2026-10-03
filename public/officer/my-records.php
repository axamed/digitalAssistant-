<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Services\RecordService;
use App\Support\RecordTypes;

$user = Auth::requireRole('officer');
$userId = (int) $user['id'];

// scope=mine  -> only records registered under this officer account (the default)
// scope=all   -> every record in the registry, with a "Registered By" column so it stays
//                clear whose desk each one came from. Edit is still offered only where
//                RecordService::canEdit() allows it.
$scope       = ($_GET['scope'] ?? 'mine') === 'all' ? 'all' : 'mine';
$filterStatus = (string) ($_GET['status'] ?? '');
$filterType   = isset(RecordTypes::ALL[(string) ($_GET['type'] ?? '')]) ? (string) $_GET['type'] : '';
$search       = trim((string) ($_GET['q'] ?? ''));

$records = RecordService::listRecords(
    $scope === 'mine' ? $userId : null,
    $filterStatus,
    $filterType,
    $search
);

$scopeAllCount = count(RecordService::listRecords(null, '', '', '', 500));
$scopeMyCount  = count(RecordService::listRecords($userId, '', '', '', 500));

/** Build a filter link that keeps the current scope and type selection. */
$link = static function (array $overrides = []) use ($scope, $filterStatus, $filterType, $search): string {
    $query = array_filter([
        'scope'  => $scope,
        'status' => $filterStatus,
        'type'   => $filterType,
        'q'      => $search,
    ], static fn($v): bool => $v !== '' && $v !== null);
    foreach ($overrides as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return 'my-records.php' . ($query === [] ? '' : '?' . http_build_query($query));
};

render('layouts/app_header', ['title' => $scope === 'all' ? 'All Records' : 'My Registered Records']);
?>

<div class="breadcrumb">
  <a href="<?= e(url(Auth::homePath())) ?>">Dashboard</a>
  <span class="sep">&rsaquo;</span>
  <span class="current"><?= $scope === 'all' ? 'All Records' : 'My Records' ?></span>
</div>

<div class="page-section-title">
  <div>
    <h2><?= $scope === 'all' ? 'All Records' : 'My Registered Records' ?></h2>
    <p class="muted" style="margin:0;">
      <?php if ($scope === 'all'): ?>
        Every civil record in the registry, from all registration desks (<?= count($records) ?> shown)
      <?php else: ?>
        All civil records registered under your officer account (<?= count($records) ?> shown)
      <?php endif; ?>
    </p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap;">
    <a href="birth.php" class="btn btn-sm btn-primary">+ Birth</a>
    <a href="marriage.php" class="btn btn-sm btn-primary">+ Marriage</a>
    <a href="death.php" class="btn btn-sm">+ Death</a>
    <a href="divorce.php" class="btn btn-sm">+ Divorce</a>
  </div>
</div>

<!-- Scope switch: this officer's own records, or the whole registry -->
<div style="display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap;">
  <a href="<?= e($link(['scope' => 'mine'])) ?>" class="btn btn-sm <?= $scope === 'mine' ? 'btn-primary' : '' ?>">
    My Records (<?= $scopeMyCount ?>)
  </a>
  <a href="<?= e($link(['scope' => 'all'])) ?>" class="btn btn-sm <?= $scope === 'all' ? 'btn-primary' : '' ?>">
    All Records (<?= $scopeAllCount ?>)
  </a>
</div>

<!-- Status filter tabs -->
<div style="display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap;">
  <a href="<?= e($link(['status' => ''])) ?>" class="btn btn-sm <?= $filterStatus === '' ? 'btn-primary' : '' ?>">All (<?= count($records) ?>)</a>
  <a href="<?= e($link(['status' => 'draft'])) ?>" class="btn btn-sm <?= $filterStatus === 'draft' ? 'btn-primary' : '' ?>">Drafts</a>
  <a href="<?= e($link(['status' => 'submitted'])) ?>" class="btn btn-sm <?= $filterStatus === 'submitted' ? 'btn-primary' : '' ?>">Submitted</a>
  <a href="<?= e($link(['status' => 'correction_required'])) ?>" class="btn btn-sm <?= $filterStatus === 'correction_required' ? 'btn-primary' : '' ?>">Corrections Needed</a>
  <a href="<?= e($link(['status' => 'approved'])) ?>" class="btn btn-sm <?= $filterStatus === 'approved' ? 'btn-primary' : '' ?>">Approved</a>
</div>

<!-- Event type and free-text search -->
<form method="get" action="my-records.php" class="card" style="padding:14px; margin-bottom:16px; display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
  <input type="hidden" name="scope" value="<?= e($scope) ?>">
  <?php if ($filterStatus !== ''): ?>
    <input type="hidden" name="status" value="<?= e($filterStatus) ?>">
  <?php endif; ?>
  <div>
    <label for="q" style="display:block; font-size:12px; color:var(--muted); margin-bottom:4px;">Search number or name</label>
    <input type="text" id="q" name="q" value="<?= e($search) ?>" placeholder="e.g. BR-2026-0001 or Hodan" style="min-width:220px;">
  </div>
  <div>
    <label for="type" style="display:block; font-size:12px; color:var(--muted); margin-bottom:4px;">Event type</label>
    <select id="type" name="type">
      <option value="">All events</option>
      <?php foreach (RecordTypes::ALL as $key => $meta): ?>
        <option value="<?= e($key) ?>" <?= $filterType === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn btn-sm btn-primary">Filter</button>
  <?php if ($search !== '' || $filterType !== ''): ?>
    <a href="<?= e($link(['q' => '', 'type' => ''])) ?>" class="btn btn-sm">Clear</a>
  <?php endif; ?>
</form>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Reg Number</th>
          <th>Event</th>
          <th>Citizen / Primary Name</th>
          <?php if ($scope === 'all'): ?>
            <th>Registered By</th>
          <?php endif; ?>
          <th>District</th>
          <th>Date</th>
          <th>Status</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($records === []): ?>
          <tr><td colspan="<?= $scope === 'all' ? 8 : 7 ?>" class="text-center muted" style="padding:28px;">No records match your filter criteria.</td></tr>
        <?php else: ?>
          <?php foreach ($records as $r): ?>
          <tr>
            <td><code><?= e($r['registration_number']) ?></code></td>
            <td><?= e(ucfirst($r['record_type'])) ?></td>
            <td><strong><?= e($r['primary_name']) ?></strong></td>
            <?php if ($scope === 'all'): ?>
              <td><?= e($r['submitted_by_name'] ?? '—') ?></td>
            <?php endif; ?>
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
