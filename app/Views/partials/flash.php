<?php foreach (\App\Core\Session::pullFlash() as $f): ?>
  <div class="alert alert-<?= e($f['type']) ?>" role="alert"><?= e($f['message']) ?></div>
<?php endforeach; ?>
