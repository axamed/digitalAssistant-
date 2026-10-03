<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Auth, Csrf, Database};
use App\Services\{CertificateService, PaymentService};
use App\Support\RecordTypes;

$user = Auth::requirePermission('certificate.pay');

$id   = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$cert = Database::fetchOne('SELECT * FROM certificates WHERE id = ?', [$id]);
if (!$cert) {
    render('errors/error', ['code' => 404, 'message' => 'Certificate not found.']);
    exit;
}

if (is_post()) {
    Csrf::check();

    try {
        if ($cert['payment_status'] === 'paid') {
            flash('warning', 'This certificate fee is already recorded as paid.');
        } elseif ($cert['status'] !== 'valid') {
            throw new \RuntimeException('A revoked certificate cannot take a fee payment.');
        } else {
            PaymentService::recordPayment($id);
            flash('success', 'Fee of ' . money((float) $cert['fee_amount']) . ' recorded for certificate ' . $cert['certificate_number'] . '. It can now be printed.');
        }
    } catch (\Throwable $e) {
        flash('danger', $e->getMessage());
    }

    redirect('certificates/preview.php?id=' . $id);
}

$table   = RecordTypes::table((string) $cert['record_type']);
$record  = Database::fetchOne("SELECT registration_number, status FROM {$table} WHERE id = ?", [(int) $cert['record_id']]);

render('layouts/app_header', ['title' => 'Record Fee Payment: ' . $cert['certificate_number']]);
?>
<div class="breadcrumb">
  <a href="<?= e(url('certificates/index.php')) ?>">Certificates</a>
  <span class="sep">&rsaquo;</span>
  <a href="preview.php?id=<?= (int) $cert['id'] ?>"><?= e($cert['certificate_number']) ?></a>
  <span class="sep">&rsaquo;</span>
  <span class="current">Fee Payment</span>
</div>

<div class="card" style="max-width: 620px; margin: 0 auto;">
  <h2 style="margin-top:0;">Confirm Certificate Fee Payment</h2>

  <table style="margin-bottom:1.2rem;">
    <tr><th style="width:190px;">Certificate Number</th><td><b><?= e($cert['certificate_number']) ?></b></td></tr>
    <tr><th>Certificate Type</th><td><?= e(ucfirst((string) $cert['record_type'])) ?></td></tr>
    <tr><th>Source Record</th>
      <td>
        <?php if ($record): ?>
          <a href="../records/view.php?type=<?= e($cert['record_type']) ?>&id=<?= (int) $cert['record_id'] ?>">
            <?= e((string) $record['registration_number']) ?>
          </a>
        <?php else: ?>
          <span class="muted">Record no longer available</span>
        <?php endif; ?>
      </td>
    </tr>
    <tr><th>Amount Due</th><td><b style="font-size:17px;"><?= e(money((float) $cert['fee_amount'])) ?></b></td></tr>
    <tr><th>Current Status</th>
      <td>
        <span class="badge <?= $cert['payment_status'] === 'paid' ? 'badge-success' : 'badge-warning' ?>">
          <?= e(ucfirst((string) $cert['payment_status'])) ?>
        </span>
      </td>
    </tr>
  </table>

  <?php if ($cert['payment_status'] === 'paid'): ?>
    <div class="alert alert-success">
      The fee for this certificate was already recorded as paid
      <?= $cert['paid_at'] ? ' on ' . e((string) $cert['paid_at']) : '' ?>.
    </div>
    <a href="print.php?id=<?= (int) $cert['id'] ?>" class="btn btn-primary">Print Certificate</a>
    <a href="preview.php?id=<?= (int) $cert['id'] ?>" class="btn">Back to Preview</a>
  <?php elseif ($cert['status'] !== 'valid'): ?>
    <div class="alert alert-danger">
      This certificate has been revoked. Restore it before recording a payment.
    </div>
    <a href="preview.php?id=<?= (int) $cert['id'] ?>" class="btn">Back to Preview</a>
  <?php else: ?>
    <form method="post" onsubmit="return confirm('Confirm that <?= e(money((float) $cert['fee_amount'])) ?> has been received for certificate <?= e($cert['certificate_number']) ?>? This cannot be undone.');">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="<?= (int) $cert['id'] ?>">
      <p class="muted" style="font-size:13px;">
        Recording the payment stores your staff identity
        (<code><?= e($user['username']) ?></code>) and the exact timestamp in the audit trail,
        and unlocks this certificate for printing.
      </p>
      <div style="display:flex; gap:10px;">
        <button type="submit" class="btn btn-primary">Confirm &amp; Mark as Paid</button>
        <a href="preview.php?id=<?= (int) $cert['id'] ?>" class="btn">Cancel</a>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php render('layouts/app_footer'); ?>
