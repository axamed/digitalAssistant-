<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Auth, Database};

Auth::requireRole('administrator');

$certificates = Database::fetchAll(
    'SELECT c.*, u.full_name AS issued_by_name
     FROM certificates c
     LEFT JOIN users u ON u.id = c.issued_by
     ORDER BY c.created_at DESC'
);

render('layouts/app_header', ['title' => 'Certificate Administration']);
?>
<div class="card">
  <h2>All Certificates</h2>
  <p class="muted">System-wide issued certificates, fee status, and verification tokens.</p>
  <table>
    <thead>
      <tr>
        <th>Certificate No</th>
        <th>Record Type</th>
        <th>Issue Date</th>
        <th>Fee</th>
        <th>Payment</th>
        <th>Status</th>
        <th>Token</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($certificates)): ?>
        <tr><td colspan="7" class="text-center muted">No certificates issued yet.</td></tr>
      <?php else: ?>
        <?php foreach ($certificates as $c): ?>
        <tr>
          <td><b><?= e($c['certificate_number']) ?></b></td>
          <td><?= e(ucfirst($c['record_type'])) ?></td>
          <td><?= e($c['issue_date']) ?></td>
          <td>$<?= e(number_format((float) $c['fee_amount'], 2)) ?></td>
          <td>
            <span class="badge <?= $c['payment_status'] === 'paid' ? 'badge-success' : 'badge-warning' ?>">
              <?= e(strtoupper($c['payment_status'])) ?>
            </span>
          </td>
          <td>
            <span class="badge <?= $c['status'] === 'valid' ? 'badge-success' : 'badge-danger' ?>">
              <?= e(ucfirst($c['status'])) ?>
            </span>
          </td>
          <td><code><?= e(substr($c['verification_token'], 0, 10)) ?>...</code></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php render('layouts/app_footer'); ?>
