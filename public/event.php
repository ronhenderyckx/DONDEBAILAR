<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Security.php';

$db = Database::connection();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(404);
    exit('Evenement niet gevonden.');
}
$statement = $db->prepare(
    "SELECT e.*, r.name AS region_name, r.logo_path, o.name AS organizer_name,
            l.name AS location_name, l.city AS location_city
     FROM events e
     INNER JOIN regions r ON r.id = e.region_id
     INNER JOIN organizers o ON o.id = e.organizer_id
     LEFT JOIN locations l ON l.id = e.location_id
     WHERE e.id = :id AND e.status = 'published' LIMIT 1"
);
$statement->execute(['id' => $id]);
$event = $statement->fetch();
if (!$event) {
    http_response_code(404);
    exit('Evenement niet gevonden.');
}
$statement = $db->prepare(
    "SELECT id, media_type, file_path, media_source, external_url, caption
     FROM event_media WHERE event_id = :event_id AND media_context = 'event'
     ORDER BY sort_order, id"
);
$statement->execute(['event_id' => $id]);
$media = $statement->fetchAll();

$pageTitle = (string) $event['title'] . ' — DONDEBAILAR';
$pageRobots = 'index, follow';
$region = [
    'id' => (int) $event['region_id'],
    'name' => (string) $event['region_name'],
    'logo_path' => $event['logo_path'],
];
require dirname(__DIR__) . '/app/views/header.php';
$escape = static fn($value): string => escapeHtml((string) $value);
?>
<section class="admin-page public-event-page">
    <p class="eyebrow"><?= $escape($event['region_name']) ?></p>
    <h1><?= $escape($event['title']) ?></h1>
    <p><strong><?= $escape(date('d-m-Y H:i', strtotime($event['starts_at']))) ?></strong></p>
    <p>Organisator: <?= $escape($event['organizer_name']) ?></p>
    <?php if (!empty($event['location_name'])): ?>
        <p>Locatie: <?= $escape($event['location_name']) ?><?= !empty($event['location_city']) ? ' — ' . $escape($event['location_city']) : '' ?></p>
    <?php endif; ?>
    <?php if (!empty($event['description'])): ?><div class="public-event-description"><?= nl2br($escape($event['description'])) ?></div><?php endif; ?>
    <?php if ($media !== []): ?>
        <section class="admin-section">
            <h2>Foto's, video's en berichten</h2>
            <div class="public-event-media">
                <?php foreach ($media as $item): ?>
                    <article class="public-event-media-item">
                        <?php if (($item['media_source'] ?? 'upload') === 'instagram'): ?>
                            <?php
                            $path = (string) (parse_url((string) $item['external_url'], PHP_URL_PATH) ?: '');
                            $segments = array_values(array_filter(explode('/', $path)));
                            $shortcode = $segments[1] ?? '';
                            $embedPath = $item['media_type'] === 'reel' ? 'reel' : 'p';
                            ?>
                            <?php if (preg_match('/\A[A-Za-z0-9_-]+\z/', $shortcode)): ?>
                                <iframe src="https://www.instagram.com/<?= $embedPath ?>/<?= rawurlencode($shortcode) ?>/embed" title="Instagram <?= $escape($item['media_type']) ?>" loading="lazy" allowtransparency="true" allowfullscreen></iframe>
                            <?php endif; ?>
                            <p><a href="<?= $escape($item['external_url']) ?>" target="_blank" rel="noopener noreferrer">Bekijk op Instagram</a></p>
                        <?php elseif (($item['media_source'] ?? 'upload') === 'facebook'): ?>
                            <?php
                            $plugin = $item['media_type'] === 'reel' ? 'video.php' : 'post.php';
                            $embedUrl = 'https://www.facebook.com/plugins/' . $plugin . '?href=' . rawurlencode((string) $item['external_url']) . '&show_text=true&width=500';
                            ?>
                            <iframe src="<?= $escape($embedUrl) ?>" title="Facebook <?= $escape($item['media_type']) ?>" loading="lazy" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share" allowfullscreen></iframe>
                            <p><a href="<?= $escape($item['external_url']) ?>" target="_blank" rel="noopener noreferrer">Bekijk op Facebook</a></p>
                        <?php elseif ($item['media_type'] === 'image'): ?>
                            <img src="/event-media.php?id=<?= (int) $item['id'] ?>" alt="<?= $escape($item['caption'] ?: $event['title']) ?>" loading="lazy">
                        <?php else: ?>
                            <video src="/event-media.php?id=<?= (int) $item['id'] ?>" controls preload="metadata"></video>
                        <?php endif; ?>
                        <?php if (!empty($item['caption'])): ?><p><?= $escape($item['caption']) ?></p><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</section>
<style>
.public-event-page{max-width:1000px;margin:0 auto}.public-event-description{margin:24px 0;line-height:1.7}.public-event-media{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:18px}.public-event-media-item{min-width:0;padding:12px;border:1px solid #e7eaf0;border-radius:10px;background:#fff}.public-event-media-item img,.public-event-media-item video,.public-event-media-item iframe{display:block;width:100%;max-width:100%;height:auto;aspect-ratio:4/5;border:0;border-radius:7px;background:#111;object-fit:contain}.public-event-media-item iframe{height:520px}.public-event-media-item p{overflow-wrap:anywhere}
</style>
<?php require dirname(__DIR__) . '/app/views/footer.php'; ?>
