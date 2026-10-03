<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Auth, Database};
use App\Support\RecordTypes;

Auth::requirePermission('record.search');

$query    = trim($_GET['q'] ?? '');
$type     = trim($_GET['type'] ?? '');
$status   = trim($_GET['status'] ?? '');
$district = (int) ($_GET['district'] ?? 0);

$results = [];
$searchedTypes = ($type !== '' && isset(RecordTypes::ALL[$type])) ? [$type => RecordTypes::ALL[$type]] : RecordTypes::ALL;

foreach ($searchedTypes as $t => $meta) {
    $table = $meta['table'];
    $nameCol = match ($t) {
        'birth'    => 'child_full_name',
        'death'    => 'deceased_full_name',
        'marriage' => 'husband_full_name',
        'divorce'  => 'husband_full_name',
    };
    $sql = "SELECT r.id, '{$t}' AS record_type, r.registration_number, r.{$nameCol} AS primary_name,
                   r.registration_date, r.status, d.name AS district_name
            FROM {$table} r
            LEFT JOIN districts d ON d.id = r.district_id
            WHERE r.is_archived = 0";
    $params = [];

    if ($query !== '') {
        $sql .= " AND (r.registration_number LIKE ? OR r.{$nameCol} LIKE ?)";
        $params[] = "%{$query}%";
        $params[] = "%{$query}%";
    }
    if ($status !== '') {
        $sql .= " AND r.status = ?";
        $params[] = $status;
    }
    if ($district > 0) {
        $sql .= " AND r.district_id = ?";
        $params[] = $district;
    }

    $sql .= " ORDER BY r.registration_date DESC LIMIT 50";
    $rows = Database::fetchAll($sql, $params);
    foreach ($rows as $r) {
        $results[] = $r;
    }
}

$districts = Database::fetchAll('SELECT id, name FROM districts ORDER BY name ASC');

render('layouts/app_header', ['title' => 'Search Records']);
?>
<div class="card">
  <h2>Search Civil Registration Records</h2>
  <form method="GET" style="display:flex; gap:10px; margin-bottom:1.5rem; flex-wrap:wrap;">
    <input type="text" name="q" value="<?= e($query) ?>" placeholder="Registration # or name..." class="form-control" style="flex:2; min-width:200px; padding:8px;">
    
    <select name="type" class="form-control" style="flex:1; min-width:140px; padding:8px;">
      <option value="">All Record Types</option>
      <option value="birth" <?= $type === 'birth' ? 'selected' : '' ?>>Birth</option>
      <option value="death" <?= $type === 'death' ? 'selected' : '' ?>>Death</option>
      <option value="marriage" <?= $type === 'marriage' ? 'selected' : '' ?>>Marriage</option>
      <option value="divorce" <?= $type === 'divorce' ? 'selected' : '' ?>>Divorce</option>
    </select>

    <select name="status" class="form-control" style="flex:1; min-width:140px; padding:8px;">
      <option value="">All Statuses</option>
      <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
      <option value="submitted" <?= $status === 'submitted' ? 'selected' : '' ?>>Submitted</option>
      <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Approved</option>
      <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
      <option value="correction_required" <?= $status === 'correction_required' ? 'selected' : '' ?>>Correction Required</option>
    </select>

    <select name="district" class="form-control" style="flex:1; min-width:140px; padding:8px;">
      <option value="0">All Districts</option>
      <?php foreach ($districts as $d): ?>
        <option value="<?= (int) $d['id'] ?>" <?= $district === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <button type="submit" class="btn btn-primary" style="padding:8px 16px;">Filter</button>
  </form>

  <table>
    <thead>
      <tr>
        <th>Reg Number</th>
        <th>Type</th>
        <th>Primary Name</th>
        <th>District</th>
        <th>Date</th>
        <th>Status</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($results)): ?>
        <tr><td colspan="7" class="text-center muted">No matching records found.</td></tr>
      <?php else: ?>
        <?php foreach ($results as $r): ?>
        <tr>
          <td><b><?= e($r['registration_number']) ?></b></td>
          <td><?= e(ucfirst($r['record_type'])) ?></td>
          <td><?= e($r['primary_name']) ?></td>
          <td><?= e($r['district_name'] ?? '—') ?></td>
          <td><?= e($r['registration_date']) ?></td>
          <td><span class="badge"><?= e(ucfirst(str_replace('_', ' ', $r['status']))) ?></span></td>
          <td>
            <a href="view.php?type=<?= e($r['record_type']) ?>&id=<?= e($r['id']) ?>">View</a>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php render('layouts/app_footer'); ?>
