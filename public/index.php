<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Security.php';
require_once dirname(__DIR__) . '/app/Authorization.php';

$db = Database::connection();
$currentUser = currentUser();
// De sessie is een directe fallback; de database blijft de permanente opslag.
$savedAgendaPreferences = $currentUser !== null
    && is_array($_SESSION['agenda_preferences'] ?? null)
    ? $_SESSION['agenda_preferences']
    : null;
if ($currentUser !== null) {
    try {
        $preferencesTableExists = (int) $db->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'user_agenda_preferences'
               AND TABLE_TYPE = 'BASE TABLE'"
        )->fetchColumn() > 0;
        if ($preferencesTableExists) {
            $preferenceQuery = $db->prepare(
                "SELECT region_id, active_filter, organizer_id, city, sound_muted
                 FROM user_agenda_preferences WHERE user_id = :user_id"
            );
            $preferenceQuery->execute(['user_id' => (int) $currentUser['id']]);
            $databaseAgendaPreferences = $preferenceQuery->fetch() ?: null;
            if ($databaseAgendaPreferences !== null) {
                $savedAgendaPreferences = $databaseAgendaPreferences;
                $_SESSION['agenda_preferences'] = $databaseAgendaPreferences;
            }
        }
    } catch (Throwable $exception) {
        $savedAgendaPreferences = null;
    }
}
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

// Herstel persoonlijke voorkeuren alleen wanneer de URL geen expliciete
// regio- of filterkeuze bevat. Zo blijft een gekozen organisator ook na
// scrollen, verversen of terugkeren naar de pagina geselecteerd.
$hasExplicitAgendaChoice = array_key_exists('region_id', $_GET)
    || array_key_exists('filter', $_GET)
    || array_key_exists('organizer_id', $_GET)
    || array_key_exists('city', $_GET);
$restoreSavedAgendaPreferences = $currentUser !== null
    && $savedAgendaPreferences !== null
    && !$hasExplicitAgendaChoice;

$requestedRegion = $restoreSavedAgendaPreferences
    ? (int) ($savedAgendaPreferences['region_id'] ?? 0)
    : (filter_input(INPUT_GET, 'region_id', FILTER_VALIDATE_INT) ?: 0);
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
$organizerId = $restoreSavedAgendaPreferences
    ? (int) ($savedAgendaPreferences['organizer_id'] ?? 0)
    : (filter_input(INPUT_GET, 'organizer_id', FILTER_VALIDATE_INT) ?: 0);
$city = $restoreSavedAgendaPreferences
    ? trim((string) ($savedAgendaPreferences['city'] ?? ''))
    : trim((string) ($_GET['city'] ?? ''));
$activeFilter = $restoreSavedAgendaPreferences
    ? ((($savedAgendaPreferences['active_filter'] ?? '') === 'city') ? 'city' : 'organizer')
    : ((($_GET['filter'] ?? '') === 'city') ? 'city' : 'organizer');

// Alleen het filtertype dat actief is mag de agenda beperken.
// Zo kan een eerder gekozen organisator geen stadsfilter blokkeren, en omgekeerd.
if ($activeFilter === 'city') {
    $organizerId = 0;
} else {
    $city = '';
}
$savedSoundMuted = !empty($savedAgendaPreferences['sound_muted']);
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

$agendaPageSizeFile = dirname(__DIR__) . '/storage/agenda-page-size.txt';
$regionAppearanceSettings = json_decode((string) ($region['weekday_colors'] ?? ''), true);
if (!is_array($regionAppearanceSettings)) $regionAppearanceSettings = [];
$agendaArrowBackground = (string) ($regionAppearanceSettings['agenda_arrow_background'] ?? '#F6B22E');
$agendaArrowText = (string) ($regionAppearanceSettings['agenda_arrow_text'] ?? '#222222');
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $agendaArrowBackground)) $agendaArrowBackground = '#F6B22E';
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $agendaArrowText)) $agendaArrowText = '#222222';
$agendaPageSize = 20;
if (is_file($agendaPageSizeFile)) {
    $storedPageSize = (int) trim((string) file_get_contents($agendaPageSizeFile));
    if ($storedPageSize >= 1 && $storedPageSize <= 100) $agendaPageSize = $storedPageSize;
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
                <?php foreach ($events as $eventIndex => $event): ?>
                    <?php
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
                    <article class="agenda-card"<?= $eventIndex >= $agendaPageSize ? ' hidden' : '' ?> style="--db-event-day-color:var(--db-weekday-<?= (int) $start->format('N') ?>,var(--db-primary))">
                        <div class="agenda-card__media-wrap">
                        <?php $eventMediaItems = $event['media_items'] ?? []; ?>
                        <?php if (count($eventMediaItems) > 1): ?>
                            <div class="agenda-card__gallery" data-gallery>
                                <div class="agenda-card__gallery-track" data-gallery-track>
                                    <?php foreach ($eventMediaItems as $mediaIndex => $mediaItem): ?>
                                        <div class="agenda-card__media-slide<?= $mediaItem['type'] === 'reel' && $mediaItem['source'] === 'instagram' ? ' agenda-card__media-slide--instagram' : '' ?>">
                                            <?php if ($mediaItem['type'] === 'image' && $mediaItem['source'] === 'upload'): ?>
                                                <div class="agenda-card__image-link">
                                                    <img class="agenda-card__image" src="/event-media.php?id=<?= (int) $mediaItem['id'] ?>" alt="<?= $escape($event['title']) ?> — foto <?= $mediaIndex + 1 ?>" loading="<?= $mediaIndex === 0 ? 'eager' : 'lazy' ?>">
                                                </div>
                                            <?php elseif ($mediaItem['type'] === 'video' && $mediaItem['source'] === 'upload'): ?>
                                                <video class="agenda-card__video" playsinline preload="metadata" controlslist="nodownload noremoteplayback" disablepictureinpicture disableremoteplayback src="/event-media.php?id=<?= (int) $mediaItem['id'] ?>"></video>
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
                                <div class="agenda-card__image-link">
                                    <img class="agenda-card__image" src="/event-media.php?id=<?= (int) $mediaItem['id'] ?>" alt="<?= $escape($event['title']) ?>" loading="lazy">
                                </div>
                            <?php elseif ($mediaItem['type'] === 'video' && $mediaItem['source'] === 'upload'): ?>
                                <video class="agenda-card__video" playsinline preload="metadata" controlslist="nodownload noremoteplayback" disablepictureinpicture disableremoteplayback src="/event-media.php?id=<?= (int) $mediaItem['id'] ?>"></video>
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
                                <button type="button" class="agenda-attendance<?= !empty($event['is_attending']) ? ' is-attending' : '' ?>" data-event-id="<?= (int) $event['id'] ?>" data-event-title="<?= $escape($event['title']) ?>" aria-label="Ik kom dansen" aria-pressed="<?= !empty($event['is_attending']) ? 'true' : 'false' ?>" title="Tik: Ik kom dansen · Lang indrukken: deelnemers"><span aria-hidden="true">👠</span><b><?= (int) ($event['attendance_count'] ?? 0) ?></b></button>
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
                            $displayTags = array_map(static fn(string $tag): string => '#' . $tag, $hashtagItems);
                            if ($locationLabel !== '') {
                                $displayTags[] = '#' . $locationLabel;
                            }
                            ?>
                            <?php if ($displayTags !== []): ?>
                                <p class="agenda-card__hashtags"><?= $escape(implode(' ', $displayTags)) ?></p>
                            <?php endif; ?>
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
                                        <?php if ($locationName !== '' || $locationAddressLine !== '' || $locationPostalCity !== '' || $locationPhone !== ''): ?>
                                            <div class="agenda-card__info-block">
                                                <h3 class="agenda-card__section-label">LOCATIE</h3>
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
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($organizerName !== '' || $organizerPhone !== ''): ?>
                                            <div class="agenda-card__info-block agenda-card__info-block--organizer">
                                                <h3 class="agenda-card__section-label">ORGANISATOR</h3>
                                                <div class="agenda-card__organizer">
                                                    <?php if ($hasOrganizerLogo): ?>
                                                        <img class="agenda-card__organizer-logo" src="/organizer-logo.php?file=<?= rawurlencode($organizerLogo) ?>" alt="<?= $escape($organizerName) ?>" loading="lazy">
                                                    <?php endif; ?>
                                                    <div class="agenda-card__organizer-info">
                                                        <?php if ($organizerName !== ''): ?><strong><?= $escape($organizerName) ?></strong><?php endif; ?>
                                                        <?php if ($organizerPhone !== ''): ?><span>☎ <a href="tel:<?= $escape(preg_replace('/[^0-9+*#]/', '', $organizerPhone)) ?>"><?= $escape($organizerPhone) ?></a></span><?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($eventDescription !== ''): ?>
                                            <div class="agenda-card__description-text"><?= nl2br($escape($eventDescription)) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <p class="agenda-card__swipe-hint" data-swipe-hint>→ Swipe voor meer info</p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="agenda-page-navigation" aria-label="Navigatie tussen evenementreeksen">
                    <button type="button" class="agenda-page-nav-icon" id="agenda-page-previous" aria-label="Vorige reeks" title="Vorige reeks">‹</button>
                    <span id="agenda-page-range">1–<?= min($agendaPageSize, count($events)) ?> van <?= count($events) ?></span>
                    <button type="button" class="agenda-page-nav-icon" id="agenda-page-next" aria-label="Volgende reeks" title="Volgende reeks">›</button>
            </div>
        <?php endif; ?>
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
// agenda_change is een eenmalige opdracht om een bewuste filterkeuze te tonen.
// Verwijder de vlag uit de adresbalk zodat ze na uitloggen/inloggen
// niet opnieuw de opgeslagen persoonlijke voorkeuren uitschakelt.
const agendaCurrentUrl = new URL(window.location.href);
if (agendaCurrentUrl.searchParams.get('agenda_change') === '1') {
    agendaCurrentUrl.searchParams.delete('agenda_change');
    window.history.replaceState({}, '', agendaCurrentUrl.pathname + agendaCurrentUrl.search + agendaCurrentUrl.hash);
}
const agendaPageSize = <?= (int) $agendaPageSize ?>;
const agendaCards = Array.from(document.querySelectorAll('.agenda-feed .agenda-card'));
const agendaPagePrevious = document.getElementById('agenda-page-previous');
const agendaPageNext = document.getElementById('agenda-page-next');
const agendaPageRange = document.getElementById('agenda-page-range');
let agendaPageStart = 0;
let agendaVisibleEnd = Math.min(agendaPageSize, agendaCards.length);
function updateAgendaPagination() {
    agendaCards.forEach(function (card, index) {
        card.hidden = index < agendaPageStart || index >= agendaVisibleEnd;
    });
    if (agendaPageRange) {
        agendaPageRange.textContent = (agendaPageStart + 1) + '–' + agendaVisibleEnd + ' van ' + agendaCards.length;
    }
    if (agendaPagePrevious) agendaPagePrevious.disabled = agendaPageStart === 0;
    if (agendaPageNext) agendaPageNext.disabled = agendaPageStart + agendaPageSize >= agendaCards.length;
}
if (agendaCards.length) updateAgendaPagination();
if (agendaPagePrevious) {
    agendaPagePrevious.addEventListener('click', function () {
        agendaPageStart = Math.max(0, agendaPageStart - agendaPageSize);
        agendaVisibleEnd = Math.min(agendaPageStart + agendaPageSize, agendaCards.length);
        updateAgendaPagination();
        document.querySelector('.agenda-feed').scrollIntoView({behavior: 'smooth', block: 'start'});
    });
}
if (agendaPageNext) {
    agendaPageNext.addEventListener('click', function () {
        if (agendaPageStart + agendaPageSize >= agendaCards.length) return;
        agendaPageStart += agendaPageSize;
        agendaVisibleEnd = Math.min(agendaPageStart + agendaPageSize, agendaCards.length);
        updateAgendaPagination();
        document.querySelector('.agenda-feed').scrollIntoView({behavior: 'smooth', block: 'start'});
    });
}
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

let agendaVideosMuted = <?= $savedSoundMuted ? 'true' : 'false' ?>;
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

        // Stop video's zodra een andere foto, video of Reel actief wordt.
        slides.forEach(function (slide, index) {
            const isActive = index === currentIndex - 1;
            slide.querySelectorAll('video.agenda-card__video').forEach(function (video) {
                if (!isActive) {
                    video.pause();
                    return;
                }

                // Hervat de video wanneer deze galerij-slide weer zichtbaar is.
                const rect = video.getBoundingClientRect();
                const inViewport = rect.width > 0 && rect.height > 0
                    && rect.bottom > 0 && rect.right > 0
                    && rect.top < window.innerHeight && rect.left < window.innerWidth;
                if (inViewport) {
                    // Behoud de geluidskeuze van de centrale geluidsknop bij terugkeren naar deze video.
                    video.muted = agendaVideosMuted;
                    const playPromise = video.play();
                    if (playPromise && typeof playPromise.catch === 'function') {
                        playPromise.catch(function () {});
                    }
                }
            });
        });
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
        const dayColor = eventCard ? getComputedStyle(eventCard).getPropertyValue('--db-event-day-color').trim() : '';
        messageModal.querySelector('.agenda-message-modal__panel').style.setProperty('--db-chat-day-color', dayColor || 'var(--db-primary,#E23A2C)');
        messageFrame.addEventListener('load', function applyChatDayColor() {
            try {
                const chatDocument = messageFrame.contentDocument;
                if (!chatDocument) return;
                chatDocument.documentElement.style.setProperty('--db-chat-day-color', dayColor || 'var(--db-primary,#E23A2C)');
            } catch (error) {}
        }, {once:true});
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
attendanceDialog.innerHTML = '<div class="agenda-attendance-list__panel" role="dialog" aria-modal="true" aria-labelledby="attendance-list-title"><button type="button" class="agenda-attendance-list__close" aria-label="Sluiten">×</button><h3 id="attendance-list-title">Wie komt dansen?</h3><p class="agenda-attendance-list__event-title"></p><div class="agenda-attendance-list__content">Laden…</div></div>';
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
        const eventCard = button.closest('.agenda-card');
        const eventTitle = button.dataset.eventTitle || eventCard?.querySelector('.agenda-card__details h2 a')?.textContent.trim() || '';
        const dayColor = eventCard ? getComputedStyle(eventCard).getPropertyValue('--db-event-day-color').trim() : '';
        attendanceDialog.style.setProperty('--db-attendance-day-color', dayColor || 'var(--db-primary,#E23A2C)');
        attendanceDialog.querySelector('.agenda-attendance-list__event-title').textContent = eventTitle;
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

// Speel geüploade video's af zodra ze zichtbaar zijn en pauzeer ze
// zodra ze bijna uit beeld verdwijnen. Autoplay start gedempt.
const agendaVideoObserver = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
        const video = entry.target;
        if (entry.isIntersecting && entry.intersectionRatio > 0) {
            // Hervatten na scrollen mag de gekozen geluidsinstelling niet overschrijven.
            video.muted = agendaVideosMuted;
            const playPromise = video.play();
            if (playPromise && typeof playPromise.catch === 'function') {
                playPromise.catch(function () {});
            }
        } else {
            video.pause();
        }
    });
}, {threshold:0});
document.querySelectorAll('.agenda-card__video').forEach(function (video) {
    // Gebruik meteen de bewaarde geluidskeuze van het ingelogde account.
    video.muted = agendaVideosMuted;
    video.setAttribute('autoplay', '');
    video.setAttribute('muted', '');
    agendaVideoObserver.observe(video);
});

// Geluid van geüploade video's centraal aan- en uitzetten.
// De footer wordt pas na dit script geladen; daarom gebruiken we event delegation.
function applyAgendaVideoSound(button) {
    document.querySelectorAll('.agenda-card__video').forEach(function (video) {
        video.muted = agendaVideosMuted;
    });
    button.setAttribute('aria-pressed', agendaVideosMuted ? 'true' : 'false');
    button.setAttribute('aria-label', agendaVideosMuted ? 'Geluid van video\'s inschakelen' : 'Geluid van video\'s uitschakelen');
    button.setAttribute('title', agendaVideosMuted ? 'Geluid van video\'s inschakelen' : 'Geluid van video\'s uitschakelen');
    button.innerHTML = agendaVideosMuted
        ? '<svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"></path><path d="m3 3 18 18"></path></svg>'
        : '<svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"></path><path d="M15.5 8.5a5 5 0 0 1 0 7"></path><path d="M19 5a10 10 0 0 1 0 14"></path></svg>';
}
// De footerknop staat pas na dit script in de HTML. Initialiseer hem zodra de pagina volledig is opgebouwd.
document.addEventListener('DOMContentLoaded', function () {
    const soundButton = document.getElementById('agenda-toggle-sound');
    if (soundButton) applyAgendaVideoSound(soundButton);
});

document.addEventListener('click', function (event) {
    const target = event.target instanceof Element ? event.target : null;
    const soundButton = target ? target.closest('#agenda-toggle-sound') : null;
    if (!soundButton) return;

    // De geluidsknop mag nooit een filter-, navigatie- of andere klikactie activeren.
    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();

    agendaVideosMuted = !agendaVideosMuted;
    applyAgendaVideoSound(soundButton);
}, true);


// Bewaar de huidige regio, filter en geluidskeuze bij het ingelogde account.
// SAVE krijgt voorrang op alle andere klikacties: deze klik mag geen
// filter-, navigatie- of reset-handler van de agenda activeren.
document.addEventListener('click', function (event) {
    const target = event.target instanceof Element ? event.target : null;
    const saveButton = target ? target.closest('#agenda-save-preferences') : null;
    if (!saveButton) return;
    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();
    if (saveButton.dataset.busy === '1') return;
    saveButton.dataset.busy = '1';
    saveButton.setAttribute('aria-busy', 'true');
    const activeFilter = agendaFilterIndex === 1 ? 'city' : 'organizer';
    const activePanel = document.querySelector('[data-panel="' + activeFilter + '"]');
    const selectedItem = activePanel ? activePanel.querySelector('.agenda-organizer-item.is-selected') : null;
    const selectedUrl = selectedItem ? new URL(selectedItem.href, window.location.origin) : null;
    const payload = {
        csrf_token: '<?= $escape(csrfToken()) ?>',
        region_id: <?= (int) $regionId ?>,
        filter: activeFilter,
        organizer_id: activeFilter === 'organizer' && selectedItem
            ? Number(selectedItem.dataset.organizerId || 0) : 0,
        city: activeFilter === 'city' && selectedItem ? selectedItem.dataset.city || '' : '',
        sound_muted: agendaVideosMuted
    };
    fetch('/save-agenda-preferences.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    }).then(async function (response) {
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || 'Opslaan is niet gelukt.');
        saveButton.classList.add('is-saved');
        saveButton.setAttribute('title', 'Instellingen bewaard');
        saveButton.setAttribute('aria-label', 'Instellingen bewaard');
    }).catch(function (error) {
        window.alert(error.message || 'Je instellingen konden niet worden bewaard.');
    }).finally(function () {
        saveButton.dataset.busy = '0';
        saveButton.removeAttribute('aria-busy');
    });
}, true);

document.addEventListener('click', function (event) {
    const backToTopButton = event.target.closest('#agenda-back-to-top');
    if (!backToTopButton) return;
    event.preventDefault();
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({top:0, behavior:reduceMotion ? 'auto' : 'smooth'});
});

// Deelvenster voor evenementen: WhatsApp, Facebook, kopiëren en de native deeloptie.
const eventShareDialog = document.createElement('div');
eventShareDialog.className = 'agenda-share-dialog';
eventShareDialog.hidden = true;
eventShareDialog.innerHTML = '<div class="agenda-share-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="agenda-share-title"><button type="button" class="agenda-share-dialog__close" aria-label="Sluiten">×</button><h3 id="agenda-share-title">Deel dit evenement</h3><p class="agenda-share-dialog__event"></p><div class="agenda-share-dialog__actions"><a class="agenda-share-option agenda-share-option--whatsapp" data-share-whatsapp target="_blank" rel="noopener noreferrer"><span aria-hidden="true">◉</span> WhatsApp</a><a class="agenda-share-option agenda-share-option--facebook" data-share-facebook target="_blank" rel="noopener noreferrer"><span aria-hidden="true">f</span> Facebook</a><button type="button" class="agenda-share-option" data-share-copy><span aria-hidden="true">▢</span> Link kopiëren</button><button type="button" class="agenda-share-option" data-share-native><span aria-hidden="true">↗</span> Andere apps</button></div><p class="agenda-share-dialog__status" role="status" aria-live="polite"></p></div>';
document.body.appendChild(eventShareDialog);
let activeShareData = null;
function closeEventShareDialog() {
    eventShareDialog.hidden = true;
    activeShareData = null;
}
async function copyEventShareLink() {
    if (!activeShareData) return;
    const status = eventShareDialog.querySelector('.agenda-share-dialog__status');
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(activeShareData.url);
        } else {
            const temporaryInput = document.createElement('textarea');
            temporaryInput.value = activeShareData.url;
            temporaryInput.setAttribute('readonly', '');
            temporaryInput.style.position = 'fixed';
            temporaryInput.style.opacity = '0';
            document.body.appendChild(temporaryInput);
            temporaryInput.select();
            const copied = document.execCommand('copy');
            temporaryInput.remove();
            if (!copied) throw new Error('copy_failed');
        }
        status.textContent = 'Link gekopieerd. Je kunt hem nu in een bericht plakken.';
    } catch (error) {
        status.textContent = 'Kopiëren lukte niet. Selecteer en kopieer de link: ' + activeShareData.url;
    }
}
eventShareDialog.addEventListener('click', function (event) {
    if (event.target === eventShareDialog || event.target.closest('.agenda-share-dialog__close')) {
        closeEventShareDialog();
        return;
    }
    if (event.target.closest('[data-share-copy]')) {
        copyEventShareLink();
        return;
    }
    if (event.target.closest('[data-share-native]')) {
        if (!activeShareData) return;
        const shareData = {title:activeShareData.title, text:activeShareData.title, url:activeShareData.url};
        if (navigator.share && (!navigator.canShare || navigator.canShare(shareData))) {
            navigator.share(shareData).then(closeEventShareDialog).catch(function (error) {
                if (error.name !== 'AbortError') eventShareDialog.querySelector('.agenda-share-dialog__status').textContent = 'Delen is niet gelukt. Kies WhatsApp of kopieer de link.';
            });
        } else {
            copyEventShareLink();
        }
    }
});
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !eventShareDialog.hidden) closeEventShareDialog();
});
document.addEventListener('click', function (event) {
    const shareButton = event.target.closest('[data-share-url]');
    if (!shareButton) return;
    event.preventDefault();
    const title = shareButton.dataset.shareTitle || 'Evenement';
    const url = shareButton.dataset.shareUrl || window.location.href;
    activeShareData = {title:title, url:url};
    const message = title + ' — ' + url;
    eventShareDialog.querySelector('.agenda-share-dialog__event').textContent = title;
    eventShareDialog.querySelector('[data-share-whatsapp]').href = 'https://wa.me/?text=' + encodeURIComponent(message);
    eventShareDialog.querySelector('[data-share-facebook]').href = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url);
    eventShareDialog.querySelector('[data-share-native]').hidden = !(navigator.share && (!navigator.canShare || navigator.canShare({title:title,text:title,url:url})));
    eventShareDialog.querySelector('.agenda-share-dialog__status').textContent = '';
    eventShareDialog.hidden = false;
});

</script>
<script>
/*
 * Agenda-filters worden binnen dezelfde pagina bijgewerkt.
 * De header en beide merklogo's blijven daardoor in de bestaande DOM staan.
 */
(function () {
    if (window.__agendaAjaxFiltersInstalled) return;
    window.__agendaAjaxFiltersInstalled = true;

    let requestController = null;

    function refreshAgendaPage(parsedDocument, targetUrl, addHistory) {
        const currentPage = document.querySelector('.site-main > .agenda-page');
        const incomingPage = parsedDocument.querySelector('.site-main > .agenda-page');
        if (!currentPage || !incomingPage) {
            window.location.assign(targetUrl.href);
            return;
        }

        // Behoud het bestaande berichtenvenster: de bestaande bediening verwijst hiernaar.
        const existingMessageModal = currentPage.querySelector('#agenda-message-modal');
        const incomingMessageModal = incomingPage.querySelector('#agenda-message-modal');
        if (existingMessageModal && incomingMessageModal) {
            incomingMessageModal.replaceWith(existingMessageModal);
        }

        currentPage.replaceWith(incomingPage);

        // Vervang alleen de inhoud van de filterstrips; de filterknop en header blijven staan.
        ['organizer', 'city'].forEach(function (panelName) {
            const currentStrip = document.querySelector('[data-panel="' + panelName + '"] .agenda-organizer-strip');
            const incomingStrip = parsedDocument.querySelector('[data-panel="' + panelName + '"] .agenda-organizer-strip');
            if (currentStrip && incomingStrip) currentStrip.innerHTML = incomingStrip.innerHTML;
        });

        // Vernieuw de URL zonder de eenmalige agenda_change-vlag.
        const cleanUrl = new URL(targetUrl.href);
        cleanUrl.searchParams.delete('agenda_change');
        const historyMethod = addHistory ? 'pushState' : 'replaceState';
        window.history[historyMethod]({agendaAjax:true}, '', cleanUrl.pathname + cleanUrl.search + cleanUrl.hash);

        // Nieuwe reeks en paginaknoppen.
        const cards = Array.from(document.querySelectorAll('.agenda-feed .agenda-card'));
        const previous = document.getElementById('agenda-page-previous');
        const next = document.getElementById('agenda-page-next');
        const range = document.getElementById('agenda-page-range');
        const pageSize = <?= (int) $agendaPageSize ?>;
        let start = 0;
        let end = Math.min(pageSize, cards.length);

        function updatePagination() {
            cards.forEach(function (card, index) {
                card.hidden = index < start || index >= end;
            });
            if (range) range.textContent = cards.length ? (start + 1) + '–' + end + ' van ' + cards.length : '';
            if (previous) previous.disabled = start === 0;
            if (next) next.disabled = start + pageSize >= cards.length;
        }
        if (cards.length) updatePagination();

        if (previous) previous.addEventListener('click', function () {
            start = Math.max(0, start - pageSize);
            end = Math.min(start + pageSize, cards.length);
            updatePagination();
            document.querySelector('.agenda-feed')?.scrollIntoView({behavior:'smooth', block:'start'});
        });
        if (next) next.addEventListener('click', function () {
            if (start + pageSize >= cards.length) return;
            start += pageSize;
            end = Math.min(start + pageSize, cards.length);
            updatePagination();
            document.querySelector('.agenda-feed')?.scrollIntoView({behavior:'smooth', block:'start'});
        });

        // Initialiseer de galerijen en video's in de nieuw geladen evenementkaarten.
        document.querySelectorAll('.agenda-page [data-gallery]').forEach(function (gallery) {
            if (gallery.dataset.ajaxInitialized === '1') return;
            gallery.dataset.ajaxInitialized = '1';
            const track = gallery.querySelector('[data-gallery-track]');
            const counter = gallery.querySelector('[data-gallery-count]');
            if (!track || !counter) return;
            const slides = Array.from(track.querySelectorAll('.agenda-card__media-slide'));
            const updateCounter = function () {
                if (!slides.length) return;
                const width = track.clientWidth || 1;
                const index = Math.min(slides.length, Math.max(1, Math.round(track.scrollLeft / width) + 1));
                counter.textContent = index + ' / ' + slides.length;
                slides.forEach(function (slide, slideIndex) {
                    slide.querySelectorAll('video.agenda-card__video').forEach(function (video) {
                        if (slideIndex !== index - 1) video.pause();
                    });
                });
            };
            track.addEventListener('scroll', updateCounter, {passive:true});
            window.addEventListener('resize', updateCounter);
            updateCounter();
        });

        document.querySelectorAll('.agenda-page [data-event-info]').forEach(function (info) {
            if (info.dataset.ajaxSwipeInitialized === '1') return;
            info.dataset.ajaxSwipeInitialized = '1';
            if (typeof setEventDescription !== 'function' || !info.querySelector('[data-event-description]')) return;
            let touchStart = null;
            info.addEventListener('touchstart', function (event) {
                if (!event.changedTouches.length) return;
                touchStart = {x:event.changedTouches[0].clientX, y:event.changedTouches[0].clientY};
            }, {passive:true});
            info.addEventListener('touchend', function (event) {
                if (!touchStart || !event.changedTouches.length) return;
                const touch = event.changedTouches[0];
                const dx = touch.clientX - touchStart.x;
                const dy = touch.clientY - touchStart.y;
                touchStart = null;
                if (Math.abs(dx) >= 45 && Math.abs(dx) >= Math.abs(dy) * 1.25) {
                    setEventDescription(info, dx > 0);
                }
            }, {passive:true});
        });

        if (typeof agendaVideoObserver !== 'undefined') {
            document.querySelectorAll('.agenda-page video.agenda-card__video').forEach(function (video) {
                video.muted = typeof agendaVideosMuted !== 'undefined' ? agendaVideosMuted : true;
                video.setAttribute('autoplay', '');
                video.setAttribute('muted', '');
                agendaVideoObserver.observe(video);
            });
        }
        if (window.instgrm && window.instgrm.Embeds) {
            window.instgrm.Embeds.process();
        }
        if (typeof updateAgendaStripIndicators === 'function') updateAgendaStripIndicators();
        window.scrollTo({top:0, behavior:'instant'});
    }

    async function loadAgendaFilter(url, addHistory) {
        if (requestController) requestController.abort();
        requestController = new AbortController();
        const response = await fetch(url.href, {
            credentials:'same-origin',
            headers:{'X-Requested-With':'XMLHttpRequest'},
            signal:requestController.signal
        });
        if (!response.ok) throw new Error('De agenda kon niet worden bijgewerkt.');
        const html = await response.text();
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        refreshAgendaPage(parsed, url, addHistory);
    }

    document.addEventListener('click', function (event) {
        const link = event.target instanceof Element ? event.target.closest('.agenda-organizer-strip a[href]') : null;
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const url = new URL(link.href, window.location.origin);
        if (url.origin !== window.location.origin || url.pathname !== '/' || url.searchParams.get('agenda_change') !== '1') return;
        event.preventDefault();
        loadAgendaFilter(url, true).catch(function (error) {
            if (error.name !== 'AbortError') window.location.assign(url.href);
        });
    });

    window.addEventListener('popstate', function () {
        const url = new URL(window.location.href);
        url.searchParams.set('agenda_change', '1');
        loadAgendaFilter(url, false).catch(function () {
            window.location.reload();
        });
    });
})();
</script>
<?php require dirname(__DIR__) . '/app/views/footer.php'; ?>
