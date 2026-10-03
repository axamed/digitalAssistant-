<?php
$title = 'Error ' . $code;
render('layouts/public_header', ['title' => $title]);
?>
<section class="narrow">
  <h1><?= e($code) ?></h1>
  <p><?= e($message) ?></p>
  <p><a class="btn" href="<?= e(url('index.php')) ?>">Back to home</a></p>
</section>
<?php render('layouts/public_footer'); ?>
