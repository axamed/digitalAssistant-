<?php render('partials/head', ['title' => $title ?? '']); $active = $active ?? ''; ?>
<body>
<header class="site-header">
  <a class="brand" href="<?= e(url('index.php')) ?>"><span class="seal">◈</span><span><b>Civil Registry</b><small>Mogadishu · Banadir Region</small></span></a>
  <nav class="site-nav" aria-label="Main">
    <a href="<?= e(url('index.php')) ?>" class="<?= $active === 'home' ? 'on' : '' ?>">Home</a>
    <a href="<?= e(url('about.php')) ?>" class="<?= $active === 'about' ? 'on' : '' ?>">About</a>
    <a href="<?= e(url('contact.php')) ?>" class="<?= $active === 'contact' ? 'on' : '' ?>">Contact</a>
    <button type="button" class="icon-btn" data-theme-toggle aria-label="Toggle theme">◐</button>
    <?php if (\App\Core\Auth::check()): ?>
      <a class="btn btn-primary" href="<?= e(url(\App\Core\Auth::homePath())) ?>">Dashboard</a>
    <?php else: ?>
      <a class="btn btn-primary" href="<?= e(url('login.php')) ?>">Login</a>
    <?php endif; ?>
  </nav>
</header>
<main class="container"><?php render('partials/flash'); ?>
