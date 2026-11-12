<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';
requirePlatformAdmin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) { http_response_code(400); exit('Ongeldige aanvraag.'); }
$id = filter_var($_POST['region_id'] ?? null, FILTER_VALIDATE_INT);
if (!$id || $id < 1) { http_response_code(400); exit('Ongeldig regionummer.'); }
$db = Database::connection();
try {
 $db->beginTransaction();
 $q = $db->prepare("SELECT id FROM regions WHERE id = :id FOR UPDATE");
 $q->execute(['id'=>$id]);
 if (!$q->fetchColumn()) { throw new RuntimeException('Regio niet gevonden.'); }
 $q = $db->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'regions'");
 $blocked = [];
 foreach ($q->fetchAll() as $ref) {
  $quote = chr(96);
  $table = str_replace($quote, $quote . $quote, $ref['TABLE_NAME']);
  $column = str_replace($quote, $quote . $quote, $ref['COLUMN_NAME']);
  $check = $db->prepare('SELECT 1 FROM ' . $quote . $table . $quote . ' WHERE ' . $quote . $column . $quote . ' = :id LIMIT 1');
  $check->execute(['id'=>$id]);
  if ($check->fetchColumn()) $blocked[] = $ref['TABLE_NAME'];
 }
 if ($blocked) {
  $db->rollBack();
  http_response_code(409);
  exit('Deze regio heeft nog gekoppelde gegevens (' . htmlspecialchars(implode(', ', array_unique($blocked)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '). Verwijder of verplaats die eerst.');
 }
 $q = $db->prepare("DELETE FROM region_appearance WHERE region_id = :id");
 $q->execute(['id'=>$id]);
 $q = $db->prepare("DELETE FROM regions WHERE id = :id");
 $q->execute(['id'=>$id]);
 $db->commit();
 header('Location: /admin/regions.php?deleted=1'); exit;
} catch (Throwable $e) {
 if ($db->inTransaction()) $db->rollBack();
 error_log('Regio wissen: '.$e->getMessage());
 http_response_code(500);
 exit('De regio kon niet worden gewist. Controleer de serverlog.');
}
