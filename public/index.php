<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Security.php';

$db = Database::connection();
$regions = $db->query(
    "SELECT id, name, timezone, logo_path FROM regions
     WHERE is_active = 1 ORDER BY id"
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

    $sql = "SELECT e.id, e.title, e.description, e.starts_at, e.ends_at,
                   e.event_language, c.name AS category_name,
                   o.name AS organizer_name, l.name AS location_name,
                   l.city AS location_city, l.latitude, l.longitude,
                   l.google_maps_url,
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
    }
}

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pageTitle = 'Agenda' . ($region ? ' — ' . $region['name'] : '') . ' — DONDEBAILAR';
$pageRobots = 'index, follow';
require dirname(__DIR__) . '/app/views/header.php';
?>
<section class="agenda-page">
    <?php if ($regions === []): ?>
        <section class="agenda-empty"><h2>De agenda wordt voorbereid</h2><p>Er zijn nog geen actieve regio's beschikbaar.</p></section>
    <?php else: ?>
        <form class="agenda-selectors" method="get" action="/">
            <input type="hidden" name="region_id" value="<?= $regionId ?>">
            <div class="agenda-selector-line">
                <button type="button" class="agenda-selector-button is-active" id="agenda-filter-switch" data-filter-index="0" aria-label="Wissel filter" title="Wissel filter"><span class="agenda-filter-icon" aria-hidden="true">👥</span></button>
                <div class="agenda-selector-panel is-open" data-panel="organizer">
                    <div class="agenda-organizer-strip" aria-label="Organisatoren met toekomstige evenementen">
                        <?php foreach ($organizers as $organizerOption): ?>
                            <?php
                            $logoFilename = basename((string) ($organizerOption['logo_path'] ?? ''));
                            $hasOrganizerLogo = preg_match('/\\A[a-f0-9]{32}\\.(jpg|png|webp)\\z/', $logoFilename) === 1;
                            $organizerSelected = $organizerId === (int) $organizerOption['id'];
                            $organizerHref = $organizerSelected
                                ? '/?region_id=' . $regionId . '&filter=organizer'
                                : '/?region_id=' . $regionId . '&filter=organizer&organizer_id=' . (int) $organizerOption['id'];
                            ?>
                            <a class="agenda-organizer-item<?= $organizerSelected ? ' is-selected' : '' ?>" href="<?= $escape($organizerHref) ?>" title="<?= $escape($organizerOption['name']) ?>" aria-label="<?= $organizerSelected ? 'Verwijder filter voor ' : 'Toon evenementen van ' ?><?= $escape($organizerOption['name']) ?>">
                                <?php if ($hasOrganizerLogo): ?>
                                    <img class="agenda-organizer-logo" src="/organizer-logo.php?file=<?= rawurlencode($logoFilename) ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <span class="agenda-organizer-fallback" aria-hidden="true"><?= $escape(mb_strtoupper(mb_substr((string) $organizerOption['name'], 0, 1))) ?></span>
                                <?php endif; ?>
                                <span class="agenda-organizer-name"><?= $escape($organizerOption['name']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="agenda-selector-panel" data-panel="city" hidden>
                    <div class="agenda-organizer-strip" aria-label="Steden met toekomstige evenementen">
                        <?php foreach ($cities as $cityOption): ?>
                            <?php
                            $cityName = (string) $cityOption['city'];
                            $citySelected = $city === $cityName;
                            $cityHref = $citySelected
                                ? '/?region_id=' . $regionId . '&filter=city'
                                : '/?region_id=' . $regionId . '&filter=city&city=' . rawurlencode($cityName);
                            ?>
                            <a class="agenda-organizer-item<?= $citySelected ? ' is-selected' : '' ?>" href="<?= $escape($cityHref) ?>" title="<?= $escape($cityName) ?>" aria-label="<?= $citySelected ? 'Verwijder stadsfilter ' : 'Toon evenementen in ' ?><?= $escape($cityName) ?>">
                                <span class="agenda-organizer-fallback" aria-hidden="true"><?= $escape(mb_strtoupper(mb_substr($cityName, 0, 1))) ?></span>
                                <span class="agenda-organizer-name"><?= $escape($cityName) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </form>

        <?php if ($events === []): ?>
            <section class="agenda-empty"><h2>Geen evenementen gevonden</h2><p>Er zijn momenteel geen toekomstige gepubliceerde evenementen met deze filters.</p></section>
        <?php else: ?>
            <div class="agenda-feed">
                <?php foreach ($events as $event): ?>
                    <?php
                    $start = new DateTimeImmutable((string) $event['starts_at'], $timezone);
                    $eventUrl = '/event.php?id=' . (int) $event['id'];
                    $locationLabel = trim((string) ($event['location_name'] ?? '') . (!empty($event['location_city']) ? ', ' . $event['location_city'] : ''));
                    $mapUrl = (string) ($event['google_maps_url'] ?? '');
                    if ($mapUrl === '' && $event['latitude'] !== null && $event['longitude'] !== null) {
                        $mapUrl = 'https://www.google.com/maps?q=' . rawurlencode((string) $event['latitude'] . ',' . (string) $event['longitude']);
                    }
                    $shareUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . $eventUrl;
                    ?>
                    <article class="agenda-card">
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
                        <div class="agenda-card__details">
                            <div class="agenda-card__quick">
                                <span class="agenda-card__quick-date" aria-label="Datum"><?= $escape($start->format('d')) ?></span>
                                <button type="button" class="agenda-icon-button agenda-share" data-share-title="<?= $escape($event['title']) ?>" data-share-url="<?= $escape($shareUrl) ?>" aria-label="Deel evenement"><span aria-hidden="true">↗</span></button>
                                <?php if ($mapUrl !== ''): ?><a class="agenda-icon-button" href="<?= $escape($mapUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Toon locatie op kaart"><span aria-hidden="true">⌖</span></a><?php endif; ?>
                            </div>
                            <h2><a href="<?= $eventUrl ?>"><?= $escape($event['title']) ?></a></h2>
                            <p class="agenda-card__tags">
                                <?php if (!empty($event['category_name'])): ?><span># <?= $escape($event['category_name']) ?></span><?php endif; ?>
                                <?php if (!empty($event['location_city'])): ?><span># <?= $escape($event['location_city']) ?></span><?php endif; ?>
                            </p>
                            <p class="agenda-card__date-line"><?= $escape(strtoupper($start->format('l, d M Y'))) ?> <span>◷</span> <?= $escape($start->format('H:i')) ?><?php if (!empty($event['ends_at'])): ?> – <?= $escape((new DateTimeImmutable((string) $event['ends_at'], $timezone))->format('H:i')) ?><?php endif; ?></p>
                            <?php if ($locationLabel !== ''): ?><p class="agenda-card__location"><?= $escape($locationLabel) ?></p><?php endif; ?>
                            <a class="agenda-card__more" href="<?= $eventUrl ?>">Bekijk het volledige evenement</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="agenda-count"><?= count($events) ?> evenement<?= count($events) === 1 ? '' : 'en' ?> gevonden</p>
    <?php endif; ?>
</section>

<style>
.agenda-page{width:100%;max-width:1080px;margin:0 auto}.agenda-selectors{margin:0 0 22px;padding:14px;background:#fff;border:1px solid var(--db-border);border-radius:12px}.agenda-selector-line{display:flex;align-items:center;gap:12px}.agenda-selector-button{align-self:flex-start;margin-top:8px}.agenda-selector-button{flex:0 0 62px;width:62px;height:62px;min-width:62px;min-height:62px;box-sizing:border-box;display:grid;place-items:center;padding:6px;border:1px solid var(--db-border);border-radius:15px;background:linear-gradient(145deg,var(--db-primary),color-mix(in srgb,var(--db-primary) 72%,#000));box-shadow:0 3px 9px #1720331c;color:#fff;font-weight:750;cursor:pointer}.agenda-filter-icon{font-size:23px;line-height:1}.agenda-selector-panel{display:flex;align-items:center;flex:1;min-width:0}.agenda-selector-panel[hidden]{display:none}.agenda-selector-panel select{width:100%;min-width:0;height:44px;padding:8px 11px;border:1px solid var(--db-border);border-radius:7px;background:#fff;color:var(--db-text)}.agenda-organizer-strip{display:flex;align-items:flex-start;gap:13px;width:100%;overflow-x:auto;overflow-y:hidden;padding:3px 2px 10px;scroll-snap-type:x proximity;-webkit-overflow-scrolling:touch;scrollbar-width:none}.agenda-organizer-strip::-webkit-scrollbar{display:none}.agenda-organizer-item{flex:0 0 82px;display:flex;flex-direction:column;align-items:center;gap:6px;min-width:0;padding:5px 4px;border:1px solid transparent;border-radius:10px;color:var(--db-text);text-decoration:none;text-align:center;scroll-snap-align:start}.agenda-organizer-item.is-selected{border-color:var(--db-primary);background:color-mix(in srgb,var(--db-primary) 8%,white)}.agenda-organizer-item img,.agenda-organizer-fallback,.agenda-organizer-all{display:grid;place-items:center;width:62px;height:62px;flex:0 0 62px;border-radius:50%;object-fit:contain;background:#fff;border:1px solid var(--db-border)}.agenda-organizer-fallback,.agenda-organizer-all{font-size:24px;font-weight:800;color:var(--db-primary)}.agenda-organizer-all{font-size:15px}.agenda-organizer-name{display:block;width:100%;overflow:hidden;font-size:11px;font-weight:650;line-height:1.25;text-overflow:ellipsis;white-space:nowrap}.agenda-selector-actions{display:flex;align-items:center;gap:15px;margin-top:12px}.agenda-heading{display:flex;align-items:center;justify-content:space-between;gap:20px;margin:0 0 20px}.agenda-heading h1{margin:0 0 4px}.agenda-heading p:not(.eyebrow){margin:0;color:var(--db-muted)}.agenda-month-select{display:flex;align-items:center;gap:9px;color:var(--db-muted);font-size:12px;font-weight:700}.agenda-month-select input{padding:9px;border:1px solid var(--db-border);border-radius:8px;background:#fff;color:var(--db-text)}.agenda-filters{display:flex;align-items:end;flex-wrap:wrap;gap:10px;margin-bottom:20px;padding:13px;background:#fff;border:1px solid var(--db-border);border-radius:10px}.agenda-filters label{display:flex;flex-direction:column;gap:4px;min-width:155px;color:var(--db-muted);font-size:11px;font-weight:700}.agenda-filters select,.agenda-filters input{height:40px;min-width:0;padding:7px 10px;border:1px solid var(--db-border);border-radius:7px;background:#fff;color:var(--db-text)}.agenda-filters .agenda-search{flex:1}.agenda-filters .agenda-search input{width:100%}.agenda-reset,.agenda-today{font-size:12px;font-weight:700;padding:9px 5px}.agenda-toolbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin:0 0 15px}.agenda-month-nav{display:flex;align-items:center;gap:9px}.agenda-month-nav h2{margin:0;min-width:140px;text-align:center;font-size:18px}.agenda-month-nav .button{min-width:36px;padding:6px;font-size:20px}.agenda-view-switch{display:flex;padding:3px;background:#eceff2;border-radius:8px}.agenda-view-switch a{padding:6px 11px;border-radius:6px;color:var(--db-muted);font-size:12px;font-weight:700}.agenda-view-switch a.is-active{background:#fff;color:var(--db-text);box-shadow:0 1px 4px #0001}.agenda-feed{display:grid;grid-template-columns:minmax(0,1fr);gap:20px;max-width:880px;margin:0 auto}.agenda-card{overflow:hidden;border:1px solid #65c99b;border-radius:15px;background:#f78d19;box-shadow:0 4px 18px #0000000d}.agenda-card__image-link{display:block;flex:0 0 100%;overflow:hidden;background:#171717;aspect-ratio:4/3;scroll-snap-align:start}.agenda-card__image{display:block;width:100%;height:100%;object-fit:contain;background:#171717}.agenda-card__gallery{position:relative;overflow:hidden;background:#171717}.agenda-card__media-slide{display:block;flex:0 0 100%;width:100%;min-width:0;box-sizing:border-box;aspect-ratio:4/3;scroll-snap-align:start;scroll-snap-stop:always;background:#171717}.agenda-card__media-slide .agenda-card__image-link{width:100%;height:100%;aspect-ratio:auto}.agenda-card__video{display:block;width:100%;height:100%;min-height:230px;aspect-ratio:4/3;object-fit:contain;background:#171717}.agenda-card__external-media{display:flex;width:100%;height:100%;min-height:230px;aspect-ratio:4/3;flex-direction:column;align-items:center;justify-content:center;gap:12px;background:radial-gradient(circle at 25% 20%,#f4802a 0,transparent 38%),linear-gradient(135deg,#202b38,#35475b);color:#fff;text-decoration:none}.agenda-card__external-media span{display:grid;place-items:center;width:64px;height:64px;border:2px solid #fff;border-radius:50%;font-size:26px}.agenda-card__external-media strong{font-size:16px}.agenda-card__media-slide--instagram{aspect-ratio:auto;min-height:500px;background:#fff}.agenda-card__instagram{width:100%;min-height:500px;display:flex;justify-content:center;overflow:hidden;background:#fff}.agenda-card__instagram .instagram-media{width:calc(100% - 2px)!important;max-width:540px!important;min-width:0!important;margin:0 auto!important}.agenda-card__gallery-track{display:flex;overflow-x:auto;overflow-y:hidden;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none}.agenda-card__gallery-track::-webkit-scrollbar{display:none}.agenda-card__media-count{position:absolute;right:12px;bottom:12px;padding:5px 9px;border-radius:20px;background:#000b;color:#fff;font-size:12px;font-weight:750;pointer-events:none}.agenda-card__placeholder{display:flex;width:100%;height:100%;min-height:230px;flex-direction:column;align-items:center;justify-content:center;gap:13px;padding:25px;text-align:center;color:#fff;background:radial-gradient(circle at 25% 20%,#f4802a 0,transparent 38%),linear-gradient(135deg,#202b38,#35475b)}.agenda-card__placeholder span{font-size:11px;letter-spacing:2px;color:#ffd36c;font-weight:800}.agenda-card__placeholder strong{font-size:clamp(20px,3vw,30px);line-height:1.15}.agenda-card__details{padding:17px 20px 20px;color:#fff}.agenda-card__quick{display:flex;align-items:center;gap:17px;margin:0 0 13px}.agenda-card__quick-date{min-width:34px;color:#fff;font-size:22px;font-weight:800}.agenda-icon-button{width:40px;height:40px;display:grid;place-items:center;border:0;border-radius:50%;background:transparent;color:#fff;font-size:31px;line-height:1;cursor:pointer}.agenda-icon-button:hover{background:#ffffff26;color:#fff}.agenda-card__details h2{margin:0 0 7px;color:#fff;font-size:22px}.agenda-card__details h2 a{color:inherit}.agenda-card__tags{display:flex;flex-wrap:wrap;gap:16px;margin:0 0 10px;color:#fff;font-size:15px}.agenda-card__date-line{margin:0 0 7px;color:#fff;font-size:14px;font-weight:600}.agenda-card__date-line span{margin:0 8px}.agenda-card__location{margin:0 0 7px;color:#fff;font-size:13px}.agenda-card__more{display:inline-block;color:#fff;text-decoration:underline;text-underline-offset:3px;font-size:14px;font-weight:600}.agenda-card__more:hover{color:#fff}.agenda-count{margin:15px 2px;color:var(--db-muted);font-size:12px}.agenda-empty{padding:38px 24px;text-align:center;background:#fff;border:1px solid var(--db-border);border-radius:12px}.agenda-empty p{color:var(--db-muted);margin:0}.agenda-calendar{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));overflow:hidden;border:1px solid var(--db-border);border-radius:12px;background:#fff}.agenda-calendar__weekday{padding:10px;text-align:center;background:#f2f5f8;color:var(--db-muted);font-size:11px;font-weight:750;text-transform:uppercase}.agenda-calendar__day{min-height:125px;padding:8px;border-top:1px solid var(--db-border);border-right:1px solid var(--db-border);min-width:0}.agenda-calendar__day:nth-child(7n){border-right:0}.agenda-calendar__day.is-outside{background:#fafbfc;color:#aab2bd}.agenda-calendar__day.is-today{background:#fffaf0}.agenda-calendar__number{display:block;margin-bottom:5px;font-size:12px;font-weight:750}.agenda-calendar__event{display:block;margin:4px 0;padding:4px 5px;overflow:hidden;border-radius:5px;background:#eaf1fa;color:#174c83;font-size:11px;line-height:1.35;overflow-wrap:anywhere}.agenda-calendar__event time{font-weight:800;margin-right:3px}
@media(max-width:700px){.agenda-heading{align-items:flex-start;flex-direction:column}.agenda-selector-line{align-items:center;flex-direction:row;gap:8px}.agenda-selector-button{flex:0 0 58px;width:58px;height:58px;min-width:58px;min-height:58px}.agenda-selector-panel{width:auto;flex:1;min-width:0}.agenda-organizer-strip{gap:9px}.agenda-organizer-item{flex-basis:76px}.agenda-organizer-item img,.agenda-organizer-fallback,.agenda-organizer-all{width:58px;height:58px;flex-basis:58px}.agenda-selector-actions{align-items:flex-start;flex-direction:column}.agenda-toolbar{align-items:flex-start;flex-direction:column}.agenda-calendar__day{min-height:95px;padding:4px}.agenda-calendar__weekday{padding:7px 2px;font-size:9px}.agenda-calendar__event{font-size:9px;padding:3px}.agenda-card__image-link{aspect-ratio:4/3}.agenda-card__details{padding:15px 17px 18px}}
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
}
if (agendaFilterSwitch) {
    agendaFilterSwitch.addEventListener('click', function () {
        showAgendaFilter(agendaFilterIndex + 1);
    });
    showAgendaFilter(agendaFilterIndex);
}
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
document.addEventListener('click', function (event) {
    const shareButton = event.target.closest('[data-share-url]');
    if (!shareButton) return;
    const text = shareButton.dataset.shareTitle + ' — ' + shareButton.dataset.shareUrl;
    window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank', 'noopener,noreferrer');
});
</script>
<?php require dirname(__DIR__) . '/app/views/footer.php'; ?>
