<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';

requirePlatformAdmin();

$pageTitle = "Regio's beheren - DONDEBAILAR";
$regions = Database::connection()->query(
    "SELECT id, name, slug, subdomain, country_code,
            timezone, default_language, is_active
     FROM regions
     ORDER BY name"
)->fetchAll();

require dirname(__DIR__, 2) . '/app/views/header.php';
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
                            <th>Actie</th>
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
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>

<?php require dirname(__DIR__, 2) . '/app/views/footer.php'; ?>
