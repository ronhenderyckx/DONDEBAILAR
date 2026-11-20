<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';

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
            COALESCE(NULLIF(a.logo_path, ''), NULLIF(r.logo_path, '')) AS logo_path,
            a.primary_color, a.primary_dark, a.accent_color,
            a.secondary_color, a.tertiary_color, a.header_color, a.menu_color, a.organizer_color, a.filter_hover_color,
            a.filter_bar_color, a.filter_text_color, a.search_button_color, a.back_top_color,
             a.search_field_color, a.search_text_color, a.footer_color, a.background_color, a.text_color, a.weekday_colors, a.past_event_color
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
$pastEventColor = strtoupper((string) ($region['past_event_color'] ?? '#777777'));
if (!preg_match('/^#[0-9A-F]{6}$/', $pastEventColor)) $pastEventColor = '#777777';

// Regiospecifieke tekst voor evenementen zonder media.
$eventFallbackText = 'SALSA · BACHATA · KIZOMBA';
if ($regionId > 0) {
    $fallbackTextFile = dirname(__DIR__, 2) . '/storage/region-event-fallback-text/' . $regionId . '.txt';
    if (is_file($fallbackTextFile)) {
        $configuredFallbackText = trim((string) file_get_contents($fallbackTextFile));
        if ($configuredFallbackText !== '') {
            $eventFallbackText = mb_substr($configuredFallbackText, 0, 100);
        }
    }
}
$timezoneName = (string) ($region['timezone'] ?? 'Europe/Madrid');
try {
    $timezone = new DateTimeZone($timezoneName);
} catch (Throwable $exception) {
    $timezone = new DateTimeZone('Europe/Madrid');
}
date_default_timezone_set($timezone->getName());

$now = new DateTimeImmutable('now', $timezone);
// Alle gepubliceerde evenementen blijven zichtbaar. Afgelopen evenementen
// staan chronologisch vóór het eerste evenement dat nu of later plaatsvindt.
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
$agendaPageSizeFile = dirname(__DIR__, 2) . '/storage/agenda-page-size.txt';
$agendaPageSize = 20;
if (is_file($agendaPageSizeFile)) {
    $storedPageSize = (int) trim((string) file_get_contents($agendaPageSizeFile));
    if ($storedPageSize >= 1 && $storedPageSize <= 100) $agendaPageSize = $storedPageSize;
}
$agendaPage = max(1, (int) ($_GET['page'] ?? 1));
$totalEvents = 0;

if ($regionId > 0) {
    $organizerQuery = $db->prepare(
        "SELECT DISTINCT o.id, o.name, o.logo_path
         FROM organizers o
         INNER JOIN events e ON e.organizer_id = o.id
         WHERE o.is_active = 1 AND e.region_id = :region_id
           AND e.status IN ('published','cancelled','cancelled_rain')
         ORDER BY o.name"
    );
    $organizerQuery->execute(['region_id' => $regionId]);
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
           AND e.region_id = :event_region_id AND e.status IN ('published','cancelled','cancelled_rain')
         ORDER BY l.city"
    );
    $cityQuery->execute([
        'region_id' => $regionId,
        'event_region_id' => $regionId,
    ]);
    $cities = $cityQuery->fetchAll();
    $cityNames = array_column($cities, 'city');
    if ($city !== '' && !in_array($city, $cityNames, true)) {
        $city = '';
    }

    $showCancelled = (($_GET['show_cancelled'] ?? '') === '1');
    $sql = "SELECT e.id, e.title, e.description, e.hashtags, e.presale_price, e.door_price, e.starts_at, e.ends_at,
                   e.event_language, e.status, c.name AS category_name,
                   o.name AS organizer_name, o.phone AS organizer_phone, o.logo_path AS organizer_logo_path,
                   l.name AS location_name,
                   l.city AS location_city, l.street AS location_street,
                   l.house_number AS location_house_number, l.postal_code AS location_postal_code,
                   l.phone AS location_phone, l.logo_path AS location_logo_path,
                   l.latitude, l.longitude, l.google_maps_url,
                   cover.id AS cover_media_id
            FROM events e
            LEFT JOIN organizers o ON o.id = e.organizer_id
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
              AND (e.status = 'published' OR (:show_cancelled = 1 AND e.status IN ('cancelled','cancelled_rain')))";
    $params = [
        'region_id' => $regionId,
        'show_cancelled' => $showCancelled ? 1 : 0,
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
    $fromPosition = strpos($sql, ' FROM ');
    if ($fromPosition !== false) {
        // Voor de telling is de cover-afbeelding niet nodig. Laat de
        // bijbehorende subquery weg, zodat de telling licht blijft.
        $countFromSql = substr($sql, $fromPosition);
        $countFromSql = preg_replace(
            '/\\s+LEFT JOIN event_media cover ON cover\\.id = COALESCE\\([\\s\\S]*?\\)\\s+WHERE/',
            ' WHERE',
            $countFromSql,
            1
        );
        $countSql = 'SELECT COUNT(*)' . $countFromSql;
        $countQuery = $db->prepare($countSql);
        $countQuery->execute($params);
        $totalEvents = (int) $countQuery->fetchColumn();
    }

    $totalPages = max(1, (int) ceil($totalEvents / $agendaPageSize));
    // Bij een nieuwe agendaweergave tonen we een reeks met vijf afgelopen
    // evenementen vóór het eerste actuele/toekomstige evenement.
    $hasExplicitPage = array_key_exists('page', $_GET);
    if (!$hasExplicitPage && $totalEvents > 0) {
        $pastCountQuery = $db->prepare(
            'SELECT COUNT(*)' . $countFromSql . ' AND COALESCE(e.ends_at, e.starts_at) < :agenda_now'
        );
        $pastCountParams = $params;
        $pastCountParams['agenda_now'] = $now->format('Y-m-d H:i:s');
        $pastCountQuery->execute($pastCountParams);
        $pastEventCount = (int) $pastCountQuery->fetchColumn();
        if ($pastEventCount < $totalEvents) {
            $desiredOffset = max(0, $pastEventCount - 5);
            $agendaPage = (int) floor($desiredOffset / $agendaPageSize) + 1;
        }
    }
    $agendaPage = min($agendaPage, $totalPages);
    $agendaOffset = ($agendaPage - 1) * $agendaPageSize;

    // Eerst alle afgelopen evenementen in chronologische volgorde; daarna
    // het eerste actuele evenement en de komende evenementen op starttijd.
    $sortNowSql = $db->quote($now->format('Y-m-d H:i:s'));
    $sql .= " ORDER BY
                  CASE
                    WHEN COALESCE(e.ends_at, e.starts_at) < $sortNowSql THEN 0
                    ELSE 1
                  END ASC,
                  CASE
                    WHEN COALESCE(e.ends_at, e.starts_at) < $sortNowSql
                      THEN COALESCE(e.ends_at, e.starts_at)
                    ELSE e.starts_at
                  END ASC,
                  e.id ASC
              LIMIT " . (int) $agendaPageSize . " OFFSET " . (int) $agendaOffset;
    $eventQuery = $db->prepare($sql);
    $eventQuery->execute($params);
    $events = $eventQuery->fetchAll();

    if ($events !== []) {
        $eventIds = array_map(static fn(array $event): int => (int) $event['id'], $events);
        $eventPlaceholders = implode(',', array_fill(0, count($eventIds), '?'));
        $mediaQuery = $db->prepare(
            "SELECT id, event_id, media_type, media_source, external_url, file_path, caption, is_cover
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
                'caption' => trim((string) ($mediaItem['caption'] ?? '')),
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

$regionAppearanceSettings = json_decode((string) ($region['weekday_colors'] ?? ''), true);
if (!is_array($regionAppearanceSettings)) $regionAppearanceSettings = [];
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$isAgendaPage = true;
$pageTitle = 'Agenda' . ($region ? ' — ' . $region['name'] : '') . ' — DONDEBAILAR';
$pageRobots = 'index, follow';
require dirname(__DIR__, 2) . '/app/views/header.php';
?>
