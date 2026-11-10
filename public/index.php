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
if (mb_strlen($city) > 120) {
    $city = mb_substr($city, 0, 120);
}

$organizers = [];
$cities = [];
$events = [];
if ($regionId > 0) {
    $organizerQuery = $db->prepare(
        "SELECT DISTINCT o.id, o.name
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
        "SELECT DISTINCT city
         FROM locations
         WHERE region_id = :region_id AND is_active = 1 AND city <> ''
         ORDER BY city"
    );
    $cityQuery->execute(['region_id' => $regionId]);
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
            <input type="hidden" name="region_id" id="agenda-region-value" value="<?= $regionId ?>">
            <div class="agenda-selector-line">
                <button type="button" class="agenda-selector-button is-active" id="agenda-filter-switch" data-filter-index="0" aria-label="Wissel filter">Regio <span>⇄</span></button>
                <div class="agenda-selector-panel is-open" data-panel="region">
                    <select id="agenda-region" name="region_choice" aria-label="Kies regio">
                        <?php foreach ($regions as $regionOption): ?>
                            <option value="<?= (int) $regionOption['id'] ?>" <?= $regionId === (int) $regionOption['id'] ? 'selected' : '' ?>><?= $escape($regionOption['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="agenda-selector-panel" data-panel="organizer" hidden>
                    <select id="agenda-organizer" name="organizer_id" disabled aria-label="Kies organisator">
                        <option value="">Alle organisatoren</option>
                        <?php foreach ($organizers as $organizerOption): ?>
                            <option value="<?= (int) $organizerOption['id'] ?>" <?= $organizerId === (int) $organizerOption['id'] ? 'selected' : '' ?>><?= $escape($organizerOption['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="agenda-selector-panel" data-panel="city" hidden>
                    <select id="agenda-city" name="city" disabled aria-label="Kies stad">
                        <option value="">Alle steden</option>
                        <?php foreach ($cities as $cityOption): ?>
                            <option value="<?= $escape($cityOption['city']) ?>" <?= $city === $cityOption['city'] ? 'selected' : '' ?>><?= $escape($cityOption['city']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="agenda-selector-actions">
                <button class="button button--primary" type="submit">Toon evenementen</button>
                <a class="agenda-reset" href="/?region_id=<?= $regionId ?>">Wis filters</a>
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
                        <a class="agenda-card__image-link" href="<?= $eventUrl ?>" aria-label="Bekijk <?= $escape($event['title']) ?>">
                            <?php if (!empty($event['cover_media_id'])): ?>
                                <img class="agenda-card__image" src="/event-media.php?id=<?= (int) $event['cover_media_id'] ?>" alt="<?= $escape($event['title']) ?>" loading="lazy">
                            <?php else: ?>
                                <div class="agenda-card__placeholder"><span>SALSA · BACHATA · KIZOMBA</span><strong><?= $escape($event['title']) ?></strong></div>
                            <?php endif; ?>
                        </a>
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
.agenda-page{width:100%;max-width:1080px;margin:0 auto}.agenda-selectors{margin:0 0 22px;padding:14px;background:#fff;border:1px solid var(--db-border);border-radius:12px}.agenda-selector-line{display:flex;align-items:center;gap:12px}.agenda-selector-button{flex:0 0 190px;display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 16px;border:1px solid var(--db-border);border-radius:9px;background:var(--db-primary);color:#fff;font-weight:750;cursor:pointer}.agenda-selector-panel{display:flex;align-items:center;flex:1;min-width:0}.agenda-selector-panel[hidden]{display:none}.agenda-selector-panel select{width:100%;min-width:0;height:44px;padding:8px 11px;border:1px solid var(--db-border);border-radius:7px;background:#fff;color:var(--db-text)}.agenda-selector-actions{display:flex;align-items:center;gap:15px;margin-top:12px}.agenda-heading{display:flex;align-items:center;justify-content:space-between;gap:20px;margin:0 0 20px}.agenda-heading h1{margin:0 0 4px}.agenda-heading p:not(.eyebrow){margin:0;color:var(--db-muted)}.agenda-month-select{display:flex;align-items:center;gap:9px;color:var(--db-muted);font-size:12px;font-weight:700}.agenda-month-select input{padding:9px;border:1px solid var(--db-border);border-radius:8px;background:#fff;color:var(--db-text)}.agenda-filters{display:flex;align-items:end;flex-wrap:wrap;gap:10px;margin-bottom:20px;padding:13px;background:#fff;border:1px solid var(--db-border);border-radius:10px}.agenda-filters label{display:flex;flex-direction:column;gap:4px;min-width:155px;color:var(--db-muted);font-size:11px;font-weight:700}.agenda-filters select,.agenda-filters input{height:40px;min-width:0;padding:7px 10px;border:1px solid var(--db-border);border-radius:7px;background:#fff;color:var(--db-text)}.agenda-filters .agenda-search{flex:1}.agenda-filters .agenda-search input{width:100%}.agenda-reset,.agenda-today{font-size:12px;font-weight:700;padding:9px 5px}.agenda-toolbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin:0 0 15px}.agenda-month-nav{display:flex;align-items:center;gap:9px}.agenda-month-nav h2{margin:0;min-width:140px;text-align:center;font-size:18px}.agenda-month-nav .button{min-width:36px;padding:6px;font-size:20px}.agenda-view-switch{display:flex;padding:3px;background:#eceff2;border-radius:8px}.agenda-view-switch a{padding:6px 11px;border-radius:6px;color:var(--db-muted);font-size:12px;font-weight:700}.agenda-view-switch a.is-active{background:#fff;color:var(--db-text);box-shadow:0 1px 4px #0001}.agenda-feed{display:grid;grid-template-columns:minmax(0,1fr);gap:20px;max-width:880px;margin:0 auto}.agenda-card{overflow:hidden;border:1px solid #65c99b;border-radius:15px;background:#f78d19;box-shadow:0 4px 18px #0000000d}.agenda-card__image-link{display:block;overflow:hidden;background:#171717;aspect-ratio:4/3}.agenda-card__image{display:block;width:100%;height:100%;object-fit:contain;background:#171717}.agenda-card__placeholder{display:flex;width:100%;height:100%;min-height:230px;flex-direction:column;align-items:center;justify-content:center;gap:13px;padding:25px;text-align:center;color:#fff;background:radial-gradient(circle at 25% 20%,#f4802a 0,transparent 38%),linear-gradient(135deg,#202b38,#35475b)}.agenda-card__placeholder span{font-size:11px;letter-spacing:2px;color:#ffd36c;font-weight:800}.agenda-card__placeholder strong{font-size:clamp(20px,3vw,30px);line-height:1.15}.agenda-card__details{padding:17px 20px 20px;color:#fff}.agenda-card__quick{display:flex;align-items:center;gap:17px;margin:0 0 13px}.agenda-card__quick-date{min-width:34px;color:#fff;font-size:22px;font-weight:800}.agenda-icon-button{width:40px;height:40px;display:grid;place-items:center;border:0;border-radius:50%;background:transparent;color:#fff;font-size:31px;line-height:1;cursor:pointer}.agenda-icon-button:hover{background:#ffffff26;color:#fff}.agenda-card__details h2{margin:0 0 7px;color:#fff;font-size:22px}.agenda-card__details h2 a{color:inherit}.agenda-card__tags{display:flex;flex-wrap:wrap;gap:16px;margin:0 0 10px;color:#fff;font-size:15px}.agenda-card__date-line{margin:0 0 7px;color:#fff;font-size:14px;font-weight:600}.agenda-card__date-line span{margin:0 8px}.agenda-card__location{margin:0 0 7px;color:#fff;font-size:13px}.agenda-card__more{display:inline-block;color:#fff;text-decoration:underline;text-underline-offset:3px;font-size:14px;font-weight:600}.agenda-card__more:hover{color:#fff}.agenda-count{margin:15px 2px;color:var(--db-muted);font-size:12px}.agenda-empty{padding:38px 24px;text-align:center;background:#fff;border:1px solid var(--db-border);border-radius:12px}.agenda-empty p{color:var(--db-muted);margin:0}.agenda-calendar{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));overflow:hidden;border:1px solid var(--db-border);border-radius:12px;background:#fff}.agenda-calendar__weekday{padding:10px;text-align:center;background:#f2f5f8;color:var(--db-muted);font-size:11px;font-weight:750;text-transform:uppercase}.agenda-calendar__day{min-height:125px;padding:8px;border-top:1px solid var(--db-border);border-right:1px solid var(--db-border);min-width:0}.agenda-calendar__day:nth-child(7n){border-right:0}.agenda-calendar__day.is-outside{background:#fafbfc;color:#aab2bd}.agenda-calendar__day.is-today{background:#fffaf0}.agenda-calendar__number{display:block;margin-bottom:5px;font-size:12px;font-weight:750}.agenda-calendar__event{display:block;margin:4px 0;padding:4px 5px;overflow:hidden;border-radius:5px;background:#eaf1fa;color:#174c83;font-size:11px;line-height:1.35;overflow-wrap:anywhere}.agenda-calendar__event time{font-weight:800;margin-right:3px}
@media(max-width:700px){.agenda-heading{align-items:flex-start;flex-direction:column}.agenda-selector-line{align-items:stretch;flex-direction:column;gap:8px}.agenda-selector-button{width:100%;flex-basis:auto}.agenda-selector-panel{width:100%}.agenda-selector-actions{align-items:flex-start;flex-direction:column}.agenda-toolbar{align-items:flex-start;flex-direction:column}.agenda-calendar__day{min-height:95px;padding:4px}.agenda-calendar__weekday{padding:7px 2px;font-size:9px}.agenda-calendar__event{font-size:9px;padding:3px}.agenda-card__image-link{aspect-ratio:4/3}.agenda-card__details{padding:15px 17px 18px}}
@media(max-width:420px){.agenda-month-nav{width:100%;gap:6px}.agenda-month-nav h2{flex:1;min-width:0;font-size:17px}.agenda-month-nav .button{min-width:34px}.agenda-calendar__day{min-height:82px}.agenda-calendar__event time{display:none}}
</style>
<script>
const agendaFilterNames = ['Regio', 'Organisator', 'Stad'];
let agendaFilterIndex = 0;
const agendaFilterSwitch = document.getElementById('agenda-filter-switch');
function showAgendaFilter(index) {
    agendaFilterIndex = index % agendaFilterNames.length;
    if (!agendaFilterSwitch) return;
    agendaFilterSwitch.innerHTML = agendaFilterNames[agendaFilterIndex] + ' <span>⇄</span>';
    document.querySelectorAll('[data-panel]').forEach(function (panel) {
        const active = panel.dataset.panel === ['region', 'organizer', 'city'][agendaFilterIndex];
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
    showAgendaFilter(0);
}
const agendaRegionSelect = document.getElementById('agenda-region');
const agendaRegionValue = document.getElementById('agenda-region-value');
if (agendaRegionSelect && agendaRegionValue) {
    agendaRegionSelect.addEventListener('change', function () {
        agendaRegionValue.value = agendaRegionSelect.value;
    });
}
document.addEventListener('click', function (event) {
    const shareButton = event.target.closest('[data-share-url]');
    if (!shareButton) return;
    const text = shareButton.dataset.shareTitle + ' — ' + shareButton.dataset.shareUrl;
    window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank', 'noopener,noreferrer');
});
</script>
<?php require dirname(__DIR__) . '/app/views/footer.php'; ?>
