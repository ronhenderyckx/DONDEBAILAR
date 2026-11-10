<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';
require_once dirname(__DIR__, 2) . '/app/Organizers.php';

$user = requireOrganizerManagement();
$db = Database::connection();

$pageTitle = 'Organisatoren beheren - DONDEBAILAR';

$search = trim((string) ($_GET['q'] ?? ''));
$sql = "SELECT id, name, phone, website, logo_path, is_active
        FROM organizers";
$params = [];
$where = [];

if ($search !== '') {
    $where[] = "(name LIKE :search_name
              OR phone LIKE :search_phone
              OR website LIKE :search_website
              OR notes LIKE :search_notes)";
    $searchValue = '%' . $search . '%';
    $params += [
        'search_name' => $searchValue,
        'search_phone' => $searchValue,
        'search_website' => $searchValue,
        'search_notes' => $searchValue,
    ];
}
$sql .= ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY name';

$statement = $db->prepare($sql);
$statement->execute($params);
$organizers = $statement->fetchAll();


require dirname(__DIR__, 2) . '/app/views/header.php';
?>

<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">BEHEER</p>
            <h1>Organisatoren</h1>
            <p>Alle beheerders met beheerdersrechten kunnen alle organisatoren beheren.</p>
        </div>
        <a class="button" href="/admin/">Terug naar dashboard</a>
    </div>

    <?php if (isset($_GET['created'])): ?>
        <div class="message message--success">De organisator is aangemaakt.</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="message message--success">De organisator is bijgewerkt.</div>
    <?php endif; ?>



    <section class="admin-section">
        <div class="section-heading">
            <h2>Organisatorenoverzicht</h2>
            <a class="button button--primary" href="/admin/organizer-new.php">
                + Organisator toevoegen
            </a>
        </div>

        <form method="get" class="organizer-search">
            <label for="organizer-search">Zoeken</label>
            <div class="organizer-search__controls">
                <input id="organizer-search" type="search" name="q"
                       value="<?= escapeHtml($search) ?>"
                       placeholder="Naam, telefoon of website">
                <button class="button" type="submit">Zoeken</button>
                <?php if ($search !== ''): ?>
                    <a class="button" href="/admin/organizers.php">Wissen</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($organizers === []): ?>
            <p><?= $search !== '' ? 'Geen organisatoren gevonden.' : 'Er zijn nog geen organisatoren aangemaakt.' ?></p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="admin-table">
                    <thead>
                    <tr>
                        <th>Logo</th>
                        <th>Naam</th>
                        <th>Telefoon</th>
                        <th>Website</th>
                        <th>Status</th>
                        <th>Actie</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($organizers as $organizer): ?>
                        <tr>
                            <td>
                                <?php if (!empty($organizer['logo_path'])): ?>
                                    <img class="organizer-logo"
                                         src="/organizer-logo.php?file=<?= rawurlencode(basename((string) $organizer['logo_path'])) ?>"
                                         alt="Logo <?= escapeHtml($organizer['name']) ?>">
                                <?php else: ?>
                                    <span class="organizer-logo-placeholder" aria-label="Geen logo">—</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= escapeHtml($organizer['name']) ?></strong></td>
                            <td><?= escapeHtml($organizer['phone'] ?: '—') ?></td>
                            <td>
                                <?php if (!empty($organizer['website'])): ?>
                                    <a href="<?= escapeHtml($organizer['website']) ?>"
                                       target="_blank" rel="noopener noreferrer">Website</a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $organizer['is_active'] === 1): ?>
                                    <span class="status-active">Actief</span>
                                <?php else: ?>
                                    <span class="status-inactive">Inactief</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="button button--small"
                                   href="/admin/organizer-edit.php?id=<?= (int) $organizer['id'] ?>">
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

<style>
.organizer-search { margin: 0 0 22px; }
.organizer-search label { display:block; margin-bottom:7px; font-size:13px; font-weight:650; }
.organizer-search__controls { display:flex; flex-wrap:wrap; gap:9px; }
.organizer-search input { min-width:220px; min-height:42px; flex:1; padding:9px 12px; border:1px solid #DCE1E8; border-radius:8px; }
.organizer-logo { width:58px; height:48px; object-fit:contain; border:1px solid #E7EAF0; border-radius:7px; background:#fff; }
.organizer-logo-placeholder { width:58px; height:48px; display:grid; place-items:center; border:1px dashed #DCE1E8; border-radius:7px; color:#9AA3AF; }
</style>

<?php require dirname(__DIR__, 2) . '/app/views/footer.php'; ?>
