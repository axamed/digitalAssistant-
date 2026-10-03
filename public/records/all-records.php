<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Services\{RecordService, UserService};
use App\Support\RecordTypes;

/**
 * Registry-wide record list for the manager desk (supervisor / administrator).
 *
 * Officers reach the same list from "My Records → All Records"; this page is the manager's
 * entry point, so it is grouped by event type and by registration desk.
 */

Auth::requireRole('supervisor', 'administrator');

$filterStatus = (string) ($_GET['status'] ?? '');
$filterType   = isset(RecordTypes::ALL[(string) ($_GET['type'] ?? '')]) ? (string) $_GET['type'] : '';
$search       = trim((string) ($_GET['q'] ?? ''));
$deskUserId   = ctype_digit((string) ($_GET['user'] ?? '')) ? (int) $_GET['user'] : null;
if ($deskUserId !== null && $deskUserId <= 0) {
    $deskUserId = null;
}

$records = RecordService::listRecords($deskUserId, $filterStatus, $filterType, $search, 500);

$byType = [];
foreach ($records as $row) {
    $byType[$row['record_type']][] = $row;
}
arsort($byType);

$desks = array_values(array_filter(
    UserService::all(),
    static fn(array $u): bool => $u['status'] === 'active'
));

$link = static function (array $overrides = []) use ($filterStatus, $filterType, $search, $deskUserId): string {
    $query = array_filter([
        'status' => $filterStatus,
        'type'   => $filterType,
        'q'      => $search,
        'user'   => $deskUserId,
    ], static fn($v): bool => $v !== '' && $v !== null);
    foreach ($overrides as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return 'all-records.php' . ($query === [] ? '' : '?' . http_build_query($query));
};

render('layouts/app_header', ['title' => 'All Records']);
?>

<div class="breadcrumb">
  <a href="<?= e(url(Auth::homePath())) ?>">Dashboard</a>
  <span class="sep">&rsaquo;</span>
  <span class="current">All Records</span>
</div>

<div class="page-section-title">
  <div>
    <h2>All Records</h2>
    <p class="muted" style="margin:0;">
      Every civil record in the registry, across all registration desks
      (<?= count($records) ?> shown<?= count($records) >= 500 ? ' — showing the most recent 500' : '' ?>)
    </p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap;">
    <?php foreach ($byType as $type => $rows): ?>
      <a href="<?= e($link(['type' => $type])) ?>" class="btn btn-sm">
        <?= e(RecordTypes::ALL[$type]['label']) ?> (<?= count($rows) ?>)
      </a>
    <?php endforeach; ?>
    <?php if ($filterType !== ''): ?>
      <a href="<?= e($link(['type' => ''])) ?>" class="btn btn-sm btn-primary">Show all events</a>
    <?php endif; ?>
  </div>
</div>

<div style="display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap;">
  <a href="<?= e($link(['status' => ''])) ?>" class="btn btn-sm <?= $filterStatus === '' ? 'btn-primary' : '' ?>">All (<?= count($records) ?>)</a>
  <a href="<?= e($link(['status' => 'draft'])) ?>" class="btn btn-sm <?= $filterStatus === 'draft' ? 'btn-primary' : '' ?>">Drafts</a>
  <a href="<?= e($link(['status' => 'submitted'])) ?>" class="btn btn-sm <?= $filterStatus === 'submitted' ? 'btn-primary' : '' ?>">Submitted</a>
  <a href="<?= e($link(['status' => 'under_review'])) ?>" class="btn btn-sm <?= $filterStatus === 'under_review' ? 'btn-primary' : '' ?>">Under Review</a>
  <a href="<?= e($link(['status' => 'correction_required'])) ?>" class="btn btn-sm <?= $filterStatus === 'correction_required' ? 'btn-primary' : '' ?>">Corrections Needed</a>
  <a href="<?= e($link(['status' => 'approved'])) ?>" class="btn btn-sm <?= $filterStatus === 'approved' ? 'btn-primary' : '' ?>">Approved</a>
</div>

<form method="get" action="all-records.php" class="card" style="padding:14px; margin-bottom:16px; display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
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
  <div>
    <label for="user" style="display:block; font-size:12px; color:var(--muted); margin-bottom:4px;">Registration desk</label>
    <select id="user" name="user">
      <option value="">All desks</option>
      <?php foreach ($desks as $desk): ?>
        <option value="<?= e((string) $desk['id']) ?>" <?= $deskUserId === (int) $desk['id'] ? 'selected' : '' ?>>
          <?= e($desk['full_name']) ?> (<?= e($desk['role_label']) ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn btn-sm btn-primary">Filter</button>
  <?php if ($search !== '' || $filterType !== '' || $deskUserId !== null): ?>
    <a href="<?= e($link(['q' => '', 'type' => '', 'user' => ''])) ?>" class="btn btn-sm">Clear</a>
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
          <th>Registered By</th>
          <th>District</th>
          <th>Date</th>
          <th>Status</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($records === []): ?>
          <tr><td colspan="8" class="text-center muted" style="padding:28px;">No records match your filter criteria.</td></tr>
        <?php else: ?>
          <?php foreach ($records as $r): ?>
          <tr>
            <td><code><?= e($r['registration_number']) ?></code></td>
            <td><?= e(ucfirst($r['record_type'])) ?></td>
            <td><strong><?= e($r['primary_name']) ?></strong></td>
            <td><?= e($r['submitted_by_name'] ?? '—') ?></td>
            <td><?= e($r['district_name'] ?? '—') ?></td>
            <td><?= e($r['registration_date']) ?></td>
            <td>
              <span class="badge status-<?= e($r['status']) ?>">
                <?= e(ucfirst(str_replace('_', ' ', $r['status']))) ?>
              </span>
            </td>
            <td style="text-align:right;">
              <a href="view.php?type=<?= e($r['record_type']) ?>&id=<?= e((string) $r['id']) ?>" class="btn btn-sm">View</a>
              <?php if (RecordService::canEdit($r)): ?>
                <a href="edit.php?type=<?= e($r['record_type']) ?>&id=<?= e((string) $r['id']) ?>" class="btn btn-sm btn-primary">Edit</a>
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
