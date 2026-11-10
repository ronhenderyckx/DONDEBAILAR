<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';
require_once dirname(__DIR__, 2) . '/app/Locations.php';
$user = requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Ongeldige aanvraag. Vernieuw de pagina en probeer opnieuw.');
}
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id || $id < 1) { http_response_code(400); exit('Ongeldig locatie-ID.'); }
$db = Database::connection();
$statement = $db->prepare('SELECT id, region_id, logo_path FROM locations WHERE id = :id LIMIT 1');
$statement->execute(['id' => $id]);
$location = $statement->fetch();
if (!$location) { header('Location: /admin/locations.php?delete_error=missing'); exit; }
if (!locationCanManageRegion((int) $location['region_id'], $user)) {
    http_response_code(403);
    exit('Je hebt geen rechten om deze locatie te verwijderen.');
}
try {
    // Stop if any declared foreign key still points to this location.
    $references = $db->prepare("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'locations' AND REFERENCED_COLUMN_NAME = 'id'");
    $references->execute();
    foreach ($references->fetchAll(PDO::FETCH_ASSOC) as $reference) {
        $table = (string) $reference['TABLE_NAME'];
        $column = (string) $reference['COLUMN_NAME'];
        if (!preg_match('/\A[A-Za-z0-9_]+\z/', $table) || !preg_match('/\A[A-Za-z0-9_]+\z/', $column)) continue;
        $check = $db->query("SELECT 1 FROM `" . $table . "` WHERE `" . $column . "` = " . (int) $id . " LIMIT 1");
        if ($check->fetchColumn()) {
            header('Location: /admin/locations.php?delete_error=linked');
            exit;
        }
    }
    $delete = $db->prepare('DELETE FROM locations WHERE id = :id AND region_id = :region_id');
    $delete->execute(['id' => $id, 'region_id' => (int) $location['region_id']]);
    if ($delete->rowCount() !== 1) { header('Location: /admin/locations.php?delete_error=failed'); exit; }
    $logoPath = (string) ($location['logo_path'] ?? '');
    if (preg_match('~\Auploads/locations/[a-f0-9]{32}\.(?:jpg|png|webp)\z~D', $logoPath)) {
        $file = dirname(__DIR__, 2) . '/' . $logoPath;
        if (is_file($file)) @unlink($file);
    }
    header('Location: /admin/locations.php?deleted=1');
    exit;
} catch (Throwable $exception) {
    header('Location: /admin/locations.php?delete_error=failed');
    exit;
}
