<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\{Auth, Csrf};

if (Auth::check()) {
    $user = Auth::user();
    if (!empty($user['must_change'])) {
        redirect('change-password.php');
    }
    redirect(Auth::homePath());
}

$error = null;
if (is_post()) {
    Csrf::check();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $result = Auth::attempt($username, $password);
    if ($result === true) {
        $user = Auth::user();
        if (!empty($user['must_change'])) {
            flash('warning', 'You are using a temporary password. You must change it before continuing.');
            redirect('change-password.php');
        }
        flash('success', 'Welcome back, ' . $user['name'] . '.');
        redirect(Auth::homePath());
    }
    $error = $result;
}

render('layouts/public_header', ['title' => 'Staff Login', 'active' => 'login']);
?>
<section class="narrow">
  <div class="card" style="padding:32px 28px;">
    <div style="text-align:center; margin-bottom:24px;">
      <div class="seal" style="margin:0 auto 12px; width:48px; height:48px; font-size:1.4rem;">◈</div>
      <h1 style="font-size:1.75rem; margin-bottom:4px;">Staff Login</h1>
      <p class="muted" style="font-size:13.5px; margin:0;">Mogadishu Municipal Civil Registration Office</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger" style="margin-top:0;">
        <?= e($error) ?>
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="off" novalidate>
      <?= Csrf::field() ?>
      <div class="form-group">
        <label for="username">Username</label>
        <input id="username" name="username" type="text" required autofocus value="<?= old('username') ?>" placeholder="e.g. faduma or abdi">
      </div>

      <div class="form-group">
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <label for="password" style="margin-bottom:0;">Password</label>
        </div>
        <input id="password" name="password" type="password" required placeholder="Enter your staff password" style="margin-top:5px;">
      </div>

      <div style="margin-top:24px;">
        <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; padding:11px 20px; font-size:15px;">
          Sign in to Civil Registry
        </button>
      </div>
    </form>

    <div style="margin-top:28px; padding-top:20px; border-top:1px solid var(--line); font-size:12.5px; color:var(--muted); line-height:1.6;">
      <p style="margin-bottom:6px;"><strong>Development staff accounts (initial setup):</strong></p>
      <ul style="padding-left:18px; margin:0;">
        <li><code>nasra</code> &mdash; System Administrator</li>
        <li><code>abdi</code> &mdash; Supervisor / Manager</li>
        <li><code>faduma</code>, <code>mahad</code> &mdash; Civil Registration Officers</li>
      </ul>
      <p style="margin-top:6px; margin-bottom:0;">Initial password: <code>ChangeMe#2026</code> (required to change on first sign-in).</p>
    </div>
  </div>
</section>
<?php render('layouts/public_footer'); ?>
