<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Auth, Database};
use App\Services\{RecordService, SignatureService};
use App\Support\Age;

Auth::requirePermission('certificate.print');

$id   = (int) ($_GET['id'] ?? 0);
$cert = Database::fetchOne(
    'SELECT c.*, u_sig.full_name AS signed_by_name
     FROM certificates c
     LEFT JOIN users u_sig ON u_sig.id = c.signed_by
     WHERE c.id = ?',
    [$id]
);
if (!$cert) {
    render('errors/error', ['code' => 404, 'message' => 'Certificate not found.']);
    exit;
}

// Payment gate: printing is strictly blocked until the fee is recorded as paid.
if ($cert['payment_status'] !== 'paid') {
    render('errors/error', [
        'code'    => 402,
        'message' => 'Payment required: Certificate printing is strictly blocked until the official fee has been marked as paid.'
    ]);
    exit;
}

// Revoked certificates are never printed.
if ($cert['status'] !== 'valid') {
    render('errors/error', [
        'code'    => 409,
        'message' => 'This certificate has been revoked and can no longer be printed or relied upon.'
    ]);
    exit;
}

$record = RecordService::find((string) $cert['record_type'], (int) $cert['record_id']);
if (!$record) {
    render('errors/error', ['code' => 404, 'message' => 'The civil record behind this certificate is no longer available.']);
    exit;
}

$type      = (string) $cert['record_type'];
$signature = !empty($cert['signature_image']) ? SignatureService::dataUri((string) $cert['signature_image']) : null;

$ageLine = match ($type) {
    'birth'    => (Age::between((string) $record['date_of_birth'], date('Y-m-d')) ?? '—') . ' (as of today)',
    'death'    => (Age::between((string) $record['date_of_birth'], (string) $record['date_of_death']) ?? '—') . ' at the time of death',
    'marriage' => sprintf('Husband %s · Wife %s',
                            Age::between((string) $record['husband_date_of_birth'], (string) $record['marriage_date']) ?? '—',
                            Age::between((string) $record['wife_date_of_birth'], (string) $record['marriage_date']) ?? '—'),
    'divorce'  => sprintf('Husband %s · Wife %s',
                            Age::between((string) $record['husband_date_of_birth'], (string) $record['divorce_date']) ?? '—',
                            Age::between((string) $record['wife_date_of_birth'], (string) $record['divorce_date']) ?? '—'),
    default    => '—',
};

// Legal wording per record type.
$subject = match ($type) {
    'birth'    => sprintf('This is to certify that <b>%s</b>, %s, was born on <b>%s</b> at %s (%s), %s District, Banadir Region, Somalia.',
                            e($record['child_full_name']), e(ucfirst((string) $record['gender'])), e((string) $record['date_of_birth']),
                            e((string) $record['place_of_birth']), e((string) $record['hospital_of_birth']), e((string) ($record['district_name'] ?? ''))),
    'death'    => sprintf('This is to certify that <b>%s</b>, %s, born on %s, died on <b>%s</b> at %s, %s District, Banadir Region, Somalia.',
                            e($record['deceased_full_name']), e(ucfirst((string) $record['gender'])), e((string) $record['date_of_birth']),
                            e((string) $record['date_of_death']), e((string) $record['place_of_death']), e((string) ($record['district_name'] ?? ''))),
    'marriage' => sprintf('This is to certify that <b>%s</b> and <b>%s</b> were lawfully married on <b>%s</b> at %s, %s District, Banadir Region, Somalia, with a registered Mahr of %s.',
                            e($record['husband_full_name']), e($record['wife_full_name']), e((string) $record['marriage_date']),
                            e((string) $record['place_of_marriage']), e((string) ($record['district_name'] ?? '')), e((string) $record['mahr_amount'])),
    'divorce'  => sprintf('This is to certify that the marriage registered under number <b>%s</b> between <b>%s</b> and <b>%s</b> was dissolved on <b>%s</b> in %s District, Banadir Region, Somalia.',
                            e((string) $record['marriage_registration_number']), e($record['husband_full_name']), e($record['wife_full_name']),
                            e((string) $record['divorce_date']), e((string) ($record['district_name'] ?? ''))),
    default    => 'Official civil status record.',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Official Certificate - <?= e($cert['certificate_number']) ?></title>
  <link rel="stylesheet" href="../assets/css/app.css">
  <style>
    @media print {
      body { background: #fff !important; }
      .no-print { display: none !important; }
      .certificate-a4 { box-shadow: none !important; margin: 0 !important; width: 100% !important; padding: 24px !important; }
    }
    .certificate-a4 {
      max-width: 800px;
      margin: 20px auto;
      padding: 40px;
      background: #fff;
      color: #111827;
      border: 3px double #1f2937;
    }
  </style>
</head>
<body>
  <div class="no-print" style="max-width:800px; margin:10px auto; display:flex; justify-content:space-between;">
    <button onclick="window.print()" class="btn btn-primary">Print / Save as PDF</button>
    <a href="preview.php?id=<?= (int) $cert['id'] ?>" class="btn">Back to Preview</a>
  </div>

  <div class="certificate-a4">
    <div style="text-align:center; border-bottom:2px solid #1f2937; padding-bottom:15px; margin-bottom:20px;">
      <h3 style="margin:0; text-transform:uppercase; letter-spacing:1px;">Federal Republic of Somalia</h3>
      <h2 style="margin:5px 0;">Mogadishu Municipal Government</h2>
      <h4 style="margin:0;">Civil Registration Office &mdash; Banadir Region</h4>
      <h1 style="margin:15px 0 5px 0; text-decoration:underline; text-transform:uppercase;">
        Certificate of <?= e(\App\Support\RecordTypes::ALL[$type]['label']) ?>
      </h1>
      <p style="margin:0;">Certificate No: <b><?= e($cert['certificate_number']) ?></b> &nbsp;|&nbsp; Reg No: <b><?= e((string) $record['registration_number']) ?></b></p>
    </div>

    <div style="line-height:1.9; margin-bottom:24px;">
      <p><?= $subject ?></p>
      <p>The record was registered on <b><?= e((string) $record['registration_date']) ?></b> at the Civil Registration Office of <?= e((string) ($record['district_name'] ?? '')) ?> District.</p>
      <p>Age at the time of this event: <b><?= e($ageLine) ?></b></p>
      <p>Statutory fee: <b>$<?= e(number_format((float) $cert['fee_amount'], 2)) ?> USD &mdash; PAID</b></p>
    </div>

    <p style="font-size:12px; color:#4b5563; border-top:1px solid #d1d5db; padding-top:10px;">
      This certificate is issued under the Municipal Civil Registration Regulations of the Banadir Region and can be verified online
      using the token <b><?= e($cert['verification_token']) ?></b>. Any alteration of this document invalidates it.
    </p>

    <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-top:40px; gap:16px;">
      <div style="border-top:1px solid #000; width:200px; text-align:center; padding-top:5px;">
        <small>Registering Officer</small>
      </div>
      <div style="text-align:center; border:2px dashed #999; padding:15px; width:100px;">
        <small>OFFICIAL STAMP</small>
      </div>
      <div style="text-align:center; width:220px;">
        <?php if ($signature): ?>
          <img src="<?= e($signature) ?>"
               alt="Digital signature of the registering supervisor" style="max-width:100%; max-height:70px;">
        <?php endif; ?>
        <div style="border-top:1px solid #000; padding-top:5px; margin-top:4px;">
          <small><?= e((string) ($cert['signed_by_name'] ?? 'Supervisor / Registrar')) ?><br>Signed <?= e((string) $cert['signed_at']) ?></small>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
