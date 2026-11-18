<?php
declare(strict_types=1);

require_once __DIR__ . '/_private-root.php';

require_once DONDEBAILAR_PRIVATE_ROOT . '/app/bootstrap.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Authorization.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Events.php';

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

$base = realpath(DONDEBAILAR_PRIVATE_ROOT . '/uploads/events/' . (int) $media['event_id']);
$file = realpath(DONDEBAILAR_PRIVATE_ROOT . '/' . $relativePath);
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
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: private, max-age=3600');

// Ondersteun HTTP Range-verzoeken zodat browsers video's in stukken kunnen laden
// en snel kunnen starten zonder eerst het volledige bestand te downloaden.
$isVideo = str_starts_with($mime, 'video/');
$fileSize = (int) filesize($file);
$start = 0;
$end = $fileSize > 0 ? $fileSize - 1 : 0;

if ($isVideo) {
    header('Accept-Ranges: bytes');
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if ($range !== '' && preg_match('/bytes=(\\d*)-(\\d*)/', $range, $matches)) {
        if ($matches[1] === '' && $matches[2] !== '') {
            $suffixLength = (int) $matches[2];
            $start = max(0, $fileSize - $suffixLength);
        } else {
            $start = (int) $matches[1];
            if ($matches[2] !== '') {
                $end = min($end, (int) $matches[2]);
            }
        }

        if ($start > $end || $start >= $fileSize) {
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }

        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    }
}

$length = $fileSize > 0 ? $end - $start + 1 : 0;
header('Content-Length: ' . (string) $length);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

$handle = fopen($file, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit;
}
if ($start > 0) {
    fseek($handle, $start);
}
$remaining = $length;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(1024 * 1024, $remaining));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
    if (connection_aborted()) {
        break;
    }
}
fclose($handle);
