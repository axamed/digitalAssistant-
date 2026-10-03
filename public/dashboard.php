<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;

Auth::requireLogin();
redirect(Auth::homePath());
