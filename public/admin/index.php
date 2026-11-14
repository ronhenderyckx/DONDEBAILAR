<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';

$user = requirePlatformAdmin();
$pageTitle = 'Platformbeheer - DONDEBAILAR';
$platformAdminPage = true;

$db = Database::connection();

$userCount = (int) $db->query(
    "SELECT COUNT(*) FROM users"
)->fetchColumn();

$regionCount = (int) $db->query(
    "SELECT COUNT(*) FROM regions"
)->fetchColumn();

$eventCount = (int) $db->query(
    "SELECT COUNT(*) FROM events"
)->fetchColumn();

$organizerCount = (int) $db->query(
    "SELECT COUNT(*) FROM organizers"
)->fetchColumn();

$regions = $db->query(
    "SELECT id, name, slug, subdomain, country_code,
            timezone, is_active
     FROM regions
     ORDER BY name"
)->fetchAll();

require dirname(__DIR__, 2) . '/app/views/header.php';
?>

<section class="admin-page">

    <div class="admin-heading">
        <div>
            <p class="eyebrow">DONDEBAILAR</p>
            <h1>Platformbeheer</h1>
            <p>Welkom, <?= escapeHtml($user['display_name']) ?>.</p>
        </div>
    </div>

    <div class="dashboard-cards">
        <article class="dashboard-card">
            <span>Gebruikers</span>
            <strong><?= $userCount ?></strong>
        </article>

        <article class="dashboard-card">
            <span>Regio's</span>
            <strong><?= $regionCount ?></strong>
        </article>

        <article class="dashboard-card">
            <span>Evenementen</span>
            <strong><?= $eventCount ?></strong>
        </article>

        <article class="dashboard-card">
            <span>Organisatoren</span>
            <strong><?= $organizerCount ?></strong>
        </article>
    </div>

    <section class="admin-section">
        <div class="section-heading">
            <div>
                <h2>Regio's</h2>
                <p>Beheer de regio's die op DONDEBAILAR beschikbaar zijn.</p>
            </div>
        </div>

        <?php if ($regions === []): ?>
            <p>Er zijn nog geen regio's aangemaakt.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Regio</th>
                            <th>Subdomein</th>
                            <th>Land</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($regions as $region): ?>
                        <tr>
                            <td>
                                <strong><?= escapeHtml($region['name']) ?></strong>
                            </td>
                            <td><?= escapeHtml($region['subdomain']) ?></td>
                            <td><?= escapeHtml($region['country_code']) ?></td>
                            <td>
                                <?php if ((int) $region['is_active'] === 1): ?>
                                    <span class="status-active">Actief</span>
                                <?php else: ?>
                                    <span class="status-inactive">Inactief</span>
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

<?php require dirname(__DIR__, 2) . '/app/views/footer.php'; ?>
