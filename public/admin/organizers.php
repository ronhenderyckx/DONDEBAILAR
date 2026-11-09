<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';

requirePlatformAdmin();

$pageTitle = 'Organisatoren beheren - DONDEBAILAR';
$db = Database::connection();

$search = trim((string) ($_GET['q'] ?? ''));
$sql = "SELECT id, name, phone, website, logo_path, is_active
        FROM organizers";
$params = [];

if ($search !== '') {
    $sql .= " WHERE name LIKE :search
              OR phone LIKE :search
              OR website LIKE :search
              OR notes LIKE :search";
    $params['search'] = '%' . $search . '%';
}
$sql .= " ORDER BY name";

$statement = $db->prepare($sql);
$statement->execute($params);
$organizers = $statement->fetchAll();

$totalCount = (int) $db->query("SELECT COUNT(*) FROM organizers")->fetchColumn();
$activeCount = (int) $db->query(
    "SELECT COUNT(*) FROM organizers WHERE is_active = 1"
)->fetchColumn();

require dirname(__DIR__, 2) . '/app/views/header.php';
?>

<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">PLATFORMBEHEER</p>
            <h1>Organisatoren</h1>
            <p>Beheer organisaties die evenementen organiseren in één of meerdere regio's.</p>
        </div>
        <a class="button" href="/admin/">Terug naar dashboard</a>
    </div>

    <?php if (isset($_GET['created'])): ?>
        <div class="message message--success">De organisator is aangemaakt.</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="message message--success">De organisator is bijgewerkt.</div>
    <?php endif; ?>

    <div class="dashboard-cards">
        <article class="dashboard-card">
            <span>Alle organisatoren</span>
            <strong><?= $totalCount ?></strong>
        </article>
        <article class="dashboard-card">
            <span>Actief</span>
            <strong><?= $activeCount ?></strong>
        </article>
        <article class="dashboard-card">
            <span>Inactief</span>
            <strong><?= $totalCount - $activeCount ?></strong>
        </article>
    </div>

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
                                         src="/<?= escapeHtml(ltrim($organizer['logo_path'], '/')) ?>"
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
