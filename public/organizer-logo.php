<?php
declare(strict_types=1);

$filename = (string) ($_GET['file'] ?? '');

if (!preg_match('/\A[a-f0-9]{32}\.(jpg|png|webp)\z/', $filename)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_private-root.php';
$directory = DONDEBAILAR_PRIVATE_ROOT . '/uploads/organizers';
$filePath = $directory . '/' . $filename;

if (!is_file($filePath)) {
    http_response_code(404);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($filePath);

$allowed = [
    'jpg'  => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
];

$extension = pathinfo($filename, PATHINFO_EXTENSION);

if (!isset($allowed[$extension]) || $mime !== $allowed[$extension]) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($filePath));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: public, max-age=86400');

readfile($filePath);
