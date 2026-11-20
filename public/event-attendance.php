<?php
declare(strict_types=1);

require_once __DIR__ . '/_private-root.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/bootstrap.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Security.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Authorization.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
$db = Database::connection();
$user = currentUser();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $eventId = filter_var($payload['event_id'] ?? null, FILTER_VALIDATE_INT);
    if (!verifyCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        http_response_code(419); echo json_encode(['ok'=>false,'message'=>'Beveiligingscontrole verlopen.']); exit;
    }
} else {
    $eventId = filter_var($_GET['event_id'] ?? null, FILTER_VALIDATE_INT);
}
if (!$eventId || $eventId < 1) { http_response_code(400); echo json_encode(['ok'=>false,'message'=>'Ongeldig evenement.']); exit; }
$checkEvent = $db->prepare("SELECT id FROM events WHERE id = ? AND status IN ('published','cancelled','cancelled_rain') LIMIT 1");
$checkEvent->execute([$eventId]);
if (!$checkEvent->fetchColumn()) { http_response_code(404); echo json_encode(['ok'=>false,'message'=>'Evenement niet gevonden.']); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($user !== null) {
        $check = $db->prepare("SELECT id FROM event_attendances WHERE event_id=? AND user_id=? AND status='attending' LIMIT 1");
        $check->execute([$eventId, (int)$user['id']]);
        if ($check->fetchColumn()) {
            $stmt=$db->prepare("DELETE FROM event_attendances WHERE event_id=? AND user_id=?");
            $stmt->execute([$eventId,(int)$user['id']]); $attending=false;
        } else {
            $stmt=$db->prepare("INSERT INTO event_attendances (event_id,user_id,status) VALUES (?,?,'attending') ON DUPLICATE KEY UPDATE status='attending'");
            $stmt->execute([$eventId,(int)$user['id']]); $attending=true;
        }
    } else {
        $token=(string)($_COOKIE['dondebailar_visitor']??'');
        if (!preg_match('/\\A[a-f0-9]{64}\\z/',$token)) {
            $token=bin2hex(random_bytes(32));
            setcookie('dondebailar_visitor',$token,['expires'=>time()+31536000,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);
        }
        $hash=hash('sha256',$token);
        $check=$db->prepare("SELECT id FROM event_guest_attendances WHERE event_id=? AND visitor_hash=? LIMIT 1");
        $check->execute([$eventId,$hash]);
        if ($check->fetchColumn()) {
            $stmt=$db->prepare("DELETE FROM event_guest_attendances WHERE event_id=? AND visitor_hash=?");
            $stmt->execute([$eventId,$hash]); $attending=false;
        } else {
            $stmt=$db->prepare("INSERT IGNORE INTO event_guest_attendances (event_id,visitor_hash) VALUES (?,?)");
            $stmt->execute([$eventId,$hash]); $attending=true;
        }
    }
    $count=$db->prepare("SELECT (SELECT COUNT(*) FROM event_attendances WHERE event_id=? AND status='attending') + (SELECT COUNT(*) FROM event_guest_attendances WHERE event_id=?)");
    $count->execute([$eventId,$eventId]);
    echo json_encode(['ok'=>true,'attending'=>$attending,'count'=>(int)$count->fetchColumn()]); exit;
}
if ($_SERVER['REQUEST_METHOD']==='GET' && ($_GET['action']??'')==='names') {
    $stmt=$db->prepare("SELECT u.display_name FROM event_attendances ea JOIN users u ON u.id=ea.user_id WHERE ea.event_id=? AND ea.status='attending' AND u.status='active' ORDER BY u.display_name");
    $stmt->execute([$eventId]); $names=$stmt->fetchAll(PDO::FETCH_COLUMN);
    $stmt=$db->prepare("SELECT COUNT(*) FROM event_guest_attendances WHERE event_id=?");
    $stmt->execute([$eventId]);
    echo json_encode(['ok'=>true,'names'=>$names,'guests'=>(int)$stmt->fetchColumn()]); exit;
}
http_response_code(405); header('Allow: GET, POST'); echo json_encode(['ok'=>false,'message'=>'Methode niet toegestaan.']);
