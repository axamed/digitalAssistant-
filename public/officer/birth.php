<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Auth, Config, Csrf, Database, Logger};
use App\Services\{RecordService, Settings};
use App\Support\{RecordRules, RecordTypes};

$user = Auth::requireRole('officer', 'supervisor');
$districts = Database::fetchAll('SELECT id, name FROM districts ORDER BY name ASC');
$fee = Settings::fee('birth');

$errors = [];
$duplicateWarning = null;

if (is_post()) {
    Csrf::check();

    $actionType = (string) ($_POST['action_type'] ?? 'draft');
    $submitNow = ($actionType === 'submit');
    $confirmDup = !empty($_POST['confirm_duplicate']);

    $v = RecordRules::make('birth', $_POST, $confirmDup);

    // Duplicate check
    $existing = RecordRules::duplicateOf('birth', $_POST);
    if ($existing && !$confirmDup) {
        $duplicateWarning = "A birth record for \"{$_POST['child_full_name']}\" born on {$_POST['date_of_birth']} already exists in the system (Registration #{$existing['registration_number']}). If you are certain this is not a duplicate, check the confirmation box below to proceed.";
    }

    // Photo file validation if uploaded
    $photoFile = (!empty($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) ? $_FILES['photo'] : null;
    if ($photoFile && $photoFile['error'] === UPLOAD_ERR_OK) {
        if ($photoFile['size'] > 2 * 1024 * 1024) {
            $v->custom('photo', "Child's photo exceeds the 2 MB maximum file limit.");
        }
    }

    $errors = $v->errors();
    if (!$v->fails()) {
        try {
            $result = RecordService::createBirth($_POST, $photoFile, $submitNow);
            $msg = $submitNow
                ? "Birth record {$result['registration_number']} has been registered and submitted for supervisor review."
                : "Birth record {$result['registration_number']} has been saved as a draft.";
            flash('success', $msg);
            redirect('records/view.php?type=birth&id=' . $result['id']);
        } catch (\Throwable $e) {
            // The generic message alone left the real fault invisible: log it, so the next
            // occurrence is diagnosable from storage/logs instead of guesswork.
            Logger::error('Birth registration failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $errors['system'] = Config::get('app.debug')
                ? 'Registration failed: ' . $e->getMessage()
                : 'Something went wrong while processing your request. Please try again or contact the system administrator.';
        }
    }
}

render('layouts/app_header', ['title' => 'Register Birth']);
?>

<div class="breadcrumb">
  <a href="<?= e(url(Auth::homePath())) ?>">Dashboard</a>
  <span class="sep">&rsaquo;</span>
  <span class="current">Register Birth</span>
</div>

<div class="page-section-title">
  <div>
    <h2>Register Citizen Birth</h2>
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

<form method="post" enctype="multipart/form-data" novalidate autocomplete="off">
  <?= Csrf::field() ?>

  <!-- Section 1: Child Information -->
  <div class="card" style="margin-bottom:24px;">
    <h3 style="margin-bottom:16px;">1. Child Information</h3>

    <div class="form-row">
      <div class="form-group">
        <label for="child_full_name">Child's Full Name <span class="required-star">*</span></label>
        <input id="child_full_name" name="child_full_name" type="text" required value="<?= old('child_full_name') ?>" placeholder="e.g. Marian Abdirashid Jama">
        <?php if (!empty($errors['child_full_name'])): ?>
          <span class="error"><?= e($errors['child_full_name']) ?></span>
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

    <div class="form-row-3">
      <div class="form-group">
        <label for="date_of_birth">Date of Birth <span class="required-star">*</span></label>
        <input id="date_of_birth" name="date_of_birth" type="date" required max="<?= date('Y-m-d') ?>" value="<?= old('date_of_birth', date('Y-m-d')) ?>" data-age-target="#birth-age-display">
        <span class="age-display" id="birth-age-display"></span>
        <?php if (!empty($errors['date_of_birth'])): ?>
          <span class="error"><?= e($errors['date_of_birth']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="time_of_birth">Time of Birth</label>
        <input id="time_of_birth" name="time_of_birth" type="time" value="<?= old('time_of_birth') ?>">
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
    </div>

    <div class="form-row">
      <div class="form-group">
        <label for="place_of_birth">Place of Birth <span class="required-star">*</span></label>
        <input id="place_of_birth" name="place_of_birth" type="text" required value="<?= old('place_of_birth', 'Mogadishu') ?>" placeholder="e.g. Mogadishu">
        <?php if (!empty($errors['place_of_birth'])): ?>
          <span class="error"><?= e($errors['place_of_birth']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="hospital_of_birth">Hospital of Birth <span class="required-star">*</span></label>
        <input id="hospital_of_birth" name="hospital_of_birth" type="text" required value="<?= old('hospital_of_birth') ?>" placeholder="Free-text entry (e.g. Banadir Maternity Hospital, Madina Hospital)">
        <span class="field-hint">Free-text hospital or clinic name as indicated on medical birth notification.</span>
        <?php if (!empty($errors['hospital_of_birth'])): ?>
          <span class="error"><?= e($errors['hospital_of_birth']) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Child Photo Upload (Birth Only) -->
    <div class="form-group" style="margin-top:8px;">
      <label for="photo">Child's Photograph (Optional &middot; Birth Only)</label>
      <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,application/pdf">
      <span class="field-hint">Permitted formats: JPG, PNG, or PDF. Maximum size: 2 MB. Stored securely in private registry storage.</span>
      <?php if (!empty($errors['photo'])): ?>
        <span class="error"><?= e($errors['photo']) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Section 2: Parents Information -->
  <div class="card" style="margin-bottom:24px;">
    <h3 style="margin-bottom:16px;">2. Parents Information</h3>

    <h4 style="font-size:1rem; color:var(--brass-dk); margin-bottom:12px;">Father's Particulars</h4>
    <div class="form-row-3">
      <div class="form-group">
        <label for="father_full_name">Father's Full Name <span class="required-star">*</span></label>
        <input id="father_full_name" name="father_full_name" type="text" required value="<?= old('father_full_name') ?>" placeholder="e.g. Abdirashid Jama Warsame">
        <?php if (!empty($errors['father_full_name'])): ?>
          <span class="error"><?= e($errors['father_full_name']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="father_nationality">Father's Nationality</label>
        <input id="father_nationality" name="father_nationality" type="text" value="<?= old('father_nationality', 'Somali') ?>">
      </div>

      <div class="form-group">
        <label for="father_reference">Father's ID / Reference Number</label>
        <input id="father_reference" name="father_reference" type="text" value="<?= old('father_reference') ?>" placeholder="e.g. SOM-NID-881234">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label for="father_phone">Father's Phone Number</label>
        <input id="father_phone" name="father_phone" type="tel" value="<?= old('father_phone') ?>" placeholder="e.g. +252 61 511 2233">
        <?php if (!empty($errors['father_phone'])): ?>
          <span class="error"><?= e($errors['father_phone']) ?></span>
        <?php endif; ?>
      </div>
      <div></div>
    </div>

    <hr style="border:none; border-top:1px solid var(--line); margin:18px 0;">

    <h4 style="font-size:1rem; color:var(--brass-dk); margin-bottom:12px;">Mother's Particulars</h4>
    <div class="form-row-3">
      <div class="form-group">
        <label for="mother_full_name">Mother's Full Name <span class="required-star">*</span></label>
        <input id="mother_full_name" name="mother_full_name" type="text" required value="<?= old('mother_full_name') ?>" placeholder="e.g. Halima Hassan Nur">
        <?php if (!empty($errors['mother_full_name'])): ?>
          <span class="error"><?= e($errors['mother_full_name']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="mother_nationality">Mother's Nationality</label>
        <input id="mother_nationality" name="mother_nationality" type="text" value="<?= old('mother_nationality', 'Somali') ?>">
      </div>

      <div class="form-group">
        <label for="mother_reference">Mother's ID / Reference Number</label>
        <input id="mother_reference" name="mother_reference" type="text" value="<?= old('mother_reference') ?>" placeholder="e.g. SOM-NID-994321">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label for="mother_phone">Mother's Phone Number</label>
        <input id="mother_phone" name="mother_phone" type="tel" value="<?= old('mother_phone') ?>" placeholder="e.g. +252 61 522 3344">
        <?php if (!empty($errors['mother_phone'])): ?>
          <span class="error"><?= e($errors['mother_phone']) ?></span>
        <?php endif; ?>
      </div>
      <div></div>
    </div>
  </div>

  <!-- Section 3: Registration Particulars & Notes -->
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
      <textarea id="notes" name="notes" rows="3" placeholder="Additional remarks, hospital certificate verification reference, or supporting details..."><?= old('notes') ?></textarea>
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
