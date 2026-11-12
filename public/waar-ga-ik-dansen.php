<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Authorization.php';
require_once dirname(__DIR__) . '/app/Security.php';

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

$statement = $db->prepare(
    "SELECT e.id, e.title, e.description, e.starts_at, e.ends_at, e.timezone,
            e.event_language, e.hashtags,
            r.id AS region_id, r.name AS region_name,
            o.name AS organizer_name, o.logo_path AS organizer_logo,
            c.name AS category_name,
            l.name AS location_name, l.street AS location_street,
            l.house_number AS location_house_number, l.postal_code AS location_postal_code,
            l.city AS location_city, l.country_code AS location_country,
            l.google_maps_url, l.latitude, l.longitude,
            cover.id AS cover_media_id, cover.media_type AS cover_media_type,
            cover.caption AS cover_caption
     FROM event_attendances ea
     INNER JOIN events e ON e.id = ea.event_id
     INNER JOIN regions r ON r.id = e.region_id
     INNER JOIN organizers o ON o.id = e.organizer_id
     LEFT JOIN categories c ON c.id = e.category_id
     LEFT JOIN locations l ON l.id = e.location_id
     LEFT JOIN event_media cover ON cover.id = e.cover_media_id
     WHERE ea.user_id = :user_id
       AND ea.status = 'attending'
       AND e.status = 'published'
       AND e.starts_at >= NOW()
     ORDER BY e.starts_at ASC, e.id ASC"
);
$statement->execute(['user_id' => (int) $user['id']]);
$myEvents = $statement->fetchAll();

$pageTitle = 'Waar ga ik dansen? - DONDEBAILAR';
require dirname(__DIR__) . '/app/views/header.php';
?>
<section class="admin-page my-dance-events">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">MIJN DONDEBAILAR</p>
            <h1>Waar ga ik dansen?</h1>
            <p>De evenementen waarvoor je hebt aangegeven dat je gaat dansen.</p>
        </div>
    </div>

    <?php if ($myEvents === []): ?>
        <section class="admin-section">
            <p>Je hebt nog geen toekomstige evenementen geselecteerd.</p>
            <p><a class="button button--primary" href="/">Bekijk de agenda</a></p>
        </section>
    <?php else: ?>
        <div class="my-dance-events__list">
            <?php foreach ($myEvents as $event): ?>
                <article class="admin-section my-dance-events__item">
                    <div class="my-dance-events__date">
                        <strong><?= escapeHtml(date('d', strtotime($event['starts_at']))) ?></strong>
                        <span><?= escapeHtml(date('M', strtotime($event['starts_at']))) ?></span>
                    </div>
                    <div class="my-dance-events__details">
                        <h2><a href="/event.php?id=<?= (int) $event['id'] ?>"><?= escapeHtml($event['title']) ?></a></h2>
                        <p class="my-dance-events__when">
                            <strong><?= escapeHtml(date('d-m-Y', strtotime($event['starts_at']))) ?></strong>
                            · <?= escapeHtml(date('H:i', strtotime($event['starts_at']))) ?> uur
                            <?php if (!empty($event['ends_at'])): ?>– <?= escapeHtml(date('H:i', strtotime($event['ends_at']))) ?> uur<?php endif; ?>
                        </p>
                        <?php if (!empty($event['category_name'])): ?><p><strong>Dansstijl:</strong> <?= escapeHtml($event['category_name']) ?></p><?php endif; ?>
                        <p><strong>Organisator:</strong> <?= escapeHtml($event['organizer_name']) ?></p>
                        <?php if (!empty($event['location_name'])): ?><p><strong>Locatie:</strong> <?= escapeHtml($event['location_name']) ?></p><?php endif; ?>
                        <?php
                        $addressParts = array_filter([
                            trim((string) ($event['location_street'] ?? '') . ' ' . (string) ($event['location_house_number'] ?? '')),
                            trim((string) ($event['location_postal_code'] ?? '') . ' ' . (string) ($event['location_city'] ?? '')),
                            $event['location_country'] ?? '',
                        ]);
                        ?>
                        <?php if ($addressParts !== []): ?><p><strong>Adres:</strong> <?= escapeHtml(implode(', ', $addressParts)) ?></p><?php endif; ?>
                        <?php if (!empty($event['google_maps_url'])): ?>
                            <p><a href="<?= escapeHtml($event['google_maps_url']) ?>" target="_blank" rel="noopener noreferrer">Bekijk route</a></p>
                        <?php elseif (!empty($event['latitude']) && !empty($event['longitude'])): ?>
                            <p><a href="https://www.google.com/maps?q=<?= rawurlencode((string) $event['latitude']) ?>,<?= rawurlencode((string) $event['longitude']) ?>" target="_blank" rel="noopener noreferrer">Bekijk route</a></p>
                        <?php endif; ?>
                        <?php if (!empty($event['description'])): ?>
                            <p class="my-dance-events__description"><?= nl2br(escapeHtml($event['description'])) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($event['hashtags'])): ?><p class="my-dance-events__hashtags"><?= escapeHtml($event['hashtags']) ?></p><?php endif; ?>
                        <p class="my-dance-events__region"><?= escapeHtml($event['region_name']) ?></p>
                    </div>
                    <a class="button button--small" href="/event.php?id=<?= (int) $event['id'] ?>">Details</a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<style>
.my-dance-events{max-width:1000px;margin:0 auto}
.my-dance-events__list{display:grid;gap:12px}
.my-dance-events__item{display:flex;align-items:center;gap:16px;margin:0;padding:16px}
.my-dance-events__date{flex:0 0 58px;min-height:62px;display:flex;flex-direction:column;align-items:center;justify-content:center;border-radius:9px;background:var(--db-primary);color:#fff}
.my-dance-events__date strong{font-size:25px;line-height:1.1}
.my-dance-events__date span{font-size:12px;text-transform:uppercase}
.my-dance-events__details{flex:1;min-width:0}
.my-dance-events__details h2{font-size:18px;margin:0 0 5px}
.my-dance-events__details p{margin:4px 0;font-size:14px;line-height:1.45}
.my-dance-events__when{font-size:15px!important}
.my-dance-events__description{margin-top:10px!important;white-space:normal}
.my-dance-events__hashtags{color:var(--db-primary);overflow-wrap:anywhere}
.my-dance-events__details a{color:var(--db-primary);font-weight:600}
.my-dance-events__region{color:var(--db-primary);font-weight:700}
@media(max-width:600px){.my-dance-events__item{gap:10px;padding:12px}.my-dance-events__item>.button{padding:8px 10px}.my-dance-events__date{flex-basis:48px}}
</style>
<?php require dirname(__DIR__) . '/app/views/footer.php'; ?>
