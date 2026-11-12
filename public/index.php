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
window.DONDEBAILAR_AGENDA_CONFIG = <?= json_encode([
    'pageSize' => (int) $agendaPageSize,
    'activeFilterIndex' => $activeFilter === 'city' ? 1 : 0,
    'soundMuted' => (bool) $savedSoundMuted,
    'csrfToken' => csrfToken(),
    'regionId' => (int) $regionId,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="/assets/js/agenda.js"></script>
<script>window.DONDEBAILAR_AGENDA_PAGE_SIZE = <?= (int) $agendaPageSize ?>;</script>
<script src="/assets/js/agenda-filters.js"></script>
<?php require dirname(__DIR__) . '/app/views/footer.php'; ?>
