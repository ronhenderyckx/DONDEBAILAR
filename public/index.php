<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Security.php';
require_once dirname(__DIR__) . '/app/Authorization.php';

$db = Database::connection();
$currentUser = currentUser();
$visitorToken = '';
if ($currentUser === null) {
    $visitorToken = (string) ($_COOKIE['dondebailar_visitor'] ?? '');
    if (!preg_match('/\\A[a-f0-9]{64}\\z/', $visitorToken)) {
        $visitorToken = bin2hex(random_bytes(32));
        setcookie('dondebailar_visitor', $visitorToken, [
            'expires' => time() + 31536000, 'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        $_COOKIE['dondebailar_visitor'] = $visitorToken;
    }
}
$visitorHash = $visitorToken !== '' ? hash('sha256', $visitorToken) : '';
$regions = $db->query(
    "SELECT r.id, r.name, r.timezone,
            CASE WHEN a.region_id IS NOT NULL THEN a.logo_path ELSE r.logo_path END AS logo_path,
            a.primary_color, a.primary_dark, a.accent_color,
            a.secondary_color, a.tertiary_color, a.header_color, a.menu_color, a.organizer_color,
            a.filter_bar_color, a.filter_text_color, a.search_button_color, a.back_top_color,
             a.search_field_color, a.search_text_color, a.footer_color, a.background_color, a.text_color, a.weekday_colors
     FROM regions r
     LEFT JOIN region_appearance a ON a.region_id = r.id
     WHERE r.is_active = 1 ORDER BY r.id"
)->fetchAll();

$regionIds = array_map(static fn(array $item): int => (int) $item['id'], $regions);
$requestedRegion = filter_input(INPUT_GET, 'region_id', FILTER_VALIDATE_INT);
$regionId = $requestedRegion && in_array((int) $requestedRegion, $regionIds, true)
    ? (int) $requestedRegion
    : (int) ($regionIds[0] ?? 0);
$regionById = [];
foreach ($regions as $item) {
    $regionById[(int) $item['id']] = $item;
}
$region = $regionById[$regionId] ?? null;
$timezoneName = (string) ($region['timezone'] ?? 'Europe/Madrid');
try {
    $timezone = new DateTimeZone($timezoneName);
} catch (Throwable $exception) {
    $timezone = new DateTimeZone('Europe/Madrid');
}
date_default_timezone_set($timezone->getName());

$now = new DateTimeImmutable('now', $timezone);
$organizerId = filter_input(INPUT_GET, 'organizer_id', FILTER_VALIDATE_INT) ?: 0;
$city = trim((string) ($_GET['city'] ?? ''));
$activeFilter = (($_GET['filter'] ?? '') === 'city') ? 'city' : 'organizer';
if (mb_strlen($city) > 120) {
    $city = mb_substr($city, 0, 120);
}
$agendaSearch = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($agendaSearch) > 120) {
    $agendaSearch = mb_substr($agendaSearch, 0, 120);
}

$organizers = [];
$cities = [];
$events = [];
if ($regionId > 0) {
    $organizerQuery = $db->prepare(
        "SELECT DISTINCT o.id, o.name, o.logo_path
         FROM organizers o
         INNER JOIN events e ON e.organizer_id = o.id
         WHERE o.is_active = 1 AND e.region_id = :region_id
           AND e.status = 'published' AND e.starts_at >= :now
         ORDER BY o.name"
    );
    $organizerQuery->execute([
        'region_id' => $regionId,
        'now' => $now->format('Y-m-d H:i:s'),
    ]);
    $organizers = $organizerQuery->fetchAll();
    $organizerIds = array_map(static fn(array $item): int => (int) $item['id'], $organizers);
    if ($organizerId > 0 && !in_array($organizerId, $organizerIds, true)) {
        $organizerId = 0;
    }

    $cityQuery = $db->prepare(
        "SELECT DISTINCT l.city
         FROM locations l
         INNER JOIN events e ON e.location_id = l.id
         WHERE l.region_id = :region_id AND l.is_active = 1 AND l.city <> ''
           AND e.region_id = :event_region_id AND e.status = 'published'
           AND e.starts_at >= :now
         ORDER BY l.city"
    );
    $cityQuery->execute([
        'region_id' => $regionId,
        'event_region_id' => $regionId,
        'now' => $now->format('Y-m-d H:i:s'),
    ]);
    $cities = $cityQuery->fetchAll();
    $cityNames = array_column($cities, 'city');
    if ($city !== '' && !in_array($city, $cityNames, true)) {
        $city = '';
    }

    $sql = "SELECT e.id, e.title, e.description, e.hashtags, e.starts_at, e.ends_at,
                   e.event_language, c.name AS category_name,
                   o.name AS organizer_name, o.phone AS organizer_phone, o.logo_path AS organizer_logo_path,
                   l.name AS location_name,
                   l.city AS location_city, l.street AS location_street,
                   l.house_number AS location_house_number, l.postal_code AS location_postal_code,
                   l.phone AS location_phone, l.logo_path AS location_logo_path,
                   l.latitude, l.longitude, l.google_maps_url,
                   cover.id AS cover_media_id
            FROM events e
            INNER JOIN organizers o ON o.id = e.organizer_id
            LEFT JOIN categories c ON c.id = e.category_id
            LEFT JOIN locations l ON l.id = e.location_id
            LEFT JOIN event_media cover ON cover.id = COALESCE(
                e.cover_media_id,
                (SELECT em.id FROM event_media em
                 WHERE em.event_id = e.id AND em.media_context = 'event'
                   AND em.media_type = 'image' AND em.media_source = 'upload'
                 ORDER BY em.is_cover DESC, em.sort_order, em.id LIMIT 1)
            )
            WHERE e.region_id = :region_id
              AND e.status = 'published'
              AND e.starts_at >= :now";
    $params = [
        'region_id' => $regionId,
        'now' => $now->format('Y-m-d H:i:s'),
    ];
    if ($organizerId > 0) {
        $sql .= " AND e.organizer_id = :organizer_id";
        $params['organizer_id'] = $organizerId;
    }
    if ($city !== '') {
        $sql .= " AND l.city = :city";
        $params['city'] = $city;
    }
    if ($agendaSearch !== '') {
        $sql .= " AND (
            e.title LIKE :search_title
            OR e.description LIKE :search_description
            OR e.hashtags LIKE :search_hashtags
            OR o.name LIKE :search_organizer
            OR l.name LIKE :search_location
            OR l.city LIKE :search_city
            OR c.name LIKE :search_category
        )";
        $searchTerm = '%' . $agendaSearch . '%';
        $params['search_title'] = $searchTerm;
        $params['search_description'] = $searchTerm;
        $params['search_hashtags'] = $searchTerm;
        $params['search_organizer'] = $searchTerm;
        $params['search_location'] = $searchTerm;
        $params['search_city'] = $searchTerm;
        $params['search_category'] = $searchTerm;
    }
    $sql .= " ORDER BY e.starts_at ASC, e.id ASC";
    $eventQuery = $db->prepare($sql);
    $eventQuery->execute($params);
    $events = $eventQuery->fetchAll();

    if ($events !== []) {
        $eventIds = array_map(static fn(array $event): int => (int) $event['id'], $events);
        $eventPlaceholders = implode(',', array_fill(0, count($eventIds), '?'));
        $mediaQuery = $db->prepare(
            "SELECT id, event_id, media_type, media_source, external_url, file_path, is_cover
             FROM event_media
             WHERE event_id IN ($eventPlaceholders)
               AND media_context = 'event'
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

        foreach ($events as &$eventItem) {
            $eventKey = (int) $eventItem['id'];
            $eventItem['media_items'] = $mediaByEvent[$eventKey] ?? [];
        }
        unset($eventItem);

        $attendanceQuery = $db->prepare(
            "SELECT event_id, COUNT(*) AS total FROM (
                SELECT event_id FROM event_attendances WHERE status = 'attending' AND event_id IN ($eventPlaceholders)
                UNION ALL
                SELECT event_id FROM event_guest_attendances WHERE event_id IN ($eventPlaceholders)
            ) attendance_rows GROUP BY event_id"
        );
        $attendanceQuery->execute(array_merge($eventIds, $eventIds));
        $attendanceCounts = [];
        foreach ($attendanceQuery->fetchAll() as $attendanceRow) {
            $attendanceCounts[(int) $attendanceRow['event_id']] = (int) $attendanceRow['total'];
        }
        if ($currentUser !== null) {
            $mine = $db->prepare("SELECT event_id FROM event_attendances WHERE user_id = ? AND status = 'attending' AND event_id IN ($eventPlaceholders)");
            $mine->execute(array_merge([(int) $currentUser['id']], $eventIds));
        } else {
            $mine = $db->prepare("SELECT event_id FROM event_guest_attendances WHERE visitor_hash = ? AND event_id IN ($eventPlaceholders)");
            $mine->execute(array_merge([$visitorHash], $eventIds));
        }
        $myAttendanceIds = array_map('intval', $mine->fetchAll(PDO::FETCH_COLUMN));
        foreach ($events as &$eventItem) {
            $eventKey = (int) $eventItem['id'];
            $eventItem['attendance_count'] = $attendanceCounts[$eventKey] ?? 0;
            $eventItem['is_attending'] = in_array($eventKey, $myAttendanceIds, true);
        }
        unset($eventItem);

        $messageCountsQuery = $db->prepare(
            "SELECT event_id, COUNT(*) AS total FROM event_messages
             WHERE is_deleted = 0 AND event_id IN ($eventPlaceholders)
             GROUP BY event_id"
        );
        $messageCountsQuery->execute($eventIds);
        $messageCounts = [];
        foreach ($messageCountsQuery->fetchAll() as $messageCountRow) {
            $messageCounts[(int) $messageCountRow['event_id']] = (int) $messageCountRow['total'];
        }
        foreach ($events as &$eventItem) {
            $eventItem['message_count'] = $messageCounts[(int) $eventItem['id']] ?? 0;
        }
        unset($eventItem);
    }
}

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$isAgendaPage = true;
$pageTitle = 'Agenda' . ($region ? ' — ' . $region['name'] : '') . ' — DONDEBAILAR';
$pageRobots = 'index, follow';
require dirname(__DIR__) . '/app/views/header.php';
?>
<section class="agenda-page">
    <?php if ($regions === []): ?>
        <section class="agenda-empty"><h2>De agenda wordt voorbereid</h2><p>Er zijn nog geen actieve regio's beschikbaar.</p></section>
    <?php else: ?>
        
        <?php if ($events === []): ?>
            <section class="agenda-empty"><h2>Geen evenementen gevonden</h2><p>Er zijn momenteel geen toekomstige gepubliceerde evenementen met deze filters.</p></section>
        <?php else: ?>
            <div class="agenda-feed">
                <?php foreach ($events as $event): ?>
                    <?php
                    $start = new DateTimeImmutable((string) $event['starts_at'], $timezone);
                    $eventUrl = '/event.php?id=' . (int) $event['id'];
                    $locationLabel = trim((string) ($event['location_city'] ?? ''));
                    $mapUrl = (string) ($event['google_maps_url'] ?? '');
                    if ($mapUrl === '' && $event['latitude'] !== null && $event['longitude'] !== null) {
                        $mapUrl = 'https://www.google.com/maps?q=' . rawurlencode((string) $event['latitude'] . ',' . (string) $event['longitude']);
                    }
                    $shareUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . $eventUrl;
                    ?>
                    <article class="agenda-card" style="--db-event-day-color:var(--db-weekday-<?= (int) $start->format('N') ?>,var(--db-primary))">
                        <div class="agenda-card__media-wrap">
                        <?php $eventMediaItems = $event['media_items'] ?? []; ?>
                        <?php if (count($eventMediaItems) > 1): ?>
                            <div class="agenda-card__gallery" data-gallery>
                                <div class="agenda-card__gallery-track" data-gallery-track>
                                    <?php foreach ($eventMediaItems as $mediaIndex => $mediaItem): ?>
                                        <div class="agenda-card__media-slide<?= $mediaItem['type'] === 'reel' && $mediaItem['source'] === 'instagram' ? ' agenda-card__media-slide--instagram' : '' ?>">
                                            <?php if ($mediaItem['type'] === 'image' && $mediaItem['source'] === 'upload'): ?>
                                                <a class="agenda-card__image-link" href="<?= $eventUrl ?>" aria-label="Bekijk <?= $escape($event['title']) ?>">
                                                    <img class="agenda-card__image" src="/event-media.php?id=<?= (int) $mediaItem['id'] ?>" alt="<?= $escape($event['title']) ?> — foto <?= $mediaIndex + 1 ?>" loading="<?= $mediaIndex === 0 ? 'eager' : 'lazy' ?>">
                                                </a>
                                            <?php elseif ($mediaItem['type'] === 'video' && $mediaItem['source'] === 'upload'): ?>
                                                <video class="agenda-card__video" controls playsinline preload="metadata" src="/event-media.php?id=<?= (int) $mediaItem['id'] ?>"></video>
                                            <?php elseif ($mediaItem['type'] === 'reel' && $mediaItem['source'] === 'instagram'): ?>
                                                <div class="agenda-card__instagram">
                                                    <blockquote class="instagram-media" data-instgrm-permalink="<?= $escape($mediaItem['external_url']) ?>" data-instgrm-version="14">
                                                        <a href="<?= $escape($mediaItem['external_url']) ?>" target="_blank" rel="noopener noreferrer">Bekijk deze Reel op Instagram</a>
                                                    </blockquote>
                                                </div>
                                            <?php else: ?>
                                                <a class="agenda-card__external-media" href="<?= $escape($mediaItem['external_url']) ?>" target="_blank" rel="noopener noreferrer">
                                                    <span aria-hidden="true">↗</span>
                                                    <strong>Bekijk externe media</strong>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <span class="agenda-card__media-count" data-gallery-count>1 / <?= count($eventMediaItems) ?></span>
                            </div>
                        <?php elseif (count($eventMediaItems) === 1): ?>
                            <?php $mediaItem = $eventMediaItems[0]; ?>
                            <?php if ($mediaItem['type'] === 'image' && $mediaItem['source'] === 'upload'): ?>
                                <a class="agenda-card__image-link" href="<?= $eventUrl ?>" aria-label="Bekijk <?= $escape($event['title']) ?>">
                                    <img class="agenda-card__image" src="/event-media.php?id=<?= (int) $mediaItem['id'] ?>" alt="<?= $escape($event['title']) ?>" loading="lazy">
                                </a>
                            <?php elseif ($mediaItem['type'] === 'video' && $mediaItem['source'] === 'upload'): ?>
                                <video class="agenda-card__video" controls playsinline preload="metadata" src="/event-media.php?id=<?= (int) $mediaItem['id'] ?>"></video>
                            <?php elseif ($mediaItem['type'] === 'reel' && $mediaItem['source'] === 'instagram'): ?>
                                <div class="agenda-card__instagram">
                                    <blockquote class="instagram-media" data-instgrm-permalink="<?= $escape($mediaItem['external_url']) ?>" data-instgrm-version="14">
                                        <a href="<?= $escape($mediaItem['external_url']) ?>" target="_blank" rel="noopener noreferrer">Bekijk deze Reel op Instagram</a>
                                    </blockquote>
                                </div>
                            <?php else: ?>
                                <a class="agenda-card__external-media" href="<?= $escape($mediaItem['external_url']) ?>" target="_blank" rel="noopener noreferrer"><span aria-hidden="true">↗</span><strong>Bekijk externe media</strong></a>
                            <?php endif; ?>
                        <?php else: ?>
                            <a class="agenda-card__image-link" href="<?= $eventUrl ?>" aria-label="Bekijk <?= $escape($event['title']) ?>">
                                <div class="agenda-card__placeholder"><span>SALSA · BACHATA · KIZOMBA</span><strong><?= $escape($event['title']) ?></strong></div>
                            </a>
                        <?php endif; ?>
                        </div>
                        <div class="agenda-card__details" data-event-info>
                            <div class="agenda-card__quick">
                                <button type="button" class="agenda-attendance<?= !empty($event['is_attending']) ? ' is-attending' : '' ?>" data-event-id="<?= (int) $event['id'] ?>" aria-label="Ik kom dansen" aria-pressed="<?= !empty($event['is_attending']) ? 'true' : 'false' ?>" title="Tik: Ik kom dansen · Lang indrukken: deelnemers"><span aria-hidden="true">👠</span><b><?= (int) ($event['attendance_count'] ?? 0) ?></b></button>
                                <button type="button" class="agenda-icon-button agenda-share" data-share-title="<?= $escape($event['title']) ?>" data-share-url="<?= $escape($shareUrl) ?>" aria-label="Deel evenement" title="Deel evenement"><svg aria-hidden="true" viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><path d="m8.7 10.7 6.6-4.4M8.7 13.3l6.6 4.4"></path></svg></button>
                                <?php if ($mapUrl !== ''): ?><a class="agenda-icon-button agenda-location" href="<?= $escape($mapUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Toon locatie op kaart" title="Toon locatie op kaart"><svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="2.5"></circle></svg></a><?php endif; ?>
                                <button type="button" class="agenda-icon-button agenda-messages" data-event-messages data-url="/event-messages.php?id=<?= (int) $event['id'] ?>" aria-label="Berichten van evenement" title="Berichten van evenement"><svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5Z"></path></svg><b class="agenda-message-count"><?= (int) ($event['message_count'] ?? 0) ?></b></button>
                            </div>
                            <h2><a href="<?= $eventUrl ?>"><?= $escape($event['title']) ?></a></h2>
                            <?php
                            $hashtagText = trim((string) ($event['hashtags'] ?? ''));
                            $hashtagItems = preg_split('/[\\s,]+/u', $hashtagText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                            $hashtagItems = array_values(array_filter(array_map(
                                static fn(string $tag): string => ltrim($tag, '#'),
                                $hashtagItems
                            ), static fn(string $tag): bool => preg_match('/^[\\p{L}\\p{N}_]+$/u', $tag) === 1));
                            ?>
                            <p class="agenda-card__date-line"><?= $escape(strtoupper($start->format('l, d M Y'))) ?> <span>◷</span> <?= $escape($start->format('H:i')) ?><?php if (!empty($event['ends_at'])): ?> – <?= $escape((new DateTimeImmutable((string) $event['ends_at'], $timezone))->format('H:i')) ?><?php endif; ?></p>
                            <?php
                            $eventDescription = trim((string) ($event['description'] ?? ''));
                            $locationName = trim((string) ($event['location_name'] ?? ''));
                            $locationStreet = trim((string) ($event['location_street'] ?? ''));
                            $locationHouseNumber = trim((string) ($event['location_house_number'] ?? ''));
                            $locationPostalCode = trim((string) ($event['location_postal_code'] ?? ''));
                            $locationAddressLine = trim($locationStreet . ' ' . $locationHouseNumber);
                            $locationPostalCity = trim($locationPostalCode . ' ' . $locationLabel);
                            $locationPhone = trim((string) ($event['location_phone'] ?? ''));
                            $locationLogo = basename((string) ($event['location_logo_path'] ?? ''));
                            $hasLocationLogo = preg_match('/\A[a-f0-9]{32}\.(jpg|png|webp)\z/', $locationLogo) === 1;
                            $organizerName = trim((string) ($event['organizer_name'] ?? ''));
                            $organizerPhone = trim((string) ($event['organizer_phone'] ?? ''));
                            $organizerLogo = basename((string) ($event['organizer_logo_path'] ?? ''));
                            $hasOrganizerLogo = preg_match('/\A[a-f0-9]{32}\.(jpg|png|webp)\z/', $organizerLogo) === 1;
                            $hasMoreInfo = $eventDescription !== '' || $locationName !== '' || $locationAddressLine !== '' || $locationPhone !== '' || $organizerName !== '' || $organizerPhone !== '';
                            ?>
                            <?php if ($hasMoreInfo): ?>
                                <div class="agenda-card__description" data-event-description aria-hidden="true">
                                    <div class="agenda-card__description-inner">
                                        <?php if ($locationName !== '' || $locationAddressLine !== '' || $locationPhone !== ''): ?>
                                            <h3 class="agenda-card__section-label">LOCATIE</h3>
                                        <?php endif; ?>
                                        <?php if ($locationName !== ''): ?>
                                            <div class="agenda-card__venue">
                                                <?php if ($hasLocationLogo): ?>
                                                    <img class="agenda-card__venue-logo" src="/location-logo.php?file=<?= rawurlencode($locationLogo) ?>" alt="<?= $escape($locationName) ?>" loading="lazy">
                                                <?php endif; ?>
                                                <strong><?= $escape($locationName) ?></strong>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($locationAddressLine !== '' || $locationPostalCity !== ''): ?>
                                            <p class="agenda-card__venue-address">⌖ <?= $escape(implode(', ', array_filter([$locationAddressLine, $locationPostalCity]))) ?></p>
                                        <?php endif; ?>
                                        <?php if ($locationPhone !== ''): ?>
                                            <p class="agenda-card__venue-phone">☎ <a href="tel:<?= $escape(preg_replace('/[^0-9+*#]/', '', $locationPhone)) ?>"><?= $escape($locationPhone) ?></a></p>
                                        <?php endif; ?>
                                        <?php if ($organizerName !== '' || $organizerPhone !== ''): ?>
                                            <h3 class="agenda-card__section-label agenda-card__section-label--organizer">ORGANISER</h3>
                                            <div class="agenda-card__organizer">
                                                <?php if ($hasOrganizerLogo): ?>
                                                    <img class="agenda-card__organizer-logo" src="/organizer-logo.php?file=<?= rawurlencode($organizerLogo) ?>" alt="<?= $escape($organizerName) ?>" loading="lazy">
                                                <?php endif; ?>
                                                <div class="agenda-card__organizer-info">
                                                    <?php if ($organizerName !== ''): ?><strong><?= $escape($organizerName) ?></strong><?php endif; ?>
                                                    <?php if ($organizerPhone !== ''): ?><span>☎ <a href="tel:<?= $escape(preg_replace('/[^0-9+*#]/', '', $organizerPhone)) ?>"><?= $escape($organizerPhone) ?></a></span><?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($eventDescription !== ''): ?>
                                            <div class="agenda-card__description-text"><?= nl2br($escape($eventDescription)) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php
                            $displayTags = array_map(static fn(string $tag): string => '#' . $tag, $hashtagItems);
                            if ($locationLabel !== '') {
                                $displayTags[] = '#' . $locationLabel;
                            }
                            ?>
                            <?php if ($displayTags !== []): ?>
                                <p class="agenda-card__hashtags"><?= $escape(implode(' ', $displayTags)) ?></p>
                            <?php endif; ?>
                            <p class="agenda-card__swipe-hint" data-swipe-hint>→ Swipe voor meer info</p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="agenda-count"><?= count($events) ?> evenement<?= count($events) === 1 ? '' : 'en' ?> gevonden</p>
    <?php endif; ?>
    <div class="agenda-message-modal" id="agenda-message-modal" hidden>
        <div class="agenda-message-modal__panel" role="dialog" aria-modal="true" aria-labelledby="agenda-message-modal-title">
            <div class="agenda-message-modal__top"><button type="button" class="agenda-message-modal__close" aria-label="Sluiten">×</button></div>
            <iframe title="Berichten bij evenement" src="about:blank" scrolling="no"></iframe>
        </div>
    </div>

</section>

<style>
.agenda-page{width:100%;max-width:1080px;margin:-26px auto 0}.agenda-selectors{margin:0 0 14px;padding:6px 8px;background:var(--db-filter-bar-color,#FFFFFF);border:0;border-radius:0}.agenda-selector-line{display:flex;align-items:center;gap:12px}.agenda-selector-button{align-self:flex-start;margin-top:8px}.agenda-selector-button{flex:0 0 62px;width:62px;height:62px;min-width:62px;min-height:62px;box-sizing:border-box;display:grid;place-items:center;padding:6px;border:1px solid var(--db-border);border-radius:15px;background:linear-gradient(145deg,var(--db-primary),color-mix(in srgb,var(--db-primary) 72%,#000));box-shadow:0 3px 9px #1720331c;color:#fff;font-weight:750;cursor:pointer}.agenda-filter-icon{font-size:23px;line-height:1}.agenda-selector-panel{display:flex;align-items:center;flex:1;min-width:0}.agenda-selector-panel.has-more::after{content:'›';position:absolute;right:0;top:8px;z-index:2;display:grid;place-items:center;width:30px;height:62px;background:linear-gradient(90deg,#ffffff00 0%,#fff 38%);color:var(--db-primary);font-size:32px;font-weight:800;line-height:1;pointer-events:none;text-shadow:0 1px 2px #fff}.agenda-selector-panel.has-prev::before{content:'‹';position:absolute;left:0;top:8px;z-index:2;display:grid;place-items:center;width:30px;height:62px;background:linear-gradient(270deg,#ffffff00 0%,#fff 38%);color:var(--db-primary);font-size:32px;font-weight:800;line-height:1;pointer-events:none;text-shadow:0 1px 2px #fff}.agenda-selector-panel{position:relative}.agenda-selector-panel[hidden]{display:none}.agenda-selector-panel select{width:100%;min-width:0;height:44px;padding:8px 11px;border:1px solid var(--db-border);border-radius:7px;background:#fff;color:var(--db-text)}.agenda-organizer-strip{display:flex;align-items:flex-start;gap:13px;width:100%;overflow-x:auto;overflow-y:hidden;padding:3px 2px 10px;scroll-snap-type:x proximity;-webkit-overflow-scrolling:touch;scrollbar-width:none}.agenda-organizer-strip::-webkit-scrollbar{display:none}.agenda-organizer-item{flex:0 0 82px;display:flex;flex-direction:column;align-items:center;gap:6px;min-width:0;padding:5px 4px;border:0;border-radius:10px;background:transparent;color:var(--db-filter-text-color,#222222);text-decoration:none;text-align:center;scroll-snap-align:start}.agenda-organizer-item.is-selected{background:color-mix(in srgb,var(--db-primary) 8%,white)}.agenda-organizer-item--organizer{background:color-mix(in srgb,var(--db-organizer-color,#E23A2C) 13%,var(--db-filter-bar-color,#FFFFFF))}.agenda-organizer-item--organizer.is-selected{background:color-mix(in srgb,var(--db-organizer-color,#E23A2C) 24%,var(--db-filter-bar-color,#FFFFFF))}.agenda-organizer-item img,.agenda-organizer-fallback,.agenda-organizer-all{display:grid;place-items:center;width:62px;height:62px;flex:0 0 62px;border-radius:50%;object-fit:contain;background:#fff;border:1px solid var(--db-border)}.agenda-organizer-fallback,.agenda-organizer-all{font-size:24px;font-weight:800;color:var(--db-filter-text-color,#222222);background:transparent;border:0}.agenda-organizer-all{font-size:15px}.agenda-organizer-name{display:block;width:100%;overflow:hidden;font-size:11px;font-weight:650;line-height:1.25;text-overflow:ellipsis;white-space:nowrap}.agenda-selector-actions{display:flex;align-items:center;gap:15px;margin-top:12px}.agenda-heading{display:flex;align-items:center;justify-content:space-between;gap:20px;margin:0 0 20px}.agenda-heading h1{margin:0 0 4px}.agenda-heading p:not(.eyebrow){margin:0;color:var(--db-muted)}.agenda-month-select{display:flex;align-items:center;gap:9px;color:var(--db-muted);font-size:12px;font-weight:700}.agenda-month-select input{padding:9px;border:1px solid var(--db-border);border-radius:8px;background:#fff;color:var(--db-text)}.agenda-filters{display:flex;align-items:end;flex-wrap:wrap;gap:10px;margin-bottom:20px;padding:13px;background:#fff;border:1px solid var(--db-border);border-radius:10px}.agenda-filters label{display:flex;flex-direction:column;gap:4px;min-width:155px;color:var(--db-muted);font-size:11px;font-weight:700}.agenda-filters select,.agenda-filters input{height:40px;min-width:0;padding:7px 10px;border:1px solid var(--db-border);border-radius:7px;background:#fff;color:var(--db-text)}.agenda-filters .agenda-search{flex:1}.agenda-filters .agenda-search input{width:100%}.agenda-reset,.agenda-today{font-size:12px;font-weight:700;padding:9px 5px}.agenda-toolbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin:0 0 15px}.agenda-month-nav{display:flex;align-items:center;gap:9px}.agenda-month-nav h2{margin:0;min-width:140px;text-align:center;font-size:18px}.agenda-month-nav .button{min-width:36px;padding:6px;font-size:20px}.agenda-view-switch{display:flex;padding:3px;background:#eceff2;border-radius:8px}.agenda-view-switch a{padding:6px 11px;border-radius:6px;color:var(--db-muted);font-size:12px;font-weight:700}.agenda-view-switch a.is-active{background:#fff;color:var(--db-text);box-shadow:0 1px 4px #0001}.agenda-feed{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:20px;max-width:1200px;margin:0 auto}.agenda-card{overflow:hidden;border:1px solid #65c99b;border-radius:15px;background:#f78d19;box-shadow:0 4px 18px #0000000d}.agenda-card__image-link{display:block;flex:0 0 100%;overflow:hidden;background:transparent;aspect-ratio:auto;scroll-snap-align:start}.agenda-card__image{display:block;width:100%;height:auto;object-fit:contain;background:transparent}.agenda-card__gallery{position:relative;overflow:hidden;background:transparent}.agenda-card__media-slide{display:block;flex:0 0 100%;width:100%;min-width:0;box-sizing:border-box;height:auto;aspect-ratio:auto;scroll-snap-align:start;scroll-snap-stop:always;background:transparent}.agenda-card__media-slide .agenda-card__image-link{width:100%;height:auto;aspect-ratio:auto}.agenda-card__video{display:block;width:100%;height:100%;min-height:230px;aspect-ratio:4/3;object-fit:contain;background:#171717}.agenda-card__external-media{display:flex;width:100%;height:100%;min-height:230px;aspect-ratio:4/3;flex-direction:column;align-items:center;justify-content:center;gap:12px;background:radial-gradient(circle at 25% 20%,#f4802a 0,transparent 38%),linear-gradient(135deg,#202b38,#35475b);color:#fff;text-decoration:none}.agenda-card__external-media span{display:grid;place-items:center;width:64px;height:64px;border:2px solid #fff;border-radius:50%;font-size:26px}.agenda-card__external-media strong{font-size:16px}.agenda-card__media-slide--instagram{aspect-ratio:auto;min-height:500px;background:#fff}.agenda-card__instagram{width:100%;min-height:500px;display:flex;justify-content:center;overflow:hidden;background:#fff}.agenda-card__instagram .instagram-media{width:calc(100% - 2px)!important;max-width:540px!important;min-width:0!important;margin:0 auto!important}.agenda-card__gallery-track{display:flex;overflow-x:auto;overflow-y:hidden;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none}.agenda-card__gallery-track::-webkit-scrollbar{display:none}.agenda-card__media-count{position:absolute;right:12px;bottom:12px;padding:5px 9px;border-radius:20px;background:#000b;color:#fff;font-size:12px;font-weight:750;pointer-events:none}.agenda-card__placeholder{display:flex;width:100%;height:100%;min-height:230px;flex-direction:column;align-items:center;justify-content:center;gap:13px;padding:25px;text-align:center;color:#fff;background:radial-gradient(circle at 25% 20%,#f4802a 0,transparent 38%),linear-gradient(135deg,#202b38,#35475b)}.agenda-card__placeholder span{font-size:11px;letter-spacing:2px;color:#ffd36c;font-weight:800}.agenda-card__placeholder strong{font-size:clamp(20px,3vw,30px);line-height:1.15}.agenda-card__media-wrap{position:relative;overflow:hidden;background:#171717}.agenda-card__details{padding:17px 20px 20px;color:#fff;touch-action:pan-y}.agenda-card__details{touch-action:pan-y}.agenda-card__description{max-height:0;overflow:hidden;margin:0;color:#fff;opacity:0;transition:max-height .3s ease,opacity .2s ease,margin .3s ease}.agenda-card__description.is-visible{max-height:2000px;margin:10px 0 14px;opacity:1}.agenda-card__description-inner{font-size:14px;line-height:1.55;overflow-wrap:anywhere}.agenda-card__venue{display:flex;align-items:center;gap:12px;margin:0 0 9px}.agenda-card__venue-logo{display:block;width:58px;height:58px;flex:0 0 58px;object-fit:contain;background:#fff;border-radius:7px;padding:4px}.agenda-card__venue strong{font-size:16px;font-weight:750}.agenda-card__venue-address,.agenda-card__venue-phone{margin:5px 0;font-size:13px;line-height:1.5}.agenda-card__venue-phone a{color:inherit;text-decoration:underline}.agenda-card__section-label{margin:0 0 8px;font-size:11px;font-weight:800;letter-spacing:.09em;line-height:1.2;text-transform:uppercase;opacity:.78}
.agenda-card__section-label--organizer{margin-top:16px;padding-top:12px;border-top:1px solid rgba(255,255,255,.22)}
.agenda-card__organizer{display:flex;align-items:center;gap:12px;margin:14px 0 0;padding-top:12px;border-top:1px solid rgba(255,255,255,.22)}.agenda-card__organizer-logo{display:block;width:58px;height:58px;flex:0 0 58px;object-fit:contain;background:#fff;border-radius:7px;padding:4px}.agenda-card__organizer-info{display:flex;flex-direction:column;gap:5px}.agenda-card__organizer-info strong{font-size:15px;font-weight:750}.agenda-card__organizer-info span{font-size:13px}.agenda-card__organizer-info a{color:inherit;text-decoration:underline}.agenda-card__description-text{margin-top:12px;font-size:14px;line-height:1.55;overflow-wrap:anywhere}.agenda-card__hashtags{display:flex;flex-wrap:wrap;gap:9px;margin:4px 0 0;color:#fff;font-size:14px;font-weight:700;overflow-wrap:anywhere}.agenda-card__description-inner{font-size:14px;line-height:1.55;overflow-wrap:anywhere}.agenda-card__quick{display:flex;align-items:center;gap:17px;margin:0 0 13px}.agenda-card__quick-date{min-width:34px;color:#fff;font-size:22px;font-weight:800}.agenda-attendance{display:inline-flex;align-items:center;gap:6px;min-height:38px;padding:5px 0;border:0;border-radius:0;background:transparent;color:#fff;font:inherit;font-size:12px;font-weight:750;cursor:pointer;touch-action:pan-y;user-select:none;-webkit-user-select:none}.agenda-attendance span:first-child{font-size:19px}.agenda-attendance b{display:inline-block;min-width:10px;height:auto;padding:0;border-radius:0;background:transparent;color:inherit;font-size:12px;line-height:1;font-weight:700;text-align:left}.agenda-attendance.is-attending{background:transparent;color:#fff}.agenda-attendance.is-attending b{background:transparent;color:inherit}.agenda-attendance-list{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;padding:18px;background:#0009}.agenda-attendance-list[hidden]{display:none}.agenda-attendance-list__panel{width:min(100%,380px);max-height:75vh;overflow:auto;padding:20px;border-radius:14px;background:#fff;color:#222;box-shadow:0 12px 40px #0004}.agenda-attendance-list__panel h3{margin:0 0 12px;font-size:18px}.agenda-attendance-list__panel ul{margin:0;padding-left:20px}.agenda-attendance-list__close{float:right;border:0;background:none;font-size:24px;cursor:pointer}.agenda-icon-button{width:40px;height:40px;display:grid;place-items:center;border:0;border-radius:50%;background:transparent;color:#fff;font-size:31px;line-height:1;cursor:pointer}.agenda-icon-button:hover{background:#ffffff26;color:#fff}.agenda-messages{position:relative;display:flex;flex-direction:row;align-items:center;justify-content:center;gap:5px;width:auto;min-width:40px;padding:0 6px;text-decoration:none}.agenda-messages svg{display:block;flex:none}.agenda-message-count{display:inline-block;font-size:12px;line-height:1;font-weight:700;min-width:10px;text-align:left}.agenda-card__details h2{margin:0 0 7px;color:#fff;font-size:22px}.agenda-card__details h2 a{color:inherit}.agenda-card__tags{display:flex;flex-wrap:wrap;gap:16px;margin:0 0 10px;color:#fff;font-size:15px}.agenda-card__date-line{margin:0 0 7px;color:#fff;font-size:14px;font-weight:600}.agenda-card__date-line span{margin:0 8px}.agenda-card__location{margin:0 0 7px;color:#fff;font-size:13px}.agenda-card__more{display:inline-block;color:#fff;text-decoration:underline;text-underline-offset:3px;font-size:14px;font-weight:600}.agenda-card__more:hover{color:#fff}.agenda-count{margin:15px 2px;color:var(--db-muted);font-size:12px}.agenda-empty{padding:38px 24px;text-align:center;background:#fff;border:1px solid var(--db-border);border-radius:12px}.agenda-empty p{color:var(--db-muted);margin:0}.agenda-calendar{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));overflow:hidden;border:1px solid var(--db-border);border-radius:12px;background:#fff}.agenda-calendar__weekday{padding:10px;text-align:center;background:#f2f5f8;color:var(--db-muted);font-size:11px;font-weight:750;text-transform:uppercase}.agenda-calendar__day{min-height:125px;padding:8px;border-top:1px solid var(--db-border);border-right:1px solid var(--db-border);min-width:0}.agenda-calendar__day:nth-child(7n){border-right:0}.agenda-calendar__day.is-outside{background:#fafbfc;color:#aab2bd}.agenda-calendar__day.is-today{background:#fffaf0}.agenda-calendar__number{display:block;margin-bottom:5px;font-size:12px;font-weight:750}.agenda-calendar__event{display:block;margin:4px 0;padding:4px 5px;overflow:hidden;border-radius:5px;background:#eaf1fa;color:#174c83;font-size:11px;line-height:1.35;overflow-wrap:anywhere}.agenda-calendar__event time{font-weight:800;margin-right:3px}
@media(max-width:700px){.agenda-feed{grid-template-columns:minmax(0,1fr)}.agenda-page{margin-top:-18px}.agenda-heading{align-items:flex-start;flex-direction:column}.agenda-selector-line{align-items:center;flex-direction:row;gap:8px}.agenda-selector-button{flex:0 0 58px;width:58px;height:58px;min-width:58px;min-height:58px}.agenda-selector-panel{width:auto;flex:1;min-width:0}.agenda-organizer-strip{gap:9px}.agenda-organizer-item{flex-basis:76px}.agenda-organizer-item img,.agenda-organizer-fallback,.agenda-organizer-all{width:58px;height:58px;flex-basis:58px}.agenda-selector-actions{align-items:flex-start;flex-direction:column}.agenda-toolbar{align-items:flex-start;flex-direction:column}.agenda-calendar__day{min-height:95px;padding:4px}.agenda-calendar__weekday{padding:7px 2px;font-size:9px}.agenda-calendar__event{font-size:9px;padding:3px}.agenda-card__image-link{aspect-ratio:auto}.agenda-card__details{padding:15px 17px 18px}}
@media(max-width:420px){.agenda-month-nav{width:100%;gap:6px}.agenda-month-nav h2{flex:1;min-width:0;font-size:17px}.agenda-month-nav .button{min-width:34px}.agenda-calendar__day{min-height:82px}.agenda-calendar__event time{display:none}}

/* Alleen de logo-afbeeldingen van organisatoren: afgeronde vierkanten met zachte kleurschakering. */
.agenda-organizer-item img.agenda-organizer-logo{
    display:block;
    width:62px;
    height:62px;
    flex:0 0 62px;
    box-sizing:border-box;
    padding:5px;
    object-fit:contain;
    border:1px solid #d8dee8;
    border-radius:15px;
    background:linear-gradient(145deg,#ffffff 0%,#f4f6fa 52%,#e7edf4 100%);
    box-shadow:0 3px 9px #1720331c, inset 0 1px 0 #ffffff;
    transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease;
}
.agenda-organizer-item:hover img.agenda-organizer-logo{
    transform:translateY(-2px);
    border-color:var(--db-primary);
    box-shadow:0 5px 13px #17203329, inset 0 1px 0 #ffffff;
}
@media(max-width:700px){
    .agenda-organizer-item img.agenda-organizer-logo{
        width:58px;
        height:58px;
        flex-basis:58px;
    }
}

/* Locatieknoppen en organisator-fallbacks krijgen dezelfde afgeronde vierkante vorm. */
.agenda-organizer-item .agenda-organizer-fallback{
    width:62px;
    height:62px;
    flex:0 0 62px;
    box-sizing:border-box;
    border:1px solid #d8dee8;
    border-radius:15px;
    background:linear-gradient(145deg,#ffffff 0%,#f4f6fa 52%,#e7edf4 100%);
    box-shadow:0 3px 9px #1720331c, inset 0 1px 0 #ffffff;
    color:var(--db-primary);
    transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease;
}
.agenda-organizer-item:hover .agenda-organizer-fallback{
    transform:translateY(-2px);
    border-color:var(--db-primary);
    box-shadow:0 5px 13px #17203329, inset 0 1px 0 #ffffff;
}

/* De wisselknop gebruikt exact dezelfde maat, vorm en achtergrondstijl. */
.agenda-selector-button{
    border:1px solid #d8dee8;
    border-radius:15px;
    background:linear-gradient(145deg,#ffffff 0%,#f4f6fa 52%,#e7edf4 100%);
    box-shadow:0 3px 9px #1720331c, inset 0 1px 0 #ffffff;
    color:var(--db-primary);
    transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease;
}
.agenda-selector-button:hover{
    transform:translateY(-2px);
    border-color:var(--db-primary);
    box-shadow:0 5px 13px #17203329, inset 0 1px 0 #ffffff;
}
@media(max-width:700px){
    .agenda-organizer-item .agenda-organizer-fallback{
        width:58px;
        height:58px;
        flex-basis:58px;
    }
}

.agenda-message-modal{position:fixed;inset:0;z-index:11000;display:flex;align-items:center;justify-content:center;padding:14px;background:#0009}.agenda-message-modal[hidden]{display:none}.agenda-message-modal__panel{position:relative;display:flex;flex-direction:column;width:min(100%,680px);height:min(82vh,820px);overflow:hidden;border-radius:18px;background:#fff;box-shadow:0 18px 60px #0005}.agenda-message-modal__top{position:absolute;top:5px;right:5px;z-index:2;display:flex;align-items:center;justify-content:flex-end;padding:0;background:transparent;pointer-events:none}.agenda-message-modal__close{display:grid;place-items:center;width:36px;height:36px;border:0;border-radius:0;background:transparent;color:#222;font-size:30px;font-weight:400;line-height:1;cursor:pointer;pointer-events:auto;box-shadow:none}.agenda-message-modal__panel iframe{display:block;flex:1 0 auto;width:calc(100% + 28px);max-width:none;min-height:0;margin-right:-28px;border:0;background:#fff}.agenda-messages{border:0}.agenda-messages:focus-visible,.agenda-message-modal__close:focus-visible{outline:3px solid #ffd36c;outline-offset:2px}@media(max-width:600px){.agenda-message-modal{padding:8px}.agenda-message-modal__panel{width:100%;height:88vh;border-radius:14px}}
.agenda-card{overflow:visible}
.agenda-card__media-wrap{border-radius:14px 14px 0 0}
.agenda-card__details{border-radius:0 0 14px 14px}
.agenda-card__quick{position:sticky;top:0;z-index:8;padding:5px 0;background:#f78d19;box-shadow:0 4px 8px -8px #0009}
/* Houd de organisator- en stedenfilters beschikbaar tijdens het scrollen. */
.agenda-selectors{
    position:sticky;
    top:0;
    z-index:950;
    margin-bottom:2px;
    padding:0;
    border:0;
    border-radius:0;
    background:transparent;
    box-shadow:none;
}

/* Laat de filterbalk direct aansluiten op de header. */
.agenda-page{margin-top:-42px}
@media(max-width:700px){.agenda-page{margin-top:-28px}}


/* Compactere organisator- en stadskeuzes. */
.agenda-organizer-strip{gap:10px}
.agenda-organizer-item{
    flex-basis:76px;
    padding:3px 2px;
    border-radius:7px;
}
.agenda-organizer-item img,.agenda-organizer-fallback,.agenda-organizer-all{
    width:58px;
    height:58px;
    flex-basis:58px;
}
.agenda-organizer-item img.agenda-organizer-logo{
    width:58px;
    height:58px;
    flex-basis:58px;
    padding:4px;
    border-radius:10px;
    box-shadow:0 2px 6px #17203315, inset 0 1px 0 #ffffff;
}
.agenda-organizer-item .agenda-organizer-fallback{
    width:58px;
    height:58px;
    flex-basis:58px;
    border-radius:10px;
    box-shadow:0 2px 6px #17203315, inset 0 1px 0 #ffffff;
}
.agenda-selector-button{border-radius:10px}
@media(max-width:700px){
    .agenda-organizer-item{flex-basis:70px}
    .agenda-organizer-strip{gap:8px}
}

/* Laat de sticky filterrij op mobiel onder de vaste header staan. */
@media(max-width:760px){
    .agenda-selectors{top:67px}
}

/* Lage, rechte achtergrond over de volledige breedte van de filterrij. */
.agenda-selector-line{
    box-sizing:border-box;
    width:100%;
    padding:0 5px;
    border-radius:0;
    background:linear-gradient(90deg,#f3f5f8 0%,#fafbfc 100%);
}
.agenda-selector-button{margin-top:2px}
.agenda-organizer-strip{padding-top:0;padding-bottom:3px}
.agenda-organizer-item{padding-top:1px;padding-bottom:1px;gap:3px}

/* Compacte achtergrond rond elke organisator- en stadsknop. */
.agenda-organizer-item{
    box-sizing:border-box;
    flex-basis:66px;
    padding:3px;
    border:1px solid #d8dee8;
    border-radius:9px;
    background:linear-gradient(145deg,#fff 0%,#f3f5f8 100%);
    box-shadow:0 2px 5px #17203312;
}
.agenda-organizer-item.is-selected{
    border-color:var(--db-primary);
    background:color-mix(in srgb,var(--db-primary) 9%,white);
    box-shadow:0 2px 6px #1720331c;
}
.agenda-organizer-name{font-size:10px}
@media(max-width:700px){
    .agenda-organizer-item{flex-basis:66px}
}

/* Regio-instellingen blijven leidend voor de volledige filterbalk. */
.agenda-selector-line{background:var(--db-filter-bar-color,#FFFFFF) !important}
.agenda-organizer-item{border:0 !important;box-shadow:none !important;background:transparent !important}
.agenda-organizer-item--organizer{background:color-mix(in srgb,var(--db-organizer-color,#E23A2C) 13%,var(--db-filter-bar-color,#FFFFFF)) !important}
.agenda-organizer-item--organizer.is-selected{background:color-mix(in srgb,var(--db-organizer-color,#E23A2C) 24%,var(--db-filter-bar-color,#FFFFFF)) !important}
.agenda-organizer-item.is-selected:not(.agenda-organizer-item--organizer){background:color-mix(in srgb,var(--db-primary) 9%,var(--db-filter-bar-color,#FFFFFF)) !important}
.agenda-organizer-item .agenda-organizer-fallback{border:0 !important;box-shadow:none !important;background:transparent !important}

/* Agenda: geen decoratieve witte lijnen of kaders rond de onderdelen. */
.agenda-card,.agenda-calendar,.agenda-filters,.agenda-empty{border:0!important;}
.agenda-calendar__day{border:0!important;}
.agenda-selector-button,.agenda-selector-panel select,.agenda-organizer-item img.agenda-organizer-logo{border:0!important;box-shadow:none!important;}
.agenda-organizer-item img.agenda-organizer-logo{background:transparent!important;}

/* Filterbalk sluit zonder opening direct aan op de header. */
body.has-agenda-footer .site-header{margin-bottom:-10px!important;padding-bottom:0!important}
body.has-agenda-footer .site-main{padding-top:0!important;margin-top:0!important}
body.has-agenda-footer .site-main > .agenda-page{margin-top:0!important;padding-top:0!important}
body.has-agenda-footer .agenda-selectors{margin-top:0!important}



/* Eventinformatie gebruikt de ingestelde kleur van de betreffende weekdag. */
.agenda-card{background:var(--db-event-day-color,var(--db-primary))!important;border:0!important}
.agenda-card__details,
.agenda-card__quick{
    background:var(--db-event-day-color,var(--db-primary))!important;
    color:var(--db-text)!important;
}
.agenda-card__details,
.agenda-card__details *{
    color:var(--db-text)!important;
}
.agenda-card__details h2,
.agenda-card__details h2 a,
.agenda-card__tags,
.agenda-card__date-line,
.agenda-card__location,
.agenda-card__more,
.agenda-card__quick-date,
.agenda-attendance,
.agenda-icon-button,
.agenda-message-count{
    color:var(--db-text)!important;
}
.agenda-card__more{text-decoration-color:var(--db-text)!important}
.agenda-card__quick{box-shadow:none!important}


/* De filterbalk maakt nu deel uit van de header, ook op smartphones. */
.site-header__agenda-filters{width:min(100% - 40px,1180px);margin:0 auto;padding:0}
.site-header__agenda-filters .agenda-selectors{position:static!important;top:auto!important;margin:0!important;padding:0!important}
body.has-agenda-footer .site-header{margin:0!important;padding:0!important}
body.has-agenda-footer .site-main{padding-top:0!important;margin-top:0!important}
body.has-agenda-footer .site-main > .agenda-page{margin:0 auto!important;padding-top:0!important}
@media(max-width:760px){
 .site-header__agenda-filters{width:100%;padding:0 10px}
 .site-header__agenda-filters .agenda-selector-line{margin:0}
 .site-header__agenda-filters .agenda-organizer-strip{padding-bottom:2px}
}

/* Compacte evenementinformatie: titel, categorie en locatie, datum, hint en hashtags. */
.agenda-card__tags-location{
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:6px 16px;
    margin:0 0 7px;
}
.agenda-card__tags-location .agenda-card__tags{
    display:inline-flex;
    margin:0;
    font-size:13px;
    font-weight:700;
}
.agenda-card__tags-location .agenda-card__location{
    display:inline-flex;
    align-items:center;
    gap:5px;
    margin:0;
    font-size:13px;
}
.agenda-card__location-icon{font-size:13px;line-height:1}
.agenda-card__date-line{margin:0 0 5px!important}
.agenda-card__swipe-hint{
    margin:2px 0 5px;
    font-size:11px;
    font-weight:600;
    opacity:.78;
}
.agenda-card__hashtags{
    margin:5px 0 0!important;
    font-size:12px!important;
    font-weight:650!important;
}

/* De swipe-aanwijzing blijft altijd zichtbaar op de evenementkaart. */
.agenda-card__swipe-hint{
    display:block!important;
    visibility:visible!important;
    margin:3px 0 5px!important;
    color:var(--db-text)!important;
    font-size:12px!important;
    font-weight:700!important;
    line-height:1.4;
    opacity:1!important;
}
</style>
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
const agendaFilterIcons = ['👥', '🗺️'];
const agendaFilterLabels = ['Organisator', 'Stad'];
let agendaFilterIndex = <?= $activeFilter === 'city' ? '1' : '0' ?>;
const agendaFilterSwitch = document.getElementById('agenda-filter-switch');
function updateAgendaStripIndicators() {
    document.querySelectorAll('.agenda-selector-panel').forEach(function (panel) {
        const strip = panel.querySelector('.agenda-organizer-strip');
        if (!strip || panel.hidden) {
            panel.classList.remove('has-more', 'has-prev');
            return;
        }
        const hasMore = strip.scrollWidth > strip.clientWidth + 2
            && strip.scrollLeft + strip.clientWidth < strip.scrollWidth - 2;
        const hasPrev = strip.scrollLeft > 2;
        panel.classList.toggle('has-more', hasMore);
        panel.classList.toggle('has-prev', hasPrev);
    });
}
document.querySelectorAll('.agenda-organizer-strip').forEach(function (strip) {
    strip.addEventListener('scroll', updateAgendaStripIndicators, { passive: true });
});
window.addEventListener('resize', updateAgendaStripIndicators);
function showAgendaFilter(index) {
    agendaFilterIndex = index % agendaFilterIcons.length;
    if (!agendaFilterSwitch) return;
    agendaFilterSwitch.innerHTML = '<span class="agenda-filter-icon" aria-hidden="true">' + agendaFilterIcons[agendaFilterIndex] + '</span>';
    agendaFilterSwitch.setAttribute('aria-label', 'Filter: ' + agendaFilterLabels[agendaFilterIndex] + '. Klik om te wisselen');
    agendaFilterSwitch.setAttribute('title', 'Filter: ' + agendaFilterLabels[agendaFilterIndex] + ' — klik om te wisselen');
    document.querySelectorAll('[data-panel]').forEach(function (panel) {
        const active = panel.dataset.panel === ['organizer', 'city'][agendaFilterIndex];
        panel.hidden = !active;
        panel.classList.toggle('is-open', active);
        const select = panel.querySelector('select');
        if (select) select.disabled = !active;
    });
    updateAgendaStripIndicators();
}
if (agendaFilterSwitch) {
    agendaFilterSwitch.addEventListener('click', function () {
        showAgendaFilter(agendaFilterIndex + 1);
    });
    showAgendaFilter(agendaFilterIndex);
}
// Swipe over de evenementinformatie: rechts toont de uitleg, links verbergt ze.
function setEventDescription(info, show) {
    const description = info ? info.querySelector('[data-event-description]') : null;
    if (!description) return;
    description.classList.toggle('is-visible', show);
    description.setAttribute('aria-hidden', show ? 'false' : 'true');
    const hint = info.querySelector('[data-swipe-hint]');
    if (hint) hint.textContent = show
        ? '← Swipe voor minder info'
        : '→ Swipe voor meer info';
}
document.querySelectorAll('[data-event-info]').forEach(function (info) {
    if (!info.querySelector('[data-event-description]')) return;
    let start = null;
    info.addEventListener('touchstart', function (event) {
        if (!event.changedTouches.length) return;
        const touch = event.changedTouches[0];
        start = {x: touch.clientX, y: touch.clientY};
    }, {passive:true});
    info.addEventListener('touchend', function (event) {
        if (!start || !event.changedTouches.length) return;
        const touch = event.changedTouches[0];
        const dx = touch.clientX - start.x;
        const dy = touch.clientY - start.y;
        start = null;
        if (Math.abs(dx) < 45 || Math.abs(dx) < Math.abs(dy) * 1.25) return;
        setEventDescription(info, dx > 0);
    }, {passive:true});
    info.addEventListener('touchcancel', function () { start = null; }, {passive:true});
});
let eventInfoPointerStart = null;
document.addEventListener('pointerdown', function (event) {
    if (event.pointerType === 'touch') return;
    const info = event.target.closest('[data-event-info]');
    if (!info || !info.querySelector('[data-event-description]')) return;
    eventInfoPointerStart = {info:info, pointerId:event.pointerId, x:event.clientX, y:event.clientY};
}, {passive:true});
document.addEventListener('pointerup', function (event) {
    if (!eventInfoPointerStart || event.pointerId !== eventInfoPointerStart.pointerId) return;
    const start = eventInfoPointerStart;
    eventInfoPointerStart = null;
    const dx = event.clientX - start.x;
    const dy = event.clientY - start.y;
    if (Math.abs(dx) < 45 || Math.abs(dx) < Math.abs(dy) * 1.25) return;
    setEventDescription(start.info, dx > 0);
}, {passive:true});
document.addEventListener('pointercancel', function () { eventInfoPointerStart = null; }, {passive:true});

document.querySelectorAll('[data-gallery]').forEach(function (gallery) {
    const track = gallery.querySelector('[data-gallery-track]');
    const counter = gallery.querySelector('[data-gallery-count]');
    if (!track || !counter) return;
    const slides = Array.from(track.querySelectorAll('.agenda-card__media-slide'));
    const updateCounter = function () {
        if (!slides.length) return;
        const slideWidth = track.clientWidth || 1;
        const currentIndex = Math.min(slides.length, Math.max(1, Math.round(track.scrollLeft / slideWidth) + 1));
        counter.textContent = currentIndex + ' / ' + slides.length;
    };
    track.addEventListener('scroll', updateCounter, { passive: true });
    window.addEventListener('resize', updateCounter);
    updateCounter();
});
const messageModal = document.getElementById('agenda-message-modal');
if (messageModal) {
    const messageFrame = messageModal.querySelector('iframe');
    const closeMessageModal = function () {
        messageModal.hidden = true;
        messageFrame.src = 'about:blank';
        document.documentElement.style.overflow = '';
        document.body.style.overflow = '';
    };
    messageModal.querySelector('.agenda-message-modal__close').addEventListener('click', closeMessageModal);
    messageModal.addEventListener('click', function (event) {
        if (event.target === messageModal) closeMessageModal();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !messageModal.hidden) closeMessageModal();
    });
    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-event-messages]');
        if (!button) return;
        event.preventDefault();
        const eventCard = button.closest('.agenda-card');
        const eventTitle = eventCard?.querySelector('.agenda-card__details h2 a')?.textContent.trim() || 'Evenement';
        const count = Number(button.querySelector('.agenda-message-count')?.textContent || 0);
        const titleText = eventTitle + ' · ' + count + ' ' + (count === 1 ? 'bericht' : 'berichten');
        const modalTitle = document.getElementById('agenda-message-modal-title');
        if (modalTitle) modalTitle.textContent = titleText;
        messageFrame.title = titleText;
        messageFrame.src = button.dataset.url + '&popup=1';
        messageModal.hidden = false;
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';
    });
}
const attendanceDialog = document.createElement('div');
attendanceDialog.className = 'agenda-attendance-list';
attendanceDialog.hidden = true;
attendanceDialog.innerHTML = '<div class="agenda-attendance-list__panel" role="dialog" aria-modal="true" aria-labelledby="attendance-list-title"><button type="button" class="agenda-attendance-list__close" aria-label="Sluiten">×</button><h3 id="attendance-list-title">Wie komt dansen?</h3><div class="agenda-attendance-list__content">Laden…</div></div>';
document.body.appendChild(attendanceDialog);
attendanceDialog.addEventListener('click', function (event) {
    if (event.target === attendanceDialog || event.target.closest('.agenda-attendance-list__close')) attendanceDialog.hidden = true;
});
function escapeAttendanceText(value) {
    return String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
}
let attendanceLongPressTimer = null;
let attendanceLongPressTriggered = false;
let attendancePointerButton = null;

document.addEventListener('pointerdown', function (event) {
    const button = event.target.closest('.agenda-attendance');
    if (!button) return;
    attendancePointerButton = button;
    attendanceLongPressTriggered = false;
    clearTimeout(attendanceLongPressTimer);
    attendanceLongPressTimer = window.setTimeout(function () {
        attendanceLongPressTriggered = true;
        const eventId = button.dataset.eventId;
        fetch('/event-attendance.php?action=names&event_id=' + encodeURIComponent(eventId), {credentials:'same-origin'})
            .then(response => response.json()).then(data => {
                if (!data.ok) throw new Error(data.message || 'De deelnemerslijst kon niet worden geladen.');
                const content = attendanceDialog.querySelector('.agenda-attendance-list__content');
                const names = Array.isArray(data.names) ? data.names : [];
                content.innerHTML = names.length ? '<ul>' + names.map(name => '<li>' + escapeAttendanceText(name) + '</li>').join('') + '</ul>' : '<p>Er zijn nog geen ingelogde deelnemers.</p>';
                if (data.guests > 0) content.innerHTML += '<p>' + data.guests + ' anonieme deelnemer' + (data.guests === 1 ? '' : 's') + '</p>';
                attendanceDialog.hidden = false;
            }).catch(error => {
                attendanceDialog.querySelector('.agenda-attendance-list__content').textContent = error.message || 'De deelnemerslijst kon niet worden geladen.';
                attendanceDialog.hidden = false;
            });
    }, 650);
});

function cancelAttendanceLongPress() {
    clearTimeout(attendanceLongPressTimer);
    attendanceLongPressTimer = null;
    attendancePointerButton = null;
}
['pointerup','pointercancel','pointerleave'].forEach(type => {
    document.addEventListener(type, function (event) {
        if (attendancePointerButton && (event.target === attendancePointerButton || attendancePointerButton.contains(event.target))) {
            cancelAttendanceLongPress();
        }
    }, true);
});
document.addEventListener('contextmenu', function (event) {
    if (event.target.closest('.agenda-attendance')) event.preventDefault();
});

document.addEventListener('click', function (event) {
    const button = event.target.closest('.agenda-attendance');
    if (!button) return;
    event.preventDefault();
    if (attendanceLongPressTriggered) {
        attendanceLongPressTriggered = false;
        return;
    }
    if (button.dataset.busy === '1') return;
    button.dataset.busy = '1';
    const eventId = button.dataset.eventId;
    fetch('/event-attendance.php', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-CSRF-Token':'<?= $escape(csrfToken()) ?>'},
        body:JSON.stringify({event_id:Number(eventId)})
    }).then(async response => {
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || 'Je keuze kon niet worden opgeslagen.');
        button.querySelector('b').textContent = String(data.count);
        button.classList.toggle('is-attending', data.attending);
        button.setAttribute('aria-pressed', data.attending ? 'true' : 'false');
    }).catch(error => {
        window.alert(error.message || 'Je keuze kon niet worden opgeslagen. Probeer opnieuw.');
    }).finally(() => {
        button.dataset.busy = '0';
    });
});

document.addEventListener('click', function (event) {
    const backToTopButton = event.target.closest('#agenda-back-to-top');
    if (!backToTopButton) return;
    event.preventDefault();
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({top:0, behavior:reduceMotion ? 'auto' : 'smooth'});
});

document.addEventListener('click', async function (event) {
    const shareButton = event.target.closest('[data-share-url]');
    if (!shareButton) return;

    event.preventDefault();
    const title = shareButton.dataset.shareTitle || 'Evenement';
    const url = shareButton.dataset.shareUrl || window.location.href;
    const shareData = {
        title: title,
        text: title,
        url: url
    };

    if (navigator.share && (!navigator.canShare || navigator.canShare(shareData))) {
        try {
            await navigator.share(shareData);
        } catch (error) {
            if (error.name !== 'AbortError') {
                window.alert('Delen is niet gelukt. Probeer de link te kopiëren.');
            }
        }
        return;
    }

    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(url);
            window.alert('De link naar het evenement is gekopieerd. Je kunt hem nu in elke app delen.');
        } else {
            window.prompt('Kopieer deze link om het evenement te delen:', url);
        }
    } catch (error) {
        window.prompt('Kopieer deze link om het evenement te delen:', url);
    }
});
</script>
<?php require dirname(__DIR__) . '/app/views/footer.php'; ?>
