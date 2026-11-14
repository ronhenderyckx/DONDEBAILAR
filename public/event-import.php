<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Authorization.php';
require_once dirname(__DIR__) . '/app/Security.php';
require_once dirname(__DIR__) . '/app/Events.php';

http_response_code(403);
exit('Evenementen importeren is tijdelijk uitgeschakeld.');

$user = requireLogin();
$db = Database::connection();
$regions = eventManageableRegions($db, $user);
$errors = [];
$preview = [];
$importMessage = '';

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="dondebailar-evenementen-template.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Titel', 'Begindatum', 'Beginuur', 'Einddatum', 'Einduur',
        'Organisator (optioneel)', 'Locatie', 'Stad', 'Categorie (optioneel)', 'Taal',
        'Beschrijving', 'Extra info', 'Hashtags'
    ], ';');
    fputcsv($out, [
        'Salsa Party', '21-11-2026', '21:00', '22-11-2026', '02:00',
        'Salsa Marina', 'Club Marina', 'Denia', 'Salsa', 'nl',
        'Een avond vol salsa', 'Gratis inkom vóór 22:00', '#salsa #bachata'
    ], ';');
    fclose($out);
    exit;
}

function importNormalizeText(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) {
        $value = $ascii;
    }
    return preg_replace('/[^a-z0-9]/', '', $value) ?? '';
}

function importHeaderKey(string $value): string
{
    return importNormalizeText($value);
}

function importDateTime(string $date, string $time, DateTimeZone $timezone, bool $required): ?DateTimeImmutable
{
    $date = trim($date);
    $time = trim($time);
    if ($date === '') {
        if ($required) {
            throw new RuntimeException('Begindatum ontbreekt.');
        }
        return null;
    }

    $parsed = false;
    foreach (['!d-m-Y', '!d/m/Y', '!Y-m-d', '!d.m.Y'] as $format) {
        $candidate = DateTimeImmutable::createFromFormat($format, $date, $timezone);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($candidate && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))) {
            $parsed = $candidate;
            break;
        }
    }
    if (!$parsed) {
        throw new RuntimeException('Datum ongeldig (gebruik DD-MM-JJJJ of JJJJ-MM-DD).');
    }
    if ($time === '') {
        if ($required) {
            throw new RuntimeException('Beginuur ontbreekt.');
        }
        $time = '23:59';
    }
    if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
        throw new RuntimeException('Tijd ongeldig (gebruik UU:MM).');
    }
    [$hour, $minute] = array_map('intval', explode(':', $time));
    return $parsed->setTime($hour, $minute, 0);
}

function resolveImportRow(PDO $db, array $raw, array $headerMap, array $region, array $user): array
{
    $value = static function (array $keys) use ($raw, $headerMap): string {
        foreach ($keys as $key) {
            $key = importHeaderKey($key);
            if (isset($headerMap[$key])) {
                return trim((string) ($raw[$headerMap[$key]] ?? ''));
            }
        }
        return '';
    };

    $row = [
        'title' => $value(['Titel', 'Naam evenement', 'Naam']),
        'start_date' => $value(['Begindatum', 'Startdatum']),
        'start_time' => $value(['Beginuur', 'Startuur', 'Begintijd']),
        'end_date' => $value(['Einddatum']),
        'end_time' => $value(['Einduur', 'Eindtijd']),
        'organizer' => $value(['Organisator']),
        'location' => $value(['Locatie']),
        'city' => $value(['Stad', 'Gemeente']),
        'category' => $value(['Categorie', 'Dansstijl']),
        'language' => strtolower($value(['Taal', 'Language'])),
        'description' => $value(['Beschrijving', 'Omschrijving']),
        'extra_info' => $value(['Extra info', 'Extra informatie']),
        'hashtags' => $value(['Hashtags', 'Tags']),
        'errors' => [],
    ];

    if ($row['title'] === '' || mb_strlen($row['title']) > 220) {
        $row['errors'][] = 'Titel ontbreekt of is langer dan 220 tekens.';
    }
    if (mb_strlen($row['description']) > 50000 || mb_strlen($row['extra_info']) > 50000) {
        $row['errors'][] = 'Beschrijving of extra info is te lang.';
    }
    if (mb_strlen($row['hashtags']) > 500) {
        $row['errors'][] = 'Hashtags mogen maximaal 500 tekens bevatten.';
    }

    try {
        $timezone = new DateTimeZone((string) ($region['timezone'] ?? 'Europe/Madrid'));
        $start = importDateTime($row['start_date'], $row['start_time'], $timezone, true);
        $end = importDateTime($row['end_date'], $row['end_time'], $timezone, false);
        if ($end !== null && $end < $start) {
            throw new RuntimeException('Einddatum en -uur liggen vóór het begin.');
        }
        $row['starts_at'] = $start->format('Y-m-d H:i:s');
        $row['ends_at'] = $end?->format('Y-m-d H:i:s');
        $row['timezone'] = $timezone->getName();
    } catch (Throwable $e) {
        $row['errors'][] = $e->getMessage();
    }

    $language = $row['language'] !== '' ? $row['language'] : (string) ($region['default_language'] ?? 'es');
    if (!in_array($language, ['nl', 'en', 'es', 'fr'], true)) {
        $row['errors'][] = 'Taal moet nl, en, es of fr zijn.';
    }
    $row['language'] = $language;

    $row['organizer_id'] = null;
    if ($row['organizer'] !== '') {
        $organizerQuery = $db->query("SELECT id, name FROM organizers WHERE is_active = 1");
        $organizerId = 0;
        $wantedOrganizer = importNormalizeText($row['organizer']);
        foreach ($organizerQuery->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            if (importNormalizeText((string) $candidate['name']) === $wantedOrganizer) {
                $organizerId = (int) $candidate['id'];
                break;
            }
        }
        if ($organizerId > 0) {
            $row['organizer_id'] = $organizerId;
        } else {
            $row['errors'][] = 'Organisator niet gevonden: ' . $row['organizer'];
        }
    }

    $row['location_id'] = null;
    if ($row['location'] !== '') {
        $locationQuery = $db->prepare(
            "SELECT id, name, city FROM locations
             WHERE region_id = :region_id AND is_active = 1"
        );
        $locationQuery->execute(['region_id' => (int) $region['id']]);
        $locationId = 0;
        $wantedLocation = importNormalizeText($row['location']);
        $wantedCity = importNormalizeText($row['city']);
        $matchingLocations = [];
        $fuzzyMatches = [];
        $wantedTokens = preg_split('/[^a-z0-9]+/', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $row['location']) ?: $row['location']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $wantedTokens = array_values(array_diff(array_unique($wantedTokens), ['bar', 'restaurante', 'restaurant', 'cafe', 'cafeteria']));
        foreach ($locationQuery->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            $candidateName = importNormalizeText((string) $candidate['name']);
            if ($candidateName === $wantedLocation) {
                $matchingLocations[] = $candidate;
                continue;
            }
            $candidateTokens = preg_split('/[^a-z0-9]+/', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $candidate['name']) ?: (string) $candidate['name']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $candidateTokens = array_values(array_diff(array_unique($candidateTokens), ['bar', 'restaurante', 'restaurant', 'cafe', 'cafeteria']));
            $sharedTokens = array_intersect($wantedTokens, $candidateTokens);
            if (count($sharedTokens) >= 2) {
                $sameCity = $wantedCity !== '' && importNormalizeText((string) ($candidate['city'] ?? '')) === $wantedCity;
                $fuzzyMatches[] = ['candidate' => $candidate, 'score' => count($sharedTokens) + ($sameCity ? 10 : 0)];
            }
        }
        // Eerst exacte naam + stad, daarna een unieke naam; pas daarna een duidelijke, unieke naamvariatie.
        foreach ($matchingLocations as $candidate) {
            if ($wantedCity !== '' && importNormalizeText((string) ($candidate['city'] ?? '')) === $wantedCity) {
                $locationId = (int) $candidate['id'];
                break;
            }
        }
        if ($locationId === 0 && count($matchingLocations) === 1) {
            $locationId = (int) $matchingLocations[0]['id'];
        }
        if ($locationId === 0 && count($fuzzyMatches) > 0) {
            usort($fuzzyMatches, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
            if (count($fuzzyMatches) === 1 || $fuzzyMatches[0]['score'] > $fuzzyMatches[1]['score']) {
                $locationId = (int) $fuzzyMatches[0]['candidate']['id'];
            }
        }
        if ($locationId > 0) {
            $row['location_id'] = $locationId;
        } else {
            $row['errors'][] = 'Locatie niet gevonden in deze regio: ' . $row['location'];
        }
    }

    $row['category_id'] = null;
    if ($row['category'] !== '') {
        $categoryQuery = $db->prepare(
            "SELECT c.id, c.name, ct.name AS translated_name
             FROM categories c
             LEFT JOIN category_translations ct ON ct.category_id = c.id
             WHERE c.is_active = 1 AND (c.region_id IS NULL OR c.region_id = :region_id)"
        );
        $categoryQuery->execute(['region_id' => (int) $region['id']]);
        $categoryId = 0;
        $wantedCategory = importNormalizeText($row['category']);
        foreach ($categoryQuery->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            if (importNormalizeText((string) $candidate['name']) === $wantedCategory
                || ($candidate['translated_name'] !== null
                    && importNormalizeText((string) $candidate['translated_name']) === $wantedCategory)) {
                $categoryId = (int) $candidate['id'];
                break;
            }
        }
        // Een onbekende categorie blokkeert de import niet.
        // Zonder bestaande categorie blijft category_id NULL.
        if ($categoryId > 0) {
            $row['category_id'] = $categoryId;
        }
    }

    return $row;
}

$selectedRegionId = (int) ($_POST['region_id'] ?? $_GET['region_id'] ?? ($regions[0]['id'] ?? 0));
$regionMap = [];
foreach ($regions as $item) {
    $regionMap[(int) $item['id']] = $item;
}
if (!isset($regionMap[$selectedRegionId])) {
    $selectedRegionId = (int) ($regions[0]['id'] ?? 0);
}
$selectedRegion = $regionMap[$selectedRegionId] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'De beveiligingscontrole is verlopen. Vernieuw de pagina.';
    } elseif (isset($_POST['confirm_import'])) {
        $stage = $_SESSION['event_import_stage'] ?? null;
        if (!is_array($stage) || (int) ($stage['user_id'] ?? 0) !== (int) $user['id']
            || (int) ($stage['region_id'] ?? 0) !== $selectedRegionId
            || (int) ($stage['expires'] ?? 0) < time()) {
            $errors[] = 'De voorbeeldgegevens zijn verlopen. Laad het bestand opnieuw in.';
        } else {
            $validRows = array_values(array_filter($stage['rows'], static fn(array $r): bool => empty($r['errors'])));
            $inserted = 0;
            $duplicates = 0;
            $db->beginTransaction();
            try {
                $insert = $db->prepare(
                    "INSERT INTO events
                        (region_id, organizer_id, location_id, category_id, title, description, extra_info,
                         hashtags, event_language, starts_at, ends_at, timezone, status, created_by, updated_by)
                     VALUES
                        (:region_id, :organizer_id, :location_id, :category_id, :title, :description, :extra_info,
                         :hashtags, :event_language, :starts_at, :ends_at, :timezone, 'draft', :created_by, :updated_by)"
                );
                $duplicateQuery = $db->prepare(
                    "SELECT id FROM events
                     WHERE region_id = :region_id AND title = :title AND starts_at = :starts_at
                       AND (organizer_id = :organizer_id OR (organizer_id IS NULL AND :organizer_is_null = 1)) LIMIT 1"
                );
                foreach ($validRows as $row) {
                    $duplicateQuery->execute([
                        'region_id' => $selectedRegionId,
                        'organizer_id' => $row['organizer_id'],
                        'organizer_is_null' => $row['organizer_id'] === null ? 1 : 0,
                        'title' => $row['title'],
                        'starts_at' => $row['starts_at'],
                    ]);
                    if ($duplicateQuery->fetchColumn()) {
                        $duplicates++;
                        continue;
                    }
                    $insert->execute([
                        'region_id' => $selectedRegionId,
                        'organizer_id' => $row['organizer_id'],
                        'location_id' => $row['location_id'],
                        'category_id' => $row['category_id'],
                        'title' => $row['title'],
                        'description' => $row['description'] !== '' ? $row['description'] : null,
                        'extra_info' => $row['extra_info'] !== '' ? $row['extra_info'] : null,
                        'hashtags' => $row['hashtags'] !== '' ? $row['hashtags'] : null,
                        'event_language' => $row['language'],
                        'starts_at' => $row['starts_at'],
                        'ends_at' => $row['ends_at'],
                        'timezone' => $row['timezone'],
                        'created_by' => (int) $user['id'],
                        'updated_by' => (int) $user['id'],
                    ]);
                    $inserted++;
                }
                $db->commit();
                unset($_SESSION['event_import_stage']);
                $importMessage = $inserted . ' evenement(en) als concept geïmporteerd. '
                    . $duplicates . ' dubbel(e) evenement(en) overgeslagen.';
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $errors[] = 'Importeren is niet gelukt: ' . $e->getMessage();
            }
        }
    } elseif (isset($_FILES['import_file']) && $selectedRegion !== null) {
        $file = $_FILES['import_file'];
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Selecteer een CSV-bestand en controleer de uploadlimiet.';
        } elseif ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
            $errors[] = 'Het bestand mag maximaal 5 MB groot zijn.';
        } elseif (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'csv') {
            $errors[] = 'Gebruik een CSV-bestand. Een Excelbestand kan in Excel worden opgeslagen als CSV UTF-8.';
        } else {
            $handle = fopen((string) $file['tmp_name'], 'rb');
            if (!$handle) {
                $errors[] = 'Het bestand kon niet worden geopend.';
            } else {
                $firstLine = fgets($handle);
                rewind($handle);
                $delimiter = (is_string($firstLine) && substr_count($firstLine, ';') >= substr_count($firstLine, ',') ) ? ';' : ',';
                $headers = fgetcsv($handle, 0, $delimiter);
                if (!is_array($headers) || $headers === []) {
                    $errors[] = 'De CSV bevat geen kolomkoppen.';
                } else {
                    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
                    $headerMap = [];
                    foreach ($headers as $index => $heading) {
                        $headerMap[importHeaderKey((string) $heading)] = $index;
                    }
                    $requiredHeaders = ['titel', 'begindatum', 'beginuur'];
                    foreach ($requiredHeaders as $requiredHeader) {
                        if (!isset($headerMap[$requiredHeader])) {
                            $errors[] = 'Verplichte kolom ontbreekt: ' . $requiredHeader;
                        }
                    }
                    if ($errors === []) {
                        $stagedRows = [];
                        $line = 1;
                        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
                            $line++;
                            if ($data === [null] || $data === []) {
                                continue;
                            }
                            $row = resolveImportRow($db, $data, $headerMap, $selectedRegion, $user);
                            $row['line'] = $line;
                            $stagedRows[] = $row;
                            if (count($stagedRows) >= 250) {
                                $errors[] = 'Maximaal 250 evenementen per importbestand.';
                                break;
                            }
                        }
                        if ($stagedRows === [] && $errors === []) {
                            $errors[] = 'Het bestand bevat geen evenementregels.';
                        }
                        if ($errors === []) {
                            $_SESSION['event_import_stage'] = [
                                'user_id' => (int) $user['id'],
                                'region_id' => $selectedRegionId,
                                'expires' => time() + 1800,
                                'rows' => $stagedRows,
                            ];
                            $preview = $stagedRows;
                        }
                    }
                }
                fclose($handle);
            }
        }
    } else {
        $errors[] = 'Kies een CSV-bestand om te controleren.';
    }
}

if ($preview === [] && isset($_SESSION['event_import_stage'])
    && (int) ($_SESSION['event_import_stage']['user_id'] ?? 0) === (int) $user['id']
    && (int) ($_SESSION['event_import_stage']['region_id'] ?? 0) === $selectedRegionId
    && (int) ($_SESSION['event_import_stage']['expires'] ?? 0) >= time()) {
    $preview = $_SESSION['event_import_stage']['rows'];
}

$pageTitle = 'Evenementen importeren - DONDEBAILAR';
require dirname(__DIR__) . '/app/views/header.php';
?>
<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">EVENEMENTEN</p>
            <h1>Evenementen importeren</h1>
            <p>Laad meerdere evenementen tegelijk in via een CSV-bestand.</p>
        </div>
        <a class="button" href="/events.php">Terug naar evenementen</a>
    </div>

    <?php if ($importMessage !== ''): ?><div class="message message--success"><?= escapeHtml($importMessage) ?></div><?php endif; ?>
    <?php foreach ($errors as $error): ?><div class="message message--error"><?= escapeHtml($error) ?></div><?php endforeach; ?>

    <section class="admin-section event-import-section">
        <p><a class="button" href="/event-import.php?template=1">Download CSV-voorbeeldbestand</a></p>
        <p>Vul het voorbeeldbestand in met Excel. Organisator en categorie zijn optioneel. Sla het op als <strong>CSV UTF-8</strong>. Datums: DD-MM-JJJJ; tijden: UU:MM.</p>
        <?php if ($regions === []): ?>
            <p>Je hebt nog geen toegang tot een regio.</p>
        <?php else: ?>
            <form method="post" enctype="multipart/form-data" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                <label for="region_id">Regio</label>
                <select id="region_id" name="region_id" required>
                    <?php foreach ($regions as $item): ?>
                        <option value="<?= (int) $item['id'] ?>" <?= $selectedRegionId === (int) $item['id'] ? 'selected' : '' ?>><?= escapeHtml($item['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="import_file">CSV-bestand (max. 5 MB)</label>
                <input id="import_file" type="file" name="import_file" accept=".csv,text/csv" required>
                <button class="button button--primary" type="submit">Bestand controleren</button>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($preview !== []): ?>
        <?php $validCount = count(array_filter($preview, static fn(array $r): bool => empty($r['errors']))); ?>
        <section class="admin-section">
            <h2>Controle vóór import</h2>
            <p><?= count($preview) ?> regels gevonden · <?= $validCount ?> klaar om te importeren · <?= count($preview) - $validCount ?> met fouten.</p>
            <div class="table-wrapper">
                <table class="admin-table">
                    <thead><tr><th>Regel</th><th>Evenement</th><th>Begint</th><th>Eindigt</th><th>Organisator</th><th>Controle</th></tr></thead>
                    <tbody>
                    <?php foreach ($preview as $row): ?>
                        <tr>
                            <td><?= (int) $row['line'] ?></td>
                            <td><?= escapeHtml($row['title']) ?></td>
                            <td><?= escapeHtml($row['starts_at'] ?? '') ?></td>
                            <td><?= escapeHtml($row['ends_at'] ?? '') ?></td>
                            <td><?= escapeHtml($row['organizer']) ?></td>
                            <td><?php if (empty($row['errors'])): ?><span class="status-active">OK</span><?php else: ?><span class="status-inactive"><?= escapeHtml(implode(' ', $row['errors'])) ?></span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($validCount > 0): ?>
                <form method="post" class="form-actions">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                    <input type="hidden" name="region_id" value="<?= $selectedRegionId ?>">
                    <button class="button button--primary" type="submit" name="confirm_import" value="1">Importeer <?= $validCount ?> evenementen als concept</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</section>
<style>
.event-import-section{max-width:900px}.event-import-section .auth-form{max-width:700px}.event-import-section input[type=file]{padding:8px}.event-import-section p{margin:10px 0}
</style>
<?php require dirname(__DIR__) . '/app/views/footer.php'; ?>
