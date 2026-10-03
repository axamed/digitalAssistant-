<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Services\UploadService;

Auth::requirePermission('record.view');

$docId = (int) ($_GET['id'] ?? 0);
if ($docId <= 0) {
    render('errors/error', ['code' => 404, 'message' => 'Document not found.']);
    exit;
}

UploadService::streamDocument($docId);
