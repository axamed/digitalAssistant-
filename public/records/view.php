<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Auth, Csrf, Database};
use App\Services\{ApprovalService, CertificateService, RecordService};
use App\Support\{Age, RecordTypes};

$user = Auth::requirePermission('record.view');

$type = trim($_GET['type'] ?? $_POST['type'] ?? '');
$id   = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if (!RecordTypes::exists($type) || $id <= 0) {
    redirect('records/search.php');
}

$record = RecordService::find($type, $id);
if (!$record) {
    render('errors/error', ['code' => 404, 'message' => 'The requested civil registration record was not found or has been archived.']);
    exit;
}

$back = 'records/view.php?type=' . $type . '&id=' . $id;

// ── Workflow actions (POST) ──────────────────────────────────────────────
if (is_post()) {
    Csrf::check();
    $action = (string) ($_POST['action'] ?? '');

    try {
        switch ($action) {
            case 'submit':
                Auth::requirePermission('record.submit');
                if (!RecordService::canSubmit($record)) {
                    throw new \RuntimeException('This record cannot be submitted in its current status.');
                }
                ApprovalService::submit($type, $id, trim((string) ($_POST['comment'] ?? '')) ?: null);
                flash('success', 'Record ' . $record['registration_number'] . ' submitted for supervisor review.');
                break;

            case 'approve':
                Auth::requirePermission('record.approve');
                $signature = trim((string) ($_POST['signature'] ?? ''));
                if ($signature === '') {
                    throw new \RuntimeException('A digital signature is required to approve a record.');
                }
                ApprovalService::approve($type, $id, $signature, trim((string) ($_POST['comment'] ?? '')) ?: null);
                flash('success', 'Record ' . $record['registration_number'] . ' approved. Its official certificate has been issued and is awaiting fee payment.');
                break;

            case 'correction':
                Auth::requirePermission('record.reject');
                $feedback = trim((string) ($_POST['comment'] ?? ''));
                if ($feedback === '') {
                    throw new \RuntimeException('Correction instructions are required.');
                }
                ApprovalService::requestCorrection($type, $id, $feedback);
                flash('warning', 'Correction requested. The registering officer has been notified.');
                break;

            case 'reject':
                Auth::requirePermission('record.reject');
                $reason = trim((string) ($_POST['comment'] ?? ''));
                if ($reason === '') {
                    throw new \RuntimeException('A rejection reason is required.');
                }
                ApprovalService::reject($type, $id, $reason);
                flash('danger', 'Record ' . $record['registration_number'] . ' rejected.');
                break;

            default:
                throw new \RuntimeException('Unknown action.');
        }
    } catch (\Throwable $e) {
        flash('danger', $e->getMessage());
    }

    redirect($back);
}

$history      = RecordService::getHistory($type, $id);
$certificate  = CertificateService::findByRecord($type, $id);
$groups       = RecordTypes::groups($type);
$reviewable   = in_array($record['status'], ['submitted', 'under_review'], true) && (int) ($record['submitted_by'] ?? 0) !== (int) $user['id'];
$canApprove   = $reviewable && Auth::can('record.approve');
$canReject    = $reviewable && Auth::can('record.reject');
$isApprover   = Auth::can('record.approve');
$ownRecord    = (int) ($record['submitted_by'] ?? 0) === (int) $user['id'];
$photoDoc     = !empty($record['photo_document_id']) ? Database::fetchOne('SELECT * FROM supporting_documents WHERE id = ?', [(int) $record['photo_document_id']]) : null;
$primaryName  = $record[RecordTypes::ALL[$type]['name']] ?? '';

render('layouts/app_header', ['title' => 'Record: ' . $record['registration_number']]);
?>
<div class="breadcrumb">
  <a href="<?= e(url(Auth::homePath())) ?>">Dashboard</a>
  <span class="sep">&rsaquo;</span>
  <a href="search.php">Search Records</a>
  <span class="sep">&rsaquo;</span>
  <span class="current"><?= e($record['registration_number']) ?></span>
</div>

<div class="page-section-title">
  <div>
    <h2><?= e(ucfirst($type)) ?> Record: <?= e($record['registration_number']) ?></h2>
    <p class="muted" style="margin:0;">Registered <?= e($record['registration_date']) ?> in <?= e($record['district_name'] ?? '—') ?></p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap;">
    <span class="badge status-<?= e($record['status']) ?>"><?= e(ucfirst(str_replace('_', ' ', $record['status']))) ?></span>
  </div>
</div>

<div style="display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap;">
  <?php if (RecordService::canEdit($record)): ?>
    <a href="edit.php?type=<?= e($type) ?>&id=<?= (int) $id ?>" class="btn">Edit Record</a>
  <?php endif; ?>
  <?php if (RecordService::canDelete($record)): ?>
    <a href="delete.php?type=<?= e($type) ?>&id=<?= (int) $id ?>" class="btn" style="color:var(--bad);">Delete (Archive)</a>
  <?php endif; ?>
  <?php if (RecordService::canSubmit($record)): ?>
    <form method="post" style="display:inline;">
      <?= Csrf::field() ?>
      <input type="hidden" name="type" value="<?= e($type) ?>">
      <input type="hidden" name="id" value="<?= (int) $id ?>">
      <input type="hidden" name="action" value="submit">
      <button type="submit" class="btn btn-primary">Submit for Supervisor Review</button>
    </form>
  <?php endif; ?>
  <a href="history.php?type=<?= e($type) ?>&id=<?= (int) $id ?>" class="btn">Full History</a>
  <?php if ($certificate): ?>
    <a href="../certificates/preview.php?id=<?= (int) $certificate['id'] ?>" class="btn btn-primary">
      Official Certificate <?= e($certificate['certificate_number']) ?>
    </a>
  <?php endif; ?>
</div>

<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:20px; align-items:start;">

  <div class="card" style="grid-column: span 2; min-width:0;">
    <h2 style="margin-bottom:4px;"><?= e($primaryName) ?></h2>
    <p class="muted" style="margin-top:0;">Registered by <?= e($record['submitted_by_name'] ?? '—') ?>
      <?php if (!empty($record['approved_by_name'])): ?>
        &middot; Approved by <?= e($record['approved_by_name']) ?> on <?= e($record['approved_at']) ?>
      <?php endif; ?>
    </p>

    <?php foreach ($groups as $group): ?>
      <h3><?= e($group['title']) ?></h3>
      <table style="margin-bottom:1.2rem;">
        <tbody>
          <?php foreach ($group['fields'] as $field): ?>
            <?php
            $name = $field['name'];
            $value = $record[$name] ?? null;
            if ($name === 'district_id') {
                $display = $record['district_name'] ?? '—';
            } elseif ($name === 'notes' || $name === 'cause_of_death') {
                $display = ($value !== null && trim((string) $value) !== '') ? (string) $value : '—';
            } else {
                $display = ($value === null || $value === '') ? '—' : (string) $value;
            }
            $age = null;
            if (!empty($field['age_asof']) && $value) {
                $asOf = $field['age_asof'] === 'today' ? date('Y-m-d') : (string) ($record[$field['age_asof']] ?? '');
                $age = Age::between((string) $value, $asOf ?: null);
            }
            ?>
            <tr>
              <th style="width:230px;"><?= e($field['label']) ?></th>
              <td>
                <?= nl2br(e($display)) ?>
                <?php if ($age !== null): ?>
                  <span class="badge" style="margin-left:6px;">Age: <?= e($age) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endforeach; ?>

    <?php if ($photoDoc): ?>
      <h3>Supporting Document</h3>
      <p class="muted"><?= e($photoDoc['original_name']) ?> (<?= e(strtoupper((string) $photoDoc['mime_type'])) ?>, <?= e(number_format(((int) $photoDoc['file_size']) / 1024, 1)) ?> KB)</p>
      <a href="document.php?id=<?= (int) $photoDoc['id'] ?>" target="_blank" class="btn">Open Document</a>
    <?php endif; ?>
  </div>

  <div>
    <?php if ($canApprove || $canReject): ?>
      <div class="card" style="margin-bottom:20px;">
        <h3>Supervisor Review</h3>
        <p class="muted">Record submitted by <?= e($record['submitted_by_name'] ?? '—') ?> on <?= e($record['created_at']) ?>.</p>

        <?php if ($canApprove): ?>
        <form method="post" style="margin-bottom:18px;">
          <?= Csrf::field() ?>
          <input type="hidden" name="type" value="<?= e($type) ?>">
          <input type="hidden" name="id" value="<?= (int) $id ?>">
          <input type="hidden" name="action" value="approve">

          <div class="form-group">
            <label for="sig-canvas">Digital Signature <span class="required-star">*</span></label>
            <canvas id="sig-canvas" width="320" height="120"
                    style="width:100%; height:120px; border:1px solid var(--line); border-radius:6px; touch-action:none;"></canvas>
            <input type="hidden" name="signature" id="sig-data">
            <div style="display:flex; gap:8px; margin-top:6px; align-items:center;">
              <button type="button" id="sig-clear" class="btn btn-sm">Clear</button>
              <span class="field-hint">Sign with mouse or finger, then approve.</span>
            </div>
          </div>

          <div class="form-group">
            <label for="approve_comment">Approval Comments</label>
            <textarea id="approve_comment" name="comment" rows="2" placeholder="Optional remarks recorded in the audit trail..."></textarea>
          </div>

          <button type="submit" class="btn btn-primary" style="width:100%;">Approve &amp; Issue Certificate</button>
        </form>
        <?php endif; ?>

        <?php if ($canApprove && $canReject): ?>
          <hr style="border:none; border-top:1px solid var(--line); margin:18px 0;">
        <?php endif; ?>

        <?php if ($canReject): ?>
        <form method="post" style="margin-bottom:14px;">
          <?= Csrf::field() ?>
          <input type="hidden" name="type" value="<?= e($type) ?>">
          <input type="hidden" name="id" value="<?= (int) $id ?>">
          <input type="hidden" name="action" value="correction">
          <div class="form-group">
            <label for="correction_comment">Request Correction</label>
            <textarea id="correction_comment" name="comment" rows="2" required placeholder="Explain exactly what the officer must correct..."></textarea>
          </div>
          <button type="submit" class="btn" style="width:100%;">Request Correction</button>
        </form>

        <form method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="type" value="<?= e($type) ?>">
          <input type="hidden" name="id" value="<?= (int) $id ?>">
          <input type="hidden" name="action" value="reject">
          <div class="form-group">
            <label for="reject_comment">Reject Record</label>
            <textarea id="reject_comment" name="comment" rows="2" required placeholder="State the reason for rejection (mandatory)..."></textarea>
          </div>
          <button type="submit" class="btn" style="width:100%; color:var(--bad); border-color:var(--bad);">Reject Record</button>
        </form>
        <?php endif; ?>
      </div>
    <?php elseif ($isApprover && $ownRecord && $record['status'] !== 'approved'): ?>
      <div class="alert alert-warning">
        You cannot review your own record. Another supervisor must action it.
      </div>
    <?php elseif ($isApprover && $record['status'] === 'approved'): ?>
      <div class="alert alert-success">
        This record is approved and locked against further editing.
      </div>
    <?php endif; ?>

    <?php if ($certificate): ?>
      <div class="card" style="margin-bottom:20px;">
        <h3>Official Certificate</h3>
        <table>
          <tr><th>Certificate No</th><td><b><?= e($certificate['certificate_number']) ?></b></td></tr>
          <tr><th>Issue Date</th><td><?= e($certificate['issue_date']) ?></td></tr>
          <tr><th>Fee</th><td><?= e(money((float) $certificate['fee_amount'])) ?></td></tr>
          <tr><th>Payment</th>
            <td>
              <span class="badge <?= $certificate['payment_status'] === 'paid' ? 'badge-success' : 'badge-warning' ?>">
                <?= e(strtoupper((string) $certificate['payment_status'])) ?>
              </span>
            </td>
          </tr>
          <tr><th>Status</th>
            <td>
              <span class="badge <?= $certificate['status'] === 'valid' ? 'badge-success' : 'badge-danger' ?>">
                <?= e(ucfirst((string) $certificate['status'])) ?>
              </span>
            </td>
          </tr>
          <tr><th>Verification</th><td><code style="font-size:11px;"><?= e(substr((string) $certificate['verification_token'], 0, 12)) ?>...</code></td></tr>
        </table>
        <div style="display:flex; gap:8px; margin-top:12px; flex-wrap:wrap;">
          <a href="../certificates/preview.php?id=<?= (int) $certificate['id'] ?>" class="btn btn-sm">Preview</a>
          <?php if ($certificate['payment_status'] === 'unpaid' && Auth::can('certificate.pay')): ?>
            <a href="../certificates/pay.php?id=<?= (int) $certificate['id'] ?>" class="btn btn-sm btn-primary">Mark Fee Paid</a>
          <?php elseif ($certificate['payment_status'] === 'paid'): ?>
            <a href="../certificates/print.php?id=<?= (int) $certificate['id'] ?>" class="btn btn-sm btn-primary">Print</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <h3>Workflow History</h3>
      <div style="display:flex; flex-direction:column; gap:10px;">
        <?php foreach (array_slice(array_reverse($history), 0, 8) as $h): ?>
          <div style="border-left:3px solid var(--line); padding-left:10px;">
            <b style="font-size:13px;"><?= e(ucfirst(str_replace('_', ' ', (string) $h['status']))) ?></b>
            <div style="font-size:13px;"><?= e($h['comment'] ?? '') ?></div>
            <small class="muted"><?= e($h['user_full_name'] ?? 'System') ?> &middot; <?= e($h['created_at']) ?></small>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php render('layouts/app_footer'); ?>
