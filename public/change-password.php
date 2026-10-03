<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\{Auth, Config, Csrf};
use App\Services\UserService;

$user = Auth::requireLogin();
$error = null;

if (is_post()) {
    Csrf::check();

    // Same rules as the security panel on the profile page, so the two cannot drift.
    try {
        UserService::changeOwnPassword(
            (int) $user['id'],
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? '')
        );
        $_SESSION['user']['must_change'] = false;
        flash('success', 'Your password has been changed successfully. You now have full access.');
        redirect(Auth::homePath());
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$min = (int) Config::get('app.security.min_password_length', 6);

render('layouts/app_header', ['title' => 'Change Password']);
?>
<section class="narrow">
  <div class="card">
    <div style="margin-bottom:20px;">
      <h2>Change Staff Password</h2>
      <p class="muted">
        <?php if (!empty($user['must_change'])): ?>
          <strong>First sign-in required step:</strong> You are currently using the default development password. Please choose a strong personal password to activate full access.
        <?php else: ?>
          Update your account password below. Choose a strong, memorable passphrase.
        <?php endif; ?>
      </p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger" style="margin-top:0;">
        <?= e($error) ?>
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="off" novalidate>
      <?= Csrf::field() ?>
      <div class="form-group">
        <label for="current_password">Current Password</label>
        <input id="current_password" name="current_password" type="password" required autofocus placeholder="Enter current / temporary password">
      </div>

      <div class="form-group">
        <label for="new_password">New Password</label>
        <input id="new_password" name="new_password" type="password" required minlength="<?= (int) $min ?>" placeholder="Minimum <?= (int) $min ?> characters, letters and numbers">
        <span class="field-hint">Must be at least <?= (int) $min ?> characters and contain letters and numbers.</span>
      </div>

      <div class="form-group">
        <label for="confirm_password">Confirm New Password</label>
        <input id="confirm_password" name="confirm_password" type="password" required placeholder="Re-type new password">
      </div>

      <div style="margin-top:24px; display:flex; gap:12px; align-items:center;">
        <button type="submit" class="btn btn-primary" style="padding:10px 22px;">Save New Password</button>
        <?php if (empty($user['must_change'])): ?>
          <a href="<?= e(url(Auth::homePath())) ?>" class="btn">Cancel</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</section>
<?php render('layouts/app_footer'); ?>
