<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\{Audit, Auth, Csrf, Database};
use App\Services\{PhotoService, UserService};
use App\Support\Validator;

$user = Auth::requireUser();
$userId = (int) $user['id'];
$errors = [];

$row = Database::fetchOne('SELECT * FROM users WHERE id = ?', [$userId]);
if (!$row) {
    abort(403, 'Your account could not be loaded.');
}

if (is_post()) {
    Csrf::check();
    $action = (string) ($_POST['action'] ?? 'details');
    $posted = false;

    try {
        switch ($action) {
            case 'photo':
                $uploaded = $_FILES['photo'] ?? null;
                if (!is_array($uploaded)) {
                    throw new RuntimeException('No photo was selected.');
                }
                $relative = PhotoService::saveUpload($uploaded, $userId);
                UserService::setPhoto($userId, $relative);
                $_SESSION['user']['photo_path'] = $relative;
                flash('success', 'Your profile photo has been updated.');
                redirect('profile.php');

            case 'remove_photo':
                if (is_string($row['photo_path'] ?? null) && trim((string) $row['photo_path']) !== '') {
                    UserService::setPhoto($userId, null);
                    $_SESSION['user']['photo_path'] = null;
                    flash('success', 'Your profile photo has been removed.');
                } else {
                    flash('warning', 'You do not have a profile photo.');
                }
                redirect('profile.php');

            case 'username':
                $username = UserService::changeOwnUsername(
                    $userId,
                    (string) ($_POST['new_username'] ?? ''),
                    (string) ($_POST['current_password'] ?? '')
                );
                $_SESSION['user']['username'] = $username;
                flash('success', 'Your username is now ' . $username . '.');
                redirect('profile.php');

            case 'password':
                UserService::changeOwnPassword(
                    $userId,
                    (string) ($_POST['current_password'] ?? ''),
                    (string) ($_POST['new_password'] ?? ''),
                    (string) ($_POST['confirm_password'] ?? '')
                );
                $_SESSION['user']['must_change'] = false;
                flash('success', 'Your password has been changed.');
                redirect('profile.php');

            default:
                $v = Validator::make($_POST)
                    ->required('full_name', 'Full name')->maxLength('full_name', 'Full name', 100)
                    ->email('email', 'Email address')
                    ->maxLength('phone', 'Phone number', 40);

                if ($v->fails()) {
                    $errors = $v->errors();
                } else {
                    $fullName = trim((string) $_POST['full_name']);
                    $email    = trim((string) ($_POST['email'] ?? ''));
                    $phone    = trim((string) ($_POST['phone'] ?? ''));

                    Database::execute(
                        'UPDATE users SET full_name = ?, email = ?, phone = ?, updated_at = NOW() WHERE id = ?',
                        [$fullName, $email !== '' ? $email : null, $phone !== '' ? $phone : null, $userId]
                    );

                    // Keep the signed-in session in step with the new name.
                    $_SESSION['user']['full_name'] = $fullName;
                    $_SESSION['user']['name'] = $fullName;

                    Audit::log('profile_update', 'users', "Updated own profile details ({$row['username']})");

                    flash('success', 'Your profile has been updated.');
                    redirect('profile.php');
                }

                $posted = true;
                $row['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
                $row['email']     = trim((string) ($_POST['email'] ?? ''));
                $row['phone']     = trim((string) ($_POST['phone'] ?? ''));
        }
    } catch (RuntimeException $e) {
        $errors['security'] = $e->getMessage();
        flash('danger', $e->getMessage());
        // Keep what was typed, so a refused change does not clear the form.
        $posted = true;
        if ($action === 'username') {
            $row['username'] = trim((string) ($_POST['new_username'] ?? $row['username']));
        }
    }
}

// Read the account back after a change; $posted is true only when the form was rejected,
// where the values on screen have to be the ones that were sent.
if (!$posted) {
    $row = Database::fetchOne('SELECT * FROM users WHERE id = ?', [$userId]) ?: $row;
}
$roleLabel = (string) Database::scalar('SELECT label FROM roles WHERE id = ?', [(int) $row['role_id']]);
$permissions = array_column(
    Database::fetchAll(
        'SELECT p.code, p.description FROM permissions p
         JOIN role_permissions rp ON rp.permission_id = p.id
         WHERE rp.role_id = ? ORDER BY p.id',
        [(int) $row['role_id']]
    ),
    'description',
    'code'
);

$err = static fn(string $key): string => isset($errors[$key]) ? '<span class="error">' . e($errors[$key]) . '</span>' : '';
$hasPhoto = is_string($row['photo_path'] ?? null) && trim((string) $row['photo_path']) !== '';

render('layouts/app_header', ['title' => 'My Profile']);
?>
<div class="breadcrumb">
  <a href="<?= e(url(Auth::homePath())) ?>">Dashboard</a>
  <span class="sep">&rsaquo;</span>
  <span class="current">My Profile</span>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start; max-width:1000px; margin:0 auto;">

  <div class="card">
    <h2 style="margin-top:0;">Staff Account</h2>
    <table>
      <tr><th style="width:150px;">Username</th><td><code><?= e($row['username']) ?></code></td></tr>
      <tr><th>Official Role</th><td><span class="badge"><?= e($roleLabel) ?></span></td></tr>
      <tr>
        <th>Account Status</th>
        <td>
          <span class="badge <?= $row['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>"><?= e(ucfirst((string) $row['status'])) ?></span>
          <?php if ((int) $row['must_change_password'] === 1): ?>
            <span class="badge badge-warning">Password change pending</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr><th>Last Login</th><td><?= e((string) ($row['last_login'] ?? '—')) ?></td></tr>
      <tr><th>Member Since</th><td><?= e((string) $row['created_at']) ?></td></tr>
      <tr><th>Office</th><td>Mogadishu Municipal Civil Registration Office</td></tr>
    </table>

    <h3>Update my details</h3>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="details">
      <div class="form-group">
        <label for="full_name">Full name <span class="required-star">*</span></label>
        <input type="text" id="full_name" name="full_name" value="<?= e((string) $row['full_name']) ?>" required>
        <?= $err('full_name') ?>
      </div>
      <div class="form-group">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= e((string) ($row['email'] ?? '')) ?>">
        <?= $err('email') ?>
      </div>
      <div class="form-group">
        <label for="phone">Phone number</label>
        <input type="text" id="phone" name="phone" value="<?= e((string) ($row['phone'] ?? '')) ?>">
        <?= $err('phone') ?>
      </div>
      <div style="display:flex; gap:10px; margin-top:14px;">
        <button type="submit" class="btn btn-primary">Save Profile</button>
        <a href="#security" class="btn">Security settings</a>
      </div>
    </form>
    <p class="field-hint">Your role and permissions are managed by the system administrator. You can change your own username and password below.</p>
  </div>

  <div class="card" id="security">
    <h2 style="margin-top:0;">Security</h2>
    <p class="muted" style="font-size:13px;">Your photo, username and password. Each change asks for your current password, so a left-open session cannot take the account over.</p>

    <h3>Profile photo</h3>
    <div style="display:flex; align-items:center; gap:14px; margin-bottom:12px;">
      <?php render('partials/avatar', ['path' => $row['photo_path'] ?? null, 'name' => (string) $row['full_name'], 'size' => 72]); ?>
      <div class="muted" style="font-size:12px;">
        <?= $hasPhoto ? 'Your photo is shown beside your name in the sidebar and top bar.' : 'No photo yet. Add one so colleagues recognise your name on the register.' ?>
      </div>
    </div>
    <form method="post" enctype="multipart/form-data" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="photo">
      <input type="file" name="photo" accept="image/png,image/jpeg,image/gif,image/webp" required style="font-size:13px;">
      <button type="submit" class="btn btn-primary btn-sm">Upload photo</button>
    </form>
    <span class="field-hint">JPG, PNG, GIF or WEBP, up to 2 MB. Kept in private storage and only ever shown to signed-in staff.</span>
    <?php if ($hasPhoto): ?>
      <form method="post" style="margin-top:10px;">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="remove_photo">
        <button type="submit" class="btn btn-sm">Remove photo</button>
      </form>
    <?php endif; ?>

    <h3>Change username</h3>
    <form method="post" autocomplete="off">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="username">
      <div class="form-group">
        <label for="new_username">New username</label>
        <input type="text" id="new_username" name="new_username" value="<?= e((string) $row['username']) ?>" pattern="[a-z0-9._\-]{3,30}" required>
        <span class="field-hint">3-30 characters: lowercase letters, digits, dot, dash or underscore.</span>
      </div>
      <div class="form-group">
        <label for="username_password">Current password</label>
        <input type="password" id="username_password" name="current_password" required>
      </div>
      <button type="submit" class="btn btn-sm btn-primary">Update username</button>
    </form>

    <h3>Change password</h3>
    <form method="post" autocomplete="off" novalidate>
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="password">
      <div class="form-group">
        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password" required>
      </div>
      <div class="form-group">
        <label for="new_password">New password</label>
        <input type="password" id="new_password" name="new_password" required placeholder="At least 6 characters">
        <span class="field-hint">At least 6 characters, with both letters and numbers.</span>
      </div>
      <div class="form-group">
        <label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password" required>
      </div>
      <div style="display:flex; gap:8px; align-items:center;">
        <button type="submit" class="btn btn-sm btn-primary">Update password</button>
        <a href="change-password.php" class="btn btn-sm">Full password page</a>
      </div>
    </form>
    <?= $err('security') ?>
  </div>

  <div class="card" style="grid-column:1 / -1;">
    <h2 style="margin-top:0;">Authorisation</h2>
    <p class="muted" style="font-size:13px;">Permissions granted to the <b><?= e($roleLabel) ?></b> role.</p>
    <div class="table-wrap">
      <table>
        <tbody>
          <?php foreach ($permissions as $code => $description): ?>
          <tr>
            <td style="width:200px;"><code style="font-size:12px;"><?= e($code) ?></code></td>
            <td style="font-size:13px;"><?= e($description) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <h3>Quick links</h3>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
      <a href="notifications.php" class="btn btn-sm">My notifications</a>
      <a href="change-password.php" class="btn btn-sm">Change password</a>
      <a href="logout.php" class="btn btn-sm">Log out</a>
    </div>
  </div>
</div>
<?php render('layouts/app_footer'); ?>
