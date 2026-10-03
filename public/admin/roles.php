<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Auth, Csrf, Database};
use App\Services\RoleService;

Auth::requirePermission('role.manage');

// An install upgraded from before the administrator became the superuser would still be
// missing the permissions added since, so the role is topped up before the screen is read.
// INSERT IGNORE makes this a no-op once they are already in place.
RoleService::syncAdministrator();

$roles       = RoleService::all();
$grouped     = RoleService::groupedPermissions();
$rolePerms   = [];
$userCounts  = [];
foreach ($roles as $role) {
    $rolePerms[(int) $role['id']] = RoleService::permissionCodes((int) $role['id']);
    $userCounts[(int) $role['id']] = (int) Database::scalar('SELECT COUNT(*) FROM users WHERE role_id = ?', [(int) $role['id']]);
}

$selectedRole = $roles[0] ?? null;
if (isset($_GET['role'])) {
    foreach ($roles as $role) {
        if ((int) $role['id'] === (int) $_GET['role']) {
            $selectedRole = $role;
        }
    }
}
$catalogueTotal = count(RoleService::permissions());

if (is_post()) {
    Csrf::check();
    $action = (string) ($_POST['action'] ?? 'permissions');
    $roleId = (int) ($_POST['role_id'] ?? 0);

    try {
        if ($action === 'permissions') {
            // Unchecked checkboxes are not posted, so read the "on" markers to rebuild the set.
            $enabled = (array) ($_POST['permissions'] ?? []);
            RoleService::setPermissions($roleId, $enabled);
            flash('success', 'Permissions updated. They apply to the accounts straight away.');
            redirect('admin/roles.php?role=' . $roleId);
        }

        if ($action === 'create_permission') {
            $id = RoleService::createPermission(
                (string) ($_POST['code'] ?? ''),
                (string) ($_POST['description'] ?? '')
            );
            flash('success', 'Permission added and granted to the administrator role.');
            redirect('admin/roles.php?role=' . ($roleId ?: (int) RoleService::findByName('administrator')['id']));
        }

        if ($action === 'update_permission') {
            RoleService::updatePermission(
                (int) ($_POST['permission_id'] ?? 0),
                (string) ($_POST['code'] ?? ''),
                (string) ($_POST['description'] ?? '')
            );
            flash('success', 'Permission updated.');
            redirect('admin/roles.php?role=' . $roleId);
        }

        if ($action === 'delete_permission') {
            RoleService::deletePermission((int) ($_POST['permission_id'] ?? 0));
            flash('success', 'Permission deleted and removed from every role.');
            redirect('admin/roles.php?role=' . $roleId);
        }

        flash('danger', 'Unknown action.');
    } catch (\Throwable $e) {
        flash('danger', $e->getMessage());
    }

    redirect('admin/roles.php?role=' . $roleId);
}

render('layouts/app_header', ['title' => 'Roles & Permissions']);
?>
<div class="breadcrumb">
  <a href="<?= e(url('admin/dashboard.php')) ?>">Dashboard</a>
  <span class="sep">&rsaquo;</span>
  <span class="current">Roles &amp; Permissions</span>
</div>

<div class="page-section-title">
  <div>
    <h2>Roles &amp; Permissions</h2>
    <p class="muted" style="margin:0;">Control exactly what each role can do. A role only appears in a user's sidebar when it holds the permission behind that section. Changes are recorded in the audit log.</p>
  </div>
</div>

<table style="margin-bottom:20px;">
  <thead>
    <tr>
      <th>Role</th>
      <th>Label</th>
      <th>Users</th>
      <th>Permissions</th>
      <th style="text-align:right;">Manage</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($roles as $role): ?>
    <tr>
      <td><code><?= e($role['name']) ?></code></td>
      <td><b><?= e($role['label']) ?></b></td>
      <td><?= (int) $userCounts[(int) $role['id']] ?></td>
      <td><?= (int) $role['permission_count'] ?></td>
      <td style="text-align:right;">
        <a href="roles.php?role=<?= (int) $role['id'] ?>" class="btn btn-sm">Edit permissions</a>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php if ($selectedRole): ?>
<?php $isAdminRole = (string) $selectedRole['name'] === 'administrator'; ?>
<div class="card" style="max-width:900px;">
  <h3 style="margin-top:0;">Permissions for <?= e($selectedRole['label']) ?> <code style="font-size:13px;"><?= e($selectedRole['name']) ?></code></h3>
  <p class="muted">
    <?php if ($isAdminRole): ?>
    <b>Administrator role:</b> this role always holds every permission in the catalogue, including any added
    later, so an administrator keeps full control of the system. The boxes are shown ticked and cannot be
    cleared, because the administrator must never be the reason the system locks itself out.
    <?php else: ?>
    This role takes exactly the boxes ticked below. Changes apply to its accounts straight away.
    <?php endif; ?>
  </p>

  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="role_id" value="<?= (int) $selectedRole['id'] ?>">

    <?php foreach ($grouped as $module => $perms): ?>
      <h4 style="margin-bottom:6px; text-transform:capitalize;"><?= e($module) ?> module</h4>
      <table style="margin-bottom:16px;">
        <tbody>
        <?php foreach ($perms as $code => $description): ?>
          <tr>
            <td style="width:34px;">
              <input type="checkbox" id="perm-<?= e($code) ?>" name="permissions[]" value="<?= e($code) ?>"
                     <?= ($isAdminRole || in_array($code, $rolePerms[(int) $selectedRole['id']], true)) ? 'checked' : '' ?>
                     <?= $isAdminRole ? 'disabled' : '' ?> style="width:auto;">
            </td>
            <td style="width:190px;"><code><?= e($code) ?></code></td>
            <td><?= e($description) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endforeach; ?>

    <div style="display:flex; gap:8px;">
      <button type="submit" class="btn btn-primary" <?= $isAdminRole ? 'disabled' : '' ?>>Save Permissions</button>
      <a href="roles.php?role=<?= (int) $selectedRole['id'] ?>" class="btn">Discard changes</a>
    </div>
    <?php if ($isAdminRole): ?>
      <p class="muted" style="margin-bottom:0;">Nothing to save here: this role already holds all <?= (int) $catalogueTotal ?> permissions.</p>
    <?php endif; ?>
  </form>
</div>
<?php endif; ?>
<?php render('layouts/app_footer'); ?>
