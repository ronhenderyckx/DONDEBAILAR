<?php
declare(strict_types=1);

require_once __DIR__ . '/_private-root.php';

require_once DONDEBAILAR_PRIVATE_ROOT . '/app/bootstrap.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Authorization.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Security.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Events.php';

$user = requireLogin();
$db = Database::connection();
$regions = eventManageableRegions($db, $user);
$regionIds = array_map(static fn(array $r): int => (int) $r['id'], $regions);
$events = [];

if ($regionIds !== []) {
    $placeholders = implode(',', array_fill(0, count($regionIds), '?'));
    $statement = $db->prepare(
        "SELECT e.id, e.region_id, e.title, e.starts_at, e.status,
                e.created_by, r.name AS region_name, o.name AS organizer_name,
                (SELECT em.id
                 FROM event_media em
                 WHERE em.event_id = e.id
                   AND em.media_context = 'event'
                   AND em.file_path IS NOT NULL
                 ORDER BY em.id ASC
                 LIMIT 1) AS first_media_id,
                (SELECT em.media_type
                 FROM event_media em
                 WHERE em.event_id = e.id
                   AND em.media_context = 'event'
                   AND (em.file_path IS NOT NULL OR em.external_url IS NOT NULL)
                 ORDER BY em.id ASC
                 LIMIT 1) AS first_media_type
         FROM events e
         INNER JOIN regions r ON r.id = e.region_id
         LEFT JOIN organizers o ON o.id = e.organizer_id
         WHERE e.region_id IN ($placeholders)
         ORDER BY e.starts_at DESC, e.id DESC
         LIMIT 200"
    );
    $statement->execute($regionIds);
    $events = $statement->fetchAll();
}

$pageTitle = 'Mijn evenementen - DONDEBAILAR';
require DONDEBAILAR_PRIVATE_ROOT . '/app/views/header.php';
?>
<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">DONDEBAILAR</p>
            <h1>Evenementen</h1>
            <p>Beheer evenementen in de regio's waartoe je toegang hebt.</p>
        </div>
        <div class="form-actions"><button class="button" type="button" disabled title="Evenementen importeren is tijdelijk uitgeschakeld">Evenementen importeren</button><a class="button button--primary" href="/event-new.php">+ Evenement toevoegen</a></div>
    </div>

    <?php if (isset($_GET['created'])): ?>
        <div class="message message--success">Het evenement is aangemaakt.</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="message message--success">Het evenement is bijgewerkt.</div>
    <?php endif; ?>

    <section class="admin-section">
        <div class="section-heading"><h2>Evenementenoverzicht</h2></div>
        <?php if ($regions === []): ?>
            <p>Je hebt nog geen toegang tot een regio. Vraag een platformbeheerder om je aan een regio toe te wijzen.</p>
        <?php elseif ($events === []): ?>
            <p>Er zijn nog geen evenementen in jouw regio's.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="admin-table">
                    <thead><tr><th></th><th>Datum</th><th>Evenement</th><th>Regio</th><th>Organisator</th><th>Status</th><th>Actie</th></tr></thead>
                    <tbody>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td class="event-list-media">
                                <?php if (!empty($event['first_media_id']) && $event['first_media_type'] === 'image'): ?>
                                    <img src="/event-media.php?id=<?= (int) $event['first_media_id'] ?>"
                                         alt=""
                                         loading="lazy"
                                         style="display:block;width:180px;height:120px;object-fit:cover;border-radius:8px;">
                                <?php elseif (!empty($event['first_media_id'])): ?>
                                    <video src="/event-media.php?id=<?= (int) $event['first_media_id'] ?>"
                                           muted playsinline preload="metadata"
                                           style="display:block;width:180px;height:120px;object-fit:cover;border-radius:8px;"></video>
                                <?php endif; ?>
                            </td>
                            <td><?= escapeHtml(date('d-m-Y H:i', strtotime($event['starts_at']))) ?></td>
                            <td><strong><?php if ($event['status'] === 'published'): ?><a href="/event.php?id=<?= (int) $event['id'] ?>" target="_blank" rel="noopener noreferrer"><?= escapeHtml($event['title']) ?></a><?php else: ?><?= escapeHtml($event['title']) ?><?php endif; ?></strong></td>
                            <td><?= escapeHtml($event['region_name']) ?></td>
                            <td><?= escapeHtml((string) ($event['organizer_name'] ?? 'Geen organisator')) ?></td>
                            <td><?= escapeHtml($event['status']) ?></td>
                            <td>
                                <?php if (eventCanEdit($db, $event, $user)): ?>
                                    <a class="button button--small" href="/event-edit.php?id=<?= (int) $event['id'] ?>">Wijzigen</a>
                                <?php else: ?>
                                    <span>—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>
<?php require DONDEBAILAR_PRIVATE_ROOT . '/app/views/footer.php'; ?>
