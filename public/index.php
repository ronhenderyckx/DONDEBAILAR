<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Security.php';

$db = Database::connection();
$regions = $db->query(
    "SELECT id, name, timezone, logo_path FROM regions
     WHERE is_active = 1 ORDER BY name"
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

$monthInput = (string) ($_GET['month'] ?? (new DateTimeImmutable('now', $timezone))->format('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $monthInput)
    || !($monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $monthInput . '-01', $timezone))) {
    $monthStart = new DateTimeImmutable('first day of this month', $timezone);
    $monthInput = $monthStart->format('Y-m');
}
$monthEnd = $monthStart->modify('first day of next month');
$calendarStart = $monthStart->modify('monday this week');
$calendarEnd = $calendarStart->modify('+41 days');

$view = (string) ($_GET['view'] ?? 'list');
if (!in_array($view, ['list', 'calendar'], true)) {
    $view = 'list';
}
$categoryId = filter_input(INPUT_GET, 'category_id', FILTER_VALIDATE_INT) ?: 0;
$search = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

$categories = [];
$events = [];
if ($regionId > 0) {
    $categoryQuery = $db->prepare(
        "SELECT id, name FROM categories
         WHERE is_active = 1 AND (region_id IS NULL OR region_id = :region_id)
         ORDER BY sort_order, name"
    );
    $categoryQuery->execute(['region_id' => $regionId]);
    $categories = $categoryQuery->fetchAll();

    $sql = "SELECT e.id, e.title, e.description, e.starts_at, e.ends_at,
                   e.event_language, c.name AS category_name,
                   o.name AS organizer_name, l.name AS location_name,
                   l.city AS location_city, cover.id AS cover_media_id
            FROM events e
            INNER JOIN organizers o ON o.id = e.organizer_id
            LEFT JOIN categories c ON c.id = e.category_id
            LEFT JOIN locations l ON l.id = e.location_id
            LEFT JOIN event_media cover ON cover.id = (
                SELECT em.id
                FROM event_media em
                WHERE em.event_id = e.id
                  AND em.media_context = 'event'
                  AND em.media_type = 'image'
                  AND em.media_source = 'upload'
                ORDER BY em.sort_order, em.id
                LIMIT 1
            )
            WHERE e.region_id = :region_id
              AND e.status = 'published'
              AND e.starts_at >= :month_start
              AND e.starts_at < :month_end";
    $params = [
        'region_id' => $regionId,
        'month_start' => $monthStart->format('Y-m-d H:i:s'),
        'month_end' => $monthEnd->format('Y-m-d H:i:s'),
    ];
    if ($categoryId > 0 && in_array($categoryId, array_map(static fn($item) => (int) $item['id'], $categories), true)) {
        $sql .= " AND e.category_id = :category_id";
        $params['category_id'] = $categoryId;
    } else {
        $categoryId = 0;
    }
    if ($search !== '') {
        $sql .= " AND (e.title LIKE :search_title OR e.description LIKE :search_description OR o.name LIKE :search_organizer)";
        $term = '%' . $search . '%';
        $params['search_title'] = $term;
        $params['search_description'] = $term;
        $params['search_organizer'] = $term;
    }
    $sql .= " ORDER BY e.starts_at ASC, e.id ASC LIMIT 500";
    $eventQuery = $db->prepare($sql);
    $eventQuery->execute($params);
    $events = $eventQuery->fetchAll();
}

$eventsByDay = [];
foreach ($events as $event) {
    $day = (new DateTimeImmutable((string) $event['starts_at'], $timezone))->format('Y-m-d');
    $eventsByDay[$day][] = $event;
}
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$monthNames = [1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
$weekdayShort = [1 => 'ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'];
$monthLabel = $monthNames[(int) $monthStart->format('n')] . ' ' . $monthStart->format('Y');
$previousMonth = $monthStart->modify('-1 month')->format('Y-m');
$nextMonth = $monthStart->modify('+1 month')->format('Y-m');
$queryBase = [
    'region_id' => $regionId,
    'month' => $monthInput,
    'category_id' => $categoryId ?: null,
    'q' => $search !== '' ? $search : null,
];
$queryBase = array_filter($queryBase, static fn($value) => $value !== null);
$pageTitle = 'Agenda' . ($region ? ' — ' . $region['name'] : '') . ' — DONDEBAILAR';
$pageRobots = 'index, follow';
require dirname(__DIR__) . '/app/views/header.php';
?>
<section class="agenda-page">
    <div class="agenda-hero">
        <div>
            <p class="eyebrow">SALSA · BACHATA · KIZOMBA</p>
            <h1>Dansagenda<?= $region ? ' ' . $escape($region['name']) : '' ?></h1>
            <p>Alle dansavonden, feesten, workshops en festivals overzichtelijk bij elkaar.</p>
        </div>
        <div class="agenda-hero__month">
            <span><?= $escape($monthNames[(int) $monthStart->format('n')]) ?></span>
            <strong><?= $escape($monthStart->format('Y')) ?></strong>
        </div>
    </div>

    <?php if ($regions === []): ?>
        <section class="agenda-empty"><h2>De agenda wordt voorbereid</h2><p>Er zijn nog geen actieve regio's beschikbaar.</p></section>
    <?php else: ?>
        <form class="agenda-filters" method="get" action="/">
            <label>Regio
                <select name="region_id" onchange="this.form.submit()">
                    <?php foreach ($regions as $item): ?>
                        <option value="<?= (int) $item['id'] ?>" <?= $regionId === (int) $item['id'] ? 'selected' : '' ?>><?= $escape($item['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Dansstijl
                <select name="category_id">
                    <option value="">Alle dansstijlen</option>
                    <?php foreach ($categories as $item): ?>
                        <option value="<?= (int) $item['id'] ?>" <?= $categoryId === (int) $item['id'] ? 'selected' : '' ?>><?= $escape($item['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="agenda-search">Zoeken
                <input type="search" name="q" value="<?= $escape($search) ?>" placeholder="Evenement of organisator">
            </label>
            <input type="hidden" name="month" value="<?= $escape($monthInput) ?>">
            <input type="hidden" name="view" value="<?= $escape($view) ?>">
            <button class="button button--primary" type="submit">Toon evenementen</button>
            <a class="agenda-reset" href="/?region_id=<?= $regionId ?>&month=<?= $escape($monthInput) ?>">Wis filters</a>
        </form>

        <div class="agenda-toolbar">
            <div class="agenda-month-nav">
                <a class="button" href="/?<?= $escape(http_build_query(array_merge($queryBase, ['month' => $previousMonth, 'view' => $view]))) ?>" aria-label="Vorige maand">‹</a>
                <h2><?= $escape($monthLabel) ?></h2>
                <a class="button" href="/?<?= $escape(http_build_query(array_merge($queryBase, ['month' => $nextMonth, 'view' => $view]))) ?>" aria-label="Volgende maand">›</a>
                <a class="agenda-today" href="/?region_id=<?= $regionId ?>&month=<?= (new DateTimeImmutable('now', $timezone))->format('Y-m') ?>&view=<?= $escape($view) ?>">Vandaag</a>
            </div>
            <div class="agenda-view-switch" aria-label="Agendaweergave">
                <a class="<?= $view === 'list' ? 'is-active' : '' ?>" href="/?<?= $escape(http_build_query(array_merge($queryBase, ['view' => 'list']))) ?>">Feed</a>
                <a class="<?= $view === 'calendar' ? 'is-active' : '' ?>" href="/?<?= $escape(http_build_query(array_merge($queryBase, ['view' => 'calendar']))) ?>">Kalender</a>
            </div>
        </div>

        <?php if ($events === []): ?>
            <section class="agenda-empty"><h2>Geen evenementen gevonden</h2><p>Er zijn in deze maand geen gepubliceerde evenementen met deze filters.</p></section>
        <?php elseif ($view === 'calendar'): ?>
            <div class="agenda-calendar">
                <?php foreach (['Ma','Di','Wo','Do','Vr','Za','Zo'] as $weekday): ?><div class="agenda-calendar__weekday"><?= $weekday ?></div><?php endforeach; ?>
                <?php for ($day = $calendarStart; $day <= $calendarEnd; $day = $day->modify('+1 day')): ?>
                    <?php $key = $day->format('Y-m-d'); $outside = $day->format('Y-m') !== $monthInput; ?>
                    <div class="agenda-calendar__day <?= $outside ? 'is-outside' : '' ?> <?= $day->format('Y-m-d') === (new DateTimeImmutable('today', $timezone))->format('Y-m-d') ? 'is-today' : '' ?>">
                        <span class="agenda-calendar__number"><?= $day->format('j') ?></span>
                        <?php foreach (($eventsByDay[$key] ?? []) as $event): ?>
                            <a class="agenda-calendar__event" href="/event.php?id=<?= (int) $event['id'] ?>">
                                <time><?= $escape((new DateTimeImmutable((string) $event['starts_at'], $timezone))->format('H:i')) ?></time>
                                <?= $escape($event['title']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endfor; ?>
            </div>
        <?php else: ?>
            <div class="agenda-feed">
                <?php foreach ($events as $event): ?>
                    <?php
                    $start = new DateTimeImmutable((string) $event['starts_at'], $timezone);
                    $eventUrl = '/event.php?id=' . (int) $event['id'];
                    $locationLabel = trim((string) ($event['location_name'] ?? '') . (!empty($event['location_city']) ? ', ' . $event['location_city'] : ''));
                    ?>
                    <article class="agenda-card">
                        <a class="agenda-card__image-link" href="<?= $eventUrl ?>" aria-label="Bekijk <?= $escape($event['title']) ?>">
                            <?php if (!empty($event['cover_media_id'])): ?>
                                <img class="agenda-card__image" src="/event-media.php?id=<?= (int) $event['cover_media_id'] ?>" alt="<?= $escape($event['title']) ?>" loading="lazy">
                            <?php else: ?>
                                <div class="agenda-card__placeholder">
                                    <span>SALSA · BACHATA · KIZOMBA</span>
                                    <strong><?= $escape($event['title']) ?></strong>
                                </div>
                            <?php endif; ?>
                            <span class="agenda-card__date"><strong><?= $escape($start->format('d')) ?></strong><span><?= $escape($weekdayShort[(int) $start->format('N')] . ' ' . substr($monthNames[(int) $start->format('n')], 0, 3)) ?></span></span>
                        </a>
                        <div class="agenda-card__content">
                            <div class="agenda-card__eyebrow">
                                <?php if (!empty($event['category_name'])): ?><span class="agenda-card__category"><?= $escape($event['category_name']) ?></span><?php endif; ?>
                                <time datetime="<?= $escape($start->format(DateTimeInterface::ATOM)) ?>"><?= $escape($start->format('H:i')) ?> uur</time>
                            </div>
                            <h2><a href="<?= $eventUrl ?>"><?= $escape($event['title']) ?></a></h2>
                            <p class="agenda-card__organizer"><?= $escape($event['organizer_name']) ?></p>
                            <?php if ($locationLabel !== ''): ?><p class="agenda-card__location"><span aria-hidden="true">⌖</span> <?= $escape($locationLabel) ?></p><?php endif; ?>
                            <?php if (!empty($event['description'])): ?><p class="agenda-card__description"><?= $escape(mb_strimwidth(strip_tags((string) $event['description']), 0, 220, '…')) ?></p><?php endif; ?>
                            <div class="agenda-card__actions">
                                <a class="button button--primary" href="<?= $eventUrl ?>">Meer info</a>
                                <button class="agenda-share" type="button" data-share-title="<?= $escape($event['title']) ?>" data-share-url="<?= $eventUrl ?>" aria-label="Deel <?= $escape($event['title']) ?> via WhatsApp">
                                    <span aria-hidden="true">↗</span> Deel via WhatsApp
                                </button>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="agenda-count"><?= count($events) ?> evenement<?= count($events) === 1 ? '' : 'en' ?> gevonden</p>
    <?php endif; ?>
</section>

<style>
.agenda-page{max-width:1120px;margin:0 auto}.agenda-hero{display:flex;align-items:center;justify-content:space-between;gap:24px;margin:0 0 26px;padding:34px 36px;border-radius:18px;background:linear-gradient(120deg,#202b38,#35475b);color:#fff}.agenda-hero h1{color:#fff;margin-bottom:8px}.agenda-hero p:not(.eyebrow){margin:0;color:#e0e7ef}.agenda-hero .eyebrow{color:#f6b22e}.agenda-hero__month{text-align:center;min-width:100px;padding:15px;border:1px solid #ffffff38;border-radius:12px}.agenda-hero__month span,.agenda-hero__month strong{display:block}.agenda-hero__month span{font-size:13px;text-transform:uppercase;letter-spacing:1px}.agenda-hero__month strong{font-size:27px}.agenda-filters{display:flex;align-items:end;flex-wrap:wrap;gap:12px;margin-bottom:24px;padding:16px;background:#fff;border:1px solid var(--db-border);border-radius:12px}.agenda-filters label{display:flex;flex-direction:column;gap:5px;min-width:165px;color:var(--db-muted);font-size:12px;font-weight:650}.agenda-filters select,.agenda-filters input{height:42px;min-width:0;padding:8px 10px;border:1px solid var(--db-border);border-radius:7px;background:#fff;color:var(--db-text)}.agenda-filters .agenda-search{flex:1}.agenda-filters .agenda-search input{width:100%}.agenda-reset,.agenda-today{font-size:13px;font-weight:650;padding:10px 5px}.agenda-toolbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin:0 0 18px}.agenda-month-nav{display:flex;align-items:center;gap:10px}.agenda-month-nav h2{margin:0;min-width:150px;text-align:center}.agenda-month-nav .button{min-width:40px;padding:8px;font-size:22px}.agenda-view-switch{display:flex;padding:4px;background:#e9edf2;border-radius:9px}.agenda-view-switch a{padding:7px 13px;border-radius:6px;color:var(--db-muted);font-size:13px;font-weight:650}.agenda-view-switch a.is-active{background:#fff;color:var(--db-text);box-shadow:0 1px 4px #00000012}.agenda-feed{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:22px;align-items:start}.agenda-card{min-width:0;overflow:hidden;background:#fff;border:1px solid var(--db-border);border-radius:14px;box-shadow:var(--db-shadow)}.agenda-card__image-link{position:relative;display:block;overflow:hidden;background:#202b38;aspect-ratio:4/3}.agenda-card__image{display:block;width:100%;height:100%;object-fit:cover;transition:transform .25s ease}.agenda-card__image-link:hover .agenda-card__image{transform:scale(1.025)}.agenda-card__placeholder{display:flex;width:100%;height:100%;min-height:220px;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:25px;text-align:center;color:#fff;background:radial-gradient(circle at 25% 20%,#f4802a 0,transparent 38%),linear-gradient(135deg,#202b38,#35475b)}.agenda-card__placeholder span{font-size:11px;letter-spacing:2px;color:#f6b22e;font-weight:800}.agenda-card__placeholder strong{font-size:clamp(20px,3vw,30px);line-height:1.15}.agenda-card__date{position:absolute;top:14px;left:14px;display:flex;flex-direction:column;align-items:center;justify-content:center;min-width:58px;padding:7px 9px;border-radius:9px;background:#fff;color:#222;box-shadow:0 3px 12px #0002}.agenda-card__date strong{font-size:25px;line-height:1.05}.agenda-card__date span{font-size:10px;text-transform:uppercase;font-weight:750}.agenda-card__content{padding:18px 19px 19px}.agenda-card__eyebrow{display:flex;align-items:center;gap:9px;flex-wrap:wrap;color:var(--db-muted);font-size:12px;font-weight:700}.agenda-card__category{padding:4px 9px;border-radius:20px;background:#fff1df;color:#95500d}.agenda-card__content h2{margin:9px 0 5px;font-size:21px;line-height:1.2}.agenda-card__content h2 a{color:var(--db-text)}.agenda-card__organizer{margin:0 0 5px;font-size:13px;font-weight:700}.agenda-card__location{margin:0 0 9px;color:var(--db-muted);font-size:13px}.agenda-card__description{margin:10px 0 15px;color:var(--db-muted);font-size:13px;line-height:1.55}.agenda-card__actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap}.agenda-card__actions .button{padding:9px 15px}.agenda-share{display:inline-flex;align-items:center;gap:6px;padding:8px 0;border:0;background:none;color:#16834a;font:inherit;font-size:13px;font-weight:750;cursor:pointer}.agenda-share:hover{text-decoration:underline}.agenda-count{margin:15px 2px;color:var(--db-muted);font-size:12px}.agenda-empty{padding:38px 24px;text-align:center;background:#fff;border:1px solid var(--db-border);border-radius:12px}.agenda-empty p{color:var(--db-muted);margin:0}.agenda-calendar{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));overflow:hidden;border:1px solid var(--db-border);border-radius:12px;background:#fff}.agenda-calendar__weekday{padding:10px;text-align:center;background:#f2f5f8;color:var(--db-muted);font-size:11px;font-weight:750;text-transform:uppercase}.agenda-calendar__day{min-height:125px;padding:8px;border-top:1px solid var(--db-border);border-right:1px solid var(--db-border);min-width:0}.agenda-calendar__day:nth-child(7n){border-right:0}.agenda-calendar__day.is-outside{background:#fafbfc;color:#aab2bd}.agenda-calendar__day.is-today{background:#fffaf0}.agenda-calendar__number{display:block;margin-bottom:5px;font-size:12px;font-weight:750}.agenda-calendar__event{display:block;margin:4px 0;padding:4px 5px;overflow:hidden;border-radius:5px;background:#eaf1fa;color:#174c83;font-size:11px;line-height:1.35;overflow-wrap:anywhere}.agenda-calendar__event time{font-weight:800;margin-right:3px}.agenda-calendar__event:hover{background:#dbe8f7;color:#123b68}
@media(max-width:760px){.agenda-feed{grid-template-columns:1fr;max-width:620px;margin:0 auto}.agenda-hero{padding:25px 21px}.agenda-filters{align-items:stretch}.agenda-filters label{flex:1 1 100%;min-width:0}.agenda-toolbar{align-items:flex-start;flex-direction:column}.agenda-calendar__day{min-height:95px;padding:4px}.agenda-calendar__weekday{padding:7px 2px;font-size:9px}.agenda-calendar__event{font-size:9px;padding:3px}}
@media(max-width:420px){.agenda-hero__month{display:none}.agenda-month-nav{width:100%;gap:6px}.agenda-month-nav h2{flex:1;min-width:0;font-size:18px}.agenda-month-nav .button{min-width:34px}.agenda-calendar__day{min-height:82px}.agenda-calendar__event time{display:none}.agenda-card__content{padding:15px}.agenda-card__content h2{font-size:19px}}
</style>
<script>
document.addEventListener('click', function (event) {
    const button = event.target.closest('[data-share-url]');
    if (!button) return;
    const url = new URL(button.dataset.shareUrl, window.location.origin).href;
    const text = button.dataset.shareTitle + ' — ' + url;
    window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank', 'noopener,noreferrer');
});
</script>
<?php require dirname(__DIR__) . '/app/views/footer.php'; ?>
