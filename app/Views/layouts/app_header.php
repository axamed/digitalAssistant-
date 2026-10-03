<?php
use App\Core\{Auth, Config, Csrf};
use App\Services\NotificationService;

$user = Auth::user();
$current = ltrim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');

/**
 * Resolve the sidebar for the signed-in account from the permission-driven menu.
 *
 * An item appears when the account holds its permission, and when it declares a desk
 * restriction it must also sit at that desk. Administrators pass both tests, so the
 * menu always offers them the whole system.
 *
 * @return array<string, array<int, array{0:string,1:string}>> section heading => items
 */
$menu = [];
foreach ((array) Config::get('navigation', []) as $group) {
    $visible = [];
    foreach ($group['items'] ?? [] as $item) {
        $permission = $item['permission'] ?? null;
        $permitted = $permission === null
            || (is_array($permission) ? Auth::canAny(...$permission) : Auth::can($permission));
        if (!$permitted) {
            continue;
        }
        $roles = $item['roles'] ?? [];
        if ($roles !== [] && !Auth::isAdmin() && !in_array($user['role'] ?? '', $roles, true)) {
            continue;
        }
        $visible[] = [$item['label'], $item['path']];
    }
    if ($visible !== []) {
        $menu[$group['section'] ?? ''] = $visible;
    }
}
$unreadNotifs = NotificationService::unreadCount((int) ($user['id'] ?? 0));
render('partials/head', ['title' => $title ?? '']);
?>
<body class="app">
<aside class="sidebar" id="sidebar">
  <a class="sidebar-brand" href="<?= e(url(Auth::homePath())) ?>">
    <span class="seal">◈</span>
    <div>
      <b style="font-family:Fraunces,serif; font-size:15px;">Civil Registry</b>
      <small style="display:block; color:var(--muted); font-size:11px;"><?= e($user['role_label'] ?? 'Staff') ?></small>
    </div>
  </a>

  <nav aria-label="Sidebar">
    <?php if ($menu === []): ?>
      <div class="sidebar-section">Main Menu</div>
      <span class="soon" title="No sections are assigned to this account yet">Menu</span>
    <?php endif; ?>
    <?php foreach ($menu as $heading => $items): ?>
      <div class="sidebar-section"><?= e($heading) ?></div>
      <?php foreach ($items as [$label, $path]): ?>
        <a href="<?= e(url($path)) ?>" class="<?= str_ends_with($current, $path) ? 'on' : '' ?>">
          <?= $label ?>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <a href="<?= e(url('profile.php')) ?>" style="color:inherit; text-decoration:none; display:flex; align-items:center; gap:9px;">
        <?php render('partials/avatar', ['path' => $user['photo_path'] ?? null, 'name' => $user['name'] ?? '', 'size' => 34]); ?>
        <span>
          <strong><?= e($user['name'] ?? '') ?></strong>
          <div style="font-size:11px; color:var(--faint);"><?= e($user['role_label'] ?? '') ?></div>
        </span>
      </a>
    </div>
  </div>
</aside>

<div class="app-main">
  <div class="topbar">
    <button type="button" class="icon-btn menu-btn" data-menu-toggle aria-label="Toggle navigation menu">☰</button>
    <div class="topbar-title"><?= e($title ?? 'Dashboard') ?></div>
    
    <a href="<?= e(url('notifications.php')) ?>" class="icon-btn notif-btn" title="Notifications" aria-label="Notifications">
      🔔
      <?php if ($unreadNotifs > 0): ?>
        <span class="notif-badge"><?= $unreadNotifs > 99 ? '99+' : $unreadNotifs ?></span>
      <?php endif; ?>
    </a>

    <a href="<?= e(url('profile.php')) ?>" class="who" title="Staff profile" style="margin: 0 4px; display:flex; align-items:center; gap:8px;">
      <?php render('partials/avatar', ['path' => $user['photo_path'] ?? null, 'name' => $user['name'] ?? '', 'size' => 28]); ?>
      <span><?= e($user['name'] ?? '') ?></span>
    </a>
    
    <button type="button" class="icon-btn" data-theme-toggle aria-label="Toggle dark/light theme" title="Toggle theme">◐</button>
    
    <form method="post" action="<?= e(url('logout.php')) ?>" style="margin:0;">
      <?= Csrf::field() ?>
      <button type="submit" class="btn btn-sm">Log out</button>
    </form>
  </div>

  <div class="content">
    <?php if (!empty($user['must_change'])): ?>
      <div class="alert alert-warning" style="margin-top:0;">
        <strong>Action Required:</strong> You are currently using a default temporary password.
        <a href="<?= e(url('change-password.php')) ?>" style="font-weight:700; text-decoration:underline;">Click here to change it now</a> to secure your account.
      </div>
    <?php endif; ?>
    <?php render('partials/flash'); ?>
