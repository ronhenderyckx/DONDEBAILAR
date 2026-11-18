<?php
declare(strict_types=1);

require_once __DIR__ . '/_private-root.php';

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';

requirePlatformAdmin();

$pageTitle = 'Gebruikers beheren - DONDEBAILAR';
$platformAdminPage = true;
$db = Database::connection();

$users = $db->query(
    "SELECT
        u.id,
        u.email,
        u.display_name,
        u.preferred_language,
        u.platform_role,
        u.status,
        GROUP_CONCAT(
            DISTINCT CONCAT(r.name, ' (', urr.role, ')')
            ORDER BY r.name SEPARATOR ', '
        ) AS region_roles
     FROM users u
     LEFT JOIN user_region_roles urr ON urr.user_id = u.id
     LEFT JOIN regions r ON r.id = urr.region_id
     GROUP BY u.id
     ORDER BY u.display_name, u.email"
)->fetchAll();

require dirname(__DIR__, 2) . '/app/views/header.php';
?>

<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">PLATFORMBEHEER</p>
            <h1>Gebruikers beheren</h1>
            <p>Beheer accounts, platformrechten en regionale toegangen.</p>
        </div>
        <a class="button" href="/admin/">Terug naar dashboard</a>
    </div>

    <?php if (isset($_GET['created'])): ?>
        <div class="message message--success">De gebruiker is aangemaakt.</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="message message--success">De gebruiker is bijgewerkt.</div>
    <?php endif; ?>

    <section class="admin-section">
        <div class="section-heading">
            <h2>Gebruikersoverzicht</h2>
            <a class="button button--primary" href="/admin/user-new.php">
                + Gebruiker toevoegen
            </a>
        </div>

        <?php if ($users === []): ?>
            <p>Er zijn nog geen gebruikers.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Naam</th>
                            <th>E-mailadres</th>
                            <th>Platformrol</th>
                            <th>Regio's en rollen</th>
                            <th>Status</th>
                            <th>Actie</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $item): ?>
                            <tr>
                                <td><strong><?= escapeHtml($item['display_name']) ?></strong></td>
                                <td><?= escapeHtml($item['email']) ?></td>
                                <td><?= escapeHtml($item['platform_role']) ?></td>
                                <td><?= escapeHtml($item['region_roles'] ?: 'Geen regio toegewezen') ?></td>
                                <td><?= escapeHtml($item['status']) ?></td>
                                <td>
                                    <a class="button button--small"
                                       href="/admin/user-edit.php?id=<?= (int) $item['id'] ?>">
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
