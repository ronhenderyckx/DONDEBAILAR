<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_private-root.php';

require_once DONDEBAILAR_PRIVATE_ROOT . '/app/bootstrap.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Authorization.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Security.php';

requirePlatformAdmin();

$pageTitle = "Regio's beheren - DONDEBAILAR";
$platformAdminPage = true;

// Gebruik dezelfde kleuren als de voorbeeldlijst bij Platforminstellingen.
$regionListColors = [
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
    $savedRegionListColors = json_decode((string) file_get_contents($platformColorsFile), true);
    if (is_array($savedRegionListColors)) {
        foreach ($regionListColors as $colorKey => $fallbackColor) {
            $candidateColor = strtoupper(trim((string) ($savedRegionListColors[$colorKey] ?? '')));
            if (preg_match('/^#[0-9A-F]{6}$/', $candidateColor)) {
                $regionListColors[$colorKey] = $candidateColor;
            }
        }
    }
}
$regions = Database::connection()->query(
    "SELECT id, name, slug, subdomain, country_code,
            timezone, default_language, is_active
     FROM regions
     ORDER BY name"
)->fetchAll();

require DONDEBAILAR_PRIVATE_ROOT . '/app/views/header.php';
?>

<style>
    #regions-admin-table { color: <?= escapeHtml($regionListColors['list_text']) ?>; border-color: <?= escapeHtml($regionListColors['border']) ?>; }
    #regions-admin-table thead th { background: <?= escapeHtml($regionListColors['list_header_bg']) ?> !important; color: <?= escapeHtml($regionListColors['list_header_text']) ?> !important; border-color: <?= escapeHtml($regionListColors['border']) ?> !important; }
    #regions-admin-table tbody tr { background: <?= escapeHtml($regionListColors['list_row_bg']) ?> !important; color: <?= escapeHtml($regionListColors['list_text']) ?> !important; }
    #regions-admin-table tbody tr:hover { background: <?= escapeHtml($regionListColors['list_hover_bg']) ?> !important; color: <?= escapeHtml($regionListColors['list_hover_text']) ?> !important; }
    #regions-admin-table tbody tr:hover td, #regions-admin-table tbody tr:hover strong { color: <?= escapeHtml($regionListColors['list_hover_text']) ?> !important; }
    #regions-admin-table .button { background: <?= escapeHtml($regionListColors['action_button']) ?> !important; color: <?= escapeHtml($regionListColors['action_button_text']) ?> !important; border-color: <?= escapeHtml($regionListColors['action_button']) ?> !important; }
    #regions-admin-table .button:hover { background: <?= escapeHtml($regionListColors['action_hover_bg']) ?> !important; color: <?= escapeHtml($regionListColors['action_hover_text']) ?> !important; border-color: <?= escapeHtml($regionListColors['action_hover_bg']) ?> !important; }
    #regions-admin-table .status-active { background: <?= escapeHtml($regionListColors['status_active_bg']) ?> !important; color: <?= escapeHtml($regionListColors['status_active_text']) ?> !important; }
    #regions-admin-table .status-inactive { background: <?= escapeHtml($regionListColors['status_inactive_bg']) ?> !important; color: <?= escapeHtml($regionListColors['status_inactive_text']) ?> !important; }
    #regions-admin-table td, #regions-admin-table th { border-color: <?= escapeHtml($regionListColors['border']) ?> !important; }
</style>

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
                <table class="admin-table" id="regions-admin-table">
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
