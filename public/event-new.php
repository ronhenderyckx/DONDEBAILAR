<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Authorization.php';
require_once dirname(__DIR__) . '/app/Security.php';
require_once dirname(__DIR__) . '/app/Events.php';

$user = requireLogin();
$db = Database::connection();
$regions = eventManageableRegions($db, $user);
$errors = [];
$selectedRegionId = (int) ($_POST['region_id'] ?? $_GET['region_id'] ?? ($regions[0]['id'] ?? 0));
$regionById = [];
foreach ($regions as $region) {
    $regionById[(int) $region['id']] = $region;
}
if (!isset($regionById[$selectedRegionId])) {
    $selectedRegionId = (int) ($regions[0]['id'] ?? 0);
}
$region = $regionById[$selectedRegionId] ?? null;

$organizers = $db->query(
    "SELECT id, name FROM organizers WHERE is_active = 1 ORDER BY name"
)->fetchAll();
$locations = [];
$categories = [];
if ($region !== null) {
    $statement = $db->prepare(
        "SELECT id, name, city FROM locations
         WHERE region_id = :region_id AND is_active = 1 ORDER BY name"
    );
    $statement->execute(['region_id' => $selectedRegionId]);
    $locations = $statement->fetchAll();

    $statement = $db->prepare(
        "SELECT id, name FROM categories
         WHERE is_active = 1 AND (region_id IS NULL OR region_id = :region_id)
         ORDER BY sort_order, name"
    );
    $statement->execute(['region_id' => $selectedRegionId]);
    $categories = $statement->fetchAll();
}

$title = trim((string) ($_POST['title'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$hashtags = trim((string) ($_POST['hashtags'] ?? ''));
$eventLanguage = (string) ($_POST['event_language'] ?? ($region['default_language'] ?? 'es'));
$organizerId = (int) ($_POST['organizer_id'] ?? 0);
$locationId = (int) ($_POST['location_id'] ?? 0);
$categoryId = (int) ($_POST['category_id'] ?? 0);
$startsAt = (string) ($_POST['starts_at'] ?? '');
$endsAt = (string) ($_POST['ends_at'] ?? '');
$recurrence = (string) ($_POST['recurrence'] ?? 'none');
$recurrenceUntil = (string) ($_POST['recurrence_until'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'De beveiligingscontrole is verlopen. Vernieuw de pagina.';
    }
    if ($region === null || !userHasRegionRole($selectedRegionId, ['user', 'editor', 'region_admin'], $user)) {
        $errors[] = 'Je hebt geen toegang tot de gekozen regio.';
    }
    if ($title === '' || mb_strlen($title) > 220) {
        $errors[] = 'Vul een titel in van maximaal 220 tekens.';
    }
    if (!in_array($recurrence, ['none', 'weekly', 'monthly'], true)) {
        $errors[] = 'Kies een geldige herhaling.';
    }
    if ($recurrence !== 'none' && $recurrenceUntil === '') {
        $errors[] = 'Vul de einddatum van de herhaling in.';
    }
    if (mb_strlen($hashtags) > 500) {
        $errors[] = 'Gebruik maximaal 500 tekens voor de hashtags.';
    }
    if (!in_array($eventLanguage, ['nl', 'en', 'es', 'fr'], true)) {
        $errors[] = 'Kies een geldige inhoudstaal.';
    }
    if ($organizerId < 1 || !in_array($organizerId, array_map(static fn($o) => (int) $o['id'], $organizers), true)) {
        $errors[] = 'Kies een actieve organisator.';
    }
    if ($locationId > 0 && !in_array($locationId, array_map(static fn($l) => (int) $l['id'], $locations), true)) {
        $errors[] = 'Kies een geldige locatie uit de geselecteerde regio.';
    }
    if ($categoryId > 0 && !in_array($categoryId, array_map(static fn($c) => (int) $c['id'], $categories), true)) {
        $errors[] = 'Kies een geldige categorie.';
    }

    try {
        $timezone = new DateTimeZone((string) ($region['timezone'] ?? 'Europe/Madrid'));
        $start = new DateTimeImmutable($startsAt, $timezone);
        $end = $endsAt !== '' ? new DateTimeImmutable($endsAt, $timezone) : null;
        if ($startsAt === '' || ($end !== null && $end < $start)) {
            $errors[] = 'Vul een geldige startdatum in en controleer de einddatum.';
        }
        $until = null;
        if ($recurrence !== 'none') {
            $until = DateTimeImmutable::createFromFormat('!Y-m-d', $recurrenceUntil, $timezone);
            if (!$until || $until < $start->setTime(0, 0)) {
                $errors[] = 'De einddatum van de herhaling moet op of na de eerste evenementdatum liggen.';
            } elseif ($until > $start->modify('+2 years')) {
                $errors[] = 'Kies een einddatum binnen twee jaar na de eerste evenementdatum.';
            }
        }
    } catch (Throwable $exception) {
        $errors[] = 'De datum of tijd is ongeldig.';
        $start = null;
        $end = null;
    }

    if ($errors === []) {
        $db->beginTransaction();
        try {
            $seriesId = null;
            if ($recurrence !== 'none') {
                $series = $db->prepare(
                    "INSERT INTO event_recurrence_series (frequency, ends_on, created_by)
                     VALUES (:frequency, :ends_on, :created_by)"
                );
                $series->execute([
                    'frequency' => $recurrence,
                    'ends_on' => $until->format('Y-m-d'),
                    'created_by' => (int) $user['id'],
                ]);
                $seriesId = (int) $db->lastInsertId();
            }

            $insert = $db->prepare(
                "INSERT INTO events
                    (region_id, organizer_id, location_id, category_id, title, description, hashtags,
                     event_language, starts_at, ends_at, timezone, status, created_by, updated_by,
                     recurrence_series_id, recurrence_number)
                 VALUES
                    (:region_id, :organizer_id, :location_id, :category_id, :title, :description, :hashtags,
                     :event_language, :starts_at, :ends_at, :timezone, 'draft', :created_by, :updated_by,
                     :recurrence_series_id, :recurrence_number)"
            );

            $occurrences = [$start];
            if ($recurrence !== 'none') {
                $cursor = $start;
                $sequence = 1;
                while (true) {
                    if ($recurrence === 'weekly') {
                        $next = $cursor->modify('+7 days');
                    } else {
                        $month = $start->modify('first day of this month')->modify('+' . $sequence . ' months');
                        $day = min((int) $start->format('j'), (int) $month->format('t'));
                        $next = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day)
                            ->setTime((int) $start->format('H'), (int) $start->format('i'), (int) $start->format('s'));
                    }
                    if ($next->setTime(0, 0) > $until) {
                        break;
                    }
                    $occurrences[] = $next;
                    $cursor = $next;
                    $sequence++;
                    if (count($occurrences) > 120) {
                        throw new RuntimeException('Deze herhaling bevat te veel edities. Kies een kortere periode.');
                    }
                }
            }

            $eventId = 0;
            foreach ($occurrences as $index => $occurrenceStart) {
                $occurrenceEnd = $end !== null
                    ? $occurrenceStart->modify('+' . ($end->getTimestamp() - $start->getTimestamp()) . ' seconds')
                    : null;
                $insert->execute([
                    'region_id' => $selectedRegionId,
                    'organizer_id' => $organizerId,
                    'location_id' => $locationId > 0 ? $locationId : null,
                    'category_id' => $categoryId > 0 ? $categoryId : null,
                    'title' => $title,
                    'description' => $description !== '' ? $description : null,
                    'hashtags' => $hashtags !== '' ? $hashtags : null,
                    'event_language' => $eventLanguage,
                    'starts_at' => $occurrenceStart->format('Y-m-d H:i:s'),
                    'ends_at' => $occurrenceEnd?->format('Y-m-d H:i:s'),
                    'timezone' => $timezone->getName(),
                    'created_by' => (int) $user['id'],
                    'updated_by' => (int) $user['id'],
                    'recurrence_series_id' => $seriesId,
                    'recurrence_number' => $index + 1,
                ]);
                if ($index === 0) {
                    $eventId = (int) $db->lastInsertId();
                }
            }

            storeEventMedia($db, $eventId, (int) $user['id'], $_FILES);
            $db->commit();
            header('Location: /event-edit.php?id=' . $eventId . '&created=1');
            exit;
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Het evenement kon niet worden opgeslagen.';
        }
    }
}

$pageTitle = 'Evenement toevoegen - DONDEBAILAR';
require dirname(__DIR__) . '/app/views/header.php';
?>
<section class="admin-page">
    <div class="admin-heading">
        <div><p class="eyebrow">EVENEMENTEN</p><h1>Evenement toevoegen</h1><p>Het evenement wordt eerst als concept opgeslagen.</p></div>
        <a class="button" href="/events.php">Terug naar evenementen</a>
    </div>
    <?php foreach ($errors as $error): ?><div class="message message--error"><?= escapeHtml($error) ?></div><?php endforeach; ?>
    <?php if ($regions === []): ?>
        <section class="admin-section"><p>Je hebt nog geen regio toegewezen gekregen.</p></section>
    <?php elseif ($organizers === []): ?>
        <section class="admin-section"><p>Er zijn nog geen actieve organisatoren. Laat eerst een organisator toevoegen.</p></section>
    <?php else: ?>
    <section class="admin-section event-form-section">
        <form method="post" enctype="multipart/form-data" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <label for="region_id">Regio *</label>
            <select id="region_id" name="region_id" required onchange="window.location.href='/event-new.php?region_id='+encodeURIComponent(this.value)">
                <?php foreach ($regions as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $selectedRegionId === (int) $item['id'] ? 'selected' : '' ?>><?= escapeHtml($item['name']) ?></option><?php endforeach; ?>
            </select>
            <label for="title">Titel *</label>
            <input id="title" name="title" maxlength="220" required value="<?= escapeHtml($title) ?>">
            <label for="organizer_id">Organisator *</label>
            <select id="organizer_id" name="organizer_id" required>
                <option value="">Kies een organisator</option>
                <?php foreach ($organizers as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $organizerId === (int) $item['id'] ? 'selected' : '' ?>><?= escapeHtml($item['name']) ?></option><?php endforeach; ?>
            </select>
            <label for="location_id">Locatie</label>
            <select id="location_id" name="location_id"><option value="">Nog geen locatie</option>
                <?php foreach ($locations as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $locationId === (int) $item['id'] ? 'selected' : '' ?>><?= escapeHtml($item['name'] . ' — ' . $item['city']) ?></option><?php endforeach; ?>
            </select>
            <label for="category_id">Categorie</label>
            <select id="category_id" name="category_id"><option value="">Geen categorie</option>
                <?php foreach ($categories as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $categoryId === (int) $item['id'] ? 'selected' : '' ?>><?= escapeHtml($item['name']) ?></option><?php endforeach; ?>
            </select>
            <label for="event_language">Taal van de evenementinhoud *</label>
            <select id="event_language" name="event_language" required>
                <?php foreach (['nl'=>'Nederlands','en'=>'English','es'=>'Español','fr'=>'Français'] as $code=>$label): ?><option value="<?= $code ?>" <?= $eventLanguage === $code ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
            </select>
            <label for="starts_at">Begint op *</label>
            <input id="starts_at" type="datetime-local" name="starts_at" required value="<?= escapeHtml($startsAt) ?>">
            <label for="ends_at">Eindigt op</label>
            <input id="ends_at" type="datetime-local" name="ends_at" value="<?= escapeHtml($endsAt) ?>">
            <h2>Herhaling</h2>
            <label for="recurrence">Herhaal dit evenement</label>
            <select id="recurrence" name="recurrence">
                <option value="none" <?= $recurrence === 'none' ? 'selected' : '' ?>>Geen herhaling</option>
                <option value="weekly" <?= $recurrence === 'weekly' ? 'selected' : '' ?>>Iedere week</option>
                <option value="monthly" <?= $recurrence === 'monthly' ? 'selected' : '' ?>>Iedere maand</option>
            </select>
            <div id="recurrence-until-wrap" <?= $recurrence === 'none' ? 'hidden' : '' ?>>
                <label for="recurrence_until">Laatste herhaling op (inclusief)</label>
                <input id="recurrence_until" type="date" name="recurrence_until" value="<?= escapeHtml($recurrenceUntil) ?>">
                <p class="event-form-note">Elke editie wordt een afzonderlijk evenement. Maximaal 120 edities en twee jaar vooruit.</p>
            </div>
            <label for="description">Beschrijving</label>
            <textarea id="description" name="description" rows="8"><?= escapeHtml($description) ?></textarea>
            <label for="hashtags">Hashtags</label>
            <input id="hashtags" name="hashtags" maxlength="500" value="<?= escapeHtml($hashtags) ?>" placeholder="#salsa #bachata #kizomba">
            <p class="event-form-note">Zet de hashtags apart van de beschrijving, gescheiden door spaties of komma's.</p>
            <h2>Media bij dit evenement</h2>
            <label for="photos">Foto's (meerdere mogelijk, JPG/PNG/WebP, max. 15 MB per foto)</label>
            <input id="photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
            <label for="videos">Video's (meerdere mogelijk, MP4/WebM/MOV, max. 80 MB per video)</label>
            <input id="videos" type="file" name="videos[]" accept="video/mp4,video/webm,video/quicktime" multiple>
            <label for="reels">Reels (meerdere mogelijk, MP4/WebM/MOV, max. 80 MB per reel)</label>
            <input id="reels" type="file" name="reels[]" accept="video/mp4,video/webm,video/quicktime" multiple>
            <p class="event-form-note">De gebruiker die dit formulier opslaat, wordt geregistreerd als aanmaker. De organisator is een afzonderlijke organisatie.</p>
            <div class="form-actions"><button class="button button--primary" type="submit">Evenement opslaan als concept</button><a class="button" href="/events.php">Annuleren</a></div>
        </form>
    </section>
    <?php endif; ?>
</section>
<style>
.event-form-section{max-width:900px}.event-form-section .auth-form{max-width:760px}.event-form-section input[type=file]{padding:8px}.event-form-section h2{margin:24px 0 0;padding-top:20px;border-top:1px solid #E7EAF0}.event-form-note{color:var(--db-muted);font-size:13px}
</style>
<script>
(() => {
    const select = document.getElementById('recurrence');
    const wrap = document.getElementById('recurrence-until-wrap');
    const date = document.getElementById('recurrence_until');
    if (select && wrap && date) {
        const sync = () => {
            const enabled = select.value !== 'none';
            wrap.hidden = !enabled;
            date.required = enabled;
        };
        select.addEventListener('change', sync);
        sync();
    }
})();
</script>
<?php require dirname(__DIR__) . '/app/views/footer.php'; ?>
