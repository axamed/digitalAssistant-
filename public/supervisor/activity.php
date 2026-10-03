<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Services\ReportService;

Auth::requireRole('supervisor');

$officerActivity = ReportService::officerActivity();

render('layouts/app_header', ['title' => 'Officer Activity Monitoring']);
?>
<div class="card">
  <h2>Officer Registration Activity</h2>
  <p class="muted">Civil registration staff activity, volume registered, and approval status.</p>

  <table>
    <thead>
      <tr>
        <th>Officer Name</th>
        <th>Username</th>
        <th>Births</th>
        <th>Deaths</th>
        <th>Marriages</th>
        <th>Divorces</th>
        <th>Total Registered</th>
        <th>Approved</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($officerActivity as $oa): ?>
      <tr>
        <td><b><?= e($oa['officer_name']) ?></b></td>
        <td><code><?= e($oa['username']) ?></code></td>
        <td><?= e($oa['births']) ?></td>
        <td><?= e($oa['deaths']) ?></td>
        <td><?= e($oa['marriages']) ?></td>
        <td><?= e($oa['divorces']) ?></td>
        <td><b><?= e($oa['total']) ?></b></td>
        <td><span class="badge badge-success"><?= e($oa['approved']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php render('layouts/app_footer'); ?>
