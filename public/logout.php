<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\{Auth, Csrf, Session};

if (is_post()) {
    Csrf::check();
}

if (Auth::check()) {
    Auth::logout();
    Session::start();
    flash('info', 'You have been signed out successfully.');
}

redirect('login.php');
