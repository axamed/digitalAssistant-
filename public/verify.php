<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\CertificateVerifier;

$token = trim((string) ($_GET['token'] ?? ''));
$res   = $token !== '' ? CertificateVerifier::verify($token) : null;

render('layouts/public_header', ['title' => 'Official Certificate Verification', 'active' => 'verify']);
?>
<section class="narrow" style="max-width: 580px;">
  <div style="text-align:center; margin-bottom:28px;">
    <div class="seal" style="margin:0 auto 12px; width:48px; height:48px; font-size:1.4rem;">◈</div>
    <span class="badge" style="margin-bottom:8px;">Banadir Regional Administration</span>
    <h1 style="font-size:1.9rem; margin-bottom:6px;">Certificate Verification</h1>
    <p class="muted" style="font-size:14px; margin:0;">
      Public authenticity portal for official civil status certificates issued by the Mogadishu Municipal Government.
    </p>
  </div>

  <div class="card" style="margin-bottom:24px;">
    <form method="get" autocomplete="off">
      <div class="form-group" style="margin-bottom:12px;">
        <label for="token">Cryptographic Verification Token</label>
        <input id="token" name="token" maxlength="64" value="<?= e($token) ?>" required placeholder="Enter token printed under certificate QR code" autofocus>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; padding:10px 20px;">
        Verify Authenticity
      </button>
    </form>
    <div style="margin-top:10px; font-size:12px; color:var(--muted); text-align:center;">
      Sample token: <code>a91f3c7e52b04d6f8e2193b4a2c107e5</code>
    </div>
  </div>

  <?php if ($res !== null): ?>
    <?php if ($res['result'] === 'valid' && isset($res['certificate'])): $c = $res['certificate']; ?>
      <div class="card" style="border-top:4px solid var(--ok); background:var(--panel);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
          <div>
            <span class="badge badge-success" style="font-size:13px; padding:4px 14px;">✔ Valid Certificate</span>
          </div>
          <small class="muted">Status Verified Live</small>
        </div>

        <p style="font-size:13.5px; color:var(--muted); margin-bottom:18px;">
          This certificate was officially issued and signed by the Civil Registration Office of the Mogadishu Municipal Government.
        </p>

        <div class="table-wrap">
          <table>
            <tbody>
              <tr>
                <th style="width:170px;">Certificate Type</th>
                <td><strong><?= e($c['type']) ?> Certificate</strong></td>
              </tr>
              <tr>
                <th>Certificate Number</th>
                <td><code><?= e($c['number']) ?></code></td>
              </tr>
              <tr>
                <th>Registration Number</th>
                <td><code><?= e($c['registration']) ?></code></td>
              </tr>
              <tr>
                <th>Issue Date</th>
                <td><?= e($c['issue_date']) ?></td>
              </tr>
              <tr>
                <th>Issuing Authority</th>
                <td><?= e($c['issued_by']) ?></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div style="margin-top:18px; padding-top:14px; border-top:1px solid var(--line); font-size:12px; color:var(--faint); line-height:1.6;">
          <p style="margin:0;">
            <em>Privacy Notice:</em> In strict compliance with civil registration data protection rules, public verification displays only authentication status and official identifiers without disclosing citizen personal data.
          </p>
        </div>
      </div>

    <?php elseif ($res['result'] === 'revoked'): ?>
      <div class="card" style="border-top:4px solid var(--bad); background:var(--panel);">
        <div style="margin-bottom:12px;">
          <span class="badge badge-danger" style="font-size:13px; padding:4px 14px;">✖ Certificate Revoked</span>
        </div>
        <h3>Certificate Has Been Revoked</h3>
        <p style="font-size:14px; color:var(--bad); line-height:1.6;">
          This certificate was previously issued but has since been officially revoked and cancelled by the Banadir Regional Civil Registration Office. It is no longer legally valid.
        </p>
        <?php if (isset($res['certificate'])): $c = $res['certificate']; ?>
          <div class="table-wrap" style="margin-top:14px;">
            <table>
              <tr><th style="width:160px;">Certificate Number</th><td><code><?= e($c['number']) ?></code></td></tr>
              <tr><th>Registration Number</th><td><code><?= e($c['registration']) ?></code></td></tr>
              <tr><th>Status</th><td><span class="badge badge-danger">REVOKED</span></td></tr>
            </table>
          </div>
        <?php endif; ?>
      </div>

    <?php else: ?>
      <div class="card" style="border-top:4px solid var(--warn); background:var(--panel);">
        <div style="margin-bottom:12px;">
          <span class="badge badge-warning" style="font-size:13px; padding:4px 14px;">? Certificate Not Found</span>
        </div>
        <h3>Invalid Token or Unrecorded Certificate</h3>
        <p style="font-size:14px; color:var(--muted); line-height:1.6; margin:0;">
          No issued certificate matches the verification token <code><?= e(substr($token, 0, 16)) ?>...</code>.
          Please inspect the physical document and re-enter the exact character sequence printed below the QR code.
        </p>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php render('layouts/public_footer'); ?>
