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
                   l.city AS location_city
            FROM events e
            INNER JOIN organizers o ON o.id = e.organizer_id
            LEFT JOIN categories c ON c.id = e.category_id
            LEFT JOIN locations l ON l.id = e.location_id
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
$monthLabel = $monthStart->format('F Y');
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
            <p>Vind dansavonden, feesten, workshops en festivals bij jou in de buurt.</p>
        </div>
        <div class="agenda-hero__month">
            <span><?= $escape($monthStart->format('F')) ?></span>
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
            <label>Dansstijl / categorie
                <select name="category_id">
                    <option value="">Alle categorieën</option>
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
            <button class="button button--primary" type="submit">Filter</button>
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
                <a class="<?= $view === 'list' ? 'is-active' : '' ?>" href="/?<?= $escape(http_build_query(array_merge($queryBase, ['view' => 'list']))) ?>">Lijst</a>
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
            <div class="agenda-list">
                <?php foreach ($events as $event): ?>
                    <?php $start = new DateTimeImmutable((string) $event['starts_at'], $timezone); ?>
                    <article class="agenda-event">
                        <div class="agenda-event__date">
                            <span><?= $escape($start->format('D')) ?></span>
                            <strong><?= $escape($start->format('d')) ?></strong>
                            <span><?= $escape($start->format('M')) ?></span>
                        </div>
                        <div class="agenda-event__body">
                            <div class="agenda-event__meta">
                                <time datetime="<?= $escape($start->format(DateTimeInterface::ATOM)) ?>"><?= $escape($start->format('H:i')) ?></time>
                                <?php if (!empty($event['category_name'])): ?><span><?= $escape($event['category_name']) ?></span><?php endif; ?>
                            </div>
                            <h2><a href="/event.php?id=<?= (int) $event['id'] ?>"><?= $escape($event['title']) ?></a></h2>
                            <p class="agenda-event__details"><?= $escape($event['organizer_name']) ?><?php if (!empty($event['location_name'])): ?> · <?= $escape($event['location_name']) ?><?php endif; ?><?php if (!empty($event['location_city'])): ?>, <?= $escape($event['location_city']) ?><?php endif; ?></p>
                            <?php if (!empty($event['description'])): ?><p class="agenda-event__description"><?= $escape(mb_strimwidth(strip_tags((string) $event['description']), 0, 190, '…')) ?></p><?php endif; ?>
                            <a class="agenda-event__link" href="/event.php?id=<?= (int) $event['id'] ?>">Bekijk evenement <span aria-hidden="true">→</span></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="agenda-count"><?= count($events) ?> evenement<?= count($events) === 1 ? '' : 'en' ?> gevonden</p>
    <?php endif; ?>
</section>

<style>
.agenda-page{max-width:1100px;margin:0 auto}.agenda-hero{display:flex;align-items:center;justify-content:space-between;gap:24px;margin:0 0 28px;padding:34px 36px;border-radius:18px;background:linear-gradient(120deg,#202b38,#35475b);color:#fff}.agenda-hero h1{color:#fff;margin-bottom:8px}.agenda-hero p:not(.eyebrow){margin:0;color:#e0e7ef}.agenda-hero .eyebrow{color:#f6b22e}.agenda-hero__month{text-align:center;min-width:100px;padding:15px;border:1px solid #ffffff38;border-radius:12px}.agenda-hero__month span,.agenda-hero__month strong{display:block}.agenda-hero__month span{font-size:13px;text-transform:uppercase;letter-spacing:1px}.agenda-hero__month strong{font-size:27px}.agenda-filters{display:flex;align-items:end;flex-wrap:wrap;gap:12px;margin-bottom:25px;padding:18px;background:#fff;border:1px solid var(--db-border);border-radius:12px}.agenda-filters label{display:flex;flex-direction:column;gap:5px;min-width:170px;color:var(--db-muted);font-size:12px;font-weight:650}.agenda-filters select,.agenda-filters input{height:42px;min-width:0;padding:8px 10px;border:1px solid var(--db-border);border-radius:7px;background:#fff;color:var(--db-text)}.agenda-filters .agenda-search{flex:1}.agenda-filters .agenda-search input{width:100%}.agenda-reset,.agenda-today{font-size:13px;font-weight:650;padding:10px 5px}.agenda-toolbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin:0 0 17px}.agenda-month-nav{display:flex;align-items:center;gap:10px}.agenda-month-nav h2{margin:0;min-width:150px;text-align:center}.agenda-month-nav .button{min-width:40px;padding:8px;font-size:22px}.agenda-view-switch{display:flex;padding:4px;background:#e9edf2;border-radius:9px}.agenda-view-switch a{padding:7px 13px;border-radius:6px;color:var(--db-muted);font-size:13px;font-weight:650}.agenda-view-switch a.is-active{background:#fff;color:var(--db-text);box-shadow:0 1px 4px #00000012}.agenda-list{display:grid;gap:13px}.agenda-event{display:grid;grid-template-columns:76px minmax(0,1fr);gap:19px;padding:20px;background:#fff;border:1px solid var(--db-border);border-radius:12px;box-shadow:var(--db-shadow)}.agenda-event__date{display:flex;flex-direction:column;align-items:center;justify-content:center;align-self:start;min-height:86px;padding:8px;border-radius:9px;background:#fff4e8;color:#8e4a10}.agenda-event__date span{font-size:11px;font-weight:700;text-transform:uppercase}.agenda-event__date strong{font-size:29px;line-height:1.15}.agenda-event__meta{display:flex;gap:9px;align-items:center;color:var(--db-muted);font-size:12px;font-weight:650}.agenda-event__meta span{padding:3px 8px;border-radius:20px;background:#eef3f8}.agenda-event__body h2{margin:5px 0;font-size:19px}.agenda-event__details,.agenda-event__description{margin:0 0 7px;color:var(--db-muted);font-size:13px}.agenda-event__description{max-width:760px}.agenda-event__link{font-size:13px;font-weight:700}.agenda-count{margin:15px 2px;color:var(--db-muted);font-size:12px}.agenda-empty{padding:38px 24px;text-align:center;background:#fff;border:1px solid var(--db-border);border-radius:12px}.agenda-empty p{color:var(--db-muted);margin:0}.agenda-calendar{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));overflow:hidden;border:1px solid var(--db-border);border-radius:12px;background:#fff}.agenda-calendar__weekday{padding:10px;text-align:center;background:#f2f5f8;color:var(--db-muted);font-size:11px;font-weight:750;text-transform:uppercase}.agenda-calendar__day{min-height:125px;padding:8px;border-top:1px solid var(--db-border);border-right:1px solid var(--db-border);min-width:0}.agenda-calendar__day:nth-child(7n){border-right:0}.agenda-calendar__day.is-outside{background:#fafbfc;color:#aab2bd}.agenda-calendar__day.is-today{background:#fffaf0}.agenda-calendar__number{display:block;margin-bottom:5px;font-size:12px;font-weight:750}.agenda-calendar__event{display:block;margin:4px 0;padding:4px 5px;overflow:hidden;border-radius:5px;background:#eaf1fa;color:#174c83;font-size:11px;line-height:1.35;overflow-wrap:anywhere}.agenda-calendar__event time{font-weight:800;margin-right:3px}.agenda-calendar__event:hover{background:#dbe8f7;color:#123b68}
@media(max-width:700px){.agenda-hero{padding:24px 20px}.agenda-hero__month{min-width:75px;padding:10px}.agenda-hero__month strong{font-size:22px}.agenda-filters{align-items:stretch}.agenda-filters label{flex:1 1 100%;min-width:0}.agenda-toolbar{align-items:flex-start;flex-direction:column}.agenda-calendar__day{min-height:95px;padding:4px}.agenda-calendar__weekday{padding:7px 2px;font-size:9px}.agenda-calendar__event{font-size:9px;padding:3px}.agenda-event{grid-template-columns:58px minmax(0,1fr);gap:12px;padding:14px}.agenda-event__date{min-height:75px}.agenda-event__date strong{font-size:25px}}
@media(max-width:420px){.agenda-hero{align-items:flex-start}.agenda-hero__month{display:none}.agenda-month-nav{width:100%;gap:6px}.agenda-month-nav h2{flex:1;min-width:0;font-size:18px}.agenda-month-nav .button{min-width:34px}.agenda-calendar__day{min-height:82px}.agenda-calendar__event time{display:none}}
</style>
<?php require dirname(__DIR__) . '/app/views/footer.php'; ?>
