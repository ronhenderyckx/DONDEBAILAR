<?php
declare(strict_types=1);

require_once __DIR__ . '/_private-root.php';

require_once DONDEBAILAR_PRIVATE_ROOT . '/app/bootstrap.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Authorization.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Security.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Events.php';

$user = requireLogin();
$db = Database::connection();
$maxEventDurationHours = platformMaxEventDurationHours();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    exit('Ongeldig evenementnummer.');
}
$statement = $db->prepare(
    "SELECT e.*, r.name AS region_name, r.timezone AS region_timezone,
            o.name AS organizer_name
     FROM events e
     INNER JOIN regions r ON r.id = e.region_id
     LEFT JOIN organizers o ON o.id = e.organizer_id
     WHERE e.id = :id LIMIT 1"
);
$statement->execute(['id' => $id]);
$event = $statement->fetch();
if (!$event) {
    http_response_code(404);
    exit('Evenement niet gevonden.');
}
if (!eventCanEdit($db, $event, $user)) {
    http_response_code(403);
    exit('Je hebt geen toestemming om dit evenement te wijzigen.');
}

$regions = eventManageableRegions($db, $user);
$organizers = $db->query("SELECT id, name FROM organizers WHERE is_active = 1 OR id = " . (int) $event['organizer_id'] . " ORDER BY name")->fetchAll();
$statement = $db->prepare("SELECT id, name, city FROM locations WHERE region_id = :region_id AND (is_active = 1 OR id = :location_id) ORDER BY name");
$statement->execute(['region_id' => (int) $event['region_id'], 'location_id' => (int) ($event['location_id'] ?? 0)]);
$locations = $statement->fetchAll();
$statement = $db->prepare("SELECT id, name FROM categories WHERE (region_id IS NULL OR region_id = :region_id) AND (is_active = 1 OR id = :category_id) ORDER BY sort_order, name");
$statement->execute(['region_id' => (int) $event['region_id'], 'category_id' => (int) ($event['category_id'] ?? 0)]);
$categories = $statement->fetchAll();
$statement = $db->prepare("SELECT id, media_type, file_path, media_source, external_url, caption, created_at FROM event_media WHERE event_id = :event_id AND media_context = 'event' ORDER BY sort_order, id");
$statement->execute(['event_id' => $id]);
$media = $statement->fetchAll();

$canPublish = isPlatformAdmin($user)
    || userHasRegionRole((int) $event['region_id'], ['editor', 'region_admin'], $user);
$errors = [];
$title = (string) $event['title'];
$description = (string) ($event['description'] ?? '');
$extraInfo = (string) ($event['extra_info'] ?? '');
$hashtags = (string) ($event['hashtags'] ?? '');
$presalePrice = (string) ($event['presale_price'] ?? '');
$doorPrice = (string) ($event['door_price'] ?? '');
$eventLanguage = (string) $event['event_language'];
$organizerId = (int) ($event['organizer_id'] ?? 0);
$locationId = (int) ($event['location_id'] ?? 0);
$categoryId = (int) ($event['category_id'] ?? 0);
$startsAt = date('Y-m-d\TH:i', strtotime($event['starts_at']));
$endsAt = $event['ends_at'] ? date('Y-m-d\TH:i', strtotime($event['ends_at'])) : '';
$status = (string) $event['status'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'De beveiligingscontrole is verlopen. Vernieuw de pagina.';
    }
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $extraInfo = trim((string) ($_POST['extra_info'] ?? ''));
    $hashtags = trim((string) ($_POST['hashtags'] ?? ''));
    $presalePrice = trim((string) ($_POST['presale_price'] ?? ''));
    $doorPrice = trim((string) ($_POST['door_price'] ?? ''));
    $eventLanguage = (string) ($_POST['event_language'] ?? 'es');
    $organizerId = (int) ($_POST['organizer_id'] ?? 0);
    $locationId = (int) ($_POST['location_id'] ?? 0);
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $startsAt = (string) ($_POST['starts_at'] ?? '');
    $endsAt = (string) ($_POST['ends_at'] ?? '');
    if ($canPublish) {
        $status = (string) ($_POST['status'] ?? $event['status']);
    }

    if ($title === '' || mb_strlen($title) > 220) $errors[] = 'Vul een titel in van maximaal 220 tekens.';
    if (mb_strlen($hashtags) > 500) $errors[] = 'Gebruik maximaal 500 tekens voor de hashtags.';
    if (!in_array($eventLanguage, ['nl','en','es','fr'], true)) $errors[] = 'Kies een geldige inhoudstaal.';
    $organizerIds = array_map(static fn($o) => (int) $o['id'], $organizers);
    if ($organizerId > 0 && !in_array($organizerId, $organizerIds, true)) $errors[] = 'Kies een geldige organisator.';
    if (!in_array($locationId, array_merge([0], array_map(static fn($l) => (int) $l['id'], $locations)), true)) $errors[] = 'Kies een geldige locatie.';
    if (!in_array($categoryId, array_merge([0], array_map(static fn($c) => (int) $c['id'], $categories)), true)) $errors[] = 'Kies een geldige categorie.';
    if ($canPublish && !in_array($status, ['draft','published','unpublished','cancelled'], true)) $errors[] = 'Kies een geldige status.';

    try {
        $timezone = new DateTimeZone((string) $event['timezone']);
        $start = new DateTimeImmutable($startsAt, $timezone);
        $end = $endsAt !== '' ? new DateTimeImmutable($endsAt, $timezone) : null;
        if ($startsAt === '' || ($end !== null && $end < $start)) {
            $errors[] = 'Controleer de start- en einddatum.';
        } elseif ($end !== null
            && ($end->getTimestamp() - $start->getTimestamp()) > ($maxEventDurationHours * 3600)
            && $end->getTimestamp() !== $start->modify('tomorrow')->setTime(0, 0)->getTimestamp()
        ) {
            $errors[] = 'Een evenement mag maximaal ' . $maxEventDurationHours . ' uur duren.';
        }
        if ($startsAt !== '' && $end === null) {
            $end = $start->modify('tomorrow')->setTime(0, 0);
        }
    } catch (Throwable $exception) {
        $errors[] = 'De datum of tijd is ongeldig.';
        $start = null;
        $end = null;
    }

    if ($errors === []) {
        $db->beginTransaction();
        try {
            $update = $db->prepare(
                "UPDATE events SET organizer_id = :organizer_id, location_id = :location_id,
                    category_id = :category_id, title = :title, description = :description, extra_info = :extra_info,
                    hashtags = :hashtags, presale_price = :presale_price, door_price = :door_price, event_language = :event_language, starts_at = :starts_at, ends_at = :ends_at,
                    status = :status, updated_by = :updated_by,
                    published_at = CASE WHEN :published_check = 'published' AND published_at IS NULL
                                        THEN CURRENT_TIMESTAMP ELSE published_at END
                 WHERE id = :id"
            );
            $update->execute([
                'organizer_id' => $organizerId > 0 ? $organizerId : null,
                'location_id' => $locationId > 0 ? $locationId : null,
                'category_id' => $categoryId > 0 ? $categoryId : null,
                'title' => $title,
                'description' => $description !== '' ? $description : null,
                'extra_info' => $extraInfo !== '' ? $extraInfo : null,
                'hashtags' => $hashtags !== '' ? $hashtags : null,
                'presale_price' => $presalePrice !== '' ? $presalePrice : null,
                'door_price' => $doorPrice !== '' ? $doorPrice : null,
                'event_language' => $eventLanguage,
                'starts_at' => $start->format('Y-m-d H:i:s'),
                'ends_at' => $end?->format('Y-m-d H:i:s'),
                'status' => $status,
                'updated_by' => (int) $user['id'],
                'published_check' => $status,
                'id' => $id,
            ]);

            foreach ((array) ($_POST['delete_media'] ?? []) as $mediaIdRaw) {
                $mediaId = filter_var($mediaIdRaw, FILTER_VALIDATE_INT);
                if (!$mediaId || $mediaId < 1) continue;
                $find = $db->prepare("SELECT id, file_path FROM event_media WHERE id = :id AND event_id = :event_id");
                $find->execute(['id' => $mediaId, 'event_id' => $id]);
                $item = $find->fetch();
                if (!$item) continue;
                $remove = $db->prepare("DELETE FROM event_media WHERE id = :id AND event_id = :event_id");
                $remove->execute(['id' => $mediaId, 'event_id' => $id]);
                $removedMediaPaths[] = (string) $item['file_path'];
            }

            $captionUpdate = $db->prepare("UPDATE event_media SET caption = :caption WHERE id = :media_id AND event_id = :event_id AND media_context = 'event'");
            foreach ((array) ($_POST['media_caption_existing'] ?? []) as $mediaIdRaw => $captionRaw) {
                $mediaId = filter_var($mediaIdRaw, FILTER_VALIDATE_INT);
                if (!$mediaId || $mediaId < 1) continue;
                $caption = trim((string) $captionRaw);
                $captionUpdate->execute(['caption' => $caption !== '' ? mb_substr($caption, 0, 500) : null, 'media_id' => $mediaId, 'event_id' => $id]);
            }
            storeEventSocialMedia($db, (int) $id, (int) $user['id'], $_POST);
            storeEventMedia($db, (int) $id, (int) $user['id'], $_FILES, (array) ($_POST['media_captions'] ?? []));
            $db->commit();

            // Verwijder bestanden pas nadat de databasewijzigingen definitief zijn.
            foreach ($removedMediaPaths ?? [] as $removedPath) {
                deleteEventMediaFile($removedPath, (int) $id);
            }

            header('Location: /event-edit.php?id=' . $id . '&updated=1');
            exit;
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Het evenement kon niet worden bijgewerkt.';
        }
    }
}

$pageTitle = 'Evenement wijzigen - DONDEBAILAR';
require DONDEBAILAR_PRIVATE_ROOT . '/app/views/header.php';
?>
<section class="admin-page">
    <div class="admin-heading">
        <div><p class="eyebrow">EVENEMENTEN · <?= escapeHtml($event['region_name']) ?></p><h1>Evenement wijzigen</h1><p>Aangemaakt door <?= escapeHtml((string) $event['created_by']) ?> · organisator <?= escapeHtml((string) ($event['organizer_name'] ?? 'Geen organisator')) ?></p></div>
        <a class="button" href="/events.php">Terug naar evenementen</a>
    </div>
    <?php if (isset($_GET['created'])): ?><div class="message message--success">Het evenement is aangemaakt. Het staat als concept klaar.</div><?php elseif (isset($_GET['updated'])): ?><div class="message message--success">De wijzigingen zijn opgeslagen.</div><?php endif; ?>
    <?php foreach ($errors as $error): ?><div class="message message--error"><?= escapeHtml($error) ?></div><?php endforeach; ?>
    <section class="admin-section event-form-section">
        <form method="post" enctype="multipart/form-data" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <label for="title">Titel *</label><input id="title" name="title" maxlength="220" required value="<?= escapeHtml($title) ?>">
            <label for="organizer_id">Organisator</label><select id="organizer_id" name="organizer_id"><option value="0">Geen organisator</option><?php foreach ($organizers as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $organizerId === (int) $item['id'] ? 'selected' : '' ?>><?= escapeHtml($item['name']) ?></option><?php endforeach; ?></select>
            <label for="location_id">Locatie</label><select id="location_id" name="location_id"><option value="0">Geen locatie</option><?php foreach ($locations as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $locationId === (int) $item['id'] ? 'selected' : '' ?>><?= escapeHtml($item['name'] . ' — ' . $item['city']) ?></option><?php endforeach; ?></select>
            <label for="category_id">Categorie</label><select id="category_id" name="category_id"><option value="0">Geen categorie</option><?php foreach ($categories as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $categoryId === (int) $item['id'] ? 'selected' : '' ?>><?= escapeHtml($item['name']) ?></option><?php endforeach; ?></select>
            <label for="event_language">Taal van de evenementinhoud *</label><select id="event_language" name="event_language"><?php foreach (['nl'=>'Nederlands','en'=>'English','es'=>'Español','fr'=>'Français'] as $code=>$label): ?><option value="<?= $code ?>" <?= $eventLanguage === $code ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
            <label for="starts_at">Begint op *</label><input id="starts_at" type="datetime-local" name="starts_at" required value="<?= escapeHtml($startsAt) ?>">
            <label for="ends_at">Eindigt op</label><input id="ends_at" type="datetime-local" name="ends_at" value="<?= escapeHtml($endsAt) ?>">
            <p class="event-form-note">Maximale duur: <?= (int) $maxEventDurationHours ?> uur. Laat leeg om het evenement automatisch om 24:00 uur (middernacht) te laten eindigen.</p>
            <label for="presale_price">Voorverkoop (€)</label><input id="presale_price" name="presale_price" type="number" min="0" max="99999.99" step="0.01" value="<?= escapeHtml($presalePrice) ?>" placeholder="Bijvoorbeeld 10">
            <label for="door_price">Aan de poort (€)</label><input id="door_price" name="door_price" type="number" min="0" max="99999.99" step="0.01" value="<?= escapeHtml($doorPrice) ?>" placeholder="Bijvoorbeeld 15">
            <label for="description">Beschrijving</label><textarea id="description" name="description" rows="8"><?= escapeHtml($description) ?></textarea>
            <label for="extra_info">Extra info</label><textarea id="extra_info" name="extra_info" rows="4"><?= escapeHtml($extraInfo) ?></textarea>
            <label for="hashtags">Hashtags</label><input id="hashtags" name="hashtags" maxlength="500" value="<?= escapeHtml($hashtags) ?>" placeholder="#salsa #bachata #kizomba">
            <p class="event-form-note">Zet de hashtags apart van de beschrijving, gescheiden door spaties of komma's.</p>
            <?php if ($canPublish): ?>
                <label for="status">Status</label><select id="status" name="status"><?php foreach (['draft'=>'Concept','published'=>'Gepubliceerd','unpublished'=>'Niet gepubliceerd','cancelled'=>'Geannuleerd'] as $code=>$label): ?><option value="<?= $code ?>" <?= $status === $code ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
            <?php else: ?><p>Dit evenement blijft een concept totdat een regioredacteur of regiobeheerder het publiceert.</p><?php endif; ?>

            <h2>Media bij dit evenement</h2>
            <?php if ($media !== []): ?>
                <div class="event-media-grid">
                <?php foreach ($media as $item): ?>
                    <label class="event-media-item">
                        <?php if (($item['media_source'] ?? 'upload') !== 'upload'): ?>
                            <div class="event-social-placeholder">
                                <strong><?= escapeHtml(ucfirst((string) $item['media_source'])) ?> · <?= escapeHtml($item['media_type'] === 'reel' ? 'Reel' : 'Bericht') ?></strong>
                                <a href="<?= escapeHtml((string) $item['external_url']) ?>" target="_blank" rel="noopener noreferrer">Open oorspronkelijk bericht</a>
                            </div>
                        <?php elseif ($item['media_type'] === 'image'): ?>
                            <img src="/event-media.php?id=<?= (int) $item['id'] ?>" alt="Evenementfoto">
                        <?php else: ?>
                            <video src="/event-media.php?id=<?= (int) $item['id'] ?>" controls preload="metadata"></video>
                        <?php endif; ?>
                        <span><?= escapeHtml($item['media_type'] === 'reel' ? 'Reel' : ($item['media_type'] === 'video' ? 'Video' : ($item['media_type'] === 'social_post' ? 'Bericht op sociale media' : 'Foto'))) ?></span>
                        <label class="event-media-caption">Tekst onder media<textarea name="media_caption_existing[<?= (int) $item['id'] ?>]" rows="2" maxlength="500" placeholder="Geen tekst laten zien als dit leeg blijft"><?= escapeHtml((string) ($item['caption'] ?? '')) ?></textarea></label>
                        <span><input type="checkbox" name="delete_media[]" value="<?= (int) $item['id'] ?>"> Verwijderen</span>
                    </label>
                <?php endforeach; ?>
                </div>
            <?php else: ?><p>Er zijn nog geen foto's, video's of reels toegevoegd.</p><?php endif; ?>
            <h3>Facebook- of Instagram-bericht koppelen</h3>
            <p>Plak de openbare link van een Facebook- of Instagram-bericht of reel. De inhoud blijft op het oorspronkelijke platform staan.</p>
            <label for="social_platform">Platform</label>
            <select id="social_platform" name="social_platform">
                <option value="instagram">Instagram</option>
                <option value="facebook">Facebook</option>
            </select>
            <label for="social_kind">Soort</label>
            <select id="social_kind" name="social_kind">
                <option value="reel">Reel</option>
                <option value="social_post">Bericht</option>
            </select>
            <label for="social_url">Link naar het openbare bericht</label>
            <input id="social_url" type="url" name="social_url" maxlength="1000" placeholder="https://www.instagram.com/reel/...">
            <p class="event-form-note">Je kunt per keer één link toevoegen. Sla de wijzigingen op om deze aan het evenement te koppelen.</p>
            <label for="photos">Foto's toevoegen (JPG/PNG/WebP, max. 15 MB per foto; worden automatisch verkleind en als WebP opgeslagen)</label><input id="photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple><div data-caption-list="photo"></div>
            <label for="videos">Video's toevoegen (MP4/WebM/MOV, max. 80 MB per video)</label><input id="videos" type="file" name="videos[]" accept="video/mp4,video/webm,video/quicktime" multiple><div data-caption-list="video"></div>
            <label for="reels">Reels toevoegen (MP4/WebM/MOV, max. 80 MB per reel)</label><input id="reels" type="file" name="reels[]" accept="video/mp4,video/webm,video/quicktime" multiple><div data-caption-list="reel"></div>
            <p class="event-form-note">De organisator is de persoon of organisatie die het evenement organiseert. De ingelogde gebruiker blijft geregistreerd als aanmaker.</p>
            <div class="form-actions"><button class="button button--primary" type="submit">Wijzigingen opslaan</button><a class="button" href="/events.php">Terug</a></div>
        </form>
    </section>
</section>
<style>
.event-form-section{max-width:900px}.event-form-section .auth-form{max-width:760px}.event-form-section input[type=file]{padding:8px}.event-form-section h2{margin:24px 0 0;padding-top:20px;border-top:1px solid #E7EAF0}.event-media-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px}.event-media-item{padding:10px;border:1px solid #E7EAF0;border-radius:9px;background:#fff}.event-media-item img,.event-media-item video{width:100%;height:120px;object-fit:cover;border-radius:6px;background:#111}.event-social-placeholder{min-height:120px;display:flex;flex-direction:column;justify-content:center;gap:12px;overflow-wrap:anywhere}.event-social-placeholder a{font-size:13px}.event-media-item span{display:block;margin-top:7px;font-size:12px}.event-media-caption{display:block;margin-top:10px;font-size:12px}.event-media-caption textarea{width:100%;min-height:54px;margin-top:4px;box-sizing:border-box}.event-form-note{color:var(--db-muted);font-size:13px}
</style>
<script>
document.querySelectorAll('input[type=file][multiple]').forEach(input => {
    const type = input.id === 'photos' ? 'photo' : (input.id === 'videos' ? 'video' : (input.id === 'reels' ? 'reel' : ''));
    const list = type ? document.querySelector('[data-caption-list="' + type + '"]') : null;
    if (!list) return;
    input.addEventListener('change', () => {
        list.replaceChildren();
        Array.from(input.files).forEach((file, index) => {
            const label = document.createElement('label');
            label.className = 'event-media-caption';
            label.textContent = file.name + ' — tekst onder media';
            const area = document.createElement('textarea');
            area.name = 'media_captions[' + type + '][' + index + ']';
            area.rows = 2; area.maxLength = 500;
            area.placeholder = 'Geen tekst laten zien als dit leeg blijft';
            label.appendChild(area); list.appendChild(label);
        });
    });
});
</script>
<?php require DONDEBAILAR_PRIVATE_ROOT . '/app/views/footer.php'; ?>
