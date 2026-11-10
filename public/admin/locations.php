<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';
require_once dirname(__DIR__, 2) . '/app/Locations.php';

$user = requireLogin();
$db = Database::connection();
$regions = locationManageableRegions($db, $user);
$regionIds = array_map(static fn(array $region): int => (int) $region['id'], $regions);

$search = trim((string) ($_GET['q'] ?? ''));
$selectedRegionId = (int) ($_GET['region_id'] ?? 0);
$locations = [];

if ($regionIds !== []) {
    $allowedRegionIds = $selectedRegionId > 0 && in_array($selectedRegionId, $regionIds, true)
        ? [$selectedRegionId]
        : $regionIds;
    $placeholders = implode(',', array_fill(0, count($allowedRegionIds), '?'));
    $sql = "SELECT l.id, l.region_id, l.name, l.street, l.house_number,
                   l.postal_code, l.city, l.country_code, l.google_maps_url,
                   l.is_active, r.name AS region_name
            FROM locations l
            INNER JOIN regions r ON r.id = l.region_id
            WHERE l.region_id IN ($placeholders)";
    $params = $allowedRegionIds;

    if ($search !== '') {
        $sql .= " AND (l.name LIKE ? OR l.street LIKE ? OR l.city LIKE ?
                       OR l.postal_code LIKE ? OR l.google_maps_url LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $sql .= " ORDER BY l.name, l.city LIMIT 500";
    $statement = $db->prepare($sql);
    $statement->execute($params);
    $locations = $statement->fetchAll();
}

$pageTitle = 'Locaties beheren - DONDEBAILAR';
require dirname(__DIR__, 2) . '/app/views/header.php';
?>
<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">DONDEBAILAR</p>
            <h1>Locaties</h1>
            <p>Beheer de locaties van de regio's waartoe je toegang hebt.</p>
        </div>
        <a class="button" href="/admin/">Terug naar dashboard</a>
    </div>

    <?php if (isset($_GET['created'])): ?>
        <div class="message message--success">De locatie is toegevoegd.</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="message message--success">De locatie is bijgewerkt.</div>
    <?php endif; ?>

    <section class="admin-section">
        <div class="section-heading">
            <h2>Locatieoverzicht</h2>
            <a class="button button--primary" href="/admin/location-new.php">+ Locatie toevoegen</a>
        </div>

        <?php if ($regions === []): ?>
            <p>Je hebt nog geen regio toegewezen gekregen met rechten om locaties te beheren.</p>
        <?php else: ?>
            <form method="get" class="location-search">
                <label for="location-search">Zoeken</label>
                <div class="location-search__controls">
                    <input id="location-search" type="search" name="q"
                           value="<?= escapeHtml($search) ?>"
                           placeholder="Naam, gemeente, straat of postcode">
                    <?php if (count($regions) > 1): ?>
                        <select name="region_id" aria-label="Filter op regio">
                            <option value="0">Alle regio's</option>
                            <?php foreach ($regions as $item): ?>
                                <option value="<?= (int) $item['id'] ?>" <?= $selectedRegionId === (int) $item['id'] ? 'selected' : '' ?>>
                                    <?= escapeHtml($item['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <button class="button" type="submit">Zoeken</button>
                    <?php if ($search !== '' || $selectedRegionId > 0): ?>
                        <a class="button" href="/admin/locations.php">Wissen</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($locations === []): ?>
                <p><?= $search !== '' ? 'Geen locaties gevonden.' : 'Er zijn nog geen locaties toegevoegd.' ?></p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="admin-table">
                        <thead>
                        <tr><th>Locatie</th><th>Gemeente</th><th>Regio</th><th>Google Maps</th><th>Status</th><th>Actie</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($locations as $location): ?>
                            <tr>
                                <td>
                                    <strong><?= escapeHtml($location['name']) ?></strong>
                                    <?php if (!empty($location['street'])): ?>
                                        <br><small><?= escapeHtml(trim($location['street'] . ' ' . ($location['house_number'] ?? ''))) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= escapeHtml(trim(($location['postal_code'] ?? '') . ' ' . $location['city'])) ?></td>
                                <td><?= escapeHtml($location['region_name']) ?></td>
                                <td>
                                    <?php if (!empty($location['google_maps_url'])): ?>
                                        <a href="<?= escapeHtml($location['google_maps_url']) ?>" target="_blank" rel="noopener noreferrer">Kaart openen</a>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int) $location['is_active'] === 1): ?>
                                        <span class="status-active">Actief</span>
                                    <?php else: ?>
                                        <span class="status-inactive">Inactief</span>
                                    <?php endif; ?>
                                </td>
                                <td><a class="button button--small" href="/admin/location-edit.php?id=<?= (int) $location['id'] ?>">Bewerken</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($locations) === 500): ?>
                    <p class="location-note">De lijst toont maximaal 500 locaties. Gebruik de zoekfunctie om gerichter te zoeken.</p>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</section>
<style>
.location-search{margin:0 0 22px}.location-search label{display:block;margin-bottom:7px;font-size:13px;font-weight:650}.location-search__controls{display:flex;flex-wrap:wrap;gap:9px}.location-search input{min-width:220px;min-height:42px;flex:1;padding:9px 12px;border:1px solid #DCE1E8;border-radius:8px}.location-search select{min-height:42px;padding:8px 12px;border:1px solid #DCE1E8;border-radius:8px;background:#fff}.location-note{font-size:13px;color:var(--db-muted)}
</style>
<?php require dirname(__DIR__, 2) . '/app/views/footer.php'; ?>
