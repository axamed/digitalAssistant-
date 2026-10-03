<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Auth, Config, Csrf, Database, Logger};
use App\Services\{RecordService, Settings};
use App\Support\{RecordRules, RecordTypes};

$user = Auth::requireRole('officer', 'supervisor');
$districts = Database::fetchAll('SELECT id, name FROM districts ORDER BY name ASC');
$fee = Settings::fee('marriage');

$errors = [];
$duplicateWarning = null;

if (is_post()) {
    Csrf::check();

    $actionType = (string) ($_POST['action_type'] ?? 'draft');
    $submitNow = ($actionType === 'submit');
    $confirmDup = !empty($_POST['confirm_duplicate']);

    $v = RecordRules::make('marriage', $_POST, $confirmDup);

    // Duplicate check
    $existing = RecordRules::duplicateOf('marriage', $_POST);
    if ($existing && !$confirmDup) {
        $duplicateWarning = "A marriage record for Husband \"{$_POST['husband_full_name']}\" on {$_POST['marriage_date']} already exists in the system (Registration #{$existing['registration_number']}). If you are certain this is not a duplicate, check the confirmation box below to proceed.";
    }

    $errors = $v->errors();
    if (!$v->fails()) {
        try {
            $result = RecordService::createMarriage($_POST, $submitNow);
            $msg = $submitNow
                ? "Marriage record {$result['registration_number']} has been registered and submitted for supervisor review."
                : "Marriage record {$result['registration_number']} has been saved as a draft.";
            flash('success', $msg);
            redirect('records/view.php?type=marriage&id=' . $result['id']);
        } catch (\Throwable $e) {
            Logger::error('Marriage registration failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $errors['system'] = Config::get('app.debug')
                ? 'Registration failed: ' . $e->getMessage()
                : 'Something went wrong while processing your request. Please try again or contact the system administrator.';
        }
    }
}

render('layouts/app_header', ['title' => 'Register Marriage']);
?>

<div class="breadcrumb">
  <a href="<?= e(url(Auth::homePath())) ?>">Dashboard</a>
  <span class="sep">&rsaquo;</span>
  <span class="current">Register Marriage</span>
</div>

<div class="page-section-title">
  <div>
    <h2>Register Citizen Marriage</h2>
    <p class="muted" style="margin:0;">
      Civil Registration Office &middot; Mogadishu Municipal Government, Banadir Region
    </p>
  </div>
  <div>
    <span class="badge" style="font-size:13px; padding:6px 14px; background:var(--brass-soft); color:var(--brass);">
      Statutory Fee: $<?= e(number_format($fee, 2)) ?> USD
    </span>
  </div>
</div>

<?php if (!empty($errors['system'])): ?>
  <div class="alert alert-danger"><?= e($errors['system']) ?></div>
<?php endif; ?>

<?php if ($duplicateWarning): ?>
  <div class="alert alert-warning">
    <strong>âš ï¸ Duplicate Warning:</strong> <?= e($duplicateWarning) ?>
  </div>
<?php endif; ?>

<form method="post" novalidate autocomplete="off">
  <?= Csrf::field() ?>

  <!-- Section 1: Husband Particulars (Plain Husband label) -->
  <div class="card" style="margin-bottom:24px;">
    <h3 style="margin-bottom:16px;">1. Husband Information</h3>

    <div class="form-row">
      <div class="form-group">
        <label for="husband_full_name">Husband's Full Name <span class="required-star">*</span></label>
        <input id="husband_full_name" name="husband_full_name" type="text" required value="<?= old('husband_full_name') ?>" placeholder="e.g. Abdirahman Jama Farah">
        <?php if (!empty($errors['husband_full_name'])): ?>
          <span class="error"><?= e($errors['husband_full_name']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="husband_date_of_birth">Husband's Date of Birth <span class="required-star">*</span></label>
        <input id="husband_date_of_birth" name="husband_date_of_birth" type="date" required max="<?= date('Y-m-d') ?>" value="<?= old('husband_date_of_birth') ?>" data-age-target="#husband-age-display" data-age-asof="marriage_date">
        <span class="age-display" id="husband-age-display"></span>
        <?php if (!empty($errors['husband_date_of_birth'])): ?>
          <span class="error"><?= e($errors['husband_date_of_birth']) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div class="form-row-3">
      <div class="form-group">
        <label for="husband_phone">Husband's Phone Number <span class="required-star">*</span></label>
        <input id="husband_phone" name="husband_phone" type="tel" required value="<?= old('husband_phone') ?>" placeholder="e.g. +252 61 511 7788">
        <?php if (!empty($errors['husband_phone'])): ?>
          <span class="error"><?= e($errors['husband_phone']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="husband_nationality">Husband's Nationality</label>
        <input id="husband_nationality" name="husband_nationality" type="text" value="<?= old('husband_nationality', 'Somali') ?>">
      </div>

      <div class="form-group">
        <label for="husband_reference">Husband's National ID / Reference</label>
        <input id="husband_reference" name="husband_reference" type="text" value="<?= old('husband_reference') ?>" placeholder="e.g. SOM-NID-771122">
      </div>
    </div>

    <div class="form-group">
      <label for="husband_address">Husband's Residential Address</label>
      <input id="husband_address" name="husband_address" type="text" value="<?= old('husband_address') ?>" placeholder="e.g. Hodan District, Mogadishu">
    </div>
  </div>

  <!-- Section 2: Wife Particulars (Plain Wife label) -->
  <div class="card" style="margin-bottom:24px;">
    <h3 style="margin-bottom:16px;">2. Wife Information</h3>

    <div class="form-row">
      <div class="form-group">
        <label for="wife_full_name">Wife's Full Name <span class="required-star">*</span></label>
        <input id="wife_full_name" name="wife_full_name" type="text" required value="<?= old('wife_full_name') ?>" placeholder="e.g. Fadumo Hassan Warsame">
        <?php if (!empty($errors['wife_full_name'])): ?>
          <span class="error"><?= e($errors['wife_full_name']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="wife_date_of_birth">Wife's Date of Birth <span class="required-star">*</span></label>
        <input id="wife_date_of_birth" name="wife_date_of_birth" type="date" required max="<?= date('Y-m-d') ?>" value="<?= old('wife_date_of_birth') ?>" data-age-target="#wife-age-display" data-age-asof="marriage_date">
        <span class="age-display" id="wife-age-display"></span>
        <?php if (!empty($errors['wife_date_of_birth'])): ?>
          <span class="error"><?= e($errors['wife_date_of_birth']) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div class="form-row-3">
      <div class="form-group">
        <label for="wife_phone">Wife's Phone Number <span class="required-star">*</span></label>
        <input id="wife_phone" name="wife_phone" type="tel" required value="<?= old('wife_phone') ?>" placeholder="e.g. +252 61 522 8899">
        <?php if (!empty($errors['wife_phone'])): ?>
          <span class="error"><?= e($errors['wife_phone']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="wife_nationality">Wife's Nationality</label>
        <input id="wife_nationality" name="wife_nationality" type="text" value="<?= old('wife_nationality', 'Somali') ?>">
      </div>

      <div class="form-group">
        <label for="wife_reference">Wife's National ID / Reference</label>
        <input id="wife_reference" name="wife_reference" type="text" value="<?= old('wife_reference') ?>" placeholder="e.g. SOM-NID-882233">
      </div>
    </div>

    <div class="form-group">
      <label for="wife_address">Wife's Residential Address</label>
      <input id="wife_address" name="wife_address" type="text" value="<?= old('wife_address') ?>" placeholder="e.g. Waberi District, Mogadishu">
    </div>
  </div>

  <!-- Section 3: Marriage Event & Mahr Details -->
  <div class="card" style="margin-bottom:24px;">
    <h3 style="margin-bottom:16px;">3. Marriage Event & Mahr Agreement</h3>

    <div class="form-row-3">
      <div class="form-group">
        <label for="marriage_date">Marriage Date <span class="required-star">*</span></label>
        <input id="marriage_date" name="marriage_date" type="date" required max="<?= date('Y-m-d') ?>" value="<?= old('marriage_date', date('Y-m-d')) ?>">
        <?php if (!empty($errors['marriage_date'])): ?>
          <span class="error"><?= e($errors['marriage_date']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="district_id">District <span class="required-star">*</span></label>
        <select id="district_id" name="district_id" required>
          <option value="">-- Select District (<?= count($districts) ?> Banadir Districts) --</option>
          <?php foreach ($districts as $d): ?>
            <option value="<?= e((string) $d['id']) ?>" <?= old('district_id') == $d['id'] ? 'selected' : '' ?>>
              <?= e($d['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (!empty($errors['district_id'])): ?>
          <span class="error"><?= e($errors['district_id']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="mahr_amount">Mahr (Dowry Amount) <span class="required-star">*</span></label>
        <input id="mahr_amount" name="mahr_amount" type="text" required value="<?= old('mahr_amount') ?>" placeholder="e.g. 500 USD or 20 Camels">
        <span class="field-hint">Agreed Islamic matrimonial dowry sum or value.</span>
        <?php if (!empty($errors['mahr_amount'])): ?>
          <span class="error"><?= e($errors['mahr_amount']) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div class="form-group">
      <label for="place_of_marriage">Place of Marriage <span class="required-star">*</span></label>
      <input id="place_of_marriage" name="place_of_marriage" type="text" required value="<?= old('place_of_marriage', 'Mogadishu') ?>" placeholder="e.g. Hamar Weyne Mosque, Mogadishu">
      <?php if (!empty($errors['place_of_marriage'])): ?>
        <span class="error"><?= e($errors['place_of_marriage']) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Section 4: Two Mandatory Witnesses -->
  <div class="card" style="margin-bottom:24px;">
    <h3 style="margin-bottom:16px;">4. Legal Witnesses (Two Required)</h3>

    <div class="form-row">
      <div class="form-group">
        <label for="witness1_full_name">First Witness Full Name <span class="required-star">*</span></label>
        <input id="witness1_full_name" name="witness1_full_name" type="text" required value="<?= old('witness1_full_name') ?>" placeholder="e.g. Mahad Nur Ali">
        <?php if (!empty($errors['witness1_full_name'])): ?>
          <span class="error"><?= e($errors['witness1_full_name']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="witness1_phone">First Witness Phone Number <span class="required-star">*</span></label>
        <input id="witness1_phone" name="witness1_phone" type="tel" required value="<?= old('witness1_phone') ?>" placeholder="e.g. +252 61 544 5566">
        <?php if (!empty($errors['witness1_phone'])): ?>
          <span class="error"><?= e($errors['witness1_phone']) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label for="witness2_full_name">Second Witness Full Name <span class="required-star">*</span></label>
        <input id="witness2_full_name" name="witness2_full_name" type="text" required value="<?= old('witness2_full_name') ?>" placeholder="e.g. Mohamed Omar Barre">
        <?php if (!empty($errors['witness2_full_name'])): ?>
          <span class="error"><?= e($errors['witness2_full_name']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="witness2_phone">Second Witness Phone Number <span class="required-star">*</span></label>
        <input id="witness2_phone" name="witness2_phone" type="tel" required value="<?= old('witness2_phone') ?>" placeholder="e.g. +252 61 566 7788">
        <?php if (!empty($errors['witness2_phone'])): ?>
          <span class="error"><?= e($errors['witness2_phone']) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Section 5: Registration Administration -->
  <div class="card" style="margin-bottom:28px;">
    <h3 style="margin-bottom:16px;">5. Registration Administration</h3>

    <div class="form-row">
      <div class="form-group">
        <label for="registration_date">Registration Date <span class="required-star">*</span></label>
        <input id="registration_date" name="registration_date" type="date" required max="<?= date('Y-m-d') ?>" value="<?= old('registration_date', date('Y-m-d')) ?>">
      </div>

      <div class="form-group">
        <label for="officer_display">Registering Officer</label>
        <input id="officer_display" type="text" disabled value="<?= e($user['name']) ?> (<?= e($user['role_label']) ?>)">
      </div>
    </div>

    <div class="form-group">
      <label for="notes">Administrative Notes</label>
      <textarea id="notes" name="notes" rows="3" placeholder="Sheekh / marriage officiant reference, marriage contract registration reference..."><?= old('notes') ?></textarea>
    </div>

    <?php if ($duplicateWarning): ?>
      <div style="margin-top:14px; padding:12px; background:var(--warn-soft); border-radius:var(--radius-sm);">
        <label style="display:flex; align-items:center; gap:8px; font-weight:600; cursor:pointer;">
          <input type="checkbox" name="confirm_duplicate" value="1" style="width:auto;">
          I confirm that this is a distinct, genuine registration and not a duplicate record.
        </label>
      </div>
    <?php endif; ?>
  </div>

  <!-- Action Submission Bar -->
  <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
    <button type="submit" name="action_type" value="submit" class="btn btn-primary" style="padding:11px 24px;">
      Submit for Supervisor Review
    </button>
    <button type="submit" name="action_type" value="draft" class="btn" style="padding:11px 20px;">
      Save as Draft
    </button>
    <a href="<?= e(url(Auth::homePath())) ?>" class="btn">Cancel</a>
  </div>
</form>

<?php render('layouts/app_footer'); ?>
