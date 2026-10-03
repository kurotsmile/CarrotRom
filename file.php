<?php

require_once __DIR__ . '/includes/rom_library.php';

$romId = trim((string) ($_GET['rom'] ?? ''));
$fileName = trim((string) ($_GET['file'] ?? ''));
$path = carrot_rom_safe_child_path($romId, $fileName);

if ($path === null) {
    http_response_code(404);
    echo 'File not found';
    exit;
}

$mimeType = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream';
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');

if (!empty($_GET['download'])) {
    header('Content-Disposition: attachment; filename="' . addcslashes(basename($path), '"\\') . '"');
}

readfile($path);
