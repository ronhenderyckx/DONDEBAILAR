<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_private-root.php';

require_once DONDEBAILAR_PRIVATE_ROOT . '/app/bootstrap.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Authorization.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Security.php';

requirePlatformAdmin();

$pageTitle = "Regio's beheren - DONDEBAILAR";
$platformAdminPage = true;
$regions = Database::connection()->query(
    "SELECT id, name, slug, subdomain, country_code,
            timezone, default_language, is_active
     FROM regions
     ORDER BY name"
)->fetchAll();

require DONDEBAILAR_PRIVATE_ROOT . '/app/views/header.php';
?>

<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">PLATFORMBEHEER</p>
            <h1>Regio's beheren</h1>
            <p>Beheer de regionale agenda's van DONDEBAILAR.</p>
        </div>

        <a class="button" href="/admin/">Terug naar dashboard</a>
    </div>

    <section class="admin-section">
        <div class="section-heading">
            <h2>Overzicht regio's</h2>
            <a class="button button--primary" href="/admin/region-new.php">
                + Regio toevoegen
            </a>
        </div>

        <?php if ($regions === []): ?>
            <p>Er zijn nog geen regio's aangemaakt.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Naam</th>
                            <th>Subdomein</th>
                            <th>Land</th>
                            <th>Tijdzone</th>
                            <th>Taal</th>
                            <th>Status</th>
                            <th>Acties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($regions as $region): ?>
                            <tr>
                                <td><strong><?= escapeHtml($region['name']) ?></strong></td>
                                <td><?= escapeHtml($region['subdomain']) ?></td>
                                <td><?= escapeHtml($region['country_code']) ?></td>
                                <td><?= escapeHtml($region['timezone']) ?></td>
                                <td><?= escapeHtml(strtoupper($region['default_language'])) ?></td>
                                <td>
                                    <?php if ((int) $region['is_active'] === 1): ?>
                                        <span class="status-active">Actief</span>
                                    <?php else: ?>
                                        <span class="status-inactive">Inactief</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a class="button button--small"
                                       href="/admin/region-edit.php?id=<?= (int) $region['id'] ?>">
                                        Wijzigen
                                    </a>
                                    <form method="post" action="/admin/region-copy.php" style="display:inline"
                                          onsubmit="return confirm('Een kopie maken van deze regio? Evenementen en gebruikers worden niet meegekopieerd.');">
                                        <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                                        <input type="hidden" name="region_id" value="<?= (int) $region['id'] ?>">
                                        <button class="button button--small" type="submit">Kopiëren</button>
                                    </form>
                                    <form method="post" action="/admin/region-delete.php" style="display:inline"
                                          onsubmit="return confirm('Weet je zeker dat je deze regio definitief wilt wissen? Dit kan niet ongedaan worden gemaakt.');">
                                        <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                                        <input type="hidden" name="region_id" value="<?= (int) $region['id'] ?>">
                                        <button class="button button--small" type="submit">Wissen</button>
                                    </form>
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
