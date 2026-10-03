<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Auth, Config, Database};
use App\Services\{BackupService, Stats, Settings};

Auth::requireRole('administrator');

$users      = Stats::userCounts();
$auditStats = Stats::auditSummary();
$certStats  = Stats::certificateCounts();
$recentLogs = Stats::recentAuditLogs(8);
$backups    = Stats::recentBackups(4);

// MySQL server info
$mysqlVersion = (string) Database::scalar('SELECT VERSION()');

render('layouts/app_header', ['title' => 'Administrator Dashboard']);
?>

<div style="margin-bottom:24px;">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
    <div>
      <h1 style="font-size:1.85rem; margin-bottom:4px;">System Administration</h1>
      <p class="muted" style="margin:0;">
        Civil Registration Office &middot; Mogadishu Municipal Government, Banadir Region
      </p>
    </div>
    <div style="display:flex; gap:8px;">
      <a href="users.php" class="btn btn-sm btn-primary">+ Manage Users</a>
      <a href="backup.php" class="btn btn-sm">Database Backups</a>
      <a href="settings.php" class="btn btn-sm">System Settings</a>
    </div>
  </div>
</div>

<!-- Admin KPIs -->
<div class="kpis">
  <div class="kpi kpi-accent">
    <div class="kpi-label">Active Users</div>
    <div class="kpi-value"><?= e((string) $users['active']) ?></div>
    <div class="kpi-sub"><?= e((string) $users['total']) ?> registered accounts</div>
  </div>

  <div class="kpi kpi-ok">
    <div class="kpi-label">Certificates Issued</div>
    <div class="kpi-value"><?= e((string) $certStats['total']) ?></div>
    <div class="kpi-sub">$<?= e(number_format($certStats['revenue'], 2)) ?> collected</div>
  </div>

  <div class="kpi kpi-warn">
    <div class="kpi-label">Audit Log Events</div>
    <div class="kpi-value"><?= e((string) $auditStats['total']) ?></div>
    <div class="kpi-sub"><?= e((string) $auditStats['today']) ?> logged today</div>
  </div>

  <div class="kpi <?= $auditStats['failed'] > 0 ? 'kpi-bad' : 'kpi-accent' ?>">
    <div class="kpi-label">Failed Logins</div>
    <div class="kpi-value"><?= e((string) $auditStats['failed']) ?></div>
    <div class="kpi-sub"><?= $users['locked'] > 0 ? $users['locked'] . ' locked' : 'No locked accounts' ?></div>
  </div>
</div>

<!-- Quick Module Navigation Cards -->
<div class="grid-3" style="margin-bottom:28px;">
  <div class="card" style="padding:18px 20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
      <h3 style="margin:0; font-size:1.15rem;">Staff & Accounts</h3>
      <span class="badge"><?= e((string) $users['total']) ?> Users</span>
    </div>
    <p class="muted" style="font-size:13px; margin-bottom:12px;">Provision staff accounts, assign roles, reset credentials, or deactivate accounts.</p>
    <a href="users.php" class="btn btn-sm">Open Users &rarr;</a>
  </div>

  <div class="card" style="padding:18px 20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
      <h3 style="margin:0; font-size:1.15rem;">Certificates & Fees</h3>
      <span class="badge"><?= e((string) $certStats['valid']) ?> Valid</span>
    </div>
    <p class="muted" style="font-size:13px; margin-bottom:12px;">Review issued certificates, unpaid fee queues, and configure municipal fee rates.</p>
    <a href="certificates.php" class="btn btn-sm">Certificates &rarr;</a>
  </div>

  <div class="card" style="padding:18px 20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
      <h3 style="margin:0; font-size:1.15rem;">Backups & Storage</h3>
      <span class="badge badge-success">Protected</span>
    </div>
    <p class="muted" style="font-size:13px; margin-bottom:12px;">Create and download full database SQL dumps stored in protected offline storage.</p>
    <a href="backup.php" class="btn btn-sm">Database Backup &rarr;</a>
  </div>
</div>

<!-- Two-Column Layout: Audit Trail & System Health -->
<div class="grid-2" style="grid-template-columns: 1.5fr 1fr; margin-bottom:28px;">
  <!-- Recent Audit Logs -->
  <div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <div>
        <h3 style="margin:0;">Recent Security & Audit Events</h3>
        <span class="muted" style="font-size:12px;">Immutable append-only audit trail</span>
      </div>
      <a href="audit.php" class="btn btn-sm">All Logs &rarr;</a>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Time</th>
            <th>Staff</th>
            <th>Action</th>
            <th>Module</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentLogs)): ?>
            <tr><td colspan="4" class="text-center muted">No audit activity recorded.</td></tr>
          <?php else: ?>
            <?php foreach ($recentLogs as $log): ?>
              <tr>
                <td><small><?= e(date('M d, H:i', strtotime($log['created_at']))) ?></small></td>
                <td><b><?= e($log['user_full_name'] ?? $log['username'] ?? 'System') ?></b></td>
                <td><code><?= e($log['action']) ?></code></td>
                <td><span class="badge"><?= e($log['module']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- System Health & Security Specs -->
  <div class="card">
    <h3 style="margin-bottom:14px;">System Health & Security</h3>
    
    <table class="record-details">
      <tbody>
        <tr>
          <th>Application</th>
          <td><?= e((string) Config::get('app.name', 'Civil Registry')) ?> v1.0</td>
        </tr>
        <tr>
          <th>PHP Runtime</th>
          <td>PHP <?= e(PHP_VERSION) ?></td>
        </tr>
        <tr>
          <th>Database Engine</th>
          <td>MySQL <?= e(substr($mysqlVersion, 0, 15)) ?></td>
        </tr>
        <tr>
          <th>Audit Triggers</th>
          <td><span class="badge badge-success">Append-Only Active</span></td>
        </tr>
        <tr>
          <th>Session Lifetime</th>
          <td><?= e((string) ((int) Config::get('app.session.lifetime', 1800) / 60)) ?> minutes</td>
        </tr>
        <tr>
          <th>Brute-Force Guard</th>
          <td><?= e((string) Config::get('app.security.max_failed_logins', 5)) ?> attempts (15m lockout)</td>
        </tr>
        <tr>
          <th>Active Region</th>
          <td>Banadir Region (<?= e((string) (int) Database::scalar('SELECT COUNT(*) FROM districts')) ?> Districts)</td>
        </tr>
      </tbody>
    </table>

    <div style="margin-top:18px; padding-top:14px; border-top:1px solid var(--line); font-size:12px; color:var(--muted); line-height:1.5;">
      <strong>Privacy Guard:</strong> Administrators maintain system infrastructure, user credentials, and security auditing. Civil status details remain segregated for privacy protection.
    </div>
  </div>
</div>

<?php render('layouts/app_footer'); ?>
