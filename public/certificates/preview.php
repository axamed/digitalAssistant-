<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Auth, Csrf, Database};
use App\Services\{CertificateService, RecordService, SignatureService};
use App\Support\RecordTypes;

Auth::requirePermission('certificate.print');

$id   = (int) ($_GET['id'] ?? $_REQUEST['id'] ?? 0);
$cert = Database::fetchOne(
    'SELECT c.*, u_iss.full_name AS issued_by_name, u_sig.full_name AS signed_by_name, u_pay.full_name AS paid_by_name
     FROM certificates c
     LEFT JOIN users u_iss ON u_iss.id = c.issued_by
     LEFT JOIN users u_sig ON u_sig.id = c.signed_by
     LEFT JOIN users u_pay ON u_pay.id = c.paid_by
     WHERE c.id = ?',
    [$id]
);
if (!$cert) {
    render('errors/error', ['code' => 404, 'message' => 'Certificate not found.']);
    exit;
}

if (is_post()) {
    Csrf::check();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'revoke') {
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if ($reason === '') {
                throw new \RuntimeException('A revocation reason is required.');
            }
            CertificateService::revoke($id, $reason);
            flash('success', 'Certificate ' . $cert['certificate_number'] . ' revoked. Public verification now reports it as revoked.');
        } elseif ($action === 'restore') {
            CertificateService::restore($id);
            flash('success', 'Certificate ' . $cert['certificate_number'] . ' restored to valid.');
        } else {
            throw new \RuntimeException('Unknown action.');
        }
    } catch (\Throwable $e) {
        flash('danger', $e->getMessage());
    }

    redirect('certificates/preview.php?id=' . $id);
}

$record    = RecordService::find((string) $cert['record_type'], (int) $cert['record_id']);
$type      = (string) $cert['record_type'];
$printable = CertificateService::canPrint($cert);
$signature = !empty($cert['signature_image']) ? SignatureService::dataUri((string) $cert['signature_image']) : null;

$summary = match ($type) {
    'birth'    => [
        "Name"         => $record['child_full_name'] ?? '—',
        "Gender"       => ucfirst((string) ($record['gender'] ?? '')),
        "Date of Birth"=> (string) ($record['date_of_birth'] ?? '—'),
        "Place of Birth" => (string) ($record['place_of_birth'] ?? '—'),
        "Hospital"     => (string) ($record['hospital_of_birth'] ?? '—'),
    ],
    'death'    => [
        "Name"         => $record['deceased_full_name'] ?? '—',
        "Gender"       => ucfirst((string) ($record['gender'] ?? '')),
        "Date of Birth"=> (string) ($record['date_of_birth'] ?? '—'),
        "Date of Death"=> (string) ($record['date_of_death'] ?? '—'),
        "Place of Death" => (string) ($record['place_of_death'] ?? '—'),
    ],
    'marriage' => [
        "Husband"      => (string) ($record['husband_full_name'] ?? '—'),
        "Husband Born" => (string) ($record['husband_date_of_birth'] ?? '—'),
        "Wife"         => (string) ($record['wife_full_name'] ?? '—'),
        "Wife Born"    => (string) ($record['wife_date_of_birth'] ?? '—'),
        "Marriage Date"=> (string) ($record['marriage_date'] ?? '—'),
        "Place"        => (string) ($record['place_of_marriage'] ?? '—'),
        "Mahr"         => (string) ($record['mahr_amount'] ?? '—'),
    ],
    'divorce'  => [
        "Marriage Reg No" => (string) ($record['marriage_registration_number'] ?? '—'),
        "Husband"      => (string) ($record['husband_full_name'] ?? '—'),
        "Wife"         => (string) ($record['wife_full_name'] ?? '—'),
        "Divorce Date" => (string) ($record['divorce_date'] ?? '—'),
        "Court Reference" => (string) ($record['court_reference'] ?? '—'),
    ],
    default    => [],
};

render('layouts/app_header', ['title' => 'Certificate: ' . $cert['certificate_number']]);
?>
<div class="card" style="max-width: 750px; margin: 0 auto;">
  <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--line); padding-bottom:10px; margin-bottom:15px; gap:10px; flex-wrap:wrap;">
    <div>
      <h2>Certificate <?= e($cert['certificate_number']) ?></h2>
      <span class="badge <?= $cert['payment_status'] === 'paid' ? 'badge-success' : 'badge-warning' ?>">
        <?= e(strtoupper((string) $cert['payment_status'])) ?>
      </span>
      <span class="badge <?= $cert['status'] === 'valid' ? 'badge-success' : 'badge-danger' ?>">
        <?= e(ucfirst((string) $cert['status'])) ?>
      </span>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
      <?php if ($cert['payment_status'] === 'unpaid' && Auth::can('certificate.pay')): ?>
        <a href="pay.php?id=<?= (int) $cert['id'] ?>" class="btn btn-primary">Mark Fee as Paid</a>
      <?php endif; ?>
      <?php if ($printable): ?>
        <a href="print.php?id=<?= (int) $cert['id'] ?>" class="btn btn-primary">Print Certificate</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$printable): ?>
    <div class="alert alert-warning">
      <?php if ($cert['payment_status'] !== 'paid'): ?>
        Printing is blocked until the statutory fee of <?= e(money((float) $cert['fee_amount'])) ?> is recorded as paid.
      <?php else: ?>
        This certificate is revoked and can no longer be printed.
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <h3>Certified Particulars</h3>
  <table style="margin-bottom:1rem;">
    <tr><th style="width:200px;">Record Type</th><td><?= e(RecordTypes::ALL[$type]['label'] ?? $type) ?></td></tr>
    <?php foreach ($summary as $label => $value): ?>
      <tr><th><?= e($label) ?></th><td><?= e($value) ?></td></tr>
    <?php endforeach; ?>
    <tr><th>Registration Number</th><td><b><?= e((string) ($record['registration_number'] ?? '—')) ?></b></td></tr>
    <tr><th>District</th><td><?= e((string) ($record['district_name'] ?? '—')) ?></td></tr>
    <tr><th>Registration Date</th><td><?= e((string) ($record['registration_date'] ?? '—')) ?></td></tr>
  </table>

  <h3>Issuance</h3>
  <table style="margin-bottom:1rem;">
    <tr><th style="width:200px;">Certificate No</th><td><b><?= e($cert['certificate_number']) ?></b></td></tr>
    <tr><th>Issue Date</th><td><?= e($cert['issue_date']) ?></td></tr>
    <tr><th>Issued By</th><td><?= e((string) ($cert['issued_by_name'] ?? '—')) ?></td></tr>
    <tr><th>Signed By</th><td><?= e((string) ($cert['signed_by_name'] ?? '—')) ?> on <?= e((string) $cert['signed_at']) ?></td></tr>
    <tr><th>Fee Amount</th><td><?= e(money((float) $cert['fee_amount'])) ?></td></tr>
    <tr><th>Paid</th>
      <td>
        <?= $cert['payment_status'] === 'paid'
            ? 'Yes — ' . e((string) $cert['paid_at']) . ' by ' . e((string) ($cert['paid_by_name'] ?? '—'))
            : 'Not yet paid' ?>
      </td>
    </tr>
    <tr><th>Verification Token</th><td><code style="font-size:12px;"><?= e($cert['verification_token']) ?></code></td></tr>
  </table>

  <p class="muted">
    Public verification URL:
    <a href="<?= e(url('verify.php')) ?>?token=<?= urlencode((string) $cert['verification_token']) ?>" target="_blank">
      verify.php?token=<?= e(substr((string) $cert['verification_token'], 0, 12)) ?>...
    </a>
    &middot; <a href="view.php?type=<?= e($type) ?>&id=<?= (int) $cert['record_id'] ?>">Open source record</a>
  </p>

  <?php if ($signature): ?>
    <p style="margin-top:14px;">
      <small class="muted">Supervisor digital signature on file:</small><br>
      <img src="<?= e($signature) ?>" alt="Supervisor digital signature" style="max-height:70px;">
    </p>
  <?php endif; ?>

  <?php if (Auth::can('certificate.revoke')): ?>
    <hr style="border:none; border-top:1px solid var(--line); margin:20px 0;">
    <?php if ($cert['status'] === 'valid'): ?>
      <form method="post" onsubmit="var r = prompt('Reason for revoking this certificate (recorded in the audit log):'); if (!r) { return false; } this.reason.value = r; return true;">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="revoke">
        <input type="hidden" name="reason" id="reason" value="">
        <button type="submit" class="btn" style="color:var(--bad); border-color:var(--bad);">Revoke Certificate</button>
      </form>
    <?php else: ?>
      <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="restore">
        <button type="submit" class="btn">Restore Certificate</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php render('layouts/app_footer'); ?>
