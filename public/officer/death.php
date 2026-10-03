<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Auth, Config, Csrf, Database, Logger};
use App\Services\{RecordService, Settings};
use App\Support\{RecordRules, RecordTypes};

$user = Auth::requireRole('officer', 'supervisor');
$districts = Database::fetchAll('SELECT id, name FROM districts ORDER BY name ASC');
$fee = Settings::fee('death');

$errors = [];
$duplicateWarning = null;

if (is_post()) {
    Csrf::check();

    $actionType = (string) ($_POST['action_type'] ?? 'draft');
    $submitNow = ($actionType === 'submit');
    $confirmDup = !empty($_POST['confirm_duplicate']);

    $v = RecordRules::make('death', $_POST, $confirmDup);

    // Duplicate check
    $existing = RecordRules::duplicateOf('death', $_POST);
    if ($existing && !$confirmDup) {
        $duplicateWarning = "A death record for \"{$_POST['deceased_full_name']}\" recorded on {$_POST['date_of_death']} already exists in the system (Registration #{$existing['registration_number']}). If you are certain this is not a duplicate, check the confirmation box below to proceed.";
    }

    $errors = $v->errors();
    if (!$v->fails()) {
        try {
            $result = RecordService::createDeath($_POST, $submitNow);
            $msg = $submitNow
                ? "Death record {$result['registration_number']} has been registered and submitted for supervisor review."
                : "Death record {$result['registration_number']} has been saved as a draft.";
            flash('success', $msg);
            redirect('records/view.php?type=death&id=' . $result['id']);
        } catch (\Throwable $e) {
            Logger::error('Death registration failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $errors['system'] = Config::get('app.debug')
                ? 'Registration failed: ' . $e->getMessage()
                : 'Something went wrong while processing your request. Please try again or contact the system administrator.';
        }
    }
}

render('layouts/app_header', ['title' => 'Register Death']);
?>

<div class="breadcrumb">
  <a href="<?= e(url(Auth::homePath())) ?>">Dashboard</a>
  <span class="sep">&rsaquo;</span>
  <span class="current">Register Death</span>
</div>

<div class="page-section-title">
  <div>
    <h2>Register Citizen Death</h2>
    <p class="muted" style="margin:0;">
      Civil Registration Office &middot; Mogadishu Municipal Government, Banadir Region
    </p>
  </div>
  <div>
    <span class="badge" style="font-size:13px; padding:6px 14px; background:var(--line-soft);">
      Statutory Fee: <?= $fee > 0 ? '$' . number_format($fee, 2) . ' USD' : 'Set by Administrator / Standard' ?>
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

  <!-- Section 1: Deceased Person Particulars -->
  <div class="card" style="margin-bottom:24px;">
    <h3 style="margin-bottom:16px;">1. Deceased Person Information</h3>

    <div class="form-row">
      <div class="form-group">
        <label for="deceased_full_name">Deceased Person's Full Name <span class="required-star">*</span></label>
        <input id="deceased_full_name" name="deceased_full_name" type="text" required value="<?= old('deceased_full_name') ?>" placeholder="e.g. Yusuf Ali Warsame">
        <?php if (!empty($errors['deceased_full_name'])): ?>
          <span class="error"><?= e($errors['deceased_full_name']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="gender">Gender <span class="required-star">*</span></label>
        <select id="gender" name="gender" required>
          <option value="">-- Select Gender --</option>
          <option value="female" <?= old('gender') === 'female' ? 'selected' : '' ?>>Female</option>
          <option value="male" <?= old('gender') === 'male' ? 'selected' : '' ?>>Male</option>
        </select>
        <?php if (!empty($errors['gender'])): ?>
          <span class="error"><?= e($errors['gender']) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label for="date_of_birth">Date of Birth <span class="required-star">*</span></label>
        <input id="date_of_birth" name="date_of_birth" type="date" required max="<?= date('Y-m-d') ?>" value="<?= old('date_of_birth') ?>" data-age-target="#death-age-display" data-age-asof="date_of_death">
        <?php if (!empty($errors['date_of_birth'])): ?>
          <span class="error"><?= e($errors['date_of_birth']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="date_of_death">Date of Death <span class="required-star">*</span></label>
        <input id="date_of_death" name="date_of_death" type="date" required max="<?= date('Y-m-d') ?>" value="<?= old('date_of_death', date('Y-m-d')) ?>">
        <span class="age-display" id="death-age-display"></span>
        <?php if (!empty($errors['date_of_death'])): ?>
          <span class="error"><?= e($errors['date_of_death']) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div class="form-row-3">
      <div class="form-group">
        <label for="time_of_death">Time of Death</label>
        <input id="time_of_death" name="time_of_death" type="time" value="<?= old('time_of_death') ?>">
      </div>

      <div class="form-group">
        <label for="place_of_death">Place of Death <span class="required-star">*</span></label>
        <input id="place_of_death" name="place_of_death" type="text" required value="<?= old('place_of_death', 'Mogadishu') ?>" placeholder="e.g. Madina Hospital, Residence">
        <?php if (!empty($errors['place_of_death'])): ?>
          <span class="error"><?= e($errors['place_of_death']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="district_id">District of Death <span class="required-star">*</span></label>
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
    </div>

    <div class="form-row">
      <div class="form-group">
        <label for="cause_of_death">Cause of Death (Where appropriate)</label>
        <input id="cause_of_death" name="cause_of_death" type="text" value="<?= old('cause_of_death') ?>" placeholder="e.g. Cardiovascular failure, Natural causes">
      </div>

      <div class="form-group">
        <label for="hospital_document_reference">Hospital Document Reference (Optional)</label>
        <input id="hospital_document_reference" name="hospital_document_reference" type="text" value="<?= old('hospital_document_reference') ?>" placeholder="e.g. HOSP-MED-2026-991">
        <span class="field-hint">Medical notification of death number or hospital record reference.</span>
      </div>
    </div>
  </div>

  <!-- Section 2: Guarantor & Informant (Required Legal Responsible Parties) -->
  <div class="card" style="margin-bottom:24px;">
    <h3 style="margin-bottom:16px;">2. Guarantor & Informant Particulars</h3>

    <div class="form-row">
      <div class="form-group">
        <label for="guarantor_full_name">Guarantor's Full Name (Responsible Person) <span class="required-star">*</span></label>
        <input id="guarantor_full_name" name="guarantor_full_name" type="text" required value="<?= old('guarantor_full_name') ?>" placeholder="e.g. Abdullahi Yusuf Ali (Family Elder / Guarantor)">
        <span class="field-hint">Mandatory responsible person legally vouching for the accuracy of this death report.</span>
        <?php if (!empty($errors['guarantor_full_name'])): ?>
          <span class="error"><?= e($errors['guarantor_full_name']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="informant_name">Informant's Full Name <span class="required-star">*</span></label>
        <input id="informant_name" name="informant_name" type="text" required value="<?= old('informant_name') ?>" placeholder="e.g. Maryan Yusuf Ali">
        <?php if (!empty($errors['informant_name'])): ?>
          <span class="error"><?= e($errors['informant_name']) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label for="informant_relationship">Informant's Relationship to Deceased</label>
        <input id="informant_relationship" name="informant_relationship" type="text" value="<?= old('informant_relationship') ?>" placeholder="e.g. Son, Daughter, Brother, Spouse">
      </div>

      <div class="form-group">
        <label for="informant_phone">Informant's Phone Number</label>
        <input id="informant_phone" name="informant_phone" type="tel" value="<?= old('informant_phone') ?>" placeholder="e.g. +252 61 577 8899">
        <?php if (!empty($errors['informant_phone'])): ?>
          <span class="error"><?= e($errors['informant_phone']) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Section 3: Registration Administration -->
  <div class="card" style="margin-bottom:28px;">
    <h3 style="margin-bottom:16px;">3. Registration Administration</h3>

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
      <textarea id="notes" name="notes" rows="3" placeholder="Burial site reference, cemetery permit reference, or special remarks..."><?= old('notes') ?></textarea>
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
