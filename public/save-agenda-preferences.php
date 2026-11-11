<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Authorization.php';
require_once dirname(__DIR__) . '/app/Security.php';

header('Content-Type: application/json; charset=utf-8');

$user = currentUser();
if ($user === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Log in om je instellingen te bewaren.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'message' => 'Ongeldige aanvraag.']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload) || !verifyCsrfToken(is_string($payload['csrf_token'] ?? null) ? $payload['csrf_token'] : null)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => 'De beveiligingscontrole is verlopen. Vernieuw de pagina.']);
    exit;
}

$regionId = filter_var($payload['region_id'] ?? null, FILTER_VALIDATE_INT);
$organizerId = filter_var($payload['organizer_id'] ?? 0, FILTER_VALIDATE_INT);
$filter = ($payload['filter'] ?? '') === 'city' ? 'city' : 'organizer';
$city = trim(is_string($payload['city'] ?? null) ? $payload['city'] : '');
$soundMuted = !empty($payload['sound_muted']) ? 1 : 0;

if (!$regionId || $regionId < 1 || $organizerId === false || $organizerId < 0 || mb_strlen($city) > 120) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'De agenda-instellingen zijn ongeldig.']);
    exit;
}

try {
    $db = Database::connection();
    $regionCheck = $db->prepare("SELECT id FROM regions WHERE id = :id AND is_active = 1");
    $regionCheck->execute(['id' => $regionId]);
    if (!$regionCheck->fetchColumn()) {
        throw new InvalidArgumentException('De gekozen regio is niet beschikbaar.');
    }

    if ($organizerId > 0) {
        $organizerCheck = $db->prepare("SELECT id FROM organizers WHERE id = :id AND is_active = 1");
        $organizerCheck->execute(['id' => $organizerId]);
        if (!$organizerCheck->fetchColumn()) {
            $organizerId = 0;
        }
    }

    $statement = $db->prepare(
        "INSERT INTO user_agenda_preferences
            (user_id, region_id, active_filter, organizer_id, city, sound_muted)
         VALUES
            (:user_id, :region_id, :active_filter, :organizer_id, :city, :sound_muted)
         ON DUPLICATE KEY UPDATE
            region_id = VALUES(region_id),
            active_filter = VALUES(active_filter),
            organizer_id = VALUES(organizer_id),
            city = VALUES(city),
            sound_muted = VALUES(sound_muted)"
    );
    $statement->execute([
        'user_id' => (int) $user['id'],
        'region_id' => $regionId,
        'active_filter' => $filter,
        'organizer_id' => $organizerId > 0 ? $organizerId : null,
        'city' => $city !== '' ? $city : null,
        'sound_muted' => $soundMuted,
    ]);

    echo json_encode(['ok' => true, 'message' => 'Je agenda-instellingen zijn bewaard.']);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $exception instanceof InvalidArgumentException
        ? $exception->getMessage()
        : 'Je instellingen konden niet worden bewaard.']);
}
