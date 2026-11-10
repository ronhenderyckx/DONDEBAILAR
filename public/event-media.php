<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Authorization.php';
require_once dirname(__DIR__) . '/app/Events.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(404);
    exit;
}

$statement = Database::connection()->prepare(
    "SELECT em.file_path, em.media_type, e.id AS event_id, e.region_id,
            e.created_by, e.status
     FROM event_media em
     INNER JOIN events e ON e.id = em.event_id
     WHERE em.id = :id AND em.media_context = 'event'
     LIMIT 1"
);
$statement->execute(['id' => $id]);
$media = $statement->fetch();
if (!$media) {
    http_response_code(404);
    exit;
}

if ($media['status'] !== 'published') {
    $user = requireLogin();
    $event = [
        'region_id' => (int) $media['region_id'],
        'created_by' => (int) $media['created_by'],
    ];
    if (!eventCanEdit(Database::connection(), $event, $user)) {
        http_response_code(403);
        exit;
    }
}

$relativePath = (string) $media['file_path'];
$prefix = 'uploads/events/' . (int) $media['event_id'] . '/';
if (!str_starts_with($relativePath, $prefix)) {
    http_response_code(404);
    exit;
}
$filename = substr($relativePath, strlen($prefix));
if (!preg_match('/\A[a-f0-9]{32}\.(jpg|png|webp|mp4|webm|mov)\z/', $filename)) {
    http_response_code(404);
    exit;
}

$base = realpath(dirname(__DIR__) . '/uploads/events/' . (int) $media['event_id']);
$file = realpath(dirname(__DIR__) . '/' . $relativePath);
if ($base === false || $file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
    http_response_code(404);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file);
$allowed = [
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'mov' => 'video/quicktime',
];
$extension = pathinfo($filename, PATHINFO_EXTENSION);
if (!isset($allowed[$extension]) || $mime !== $allowed[$extension]) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($file));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: private, max-age=3600');
readfile($file);
