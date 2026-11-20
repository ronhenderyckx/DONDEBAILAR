<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_private-root.php';

require_once DONDEBAILAR_PRIVATE_ROOT . '/app/bootstrap.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Authorization.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Security.php';

requirePlatformAdmin();

$pageTitle = 'Gebruikers beheren - DONDEBAILAR';
$platformAdminPage = true;

// Haal dezelfde lijstkleuren op als bij Platforminstellingen.
$userListColors = [
    'list_text' => '#202B38',
    'list_header_bg' => '#F1F3F6',
    'list_header_text' => '#687586',
    'list_row_bg' => '#FFFFFF',
    'list_hover_bg' => '#083B79',
    'list_hover_text' => '#FFFFFF',
    'action_button' => '#0A4D9F',
    'action_button_text' => '#FFFFFF',
    'action_hover_bg' => '#083B79',
    'action_hover_text' => '#FFFFFF',
    'status_active_bg' => '#EAF7F0',
    'status_active_text' => '#16845B',
    'status_inactive_bg' => '#F0F1F3',
    'status_inactive_text' => '#68717C',
    'border' => '#E7EAF0',
];
$platformColorsFile = DONDEBAILAR_PRIVATE_ROOT . '/storage/platform-admin-colors.json';
if (is_file($platformColorsFile)) {
    $savedUserListColors = json_decode((string) file_get_contents($platformColorsFile), true);
    if (is_array($savedUserListColors)) {
        foreach ($userListColors as $colorKey => $fallbackColor) {
            $candidateColor = strtoupper(trim((string) ($savedUserListColors[$colorKey] ?? '')));
            if (preg_match('/^#[0-9A-F]{6}$/', $candidateColor)) {
                $userListColors[$colorKey] = $candidateColor;
            }
        }
    }
}
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

require DONDEBAILAR_PRIVATE_ROOT . '/app/views/header.php';
?>

<style>
    #users-admin-table { color: <?= escapeHtml($userListColors['list_text']) ?>; border-color: <?= escapeHtml($userListColors['border']) ?>; }
    #users-admin-table thead th { background: <?= escapeHtml($userListColors['list_header_bg']) ?> !important; color: <?= escapeHtml($userListColors['list_header_text']) ?> !important; border-color: <?= escapeHtml($userListColors['border']) ?> !important; }
    #users-admin-table tbody tr { background: <?= escapeHtml($userListColors['list_row_bg']) ?> !important; color: <?= escapeHtml($userListColors['list_text']) ?> !important; }
    #users-admin-table tbody tr:hover { background: <?= escapeHtml($userListColors['list_hover_bg']) ?> !important; color: <?= escapeHtml($userListColors['list_hover_text']) ?> !important; }
    #users-admin-table tbody tr:hover td, #users-admin-table tbody tr:hover strong { color: <?= escapeHtml($userListColors['list_hover_text']) ?> !important; }
    #users-admin-table .button { background: <?= escapeHtml($userListColors['action_button']) ?> !important; color: <?= escapeHtml($userListColors['action_button_text']) ?> !important; border-color: <?= escapeHtml($userListColors['action_button']) ?> !important; }
    #users-admin-table .button:hover { background: <?= escapeHtml($userListColors['action_hover_bg']) ?> !important; color: <?= escapeHtml($userListColors['action_hover_text']) ?> !important; border-color: <?= escapeHtml($userListColors['action_hover_bg']) ?> !important; }
    #users-admin-table td, #users-admin-table th { border-color: <?= escapeHtml($userListColors['border']) ?> !important; }
</style>

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
                <table class="admin-table" id="users-admin-table">
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

<?php require DONDEBAILAR_PRIVATE_ROOT . '/app/views/footer.php'; ?>
