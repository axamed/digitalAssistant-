<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Audit, Auth, Csrf};
use App\Services\Settings;
use App\Support\Validator;

Auth::requirePermission('settings.manage');

$errors = [];

if (is_post()) {
    Csrf::check();

    $v = Validator::make($_POST);
    foreach (['birth', 'marriage', 'death', 'divorce'] as $type) {
        $v->required('fee_' . $type, ucfirst($type) . ' certificate fee')
          ->numeric('fee_' . $type, ucfirst($type) . ' certificate fee')
          ->min('fee_' . $type, ucfirst($type) . ' certificate fee', 0);
    }
    $v->required('office_name', 'Office name')->maxLength('office_name', 'Office name', 150)
      ->required('office_address', 'Office address')->maxLength('office_address', 'Office address', 255)
      ->required('office_phone', 'Office phone')->maxLength('office_phone', 'Office phone', 40)
      ->required('office_email', 'Office email')
      ->email('office_email', 'Office email')
      ->required('office_hours', 'Office hours')->maxLength('office_hours', 'Office hours', 150);

    // The WhatsApp number is optional, but when given it must be digits only and long enough
    // to be a real subscriber number, since wa.me links break on anything else.
    $whatsapp = trim((string) ($_POST['office_whatsapp'] ?? ''));
    if ($whatsapp !== '' && !preg_match('/^[0-9]{9,15}$/', $whatsapp)) {
        $v->custom('office_whatsapp', 'WhatsApp number must be 9 to 15 digits, country code first, with no spaces or symbols.');
    }

    if ($v->fails()) {
        $errors = $v->errors();
    } else {
        foreach (['birth', 'marriage', 'death', 'divorce'] as $type) {
            Settings::set('fee_' . $type, number_format((float) $_POST['fee_' . $type], 2, '.', ''));
        }
        foreach (['office_name', 'office_address', 'office_phone', 'office_email', 'office_hours'] as $key) {
            Settings::set($key, trim((string) $_POST[$key]));
        }
        Settings::set('office_whatsapp', $whatsapp);

        Audit::log('settings_update', 'settings', 'Updated certificate fees and office details');
        flash('success', 'Settings saved successfully.');
        redirect('admin/settings.php');
    }
}

$fees = [
    'birth'    => Settings::get('fee_birth', '20.00'),
    'marriage' => Settings::get('fee_marriage', '25.00'),
    'death'    => Settings::get('fee_death', '15.00'),
    'divorce'  => Settings::get('fee_divorce', '30.00'),
];
$office = [
    'name'    => Settings::get('office_name', 'Civil Registration Office, Mogadishu Municipal Government'),
    'address' => Settings::get('office_address'),
    'phone'   => Settings::get('office_phone'),
    'email'   => Settings::get('office_email'),
    'hours'   => Settings::get('office_hours'),
    'whatsapp' => Settings::get('office_whatsapp', ''),
];

$err = static fn(string $key): string => isset($errors[$key]) ? '<span class="error">' . e($errors[$key]) . '</span>' : '';

render('layouts/app_header', ['title' => 'Settings & Certificate Fees']);
?>
<div class="breadcrumb">
  <a href="<?= e(url('admin/dashboard.php')) ?>">Dashboard</a>
  <span class="sep">&rsaquo;</span>
  <span class="current">Settings</span>
</div>

<div class="page-section-title">
  <div>
    <h2>Office Settings &amp; Certificate Fees</h2>
    <p class="muted" style="margin:0;">Fee rates applied to newly issued certificates, and the office contact details printed on the public site.</p>
  </div>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start;">
  <form method="post">
    <?= Csrf::field() ?>

    <div class="card">
      <h3 style="margin-top:0;">Certificate Fees (USD)</h3>
      <p class="muted" style="font-size:13px;">Applied to certificates issued from now on. Already issued certificates keep their recorded fee.</p>

      <?php foreach ($fees as $type => $fee): ?>
      <div class="form-group">
        <label for="fee_<?= e($type) ?>"><?= e(ucfirst($type)) ?> certificate fee <span class="required-star">*</span></label>
        <input type="number" step="0.01" min="0" id="fee_<?= e($type) ?>" name="fee_<?= e($type) ?>"
               value="<?= e($_POST['fee_' . $type] ?? $fee) ?>" required>
        <?= $err('fee_' . $type) ?>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="card" style="margin-top:20px;">
      <h3 style="margin-top:0;">Office Details</h3>

      <div class="form-group">
        <label for="office_name">Office name <span class="required-star">*</span></label>
        <input type="text" id="office_name" name="office_name" value="<?= e($_POST['office_name'] ?? $office['name']) ?>" required>
        <?= $err('office_name') ?>
      </div>
      <div class="form-group">
        <label for="office_address">Address <span class="required-star">*</span></label>
        <input type="text" id="office_address" name="office_address" value="<?= e($_POST['office_address'] ?? $office['address']) ?>" required>
        <?= $err('office_address') ?>
      </div>
      <div class="form-group">
        <label for="office_phone">Phone <span class="required-star">*</span></label>
        <input type="text" id="office_phone" name="office_phone" value="<?= e($_POST['office_phone'] ?? $office['phone']) ?>" required>
        <?= $err('office_phone') ?>
      </div>
      <div class="form-group">
        <label for="office_email">Email <span class="required-star">*</span></label>
        <input type="email" id="office_email" name="office_email" value="<?= e($_POST['office_email'] ?? $office['email']) ?>" required>
        <?= $err('office_email') ?>
      </div>
      <div class="form-group">
        <label for="office_hours">Opening hours <span class="required-star">*</span></label>
        <input type="text" id="office_hours" name="office_hours" value="<?= e($_POST['office_hours'] ?? $office['hours']) ?>" required>
        <?= $err('office_hours') ?>
      </div>
      <div class="form-group">
        <label for="office_whatsapp">WhatsApp number</label>
        <input type="text" id="office_whatsapp" name="office_whatsapp" inputmode="numeric"
               value="<?= e($_POST['office_whatsapp'] ?? $office['whatsapp']) ?>"
               placeholder="252615212845">
        <span class="field-hint">Country code first, digits only, no spaces or symbols. Leave empty to hide the QR code on the contact page.</span>
        <?= $err('office_whatsapp') ?>
      </div>

      <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
  </form>

  <div class="card">
    <h3 style="margin-top:0;">Fee Preview</h3>
    <table>
      <thead>
        <tr><th>Certificate</th><th style="text-align:right;">Fee</th></tr>
      </thead>
      <tbody>
        <?php foreach ($fees as $type => $fee): ?>
        <tr>
          <td><?= e(ucfirst($type)) ?></td>
          <td style="text-align:right;"><?= e(money((float) $fee)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted" style="font-size:13px;">
      Printing an official certificate stays blocked until the fee for that specific certificate is recorded as paid.
    </p>
  </div>
</div>
<?php render('layouts/app_footer'); ?>
