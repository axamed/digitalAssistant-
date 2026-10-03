<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Auth, Csrf, Database};
use App\Services\{CertificateService, PaymentService};

Auth::requirePermission('certificate.print');

$certificates = Database::fetchAll(
    'SELECT c.*, u_iss.full_name AS issued_by_name,
            COALESCE(r.registration_number, r2.registration_number, r3.registration_number, r4.registration_number) AS record_registration_number
     FROM certificates c
     LEFT JOIN users u_iss ON u_iss.id = c.issued_by
     LEFT JOIN birth_records     r  ON c.record_type = "birth"    AND r.id  = c.record_id
     LEFT JOIN death_records     r2 ON c.record_type = "death"    AND r2.id = c.record_id
     LEFT JOIN marriage_records  r3 ON c.record_type = "marriage" AND r3.id = c.record_id
     LEFT JOIN divorce_records   r4 ON c.record_type = "divorce"  AND r4.id = c.record_id
     ORDER BY c.created_at DESC'
);

$filter = (string) ($_GET['status'] ?? 'all');
$canPay    = Auth::can('certificate.pay');
$canRevoke = Auth::can('certificate.revoke');

$visible = array_values(array_filter(
    $certificates,
    static fn(array $c): bool => match ($filter) {
        'unpaid'  => $c['payment_status'] === 'unpaid',
        'paid'    => $c['payment_status'] === 'paid',
        'revoked' => $c['status'] === 'revoked',
        default   => true,
    }
));

$counts = ['all' => count($certificates), 'unpaid' => 0, 'paid' => 0, 'revoked' => 0];
foreach ($certificates as $c) {
    if ($c['payment_status'] === 'unpaid') { $counts['unpaid']++; }
    if ($c['payment_status'] === 'paid')   { $counts['paid']++; }
    if ($c['status'] === 'revoked')        { $counts['revoked']++; }
}

$regNumbers = [];
foreach ($certificates as $c) {
    $regNumbers[$c['record_type'] . '-' . $c['record_id']] = $c['record_registration_number'] ?? null;
}

render('layouts/app_header', ['title' => 'Civil Registration Certificates']);
?>
<div class="breadcrumb">
  <a href="<?= e(url(Auth::homePath())) ?>">Dashboard</a>
  <span class="sep">&rsaquo;</span>
  <span class="current">Certificates</span>
</div>

<div class="page-section-title">
  <div>
    <h2>Official Civil Certificates</h2>
    <p class="muted" style="margin:0;">Issued automatically on supervisor approval. Printing unlocks once the fee is recorded as paid.</p>
  </div>
  <div style="display:flex; gap:6px; flex-wrap:wrap;">
    <?php foreach (['all' => 'All', 'unpaid' => 'Awaiting payment', 'paid' => 'Paid', 'revoked' => 'Revoked'] as $key => $label): ?>
      <a href="index.php?status=<?= e($key) ?>"
         class="btn btn-sm <?= $filter === $key ? 'btn-primary' : '' ?>">
        <?= e($label) ?> (<?= (int) $counts[$key] ?>)
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <table>
    <thead>
      <tr>
        <th>Certificate No</th>
        <th>Type</th>
        <th>Record</th>
        <th>Issue Date</th>
        <th>Fee</th>
        <th>Payment</th>
        <th>Status</th>
        <th style="text-align:right;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($visible === []): ?>
        <tr><td colspan="8" class="muted" style="text-align:center; padding:24px;">No certificates match this filter.</td></tr>
      <?php endif; ?>
      <?php foreach ($visible as $c): ?>
        <?php
        $printable = $c['payment_status'] === 'paid' && $c['status'] === 'valid';
        $regNo = $regNumbers[$c['record_type'] . '-' . $c['record_id']] ?? null;
        ?>
        <tr>
          <td><b><?= e($c['certificate_number']) ?></b></td>
          <td><?= e(ucfirst((string) $c['record_type'])) ?></td>
          <td>
            <?php if ($regNo): ?>
              <a href="../records/view.php?type=<?= e($c['record_type']) ?>&id=<?= (int) $c['record_id'] ?>"><?= e($regNo) ?></a>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td><?= e((string) $c['issue_date']) ?></td>
          <td><?= e(money((float) $c['fee_amount'])) ?></td>
          <td>
            <span class="badge <?= $c['payment_status'] === 'paid' ? 'badge-success' : 'badge-warning' ?>">
              <?= e(strtoupper((string) $c['payment_status'])) ?>
            </span>
          </td>
          <td>
            <span class="badge <?= $c['status'] === 'valid' ? 'badge-success' : 'badge-danger' ?>">
              <?= e(ucfirst((string) $c['status'])) ?>
            </span>
          </td>
          <td style="text-align:right; white-space:nowrap;">
            <a href="preview.php?id=<?= (int) $c['id'] ?>" class="btn btn-sm">Preview</a>
            <?php if ($printable): ?>
              <a href="print.php?id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-primary">Print</a>
            <?php elseif ($c['payment_status'] === 'unpaid' && $canPay && $c['status'] === 'valid'): ?>
              <a href="pay.php?id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-primary">Mark Paid</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php render('layouts/app_footer'); ?>
