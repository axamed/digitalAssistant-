<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Auth, Config, Csrf, Database, Logger};
use App\Services\RecordService;
use App\Support\{RecordRules, RecordTypes};

Auth::requirePermission('record.update');

$type = trim($_GET['type'] ?? $_POST['type'] ?? '');
$id   = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if (!RecordTypes::exists($type) || $id <= 0) {
    redirect('records/search.php');
}

$record = RecordService::find($type, $id);
if (!$record) {
    render('errors/error', ['code' => 404, 'message' => 'Record not found.']);
    exit;
}

if (!RecordService::canEdit($record)) {
    render('errors/error', ['code' => 403, 'message' => 'You do not have permission to edit this record or the record has already been approved and locked.']);
    exit;
}

$districts = Database::fetchAll('SELECT id, name FROM districts ORDER BY name ASC');
$groups  = RecordTypes::groups($type);
$errors  = [];
$duplicate = null;

if (is_post()) {
    Csrf::check();

    $confirmDup = !empty($_POST['confirm_duplicate']);
    $duplicate  = RecordRules::duplicateOf($type, $_POST, $id);
    $v = RecordRules::make($type, $_POST, $confirmDup, $id);
    $errors = $v->errors();

    if (!$v->fails()) {
        try {
            $changed = RecordService::update($type, $id, $_POST);
            flash('success', $changed
                ? 'Record ' . $record['registration_number'] . ' updated. The change is recorded in the record history.'
                : 'No changes were submitted.');
            redirect('records/view.php?type=' . $type . '&id=' . $id);
        } catch (\Throwable $e) {
            Logger::error("Saving {$type} record #{$id} failed: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $errors['system'] = Config::get('app.debug')
                ? 'The record could not be saved: ' . $e->getMessage()
                : 'Something went wrong while saving the record. Please try again or contact the system administrator.';
        }
    }
}

/** Current value: posted value first (redisplay after a failure), then the stored record. */
$valueOf = static function (string $name) use ($record): string {
    if (array_key_exists($name, $_POST)) {
        return trim((string) $_POST[$name]);
    }
    if ($name === 'district_id') {
        return (string) (int) ($record['district_id'] ?? 0);
    }
    return trim((string) ($record[$name] ?? ''));
};

render('layouts/app_header', ['title' => 'Edit ' . ucfirst($type) . ' Record']);
?>
<div class="breadcrumb">
  <a href="<?= e(url(Auth::homePath())) ?>">Dashboard</a>
  <span class="sep">&rsaquo;</span>
  <a href="view.php?type=<?= e($type) ?>&id=<?= (int) $id ?>"><?= e($record['registration_number']) ?></a>
  <span class="sep">&rsaquo;</span>
  <span class="current">Edit</span>
</div>

<div class="page-section-title">
  <div>
    <h2>Edit <?= e(ucfirst($type)) ?> Record: <?= e($record['registration_number']) ?></h2>
    <p class="muted" style="margin:0;">
      Status: <span class="badge status-<?= e($record['status']) ?>"><?= e(ucfirst(str_replace('_', ' ', $record['status']))) ?></span>
      &middot; the registration number never changes and every modification is appended to the record history.
    </p>
  </div>
</div>

<?php if (!empty($errors['system'])): ?>
  <div class="alert alert-danger"><?= e($errors['system']) ?></div>
<?php endif; ?>

<?php if ($duplicate && !empty($errors[RecordRules::duplicateField($type)])): ?>
  <div class="alert alert-warning">
    <strong>âš ï¸ Duplicate Warning:</strong> A <?= e($type) ?> record matching these details already exists
    (Registration #<?= e($duplicate['registration_number']) ?>). Tick the confirmation box below to save anyway.
  </div>
<?php endif; ?>

<form method="post" novalidate>
  <?= Csrf::field() ?>
  <input type="hidden" name="type" value="<?= e($type) ?>">
  <input type="hidden" name="id" value="<?= (int) $id ?>">

  <?php $lastGroup = count($groups) - 1; foreach ($groups as $groupIndex => $group): ?>
    <div class="card" style="margin-bottom:22px;">
      <h3 style="margin-bottom:16px;"><?= e($group['title']) ?></h3>

      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:14px;">
        <?php foreach ($group['fields'] as $field):
            $name  = $field['name'];
            $label = $field['label'];
            $inputType = $field['type'] ?? 'text';
            $value = $valueOf($name);
            $err   = $errors[$name] ?? '';
            $required = !empty($field['required']);
        ?>
          <div class="form-group" style="margin:0;">
            <label for="f-<?= e($name) ?>"><?= e($label) ?><?= $required ? ' <span class="required-star">*</span>' : '' ?></label>

            <?php if ($inputType === 'gender' || $inputType === 'marital'): ?>
              <?php $options = $inputType === 'gender' ? RecordTypes::GENDERS : RecordTypes::MARITAL_STATUSES; ?>
              <select id="f-<?= e($name) ?>" name="<?= e($name) ?>" <?= $required ? 'required' : '' ?>>
                <?php if (!$required): ?><option value="">-- None --</option><?php endif; ?>
                <?php foreach ($options as $opt): ?>
                  <option value="<?= e($opt) ?>" <?= $value === $opt ? 'selected' : '' ?>><?= e(ucfirst($opt)) ?></option>
                <?php endforeach; ?>
              </select>

            <?php elseif ($inputType === 'district'): ?>
              <select id="f-<?= e($name) ?>" name="<?= e($name) ?>" required>
                <option value="">-- Select District (<?= count($districts) ?> Banadir Districts) --</option>
                <?php foreach ($districts as $d): ?>
                  <option value="<?= (int) $d['id'] ?>" <?= $value === (string) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                <?php endforeach; ?>
              </select>

            <?php elseif ($inputType === 'textarea'): ?>
              <textarea id="f-<?= e($name) ?>" name="<?= e($name) ?>" rows="3"><?= e($value) ?></textarea>

            <?php else: ?>
              <?php
              $htmlType = match ($inputType) {
                  'date' => 'date',
                  'time' => 'time',
                  'phone'=> 'tel',
                  default => 'text',
              };
              ?>
              <input id="f-<?= e($name) ?>" name="<?= e($name) ?>" type="<?= $htmlType ?>"
                     value="<?= e($value) ?>" <?= $required ? 'required' : '' ?>
                     <?= $inputType === 'date' ? 'max="' . date('Y-m-d') . '"' : '' ?>
                     <?= $inputType === 'date' && !empty($field['age_asof']) && $field['age_asof'] !== 'today' ? 'data-age-asof="f-' . e((string) $field['age_asof']) . '"' : '' ?>
                     <?= $inputType === 'date' ? 'data-age-target="#age-' . e($name) . '"' : '' ?>>
              <?php if ($inputType === 'date' && !empty($field['age_asof'])): ?>
                <span class="age-display" id="age-<?= e($name) ?>"></span>
              <?php endif; ?>
            <?php endif; ?>

            <?php if (!empty($field['hint'])): ?>
              <span class="field-hint"><?= e($field['hint']) ?></span>
            <?php endif; ?>
            <?php if ($err): ?>
              <span class="error"><?= e($err) ?></span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($groupIndex === $lastGroup && $duplicate && !empty($errors[RecordRules::duplicateField($type)])): ?>
        <div style="margin-top:16px; padding:12px; background:var(--warn-soft); border-radius:var(--radius-sm);">
          <label style="display:flex; align-items:center; gap:8px; font-weight:600; cursor:pointer;">
            <input type="checkbox" name="confirm_duplicate" value="1" style="width:auto;" <?= !empty($_POST['confirm_duplicate']) ? 'checked' : '' ?>>
            I confirm that this is a distinct, genuine record and not a duplicate.
          </label>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
    <button type="submit" class="btn btn-primary" style="padding:11px 24px;">Save Changes</button>
    <a href="view.php?type=<?= e($type) ?>&id=<?= (int) $id ?>" class="btn" style="padding:11px 20px;">Cancel</a>
  </div>
</form>

<?php render('layouts/app_footer'); ?>
