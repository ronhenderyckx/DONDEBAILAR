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

// Filteropties ophalen uit de bestaande gegevens, zodat ook nieuwe rollen,
// statussen en talen automatisch beschikbaar worden in de filters.
$platformRoles = $db->query("SELECT DISTINCT platform_role FROM users WHERE platform_role IS NOT NULL AND platform_role <> '' ORDER BY platform_role")->fetchAll(PDO::FETCH_COLUMN);
$userStatuses = $db->query("SELECT DISTINCT status FROM users WHERE status IS NOT NULL AND status <> '' ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);
$userLanguages = $db->query("SELECT DISTINCT preferred_language FROM users WHERE preferred_language IS NOT NULL AND preferred_language <> '' ORDER BY preferred_language")->fetchAll(PDO::FETCH_COLUMN);
$regionRoles = $db->query("SELECT DISTINCT role FROM user_region_roles WHERE role IS NOT NULL AND role <> '' ORDER BY role")->fetchAll(PDO::FETCH_COLUMN);
$regions = $db->query("SELECT id, name FROM regions ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'platform_role' => trim((string) ($_GET['platform_role'] ?? '')),
    'region_id' => trim((string) ($_GET['region_id'] ?? '')),
    'region_role' => trim((string) ($_GET['region_role'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'language' => trim((string) ($_GET['language'] ?? '')),
];

$where = [];
$params = [];

if ($filters['search'] !== '') {
    $where[] = "(u.display_name LIKE :search_name OR u.email LIKE :search_email)";
    $searchTerm = '%' . $filters['search'] . '%';
    $params['search_name'] = $searchTerm;
    $params['search_email'] = $searchTerm;
}
if ($filters['platform_role'] !== '' && in_array($filters['platform_role'], $platformRoles, true)) {
    $where[] = "u.platform_role = :platform_role";
    $params['platform_role'] = $filters['platform_role'];
}
if ($filters['region_id'] !== '' && ctype_digit($filters['region_id'])) {
    $where[] = "EXISTS (SELECT 1 FROM user_region_roles filter_urr WHERE filter_urr.user_id = u.id AND filter_urr.region_id = :region_id)";
    $params['region_id'] = (int) $filters['region_id'];
}
if ($filters['region_role'] !== '' && in_array($filters['region_role'], $regionRoles, true)) {
    $where[] = "EXISTS (SELECT 1 FROM user_region_roles filter_role WHERE filter_role.user_id = u.id AND filter_role.role = :region_role)";
    $params['region_role'] = $filters['region_role'];
}
if ($filters['status'] !== '' && in_array($filters['status'], $userStatuses, true)) {
    $where[] = "u.status = :status";
    $params['status'] = $filters['status'];
}
if ($filters['language'] !== '' && in_array($filters['language'], $userLanguages, true)) {
    $where[] = "u.preferred_language = :language";
    $params['language'] = $filters['language'];
}

$sql = "SELECT
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
        LEFT JOIN regions r ON r.id = urr.region_id";
if ($where !== []) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " GROUP BY u.id ORDER BY u.display_name, u.email";

$userQuery = $db->prepare($sql);
$userQuery->execute($params);
$users = $userQuery->fetchAll(PDO::FETCH_ASSOC);
$activeFilterCount = count(array_filter($filters, static fn ($value) => $value !== ''));

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

    /* Compacte en responsieve tabel, gelijk aan het regio-overzicht. */
    .users-admin-page .admin-heading { margin-bottom: 20px; gap: 12px; }
    .users-admin-page .admin-section { margin-bottom: 18px; padding: 18px; }
    .users-admin-page .section-heading { margin-bottom: 12px; gap: 10px; }
    #users-admin-table { table-layout: auto; }
    #users-admin-table th { padding: 9px 11px; font-size: 10px; }
    #users-admin-table td { padding: 9px 11px; font-size: 12px; line-height: 1.4; }
    #users-admin-table td:last-child { min-width: 100px; white-space: nowrap; }
    #users-admin-table td:last-child .button { margin: 0 4px 0 0; vertical-align: middle; }
    #users-admin-table .button { min-height: 32px; padding: 6px 10px; font-size: 11px; }
    .users-filter-form { margin: 0 0 18px; padding: 14px; background: #F7F8FA; border: 1px solid <?= escapeHtml($userListColors['border']) ?>; border-radius: 10px; }
    .users-filter-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
    .users-filter-grid label { display: flex; flex-direction: column; gap: 5px; min-width: 0; color: #354052; font-size: 12px; font-weight: 600; }
    .users-filter-grid input, .users-filter-grid select { width: 100%; min-height: 38px; padding: 8px 10px; border: 1px solid #D5DAE2; border-radius: 6px; background: #FFFFFF; color: #202B38; font: inherit; font-weight: 400; }
    .users-filter-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 9px; margin-top: 13px; }
    .users-filter-actions .button { min-height: 36px; padding: 8px 13px; font-size: 12px; }
    .users-result-count { margin-left: auto; color: #687586; font-size: 12px; }
    @media (max-width: 760px) {
        .users-filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .users-filter-search { grid-column: 1 / -1; }
        .users-filter-actions { align-items: stretch; }
        .users-filter-actions .button { flex: 1 1 calc(50% - 9px); text-align: center; }
        .users-result-count { flex: 1 1 100%; margin: 3px 0 0; }
    }
    @media (max-width: 420px) {
        .users-filter-grid { grid-template-columns: 1fr; }
        .users-filter-search { grid-column: auto; }
    }
    @media (max-width: 760px) {
        .users-admin-page .admin-heading { margin-bottom: 16px; }
        .users-admin-page .admin-section { padding: 14px; }
        .users-admin-page .section-heading { align-items: flex-start; }
        #users-admin-table th, #users-admin-table td { padding: 9px 10px; }
    }
</style>

<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">PLATFORMBEHEER</p>
            <h1>Gebruikers beheren</h1>
            <p>Beheer accounts, platformrechten en regionale toegangen.</p>
        </div>
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

        <form method="get" action="/admin/users.php" class="users-filter-form">
            <div class="users-filter-grid">
                <label class="users-filter-search">
                    <span>Zoeken op naam of e-mailadres</span>
                    <input type="search" name="search" value="<?= escapeHtml($filters['search']) ?>" placeholder="Naam of e-mailadres">
                </label>
                <label>
                    <span>Platformrol</span>
                    <select name="platform_role">
                        <option value="">Alle platformrollen</option>
                        <?php foreach ($platformRoles as $role): ?>
                            <option value="<?= escapeHtml($role) ?>" <?= $filters['platform_role'] === $role ? 'selected' : '' ?>><?= escapeHtml($role) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Regio</span>
                    <select name="region_id">
                        <option value="">Alle regio's</option>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?= (int) $region['id'] ?>" <?= $filters['region_id'] === (string) $region['id'] ? 'selected' : '' ?>><?= escapeHtml($region['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Regionale rol</span>
                    <select name="region_role">
                        <option value="">Alle regionale rollen</option>
                        <?php foreach ($regionRoles as $role): ?>
                            <option value="<?= escapeHtml($role) ?>" <?= $filters['region_role'] === $role ? 'selected' : '' ?>><?= escapeHtml($role) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Status</span>
                    <select name="status">
                        <option value="">Alle statussen</option>
                        <?php foreach ($userStatuses as $status): ?>
                            <option value="<?= escapeHtml($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= escapeHtml($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Voorkeurstaal</span>
                    <select name="language">
                        <option value="">Alle talen</option>
                        <?php foreach ($userLanguages as $language): ?>
                            <option value="<?= escapeHtml($language) ?>" <?= $filters['language'] === $language ? 'selected' : '' ?>><?= escapeHtml(strtoupper($language)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="users-filter-actions">
                <button class="button button--primary" type="submit">Zoeken / filteren</button>
                <a class="button" href="/admin/users.php">Filters wissen</a>
                <span class="users-result-count"><?= count($users) ?> gebruiker(s) gevonden<?php if ($activeFilterCount > 0): ?> · <?= $activeFilterCount ?> filter(s) actief<?php endif; ?></span>
            </div>
        </form>

        <?php if ($users === []): ?>
            <p>Geen gebruikers gevonden met deze zoekopdracht of filters.</p>
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
