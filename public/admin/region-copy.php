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
$q = $db->prepare("SELECT name, slug, subdomain, country_code, timezone, default_language FROM regions WHERE id = :id");
$q->execute(['id' => $id]);
$r = $q->fetch();
if (!$r) { http_response_code(404); exit('Regio niet gevonden.'); }
$base = $r['slug'] . '-kopie';
$slug = $base; $subdomain = $r['subdomain'] . '-kopie'; $name = $r['name'] . ' (kopie)';
for ($n = 2; ; $n++) {
 $q = $db->prepare("SELECT COUNT(*) FROM regions WHERE slug = :s OR subdomain = :d");
 $q->execute(['s' => $slug, 'd' => $subdomain]);
 if ((int)$q->fetchColumn() === 0) break;
 $slug = $base . '-' . $n; $subdomain = $r['subdomain'] . '-kopie-' . $n; $name = $r['name'] . ' (kopie ' . $n . ')';
 if ($n > 100) { http_response_code(409); exit('Geen unieke naam beschikbaar.'); }
}
try {
 $db->beginTransaction();
 $q = $db->prepare("INSERT INTO regions (name, slug, subdomain, country_code, timezone, default_language, is_active) VALUES (:n,:s,:d,:c,:t,:l,0)");
 $q->execute(['n'=>$name,'s'=>$slug,'d'=>$subdomain,'c'=>$r['country_code'],'t'=>$r['timezone'],'l'=>$r['default_language']]);
 $newId = (int)$db->lastInsertId();
 $q = $db->prepare("INSERT INTO region_appearance (region_id, primary_color, primary_dark, accent_color, secondary_color, tertiary_color, header_color, background_color, text_color, weekday_colors, logo_path, updated_by) SELECT :new_id, primary_color, primary_dark, accent_color, secondary_color, tertiary_color, header_color, background_color, text_color, weekday_colors, logo_path, :user_id FROM region_appearance WHERE region_id = :old_id");
 $q->execute(['new_id'=>$newId,'user_id'=>(int)currentUser()['id'],'old_id'=>$id]);
 if ($q->rowCount() === 0) { $q=$db->prepare("INSERT INTO region_appearance (region_id, updated_by) VALUES (:id,:uid)"); $q->execute(['id'=>$newId,'uid'=>(int)currentUser()['id']]); }
 $db->commit();
 header('Location: /admin/regions.php?copied=1'); exit;
} catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); error_log('Regio kopiëren: '.$e->getMessage()); http_response_code(500); exit('Kopiëren mislukt. Controleer de serverlog.'); }
