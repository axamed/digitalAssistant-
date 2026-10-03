<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\{Auth, Csrf, Database};
use App\Services\UserService;

$admin = Auth::requirePermission('user.manage');

$users       = UserService::all();
$roles       = Database::fetchAll('SELECT id, name, label FROM roles ORDER BY id ASC');
$editUser    = null;
$resetPassword = null;

$action = (string) ($_POST['action'] ?? '');

if (is_post()) {
    Csrf::check();
    $userId = (int) ($_POST['user_id'] ?? 0);

    try {
        switch ($action) {
            case 'create':
                $result = UserService::create($_POST);
                flash('success', 'User account created successfully.');
                $_SESSION['tmp_password'] = ['user' => strtolower(trim((string) $_POST['username'])), 'password' => $result['password']];
                redirect('admin/users.php');
                // no break

            case 'update':
                UserService::update($userId, $_POST);
                flash('success', 'User account updated.');
                break;

            case 'toggle':
                $target = UserService::find($userId);
                if (!$target) {
                    throw new \RuntimeException('User not found.');
                }
                $newStatus = $target['status'] === 'active' ? 'inactive' : 'active';
                UserService::setStatus($userId, $newStatus);
                flash('success', $target['username'] . ' is now ' . $newStatus . '.');
                break;

            case 'unlock':
                $target = UserService::find($userId);
                if (!$target) {
                    throw new \RuntimeException('User not found.');
                }
                UserService::unlock($userId);
                flash('success', 'Login lockout cleared for ' . $target['username'] . '.');
                break;

            case 'reset':
                $target = UserService::find($userId);
                if (!$target) {
                    throw new \RuntimeException('User not found.');
                }
                $provided = trim((string) ($_POST['new_password'] ?? ''));
                $plain = UserService::resetPassword($userId, $provided !== '' ? $provided : null);
                flash('success', 'Password reset for ' . $target['username'] . '. They must set a new password at next login.');
                $_SESSION['tmp_password'] = ['user' => $target['username'], 'password' => $plain];
                break;

            default:
                throw new \RuntimeException('Unknown action.');
        }
    } catch (\Throwable $e) {
        flash('danger', $e->getMessage());
    }

    redirect('admin/users.php');
}

// Show the temporary password exactly once after a reset.
if (isset($_SESSION['tmp_password'])) {
    $resetPassword = $_SESSION['tmp_password'];
    unset($_SESSION['tmp_password']);
}

// Pre-fill the edit form when ?edit=id is present.
if (isset($_GET['edit'])) {
    $editUser = UserService::find((int) $_GET['edit']);
}

render('layouts/app_header', ['title' => 'User Management']);
?>
<div class="breadcrumb">
  <a href="<?= e(url('admin/dashboard.php')) ?>">Dashboard</a>
  <span class="sep">&rsaquo;</span>
  <span class="current">Users</span>
</div>

<div class="page-section-title">
  <div>
    <h2>System Users</h2>
    <p class="muted" style="margin:0;">Create accounts, assign roles, and control access to the registry.</p>
  </div>
  <span class="badge"><?= (int) count($users) ?> account<?= count($users) === 1 ? '' : 's' ?></span>
</div>

<?php if ($resetPassword): ?>
  <div class="alert alert-success" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
    <div>
      <b>Temporary password for <?= e($resetPassword['user']) ?>:</b>
      <code style="font-size:15px; background:rgba(0,0,0,.06); padding:3px 8px; border-radius:4px;"><?= e($resetPassword['password']) ?></code>
      <div class="muted" style="font-size:13px;">Copy it now — it is not stored in readable form and will not be shown again.</div>
    </div>
  </div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: 2fr 1fr; gap:20px; align-items:start;">

  <div class="card">
    <h3 style="margin-top:0;">All Users</h3>
    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th>Username</th>
          <th>Role</th>
          <th>Status</th>
          <th>Last Login</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): $isLocked = $u['locked_until'] !== null && strtotime((string) $u['locked_until']) > time(); ?>
        <tr>
          <td>
            <b><?= e($u['full_name']) ?></b>
            <?php if ((int) $u['id'] === (int) $admin['id']): ?>
              <span class="badge badge-info" style="font-size:11px;">You</span>
            <?php endif; ?>
            <?php if ((int) $u['must_change_password'] === 1): ?>
              <div class="muted" style="font-size:12px;">Must set a new password at next login</div>
            <?php endif; ?>
            <?php if ($isLocked): ?>
              <div style="font-size:12px; color:var(--bad);">Locked until <?= e((string) $u['locked_until']) ?></div>
            <?php endif; ?>
          </td>
          <td><code><?= e($u['username']) ?></code></td>
          <td><span class="badge"><?= e($u['role_label']) ?></span></td>
          <td>
            <span class="badge <?= $u['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>">
              <?= e(ucfirst((string) $u['status'])) ?>
            </span>
          </td>
          <td><?= e($u['last_login'] ?? 'Never') ?></td>
          <td style="text-align:right; white-space:nowrap;">
            <a href="users.php?edit=<?= (int) $u['id'] ?>#user-form" class="btn btn-sm">Edit</a>

            <?php if ($isLocked): ?>
              <form method="post" style="display:inline;" onsubmit="return confirm('Clear the login lockout for <?= e($u['username']) ?>?');">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="unlock">
                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                <button type="submit" class="btn btn-sm">Unlock</button>
              </form>
            <?php endif; ?>

            <form method="post" style="display:inline;">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
              <button type="submit" class="btn btn-sm" <?= (int) $u['id'] === (int) $admin['id'] ? 'disabled title="You cannot change your own status"' : '' ?>>
                <?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
              </button>
            </form>

            <form method="post" style="display:inline;" onsubmit="return confirm('Reset the password for <?= e($u['username']) ?>? They will be forced to set a new one at next login.');">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="reset">
              <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
              <button type="submit" class="btn btn-sm" <?= (int) $u['id'] === (int) $admin['id'] ? 'disabled title="Use your own Change Password page"' : '' ?>>Reset password</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card" id="user-form">
    <?php if ($editUser): ?>
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <h3 style="margin-top:0;">Edit: <?= e($editUser['username']) ?></h3>
        <a href="users.php" class="btn btn-sm">Cancel</a>
      </div>
      <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="user_id" value="<?= (int) $editUser['id'] ?>">

        <div class="form-group">
          <label for="edit_full_name">Full name <span class="required-star">*</span></label>
          <input type="text" id="edit_full_name" name="full_name" value="<?= e((string) $editUser['full_name']) ?>" required>
        </div>
        <div class="form-group">
          <label for="edit_email">Email</label>
          <input type="email" id="edit_email" name="email" value="<?= e((string) ($editUser['email'] ?? '')) ?>">
        </div>
        <div class="form-group">
          <label for="edit_phone">Phone</label>
          <input type="text" id="edit_phone" name="phone" value="<?= e((string) ($editUser['phone'] ?? '')) ?>">
        </div>
        <div class="form-group">
          <label for="edit_role">Role <span class="required-star">*</span></label>
          <select id="edit_role" name="role_name" required>
            <?php foreach ($roles as $role): ?>
              <option value="<?= e($role['name']) ?>" <?= $role['name'] === $editUser['role_name'] ? 'selected' : '' ?>>
                <?= e($role['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if ((int) $editUser['id'] === (int) $admin['id']): ?>
            <div class="field-hint">You cannot change your own role.</div>
          <?php endif; ?>
        </div>
        <div class="form-group">
          <label for="edit_status">Status</label>
          <select id="edit_status" name="status">
            <option value="active" <?= $editUser['status'] === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $editUser['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;">Save Changes</button>
      </form>
    <?php else: ?>
      <h3 style="margin-top:0;">Create New User</h3>
      <p class="muted" style="font-size:13px;">A temporary password is generated automatically and must be changed at first login.</p>
      <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="create">

        <div class="form-group">
          <label for="new_full_name">Full name <span class="required-star">*</span></label>
          <input type="text" id="new_full_name" name="full_name" required>
        </div>
        <div class="form-group">
          <label for="new_username">Username <span class="required-star">*</span></label>
          <input type="text" id="new_username" name="username" pattern="[a-z0-9._\-]{3,30}" required>
          <div class="field-hint">3-30 characters: lowercase letters, digits, dot, dash or underscore.</div>
        </div>
        <div class="form-group">
          <label for="new_email">Email</label>
          <input type="email" id="new_email" name="email">
        </div>
        <div class="form-group">
          <label for="new_phone">Phone</label>
          <input type="text" id="new_phone" name="phone">
        </div>
        <div class="form-group">
          <label for="new_role">Role <span class="required-star">*</span></label>
          <select id="new_role" name="role_name" required>
            <?php foreach ($roles as $role): ?>
              <option value="<?= e($role['name']) ?>" <?= $role['name'] === 'officer' ? 'selected' : '' ?>><?= e($role['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="new_password">Initial password (optional)</label>
          <input type="text" id="new_password" name="password" autocomplete="off">
          <div class="field-hint">Leave blank to auto-generate one. Minimum 6 characters with letters and numbers.</div>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;">Create User</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php render('layouts/app_footer'); ?>
