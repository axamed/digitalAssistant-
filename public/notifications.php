<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\{Auth, Csrf};
use App\Services\NotificationService;

Auth::requireUser();
$userId = (int) Auth::id();

if (is_post()) {
    Csrf::check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'mark_all') {
        NotificationService::markAllAsRead($userId);
        flash('success', 'All notifications marked as read.');
    } elseif ($action === 'mark_one') {
        $id = (int) ($_POST['id'] ?? 0);
        if (NotificationService::markAsRead($id, $userId)) {
            flash('success', 'Notification marked as read.');
        }
    } elseif ($action === 'clear_read') {
        NotificationService::clearRead($userId);
        flash('success', 'Read notifications cleared.');
    }

    redirect('notifications.php');
}

$notifications = NotificationService::forUser($userId, 50);
$unread         = 0;
foreach ($notifications as $n) {
    if ((int) $n['is_read'] === 0) {
        $unread++;
    }
}

render('layouts/app_header', ['title' => 'Notifications']);
?>
<div class="breadcrumb">
  <a href="<?= e(url(Auth::homePath())) ?>">Dashboard</a>
  <span class="sep">&rsaquo;</span>
  <span class="current">Notifications</span>
</div>

<div class="card" style="max-width: 800px; margin: 0 auto;">
  <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:1.2rem;">
    <div>
      <h2 style="margin:0;">Notifications</h2>
      <p class="muted" style="margin:2px 0 0;">
        <?= $unread > 0 ? e($unread) . ' unread' : 'All caught up' ?>
      </p>
    </div>
    <div style="display:flex; gap:8px;">
      <?php if ($unread > 0): ?>
        <form method="POST">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="mark_all">
          <button type="submit" class="btn btn-sm">Mark all read</button>
        </form>
      <?php endif; ?>
      <?php if (count($notifications) > $unread): ?>
        <form method="POST" onsubmit="return confirm('Delete all notifications you have already read?');">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="clear_read">
          <button type="submit" class="btn btn-sm">Clear read</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($notifications === []): ?>
    <p class="muted">No notifications at this time.</p>
  <?php else: ?>
    <div style="display:flex; flex-direction:column; gap:12px;">
      <?php foreach ($notifications as $n): ?>
        <?php $isRead = (int) $n['is_read'] === 1; ?>
        <div style="padding:12px 14px; border:1px solid var(--line); border-left:3px solid <?= $isRead ? 'var(--line)' : 'var(--brass)' ?>; border-radius:6px; background:<?= $isRead ? 'transparent' : 'var(--brass-faint)' ?>;">
          <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">
            <div style="min-width:0;">
              <b><?= e($n['title']) ?></b>
              <?php if (!$isRead): ?>
                <span class="badge badge-warning" style="font-size:11px;">New</span>
              <?php endif; ?>
            </div>
            <small class="muted" style="white-space:nowrap;"><?= e($n['created_at']) ?></small>
          </div>
          <p style="margin:4px 0; font-size:14px;"><?= e($n['message']) ?></p>
          <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <?php if (!empty($n['link'])): ?>
              <a href="<?= e($n['link']) ?>" style="font-size:13px; font-weight:600;">View details &rarr;</a>
            <?php endif; ?>
            <?php if (!$isRead): ?>
              <form method="POST">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="mark_one">
                <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                <button type="submit" class="btn btn-sm" style="padding:2px 10px; font-size:12px;">Mark read</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php render('layouts/app_footer'); ?>
