<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Auth, Config, Csrf, Database, Logger};
use App\Services\{RecordService, Settings};
use App\Support\{RecordRules, RecordTypes};

$user = Auth::requireRole('officer', 'supervisor');
$districts = Database::fetchAll('SELECT id, name FROM districts ORDER BY name ASC');
$fee = Settings::fee('divorce');

$errors = [];
$duplicateWarning = null;

if (is_post()) {
    Csrf::check();

    $actionType = (string) ($_POST['action_type'] ?? 'draft');
    $submitNow = ($actionType === 'submit');
    $confirmDup = !empty($_POST['confirm_duplicate']);

    $v = RecordRules::make('divorce', $_POST, $confirmDup);

    // Duplicate check
    $mRegNum = trim((string) ($_POST['marriage_registration_number'] ?? ''));
    $existing = RecordRules::duplicateOf('divorce', $_POST);
    if ($existing && !$confirmDup) {
        $duplicateWarning = "A divorce record referencing marriage {$mRegNum} already exists in the system (Registration #{$existing['registration_number']}). If you are certain this is not a duplicate, check the confirmation box below to proceed.";
    }

    $errors = $v->errors();
    if (!$v->fails()) {
        try {
            $result = RecordService::createDivorce($_POST, $submitNow);
            $msg = $submitNow
                ? "Divorce record {$result['registration_number']} has been registered and submitted for supervisor review."
                : "Divorce record {$result['registration_number']} has been saved as a draft.";
            flash('success', $msg);
            redirect('records/view.php?type=divorce&id=' . $result['id']);
        } catch (\Throwable $e) {
            Logger::error('Divorce registration failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $errors['system'] = Config::get('app.debug')
                ? 'Registration failed: ' . $e->getMessage()
                : 'Something went wrong while processing your request. Please try again or contact the system administrator.';
        }
    }
}

render('layouts/app_header', ['title' => 'Register Divorce']);
?>

<div class="breadcrumb">
  <a href="<?= e(url(Auth::homePath())) ?>">Dashboard</a>
  <span class="sep">&rsaquo;</span>
  <span class="current">Register Divorce</span>
</div>

<div class="page-section-title">
  <div>
    <h2>Register Matrimonial Dissolution (Divorce)</h2>
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

  <!-- Section 1: Original Marriage Particulars -->
  <div class="card" style="margin-bottom:24px;">
    <h3 style="margin-bottom:16px;">1. Original Marriage Reference</h3>

    <div class="form-row">
      <div class="form-group">
        <label for="marriage_registration_number">Original Marriage Registration Number <span class="required-star">*</span></label>
        <input id="marriage_registration_number" name="marriage_registration_number" type="text" required value="<?= old('marriage_registration_number') ?>" placeholder="e.g. MR-2024-0045">
        <span class="field-hint">Registration number of the marriage being legally dissolved.</span>
        <?php if (!empty($errors['marriage_registration_number'])): ?>
          <span class="error"><?= e($errors['marriage_registration_number']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="court_reference">Court Decree / Reference Number (Optional)</label>
        <input id="court_reference" name="court_reference" type="text" value="<?= old('court_reference') ?>" placeholder="e.g. BDR-CIV-2026-441 (Banadir Regional Court)">
        <span class="field-hint">Judicial decree or official registry dissolution ruling reference.</span>
      </div>
    </div>
  </div>

  <!-- Section 2: Husband Information -->
  <div class="card" style="margin-bottom:24px;">
    <h3 style="margin-bottom:16px;">2. Husband Information</h3>

    <div class="form-row">
      <div class="form-group">
        <label for="husband_full_name">Husband's Full Name <span class="required-star">*</span></label>
        <input id="husband_full_name" name="husband_full_name" type="text" required value="<?= old('husband_full_name') ?>" placeholder="e.g. Ibrahim Farah Guled">
        <?php if (!empty($errors['husband_full_name'])): ?>
          <span class="error"><?= e($errors['husband_full_name']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="husband_date_of_birth">Husband's Date of Birth <span class="required-star">*</span></label>
        <input id="husband_date_of_birth" name="husband_date_of_birth" type="date" required max="<?= date('Y-m-d') ?>" value="<?= old('husband_date_of_birth') ?>" data-age-target="#divorce-husband-age" data-age-asof="divorce_date">
        <span class="age-display" id="divorce-husband-age"></span>
        <?php if (!empty($errors['husband_date_of_birth'])): ?>
          <span class="error"><?= e($errors['husband_date_of_birth']) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Section 3: Wife Information -->
  <div class="card" style="margin-bottom:24px;">
    <h3 style="margin-bottom:16px;">3. Wife Information</h3>

    <div class="form-row">
      <div class="form-group">
        <label for="wife_full_name">Wife's Full Name <span class="required-star">*</span></label>
        <input id="wife_full_name" name="wife_full_name" type="text" required value="<?= old('wife_full_name') ?>" placeholder="e.g. Maryan Mohamed Ali">
        <?php if (!empty($errors['wife_full_name'])): ?>
          <span class="error"><?= e($errors['wife_full_name']) ?></span>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label for="wife_date_of_birth">Wife's Date of Birth <span class="required-star">*</span></label>
        <input id="wife_date_of_birth" name="wife_date_of_birth" type="date" required max="<?= date('Y-m-d') ?>" value="<?= old('wife_date_of_birth') ?>" data-age-target="#divorce-wife-age" data-age-asof="divorce_date">
        <span class="age-display" id="divorce-wife-age"></span>
        <?php if (!empty($errors['wife_date_of_birth'])): ?>
          <span class="error"><?= e($errors['wife_date_of_birth']) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Section 4: Dissolution Particulars -->
  <div class="card" style="margin-bottom:24px;">
    <h3 style="margin-bottom:16px;">4. Dissolution Details</h3>

    <div class="form-row">
      <div class="form-group">
        <label for="divorce_date">Date of Dissolution / Divorce <span class="required-star">*</span></label>
        <input id="divorce_date" name="divorce_date" type="date" required max="<?= date('Y-m-d') ?>" value="<?= old('divorce_date', date('Y-m-d')) ?>">
        <?php if (!empty($errors['divorce_date'])): ?>
          <span class="error"><?= e($errors['divorce_date']) ?></span>
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
    </div>

    <div class="form-group">
      <label for="divorce_reason">Legal Grounds / Reason (Optional)</label>
      <input id="divorce_reason" name="divorce_reason" type="text" value="<?= old('divorce_reason') ?>" placeholder="e.g. Irreconcilable differences, Mutual agreement, Khul'">
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
      <textarea id="notes" name="notes" rows="3" placeholder="Custody agreement notes, financial settlement notes, or special remarks..."><?= old('notes') ?></textarea>
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
