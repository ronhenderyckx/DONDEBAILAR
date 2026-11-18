<?php
declare(strict_types=1);

require_once __DIR__ . '/_private-root.php';

require_once DONDEBAILAR_PRIVATE_ROOT . '/app/bootstrap.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Authorization.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Security.php';

$user = requireLogin();
$db = Database::connection();

$regions = $db->query(
    "SELECT r.id, r.name, r.timezone,
            CASE WHEN a.region_id IS NOT NULL THEN a.logo_path ELSE r.logo_path END AS logo_path,
            a.primary_color, a.primary_dark, a.accent_color, a.secondary_color,
            a.tertiary_color, a.header_color, a.menu_color, a.organizer_color,
            a.filter_hover_color, a.filter_bar_color, a.filter_text_color,
            a.search_button_color, a.back_top_color, a.search_field_color,
            a.search_text_color, a.footer_color, a.background_color,
            a.text_color, a.weekday_colors
     FROM regions r
     LEFT JOIN region_appearance a ON a.region_id = r.id
     WHERE r.is_active = 1 ORDER BY r.id"
)->fetchAll();

$requestedRegionId = filter_input(INPUT_GET, 'region_id', FILTER_VALIDATE_INT) ?: 0;
$region = null;
foreach ($regions as $regionOption) {
    if ((int) $regionOption['id'] === $requestedRegionId) {
        $region = $regionOption;
        break;
    }
}
if ($region === null && $regions !== []) {
    $region = $regions[0];
}

$timezoneName = (string) ($region['timezone'] ?? 'Europe/Madrid');
try {
    $timezone = new DateTimeZone($timezoneName);
} catch (Throwable $exception) {
    $timezone = new DateTimeZone('Europe/Madrid');
}
date_default_timezone_set($timezone->getName());

$statement = $db->prepare(
    "SELECT e.id, e.title, e.description, e.hashtags, e.starts_at, e.ends_at,
            e.event_language, c.name AS category_name,
            o.name AS organizer_name, o.phone AS organizer_phone,
            o.logo_path AS organizer_logo_path,
            l.name AS location_name, l.city AS location_city,
            l.street AS location_street, l.house_number AS location_house_number,
            l.postal_code AS location_postal_code, l.phone AS location_phone,
            l.logo_path AS location_logo_path, l.latitude, l.longitude, l.google_maps_url,
            r.id AS region_id, r.name AS region_name, r.timezone AS region_timezone
     FROM event_attendances ea
     INNER JOIN events e ON e.id = ea.event_id
     INNER JOIN regions r ON r.id = e.region_id
     INNER JOIN organizers o ON o.id = e.organizer_id
     LEFT JOIN categories c ON c.id = e.category_id
     LEFT JOIN locations l ON l.id = e.location_id
     WHERE ea.user_id = :user_id
       AND ea.status = 'attending'
       AND e.status = 'published'
       AND e.starts_at >= NOW()
     ORDER BY e.starts_at ASC, e.id ASC"
);
$statement->execute(['user_id' => (int) $user['id']]);
$events = $statement->fetchAll();

if ($events !== []) {
    $eventIds = array_map(static fn(array $event): int => (int) $event['id'], $events);
    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));

    $mediaQuery = $db->prepare(
        "SELECT id, event_id, media_type, media_source, external_url, file_path, is_cover
         FROM event_media
         WHERE event_id IN ($placeholders) AND media_context = 'event'
         ORDER BY is_cover DESC, sort_order ASC, id ASC"
    );
    $mediaQuery->execute($eventIds);
    $mediaByEvent = [];
    foreach ($mediaQuery->fetchAll() as $mediaItem) {
        $mediaType = (string) $mediaItem['media_type'];
        $mediaSource = (string) $mediaItem['media_source'];
        $filePath = trim((string) ($mediaItem['file_path'] ?? ''));
        $externalUrl = trim((string) ($mediaItem['external_url'] ?? ''));
        $hasUpload = $mediaSource === 'upload' && $filePath !== '';
        $hasExternal = $externalUrl !== ''
            && filter_var($externalUrl, FILTER_VALIDATE_URL)
            && in_array((string) parse_url($externalUrl, PHP_URL_SCHEME), ['http', 'https'], true);
        if (!$hasUpload && !$hasExternal) {
            continue;
        }
        $mediaByEvent[(int) $mediaItem['event_id']][] = [
            'id' => (int) $mediaItem['id'],
            'type' => $mediaType,
            'source' => $mediaSource,
            'external_url' => $hasExternal ? $externalUrl : '',
        ];
    }

    $attendanceQuery = $db->prepare(
        "SELECT event_id, COUNT(*) AS total FROM (
            SELECT event_id FROM event_attendances
             WHERE status = 'attending' AND event_id IN ($placeholders)
            UNION ALL
            SELECT event_id FROM event_guest_attendances WHERE event_id IN ($placeholders)
         ) attendance_rows GROUP BY event_id"
    );
    $attendanceQuery->execute(array_merge($eventIds, $eventIds));
    $attendanceCounts = [];
    foreach ($attendanceQuery->fetchAll() as $row) {
        $attendanceCounts[(int) $row['event_id']] = (int) $row['total'];
    }

    $messageQuery = $db->prepare(
        "SELECT event_id, COUNT(*) AS total FROM event_messages
         WHERE is_deleted = 0 AND event_id IN ($placeholders) GROUP BY event_id"
    );
    $messageQuery->execute($eventIds);
    $messageCounts = [];
    foreach ($messageQuery->fetchAll() as $row) {
        $messageCounts[(int) $row['event_id']] = (int) $row['total'];
    }

    foreach ($events as &$event) {
        $eventId = (int) $event['id'];
        $event['media_items'] = $mediaByEvent[$eventId] ?? [];
        $event['attendance_count'] = $attendanceCounts[$eventId] ?? 0;
        $event['message_count'] = $messageCounts[$eventId] ?? 0;
        $event['is_attending'] = true;
    }
    unset($event);
}

$eventFallbackText = 'SALSA · BACHATA · KIZOMBA';
if ((int) ($region['id'] ?? 0) > 0) {
    $fallbackTextFile = DONDEBAILAR_PRIVATE_ROOT . '/storage/region-event-fallback-text/' . (int) $region['id'] . '.txt';
    if (is_file($fallbackTextFile)) {
        $configuredFallbackText = trim((string) file_get_contents($fallbackTextFile));
        if ($configuredFallbackText !== '') {
            $eventFallbackText = mb_substr($configuredFallbackText, 0, 100);
        }
    }
}

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$agendaPageSize = max(1, count($events));
$isAgendaPage = false;
$pageTitle = 'Waar ga ik dansen? — DONDEBAILAR';
$pageRobots = 'noindex, nofollow';
require DONDEBAILAR_PRIVATE_ROOT . '/app/views/header.php';
?>
<section class="agenda-page my-dance-events">
    <div class="agenda-heading">
        <div>
            <h1>Waar ga ik dansen?</h1>
            <p class="my-dance-events__subtitle">De evenementen waarvoor je hebt aangegeven dat je gaat dansen.</p>
        </div>
    </div>

    <?php if ($events === []): ?>
        <section class="agenda-empty">
            <h2>Je hebt nog geen evenementen geselecteerd</h2>
            <p>Klik in de agenda op het dansschoentje bij een evenement om het hier te bewaren.</p>
            <p><a class="button button--primary" href="/">Bekijk de agenda</a></p>
        </section>
    <?php else: ?>
        <div class="agenda-feed">
            <?php foreach ($events as $eventIndex => $event): ?>
                <?php
                try {
                    $timezone = new DateTimeZone((string) ($event['region_timezone'] ?? 'Europe/Madrid'));
                } catch (Throwable $exception) {
                    $timezone = new DateTimeZone('Europe/Madrid');
                }
                $start = new DateTimeImmutable((string) $event['starts_at'], $timezone);
                $eventUrl = '/event.php?id=' . (int) $event['id'];
                $locationLabel = trim((string) ($event['location_city'] ?? ''));
                $mapUrl = (string) ($event['google_maps_url'] ?? '');
                if ($mapUrl === '' && $event['latitude'] !== null && $event['longitude'] !== null) {
                    $mapUrl = 'https://www.google.com/maps?q=' . rawurlencode((string) $event['latitude'] . ',' . (string) $event['longitude']);
                }
                $shareScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $shareUrl = $shareScheme . ($_SERVER['HTTP_HOST'] ?? '') . $eventUrl;
                ?>
                <?php require DONDEBAILAR_PRIVATE_ROOT . '/app/views/agenda/event-card.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="agenda-message-modal" id="agenda-message-modal" hidden>
        <div class="agenda-message-modal__panel" role="dialog" aria-modal="true" aria-labelledby="agenda-message-modal-title">
            <div class="agenda-message-modal__top"><button type="button" class="agenda-message-modal__close" aria-label="Sluiten">×</button></div>
            <iframe title="Berichten bij evenement" src="about:blank" scrolling="no"></iframe>
        </div>
    </div>
</section>
<?php
$hasInstagramReels = false;
foreach ($events as $instagramCheckEvent) {
    foreach (($instagramCheckEvent['media_items'] ?? []) as $instagramCheckMedia) {
        if (($instagramCheckMedia['type'] ?? '') === 'reel' && ($instagramCheckMedia['source'] ?? '') === 'instagram') {
            $hasInstagramReels = true;
            break 2;
        }
    }
}
?>
<?php if ($hasInstagramReels): ?><script async src="https://www.instagram.com/embed.js"></script><?php endif; ?>
<script>
window.DONDEBAILAR_AGENDA_CONFIG = <?= json_encode([
    'pageSize' => (int) $agendaPageSize,
    'activeFilterIndex' => 0,
    'soundMuted' => true,
    'csrfToken' => csrfToken(),
    'regionId' => (int) ($region['id'] ?? 0),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="/assets/js/agenda.js"></script>
<?php require DONDEBAILAR_PRIVATE_ROOT . '/app/views/footer.php'; ?>
